<?php
if (!defined('ABSPATH')) {
    exit;
}

class Sync_CLI
{
    /**
     * Get a baseline list of post types to sync when nothing is explicitly selected:
     * public custom post types (non-builtin), excluding attachments.
     *
     * @return array
     */
    private static function get_default_post_types()
    {
        $types = get_post_types(['public' => true, '_builtin' => false], 'names');
        if (!is_array($types)) {
            $types = [];
        }

        $types = array_values(array_unique(array_map('sanitize_key', $types)));
        $types = array_values(array_filter($types, static function ($t) {
            return $t !== '' && $t !== 'attachment';
        }));

        return $types;
    }

    /**
     * Get all public post type slugs (builtin + custom), excluding attachments.
     * Used to validate CLI-selected post types against what is currently registered.
     *
     * @return array
     */
    private static function get_all_public_post_types()
    {
        $types = get_post_types(['public' => true], 'names');
        if (!is_array($types)) {
            $types = [];
        }

        $types = array_values(array_unique(array_map('sanitize_key', $types)));
        $types = array_values(array_filter($types, static function ($t) {
            return $t !== '' && $t !== 'attachment';
        }));

        return $types;
    }

    /**
     * WP-CLI command: run synchronization.
     *
     * Supported arguments:
     * - --types=post,service,page (comma-separated)
     *
     * If --types is omitted, post types are read from plugin settings.
     * If still empty, falls back to default public custom post types.
     *
     * @param array $args Positional arguments (unused).
     * @param array $assoc_args Associative arguments (e.g., --types).
     * @return void
     */
    public function sync($args, $assoc_args)
    {
        $types = [];
        $options = [];
        $result = [];

        WP_CLI::log('Starting synchronization process...');

        if (!empty($assoc_args['types']) && is_string($assoc_args['types'])) {
            WP_CLI::log('Parsing post types from command line...');

            $parts = explode(',', $assoc_args['types']);
            foreach ($parts as $part) {
                $key = sanitize_key(trim($part));

                if ($key !== '' && Sync_Validator::is_valid_post_type($key)) {
                    $types[] = $key;
                    WP_CLI::log("  ✓ Added post type: {$key}");
                } elseif ($key !== '') {
                    WP_CLI::warning("  ✗ Invalid or non-public post type: {$key}");
                }
            }
        }

        if (empty($types)) {
            WP_CLI::log('No types specified, loading from settings...');

            $options = get_option(SYNC_STP_OPT_KEY, []);

            if (!empty($options['post_types']) && is_array($options['post_types'])) {
                foreach ($options['post_types'] as $type) {
                    $key = sanitize_key($type);

                    if ($key !== '' && Sync_Validator::is_valid_post_type($key)) {
                        $types[] = $key;
                        WP_CLI::log("  ✓ Loaded post type: {$key}");
                    } elseif ($key !== '') {
                        WP_CLI::warning("  ✗ Configured type is invalid: {$key}");
                    }
                }
            }
        }

        /**
         * Final normalization: ensure uniqueness, ensure the post type is public,
         * and drop any invalid values before attempting to sync.
         */
        $types = array_values(array_unique(array_map('sanitize_key', $types)));
        $available = self::get_all_public_post_types();
        $types = array_values(array_filter($types, static function ($t) use ($available) {
            return $t !== '' && in_array($t, $available, true);
        }));

        if (empty($types)) {
            $types = self::get_default_post_types();
        }

        if (empty($types)) {
            WP_CLI::error('No valid post types selected for sync. Please specify types with --types or configure them in plugin settings.');
            return;
        }

        WP_CLI::log('Post types to sync: ' . implode(', ', $types));
        WP_CLI::log('');

        WP_CLI::log('Running synchronization...');
        $result = Sync_Service::run_sync($types);

        if (is_wp_error($result)) {
            WP_CLI::error($result->get_error_message());
            return;
        }

        /**
         * Normalize result structures: the sync service may return grouped stats
         * (posts/terms/options) and/or top-level counters for compatibility.
         */
        $posts = isset($result['posts']) && is_array($result['posts']) ? $result['posts'] : [];
        $terms = isset($result['terms']) && is_array($result['terms']) ? $result['terms'] : [];
        $options_stats = isset($result['options']) && is_array($result['options']) ? $result['options'] : [];

        $posts_sent = isset($posts['sent']) ? (int) $posts['sent'] : (isset($result['sent']) ? (int) $result['sent'] : 0);
        $posts_created = isset($posts['created']) ? (int) $posts['created'] : (isset($result['created']) ? (int) $result['created'] : 0);
        $posts_updated = isset($posts['updated']) ? (int) $posts['updated'] : (isset($result['updated']) ? (int) $result['updated'] : 0);

        $terms_sent = isset($terms['sent']) ? (int) $terms['sent'] : 0;
        $terms_created = isset($terms['created']) ? (int) $terms['created'] : 0;
        $terms_updated = isset($terms['updated']) ? (int) $terms['updated'] : 0;

        $opt_groups = isset($options_stats['groups_updated']) ? (int) $options_stats['groups_updated'] : 0;
        $opt_fields = isset($options_stats['fields_updated']) ? (int) $options_stats['fields_updated'] : 0;

        WP_CLI::log('');
        WP_CLI::log(str_repeat('=', 50));
        WP_CLI::log('SYNCHRONIZATION RESULTS');
        WP_CLI::log(str_repeat('=', 50));
        WP_CLI::log('');
        WP_CLI::log('Posts:');
        WP_CLI::log('  Sent:    ' . $posts_sent);
        WP_CLI::log('  Created: ' . $posts_created);
        WP_CLI::log('  Updated: ' . $posts_updated);
        WP_CLI::log('');
        WP_CLI::log('Terms:');
        WP_CLI::log('  Sent:    ' . $terms_sent);
        WP_CLI::log('  Created: ' . $terms_created);
        WP_CLI::log('  Updated: ' . $terms_updated);
        WP_CLI::log('');
        WP_CLI::log('ACF Options:');
        WP_CLI::log('  Groups updated: ' . $opt_groups);
        WP_CLI::log('  Fields updated: ' . $opt_fields);
        WP_CLI::log('');
        WP_CLI::log(str_repeat('=', 50));

        WP_CLI::success('Sync completed successfully!');
    }

    /**
     * WP-CLI command: validate plugin configuration.
     * Checks mode, endpoint path normalization, target URL (stage mode),
     * auth token requirements, and configured post type validity.
     *
     * @param array $args Positional arguments (unused).
     * @param array $assoc_args Associative arguments (unused).
     * @return void
     */
    public function validate($args, $assoc_args)
    {
        $options = get_option(SYNC_STP_OPT_KEY, []);
        $errors = [];
        $warnings = [];

        WP_CLI::log('Validating plugin configuration...');
        WP_CLI::log('');

        $mode = isset($options['mode']) && $options['mode'] === 'prod' ? 'prod' : 'stage';
        WP_CLI::log("Mode: {$mode}");

        if ($mode === 'prod') {
            WP_CLI::warning('Plugin is in PRODUCTION mode. Sync can only run from STAGE mode.');
            $warnings[] = 'Prod mode enabled (sync should run only from stage)';
        }

        $endpoint_path = isset($options['endpoint_path']) && is_string($options['endpoint_path']) ? $options['endpoint_path'] : '/wp-json/sync-stp/v1';
        $validated_path = Sync_Validator::validate_endpoint_path($endpoint_path);

        if ($endpoint_path === $validated_path) {
            WP_CLI::log("✓ Endpoint path is valid: {$endpoint_path}");
        } else {
            WP_CLI::warning("✗ Endpoint path was normalized: {$endpoint_path} → {$validated_path}");
            $warnings[] = 'Endpoint path was normalized';
        }

        if ($mode === 'stage') {
            $target_url = isset($options['target_url']) && is_string($options['target_url']) ? trim($options['target_url']) : '';

            if ($target_url === '') {
                WP_CLI::log('✗ Target URL is not configured');
                $errors[] = 'Missing target URL';
            } else {
                $validated_url = Sync_Validator::validate_url($target_url);

                if ($validated_url === false) {
                    WP_CLI::log("✗ Target URL is invalid: {$target_url}");
                    $errors[] = 'Invalid target URL';
                } else {
                    WP_CLI::log("✓ Target URL is valid: {$validated_url}");
                }
            }
        }

        $auth_token = isset($options['auth_token']) && is_string($options['auth_token']) ? trim($options['auth_token']) : '';

        if ($auth_token === '') {
            WP_CLI::log('✗ Authentication token is not configured');
            $errors[] = 'Missing auth token';
        } else {
            if (Sync_Validator::is_valid_auth_token($auth_token)) {
                WP_CLI::log('✓ Authentication token is valid (length: ' . strlen($auth_token) . ' chars)');
            } else {
                WP_CLI::log('✗ Authentication token is invalid or too short (minimum 32 characters)');
                $errors[] = 'Invalid auth token';
            }
        }

        if ($mode === 'stage') {
            $post_types = isset($options['post_types']) && is_array($options['post_types']) ? $options['post_types'] : [];
            $post_types = array_values(array_unique(array_map('sanitize_key', $post_types)));

            if (empty($post_types)) {
                WP_CLI::warning('✗ No post types configured for sync (will fallback to default public custom post types)');
                $warnings[] = 'No post types configured';
            } else {
                WP_CLI::log('Configured post types:');
                foreach ($post_types as $type) {
                    $key = sanitize_key($type);
                    if (Sync_Validator::is_valid_post_type($key)) {
                        WP_CLI::log("  ✓ {$key}");
                    } else {
                        WP_CLI::warning("  ✗ {$key} (invalid or non-public)");
                        $warnings[] = "Invalid post type: {$key}";
                    }
                }
            }
        }

        WP_CLI::log('');
        WP_CLI::log(str_repeat('=', 50));

        if (empty($errors) && empty($warnings)) {
            WP_CLI::success('Configuration is valid!');
            return;
        }

        if (!empty($warnings)) {
            WP_CLI::warning('Configuration warnings: ' . count($warnings));
        }

        if (!empty($errors)) {
            WP_CLI::error('Configuration errors: ' . count($errors));
        }

        WP_CLI::success('Configuration looks OK (with warnings).');
    }
}

/**
 * Register WP-CLI commands only when WP-CLI is available.
 */
if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('sync-stage-to-prod', 'Sync_CLI');
}
