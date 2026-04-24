<?php
if (!defined('ABSPATH')) {
    exit;
}

class Sync_Admin
{
    /**
     * Minimum allowed length for the shared secret token used to authorize sync requests.
     */
    const MIN_TOKEN_LENGTH = 32;

    /**
     * Default length for auto-generated tokens.
     */
    const DEFAULT_TOKEN_LENGTH = 64;

    /**
     * The current admin page hook suffix returned by add_menu_page().
     * Used to enqueue assets only on the plugin page.
     *
     * @var string
     */
    private static $hook = '';

    /**
     * Register all admin hooks for the plugin:
     * - Admin menu page
     * - Settings registration
     * - Admin assets (CSS/JS)
     * - Form save handler (admin-post)
     * - AJAX endpoints (run sync, generate token)
     *
     * @return void
     */
    public static function init()
    {
        add_action('admin_menu', [__CLASS__, 'register_menu']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
        add_action('admin_post_sync_stp_save', [__CLASS__, 'handle_save']);
        add_action('wp_ajax_sync_stp_run', [__CLASS__, 'ajax_run_sync']);
        add_action('wp_ajax_sync_stp_generate_token', [__CLASS__, 'ajax_generate_token']);
    }

    /**
     * Add the plugin top-level admin menu page and store the page hook suffix.
     *
     * @return void
     */
    public static function register_menu()
    {
        self::$hook = add_menu_page(
            __('Sync Stage To Prod', 'sync-stage-to-prod'),
            __('Sync Stage To Prod', 'sync-stage-to-prod'),
            SYNC_STP_CAP,
            SYNC_STP_SLUG,
            [__CLASS__, 'render_page'],
            'dashicons-migrate',
            80
        );
    }

    /**
     * Register the plugin option in the WordPress Settings API.
     *
     * @return void
     */
    public static function register_settings()
    {
        register_setting(
            SYNC_STP_SLUG,
            SYNC_STP_OPT_KEY,
            [
                'type' => 'array',
                'sanitize_callback' => [__CLASS__, 'sanitize_options'],
                'default' => [],
            ]
        );
    }

    /**
     * Ensure the provided list of post type slugs is valid and optionally
     * append "contact_form" if that post type exists.
     *
     * @param mixed $types Candidate post type list (expected array of slugs).
     * @return array Sanitized list of post type slugs.
     */
    private static function maybe_add_contact_form_to_names($types)
    {
        if (!is_array($types)) {
            $types = [];
        }

        if (post_type_exists('contact_form') && !in_array('contact_form', $types, true)) {
            $types[] = 'contact_form';
        }

        $types = array_values(array_unique(array_map('sanitize_key', $types)));
        $types = array_values(array_filter($types, static function ($t) {
            return $t !== '' && $t !== 'attachment';
        }));

        return $types;
    }

    /**
     * Get default post types to sync:
     * - All public custom post types (non-builtin)
     * - Plus "page" and "post" when present
     * - Plus "contact_form" when present
     *
     * @return array
     */
    private static function get_default_post_types()
    {
        $types = get_post_types(['public' => true, '_builtin' => false], 'names');
        if (!is_array($types)) {
            $types = [];
        }

        if (post_type_exists('page')) {
            $types[] = 'page';
        }

        if (post_type_exists('post')) {
            $types[] = 'post';
        }

        $types = self::maybe_add_contact_form_to_names($types);

        return $types;
    }

    /**
     * Get all public post type slugs (including builtin ones), excluding attachments,
     * and optionally including "contact_form" if it exists.
     *
     * @return array
     */
    private static function get_all_public_post_types()
    {
        $types = get_post_types(['public' => true], 'names');
        if (!is_array($types)) {
            $types = [];
        }

        $types = self::maybe_add_contact_form_to_names($types);

        return $types;
    }

    /**
     * Get public post type objects for display in the admin UI.
     * Ensures "contact_form" is included (if registered) and excludes attachments.
     *
     * @return array<string, WP_Post_Type>
     */
    private static function get_available_post_type_objects()
    {
        $types = get_post_types(['public' => true], 'objects');
        if (!is_array($types)) {
            $types = [];
        }

        if (post_type_exists('contact_form') && !isset($types['contact_form'])) {
            $obj = get_post_type_object('contact_form');
            if ($obj) {
                $types['contact_form'] = $obj;
            }
        }

        if (isset($types['attachment'])) {
            unset($types['attachment']);
        }

        return $types;
    }

    /**
     * Sanitize and normalize the plugin options before saving.
     *
     * Key idea:
     * - Stage/Dev site stores the PROD URL (target_url) it will send data to.
     * - Prod site stores the allowed Stage/Dev URL (source_url) it will accept media downloads from (SSRF protection).
     *
     * Behavior depends on mode:
     * - "stage": allow editing post types and target_url; preserve source_url from existing options
     * - "prod": allow editing source_url; preserve post types + target_url from existing options
     *
     * Token and endpoint path are always sanitized/validated.
     *
     * @param mixed $options Raw settings input from the Settings API.
     * @return array Sanitized options array.
     */
    public static function sanitize_options($options)
    {
        $output = [];
        $post_types = [];
        $available = [];
        $target_url = '';
        $source_url = '';
        $auth_token = '';
        $endpoint_path = '';
        $mode = 'stage';
        $existing = get_option(SYNC_STP_OPT_KEY, []);

        if (!empty($options['mode']) && is_string($options['mode']) && $options['mode'] === 'prod') {
            $mode = 'prod';
        }

        if ($mode === 'stage') {
            $available = self::get_all_public_post_types();

            if (!empty($options['post_types']) && is_array($options['post_types'])) {
                foreach ($options['post_types'] as $type) {
                    $key = sanitize_key($type);
                    if ($key !== '' && in_array($key, $available, true)) {
                        $post_types[] = $key;
                    }
                }
            }

            $post_types = array_values(array_unique($post_types));

            if (empty($post_types)) {
                $post_types = self::get_default_post_types();
            }

            if (!empty($options['target_url']) && is_string($options['target_url'])) {
                $validated = Sync_Validator::validate_url($options['target_url']);
                if (is_string($validated)) {
                    $target_url = $validated;
                }
            }

            if ($target_url === '' && !empty($existing['target_url']) && is_string($existing['target_url'])) {
                $target_url = esc_url_raw(trim($existing['target_url']));
            }

            if (!empty($existing['source_url']) && is_string($existing['source_url'])) {
                $source_url = esc_url_raw(trim($existing['source_url']));
            }
        } else {
            if (!empty($existing['post_types']) && is_array($existing['post_types'])) {
                $post_types = array_values(array_unique(array_map('sanitize_key', $existing['post_types'])));
            }

            if (!empty($existing['target_url']) && is_string($existing['target_url'])) {
                $target_url = esc_url_raw(trim($existing['target_url']));
            }

            if (!empty($options['source_url']) && is_string($options['source_url'])) {
                $validated = Sync_Validator::validate_url($options['source_url']);
                if (is_string($validated)) {
                    $source_url = $validated;
                }
            }

            if ($source_url === '' && !empty($existing['source_url']) && is_string($existing['source_url'])) {
                $source_url = esc_url_raw(trim($existing['source_url']));
            }
        }

        if (!empty($options['auth_token']) && is_string($options['auth_token'])) {
            $auth_token = sanitize_text_field($options['auth_token']);
        } elseif (!empty($existing['auth_token']) && is_string($existing['auth_token'])) {
            $auth_token = sanitize_text_field($existing['auth_token']);
        }

        if (!empty($options['endpoint_path']) && is_string($options['endpoint_path'])) {
            $endpoint_path = Sync_Validator::validate_endpoint_path($options['endpoint_path']);
        } else {
            $endpoint_path = Sync_Validator::validate_endpoint_path('');
        }

        $output['mode'] = $mode;
        $output['post_types'] = $post_types;
        $output['target_url'] = $target_url;
        $output['source_url'] = $source_url;
        $output['auth_token'] = $auth_token;
        $output['endpoint_path'] = $endpoint_path;

        return $output;
    }

    /**
     * Enqueue admin UI assets only on this plugin's admin page.
     *
     * @param string $hook Current admin page hook suffix.
     * @return void
     */
    public static function enqueue_assets($hook)
    {
        if ($hook !== self::$hook) {
            return;
        }

        wp_enqueue_style(
            'sync-stp-admin-style',
            SYNC_STP_URL . 'assets/css/admin-sync.css',
            [],
            SYNC_STP_VERSION
        );

        wp_enqueue_script(
            'sync-stp-admin-js',
            SYNC_STP_URL . 'assets/js/admin-sync.js',
            [],
            SYNC_STP_VERSION,
            true
        );

        /**
         * Pass server-side config and translated UI messages to the admin JS.
         * Note: translations remain in the plugin text domain; only comments are in English.
         */
        wp_localize_script(
            'sync-stp-admin-js',
            'syncStageToProd',
            [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('sync_stp_nonce'),
                'tokenMinLength' => self::MIN_TOKEN_LENGTH,
                'tokenDefaultLength' => self::DEFAULT_TOKEN_LENGTH,
                'messages' => [
                    'noTypes' => __('Оберіть хоча б один тип записів.', 'sync-stage-to-prod'),
                    'error' => __('Сталася помилка під час синхронізації.', 'sync-stage-to-prod'),
                    'success' => __('Синхронізація успішно завершена.', 'sync-stage-to-prod'),
                    'generate' => __('Згенерувати', 'sync-stage-to-prod'),
                    'generating' => __('Генерується...', 'sync-stage-to-prod'),
                    'tokenGenerated' => __('Згенеровано!', 'sync-stage-to-prod'),
                    'tokenError' => __('Помилка генерації токена. Спробуйте ще раз.', 'sync-stage-to-prod'),
                    'tokenTooShort' => sprintf(
                        __('Токен має містити щонайменше %d символів.', 'sync-stage-to-prod'),
                        self::MIN_TOKEN_LENGTH
                    ),
                ],
            ]
        );
    }

    /**
     * Handle saving the plugin settings from the admin form (admin-post endpoint).
     * Validates permissions + nonce, sanitizes inputs, and persists them to the options table.
     *
     * Important:
     * - In Stage mode: allow editing post types + target_url (Prod URL).
     * - In Prod mode: allow editing source_url (Stage URL allowlist for media downloads).
     *
     * @return void
     */
    public static function handle_save()
    {
        $types = [];
        $available = [];
        $options = [];
        $redirect = '';
        $target_url = '';
        $source_url = '';
        $auth_token = '';
        $endpoint_path = '';
        $mode = 'stage';
        $existing = get_option(SYNC_STP_OPT_KEY, []);

        if (!current_user_can(SYNC_STP_CAP)) {
            wp_die(esc_html__('Недостатньо прав для цієї дії.', 'sync-stage-to-prod'));
        }

        check_admin_referer('sync_stp_save', 'sync_stp_save_nonce');

        if (!empty($_POST['sync_stp_mode']) && is_string($_POST['sync_stp_mode']) && $_POST['sync_stp_mode'] === 'prod') {
            $mode = 'prod';
        }

        if ($mode === 'stage') {
            $available = self::get_all_public_post_types();

            if (!empty($_POST['sync_stp_post_types']) && is_array($_POST['sync_stp_post_types'])) {
                foreach ($_POST['sync_stp_post_types'] as $type) {
                    $key = sanitize_key($type);
                    if ($key !== '' && in_array($key, $available, true)) {
                        $types[] = $key;
                    }
                }
            }

            $types = array_values(array_unique($types));

            if (empty($types)) {
                $types = self::get_default_post_types();
            }

            if (!empty($_POST['sync_stp_target_url']) && is_string($_POST['sync_stp_target_url'])) {
                $raw_url = trim(wp_unslash($_POST['sync_stp_target_url']));
                $validated = Sync_Validator::validate_url($raw_url);
                if (is_string($validated)) {
                    $target_url = $validated;
                }
            }

            if ($target_url === '' && !empty($existing['target_url']) && is_string($existing['target_url'])) {
                $target_url = esc_url_raw(trim($existing['target_url']));
            }

            if (!empty($existing['source_url']) && is_string($existing['source_url'])) {
                $source_url = esc_url_raw(trim($existing['source_url']));
            }
        } else {
            if (!empty($existing['post_types']) && is_array($existing['post_types'])) {
                $types = array_values(array_unique(array_map('sanitize_key', $existing['post_types'])));
            }

            if (!empty($existing['target_url']) && is_string($existing['target_url'])) {
                $target_url = esc_url_raw(trim($existing['target_url']));
            }

            if (!empty($_POST['sync_stp_source_url']) && is_string($_POST['sync_stp_source_url'])) {
                $raw_url = trim(wp_unslash($_POST['sync_stp_source_url']));
                $validated = Sync_Validator::validate_url($raw_url);
                if (is_string($validated)) {
                    $source_url = $validated;
                }
            }

            if ($source_url === '' && !empty($existing['source_url']) && is_string($existing['source_url'])) {
                $source_url = esc_url_raw(trim($existing['source_url']));
            }
        }

        if (!empty($_POST['sync_stp_auth_token']) && is_string($_POST['sync_stp_auth_token'])) {
            $auth_token = sanitize_text_field(wp_unslash($_POST['sync_stp_auth_token']));
        } elseif (!empty($existing['auth_token']) && is_string($existing['auth_token'])) {
            $auth_token = sanitize_text_field($existing['auth_token']);
        }

        if (!empty($_POST['sync_stp_endpoint_path']) && is_string($_POST['sync_stp_endpoint_path'])) {
            $raw_path = trim(wp_unslash($_POST['sync_stp_endpoint_path']));
            $endpoint_path = Sync_Validator::validate_endpoint_path($raw_path);
        } else {
            $endpoint_path = Sync_Validator::validate_endpoint_path('');
        }

        $options['mode'] = $mode;
        $options['post_types'] = $types;
        $options['target_url'] = $target_url;
        $options['source_url'] = $source_url;
        $options['auth_token'] = $auth_token;
        $options['endpoint_path'] = $endpoint_path;

        update_option(SYNC_STP_OPT_KEY, $options);

        $redirect = menu_page_url(SYNC_STP_SLUG, false);
        if (!$redirect) {
            $redirect = admin_url('admin.php?page=' . SYNC_STP_SLUG);
        }

        $redirect = add_query_arg('sync_stp_saved', '1', $redirect);
        wp_safe_redirect($redirect);
        exit;
    }

    /**
     * Render the plugin admin page (settings + sync action UI).
     *
     * Note:
     * - Stage mode shows "Target URL" (Prod URL).
     * - Prod mode shows "Source URL" (allowed Stage/Dev URL for media downloads).
     *
     * @return void
     */
    public static function render_page()
    {
        $options = [];
        $selected = [];
        $post_types = [];
        $target_url = '';
        $source_url = '';
        $auth_token = '';
        $endpoint_path = '/wp-json/sync-stp/v1';
        $mode = 'stage';

        if (!current_user_can(SYNC_STP_CAP)) {
            return;
        }

        $options = get_option(SYNC_STP_OPT_KEY, []);

        if (!empty($options['mode']) && is_string($options['mode']) && $options['mode'] === 'prod') {
            $mode = 'prod';
        }

        if (!empty($options['post_types']) && is_array($options['post_types'])) {
            $selected = array_values(array_unique(array_map('sanitize_key', $options['post_types'])));
        }

        if (empty($selected)) {
            $selected = self::get_default_post_types();
        }

        if (!empty($options['target_url']) && is_string($options['target_url'])) {
            $target_url = esc_url($options['target_url']);
        }

        if (!empty($options['source_url']) && is_string($options['source_url'])) {
            $source_url = esc_url($options['source_url']);
        }

        if (!empty($options['auth_token']) && is_string($options['auth_token'])) {
            $auth_token = $options['auth_token'];
        }

        if (!empty($options['endpoint_path']) && is_string($options['endpoint_path'])) {
            $endpoint_path = $options['endpoint_path'];
        }

        $post_types = self::get_available_post_type_objects();
        ?>
        <div class="wrap sync-stp <?php echo $mode === 'prod' ? 'sync-stp--mode-prod' : 'sync-stp--mode-stage'; ?>">
            <h1 class="sync-stp__title">
                <?php echo esc_html__('Синхронізація контенту зі стейджа на прод', 'sync-stage-to-prod'); ?>
            </h1>

            <?php if (!empty($_GET['sync_stp_saved']) && $_GET['sync_stp_saved'] === '1') : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php echo esc_html__('Налаштування збережено.', 'sync-stage-to-prod'); ?></p>
                </div>
            <?php endif; ?>

            <div class="sync-stp__inner">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="sync-stp__form">
                    <input type="hidden" name="action" value="sync_stp_save">
                    <?php wp_nonce_field('sync_stp_save', 'sync_stp_save_nonce'); ?>

                    <?php if ($mode === 'stage') : ?>
                        <div class="sync-stp__row sync-stp__section sync-stp__section--types">
                            <h2 class="sync-stp__subtitle">
                                <?php echo esc_html__('Типи записів для синхронізації', 'sync-stage-to-prod'); ?>
                            </h2>
                            <div class="sync-stp__types-list">
                                <?php
                                if (!empty($post_types) && is_array($post_types)) :
                                    foreach ($post_types as $name => $type) :
                                        if ($name === 'attachment') {
                                            continue;
                                        }
                                        $checked = in_array($name, $selected, true);
                                        ?>
                                        <label class="sync-stp__type-label">
                                            <input
                                                type="checkbox"
                                                name="sync_stp_post_types[]"
                                                value="<?php echo esc_attr($name); ?>"
                                                <?php checked($checked); ?>
                                            >
                                            <span class="sync-stp__type-name">
                                                <?php echo esc_html($type->labels->name); ?>
                                            </span>
                                        </label>
                                    <?php
                                    endforeach;
                                endif;
                                ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="sync-stp__row">
                        <h2 class="sync-stp__subtitle">
                            <?php echo esc_html__('Налаштування з\'єднання', 'sync-stage-to-prod'); ?>
                        </h2>
                        <table class="form-table sync-stp__table">
                            <tr>
                                <th scope="row">
                                    <?php echo esc_html__('Режим роботи плагіна', 'sync-stage-to-prod'); ?>
                                </th>
                                <td>
                                    <label>
                                        <input
                                            type="radio"
                                            name="sync_stp_mode"
                                            value="stage"
                                            <?php checked($mode, 'stage'); ?>
                                        >
                                        <?php echo esc_html__('Цей сайт = Stage/Dev (відправляє дані)', 'sync-stage-to-prod'); ?>
                                    </label>
                                    <br>
                                    <label>
                                        <input
                                            type="radio"
                                            name="sync_stp_mode"
                                            value="prod"
                                            <?php checked($mode, 'prod'); ?>
                                        >
                                        <?php echo esc_html__('Цей сайт = Prod (приймає дані)', 'sync-stage-to-prod'); ?>
                                    </label>
                                    <p class="description">
                                        <?php echo esc_html__('На проді виберіть режим Prod, на стейджі/деві — Stage/Dev.', 'sync-stage-to-prod'); ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="sync-stp-endpoint-path">
                                        <?php echo esc_html__('REST ендпоінт плагіна', 'sync-stage-to-prod'); ?>
                                    </label>
                                </th>
                                <td>
                                    <input
                                        type="text"
                                        id="sync-stp-endpoint-path"
                                        name="sync_stp_endpoint_path"
                                        value="<?php echo esc_attr($endpoint_path); ?>"
                                        class="regular-text"
                                    >
                                    <p class="description">
                                        <?php echo esc_html__('Шлях REST-ендпоінта від кореня. За замовчуванням: /wp-json/sync-stp/v1', 'sync-stage-to-prod'); ?>
                                    </p>
                                </td>
                            </tr>

                            <?php if ($mode === 'stage') : ?>
                                <tr class="sync-stp__row-target-url">
                                    <th scope="row">
                                        <label for="sync-stp-target-url">
                                            <?php echo esc_html__('URL прод-сайта', 'sync-stage-to-prod'); ?>
                                        </label>
                                    </th>
                                    <td>
                                        <input
                                            type="text"
                                            id="sync-stp-target-url"
                                            name="sync_stp_target_url"
                                            value="<?php echo esc_attr($target_url); ?>"
                                            class="regular-text"
                                            placeholder="<?php echo esc_attr__('https://example.com', 'sync-stage-to-prod'); ?>"
                                        >
                                        <p class="description">
                                            <?php echo esc_html__('Вказується тільки на стейджі/деві. Без /wp-json на кінці.', 'sync-stage-to-prod'); ?>
                                        </p>
                                    </td>
                                </tr>
                            <?php else : ?>
                                <tr class="sync-stp__row-source-url">
                                    <th scope="row">
                                        <label for="sync-stp-source-url">
                                            <?php echo esc_html__('URL Stage/Dev-сайта (джерело медіа)', 'sync-stage-to-prod'); ?>
                                        </label>
                                    </th>
                                    <td>
                                        <input
                                            type="text"
                                            id="sync-stp-source-url"
                                            name="sync_stp_source_url"
                                            value="<?php echo esc_attr($source_url); ?>"
                                            class="regular-text"
                                            placeholder="<?php echo esc_attr__('https://stage.example.com', 'sync-stage-to-prod'); ?>"
                                        >
                                        <p class="description">
                                            <?php echo esc_html__('Обовʼязково для Prod: дозволяємо завантаження медіа тільки з цього домену (SSRF захист). Без цього прийом даних має бути заблокований на REST-рівні.', 'sync-stage-to-prod'); ?>
                                        </p>
                                    </td>
                                </tr>
                            <?php endif; ?>

                            <tr>
                                <th scope="row">
                                    <label for="sync-stp-auth-token">
                                        <?php echo esc_html__('Секретний токен', 'sync-stage-to-prod'); ?>
                                    </label>
                                </th>
                                <td>
                                    <div class="sync-stp__token-row">
                                        <input
                                            type="text"
                                            id="sync-stp-auth-token"
                                            name="sync_stp_auth_token"
                                            value="<?php echo esc_attr($auth_token); ?>"
                                            class="regular-text sync-stp__token-input"
                                            minlength="<?php echo esc_attr(self::MIN_TOKEN_LENGTH); ?>"
                                            placeholder="<?php echo esc_attr(sprintf(__('Мінімум %d символів', 'sync-stage-to-prod'), self::MIN_TOKEN_LENGTH)); ?>"
                                        >
                                        <button type="button" class="button sync-stp__token-generate" id="sync-stp-generate-token">
                                            <?php echo esc_html__('Згенерувати', 'sync-stage-to-prod'); ?>
                                        </button>
                                    </div>
                                    <p class="description">
                                        <?php echo esc_html(sprintf(__('Однаковий токен має бути налаштований і на стейджі, і на проді (мінімум %d символів).', 'sync-stage-to-prod'), self::MIN_TOKEN_LENGTH)); ?>
                                    </p>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <div class="sync-stp__row sync-stp__row--actions">
                        <button type="submit" class="button">
                            <?php echo esc_html__('Зберегти налаштування', 'sync-stage-to-prod'); ?>
                        </button>

                        <button type="button" class="button button-primary sync-stp__run" id="sync-stp-start">
                            <?php echo esc_html__('Запустити синхронізацію', 'sync-stage-to-prod'); ?>
                        </button>

                        <div class="sync-stp__loader">
                            <div class="sync-stp__progress">
                                <div class="sync-stp__progress-bar"></div>
                            </div>
                            <span class="sync-stp__progress-label">0%</span>
                        </div>
                    </div>

                    <div id="sync-stp-status" class="sync-stp__status"></div>
                </form>
            </div>
        </div>
        <?php
    }

    /**
     * AJAX handler: generate a new shared secret token for the sync.
     *
     * @return void
     */
    public static function ajax_generate_token()
    {
        if (!check_ajax_referer('sync_stp_nonce', 'nonce', false)) {
            wp_send_json_error([
                'message' => esc_html__('Невірний nonce.', 'sync-stage-to-prod'),
            ]);
        }

        if (!current_user_can(SYNC_STP_CAP)) {
            wp_send_json_error([
                'message' => esc_html__('Недостатньо прав для генерації токена.', 'sync-stage-to-prod'),
            ]);
        }

        $token = wp_generate_password(self::DEFAULT_TOKEN_LENGTH, false, false);

        wp_send_json_success([
            'token' => $token,
        ]);
    }

    /**
     * AJAX handler: run the sync process for the selected post types.
     * Post types are validated against the current list of public post types.
     *
     * @return void
     */
    public static function ajax_run_sync()
    {
        if (!check_ajax_referer('sync_stp_nonce', 'nonce', false)) {
            wp_send_json_error([
                'message' => esc_html__('Невірний nonce.', 'sync-stage-to-prod'),
            ]);
        }

        if (!current_user_can(SYNC_STP_CAP)) {
            wp_send_json_error([
                'message' => esc_html__('Недостатньо прав для синхронізації.', 'sync-stage-to-prod'),
            ]);
        }

        $raw_types = [];
        $available = [];
        $post_types = [];

        if (isset($_POST['post_types']) && is_array($_POST['post_types'])) {
            $raw_types = array_map('sanitize_text_field', $_POST['post_types']);
        }

        $available = self::get_all_public_post_types();

        foreach ($raw_types as $type) {
            $key = sanitize_key($type);
            if ($key !== '' && in_array($key, $available, true)) {
                $post_types[] = $key;
            }
        }

        $post_types = array_values(array_unique($post_types));

        /**
         * Fallback: if nothing passed from the UI, try saved settings.
         * If that also yields nothing, fallback to default post types list.
         */
        if (empty($post_types)) {
            $opts = get_option(SYNC_STP_OPT_KEY, []);
            if (!empty($opts['post_types']) && is_array($opts['post_types'])) {
                $saved = array_values(array_unique(array_map('sanitize_key', $opts['post_types'])));
                foreach ($saved as $t) {
                    if ($t !== '' && in_array($t, $available, true)) {
                        $post_types[] = $t;
                    }
                }
                $post_types = array_values(array_unique($post_types));
            }
        }

        if (empty($post_types)) {
            $post_types = self::get_default_post_types();
        }

        if (empty($post_types)) {
            wp_send_json_error([
                'message' => esc_html__('Не вибрано жодного типу записів.', 'sync-stage-to-prod'),
            ]);
        }

        $result = Sync_Service::run_sync($post_types);

        if (is_wp_error($result)) {
            wp_send_json_error([
                'message' => esc_html($result->get_error_message()),
            ]);
        }

        /**
         * Normalize result counters for backward/forward compatibility:
         * counters may live directly in $result or inside grouped arrays.
         */
        $posts = isset($result['posts']) && is_array($result['posts']) ? $result['posts'] : [];
        $terms = isset($result['terms']) && is_array($result['terms']) ? $result['terms'] : [];
        $options = isset($result['options']) && is_array($result['options']) ? $result['options'] : [];

        $posts_sent = isset($posts['sent']) ? (int) $posts['sent'] : (isset($result['sent']) ? (int) $result['sent'] : 0);
        $posts_created = isset($posts['created']) ? (int) $posts['created'] : (isset($result['created']) ? (int) $result['created'] : 0);
        $posts_updated = isset($posts['updated']) ? (int) $posts['updated'] : (isset($result['updated']) ? (int) $result['updated'] : 0);

        $terms_sent = isset($terms['sent']) ? (int) $terms['sent'] : 0;
        $terms_created = isset($terms['created']) ? (int) $terms['created'] : 0;
        $terms_updated = isset($terms['updated']) ? (int) $terms['updated'] : 0;

        $options_groups = isset($options['groups_updated']) ? (int) $options['groups_updated'] : 0;
        $options_fields = isset($options['fields_updated']) ? (int) $options['fields_updated'] : 0;

        wp_send_json_success([
            'sent' => $posts_sent,
            'created' => $posts_created,
            'updated' => $posts_updated,
            'posts' => [
                'sent' => $posts_sent,
                'created' => $posts_created,
                'updated' => $posts_updated,
            ],
            'terms' => [
                'sent' => $terms_sent,
                'created' => $terms_created,
                'updated' => $terms_updated,
            ],
            'options' => [
                'groups_updated' => $options_groups,
                'fields_updated' => $options_fields,
            ],
        ]);
    }
}
