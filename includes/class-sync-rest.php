<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * REST API handler for Stage to Production synchronization.
 *
 * Manages REST endpoints for syncing taxonomies, posts, options, media,
 * and ACF relationships between staging and production environments.
 */
class Sync_Rest
{
    private static $rid = '';

    /**
     * Initialize REST API routes.
     *
     * Registers all sync endpoints on WordPress rest_api_init hook.
     *
     * @return void
     */
    public static function init()
    {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
    }

    /**
     * Register all REST API routes for sync operations.
     *
     * Endpoints include:
     * - /taxonomies - Import taxonomy terms
     * - /posts - Import posts with ACF fields
     * - /options - Import ACF options
     * - /media - Import media attachments
     * - /media/delete - Delete removed media
     * - /validate-relationships - Validate ACF relationship references
     * - /update-relationships - Update ACF relationships after post creation
     * - /post-map - Map stage post IDs to production IDs
     *
     * @return void
     */
    public static function register_routes()
    {
        register_rest_route(
            'sync-stp/v1',
            '/taxonomies',
            [
                'methods' => 'POST',
                'callback' => [__CLASS__, 'handle_taxonomies'],
                'permission_callback' => [__CLASS__, 'check_sync_permission'],
            ]
        );

        register_rest_route(
            'sync-stp/v1',
            '/posts',
            [
                'methods' => 'POST',
                'callback' => [__CLASS__, 'handle_posts'],
                'permission_callback' => [__CLASS__, 'check_sync_permission'],
            ]
        );

        register_rest_route(
            'sync-stp/v1',
            '/options',
            [
                'methods' => 'POST',
                'callback' => [__CLASS__, 'handle_options'],
                'permission_callback' => [__CLASS__, 'check_sync_permission'],
            ]
        );

        register_rest_route(
            'sync-stp/v1',
            '/media',
            [
                'methods' => 'POST',
                'callback' => [__CLASS__, 'handle_media'],
                'permission_callback' => [__CLASS__, 'check_sync_permission'],
            ]
        );

        register_rest_route(
            'sync-stp/v1',
            '/media/delete',
            [
                'methods' => 'POST',
                'callback' => [__CLASS__, 'handle_media_delete'],
                'permission_callback' => [__CLASS__, 'check_sync_permission'],
            ]
        );

        register_rest_route(
            'sync-stp/v1',
            '/validate-relationships',
            [
                'methods' => 'POST',
                'callback' => [__CLASS__, 'handle_validate_relationships'],
                'permission_callback' => [__CLASS__, 'check_sync_permission'],
            ]
        );

        register_rest_route(
            'sync-stp/v1',
            '/update-relationships',
            [
                'methods' => 'POST',
                'callback' => [__CLASS__, 'handle_update_relationships'],
                'permission_callback' => [__CLASS__, 'check_sync_permission'],
            ]
        );

        register_rest_route(
            'sync-stp/v1',
            '/post-map',
            [
                'methods' => 'POST',
                'callback' => [__CLASS__, 'handle_post_map'],
                'permission_callback' => [__CLASS__, 'check_sync_permission'],
            ]
        );
    }

    /**
     * Check permission for sync REST API requests.
     *
     * Validates:
     * - Production mode is enabled
     * - Authentication token matches
     * - Source URL is configured for media downloads
     *
     * @param WP_REST_Request $request Incoming REST request.
     * @return bool|WP_Error True if permission granted, WP_Error otherwise.
     */
    public static function check_sync_permission(WP_REST_Request $request)
    {
        $options = get_option(SYNC_STP_OPT_KEY, []);

        $route = '';
        if (is_object($request) && method_exists($request, 'get_route')) {
            $route = (string) $request->get_route();
        }

        $method = '';
        if (is_object($request) && method_exists($request, 'get_method')) {
            $method = (string) $request->get_method();
        }

        // Ensure production mode is enabled
        $mode = 'stage';
        if (isset($options['mode']) && $options['mode'] === 'prod') {
            $mode = 'prod';
        }

        if ($mode !== 'prod') {
            return new WP_Error(
                'sync_not_prod',
                __('Цей ендпоінт приймає дані лише в режимі Prod.', 'sync-stage-to-prod'),
                ['status' => 403]
            );
        }

        // Validate stored authentication token exists
        $stored_token = '';
        if (isset($options['auth_token']) && is_string($options['auth_token'])) {
            $stored_token = trim($options['auth_token']);
        }

        if ($stored_token === '') {
            return new WP_Error(
                'sync_no_token',
                __('Токен автентифікації не налаштований.', 'sync-stage-to-prod'),
                ['status' => 401]
            );
        }

        // Extract incoming token from request
        $incoming_token = '';
        $json_params = $request->get_json_params();

        if (is_array($json_params) && isset($json_params['auth_token'])) {
            $incoming_token = $json_params['auth_token'];
        }

        if ($incoming_token === '') {
            $incoming_token = $request->get_param('auth_token');
        }

        if (!is_string($incoming_token) || trim($incoming_token) === '') {
            return new WP_Error(
                'sync_missing_token',
                __('Відсутній токен доступу в запиті.', 'sync-stage-to-prod'),
                ['status' => 401]
            );
        }

        // Normalize tokens for comparison (remove control characters)
        $stored_token = trim((string) $stored_token);
        $incoming_token = trim((string) $incoming_token);

        if (function_exists('mb_convert_encoding')) {
            $stored_token = mb_convert_encoding($stored_token, 'UTF-8', 'UTF-8');
            $incoming_token = mb_convert_encoding($incoming_token, 'UTF-8', 'UTF-8');
        }

        $stored_token = preg_replace('/[\x00-\x1F\x80-\xFF]/', '', (string) $stored_token);
        $incoming_token = preg_replace('/[\x00-\x1F\x80-\xFF]/', '', (string) $incoming_token);

        $stored_hash = substr(hash('sha256', (string) $stored_token), 0, 12);
        $incoming_hash = substr(hash('sha256', (string) $incoming_token), 0, 12);

        // Verify token match using timing-safe comparison
        if (!hash_equals((string) $stored_token, (string) $incoming_token)) {
            return new WP_Error(
                'sync_bad_token',
                __('Невірний токен доступу.', 'sync-stage-to-prod'),
                ['status' => 401]
            );
        }

        // Validate media source URL configuration
        $allowed = self::get_allowed_media_source_context();
        if (is_wp_error($allowed)) {
            return $allowed;
        }

        return true;
    }

    /**
     * Handle taxonomy terms import endpoint.
     *
     * Imports terms with metadata and ACF fields, replacing media IDs.
     *
     * @param WP_REST_Request $request Request with terms payload.
     * @return array|WP_Error Import results with counts.
     */
    public static function handle_taxonomies(WP_REST_Request $request)
    {
        $data = $request->get_json_params();

        if (!is_array($data)) {
            return new WP_Error(
                'sync_bad_payload',
                __('Невірний формат даних.', 'sync-stage-to-prod'),
                ['status' => 400]
            );
        }

        // Import inline media if present
        $media_result = self::maybe_import_media_from_payload($data);
        if (is_wp_error($media_result)) {
            return $media_result;
        }

        $media_map = isset($media_result['map']) && is_array($media_result['map']) ? $media_result['map'] : self::get_media_map_option();
        $url_map = self::build_media_url_map($media_map);

        $terms = [];
        if (isset($data['terms']) && is_array($data['terms'])) {
            $terms = $data['terms'];
        }

        $counts = self::import_terms($terms, $media_map, $url_map);

        return [
            'sent' => count($terms),
            'created' => isset($counts['created']) ? (int) $counts['created'] : 0,
            'updated' => isset($counts['updated']) ? (int) $counts['updated'] : 0,
        ];
    }

    /**
     * Handle posts import endpoint.
     *
     * Imports posts with content, ACF fields, taxonomies, and featured images.
     * For single post sync (strict mode), validates and remaps ACF relationship fields.
     * For batch sync, defers relationship remapping to second stage.
     *
     * @param WP_REST_Request $request Request with posts payload.
     * @return array|WP_Error Import results with counts.
     */
    public static function handle_posts(WP_REST_Request $request)
    {
        $data = $request->get_json_params();

        if (!is_array($data)) {
            return new WP_Error(
                'sync_bad_payload',
                __('Невірний формат даних.', 'sync-stage-to-prod'),
                ['status' => 400]
            );
        }

        $is_batch = !empty($data['is_batch']);

        // Import inline media if present
        $media_result = self::maybe_import_media_from_payload($data);
        if (is_wp_error($media_result)) {
            return $media_result;
        }

        $media_map = isset($media_result['map']) && is_array($media_result['map']) ? $media_result['map'] : self::get_media_map_option();
        $url_map = self::build_media_url_map($media_map);

        $posts = [];
        if (isset($data['posts']) && is_array($data['posts'])) {
            $posts = $data['posts'];
        }

        // For single post sync (strict mode), remap block relationships before import
        if (!$is_batch) {
            foreach ($posts as &$post_data) {
                $featured_image_id = isset($post_data['featured_image']) ? $post_data['featured_image'] : '';
                $featured_image_filename = '';
                if ($featured_image_id) {
                    $attachment = get_post($featured_image_id);
                    if ($attachment && $attachment->post_type === 'attachment') {
                        $featured_image_filename = basename(get_attached_file($featured_image_id));
                    }
                }

                if (empty($post_data['content']) || !is_string($post_data['content'])) {
                    continue;
                }

                if (!function_exists('parse_blocks') || !function_exists('serialize_blocks')) {
                    continue;
                }

                $blocks = parse_blocks($post_data['content']);
                $res = self::remap_acf_post_refs_in_blocks($blocks, true);
                if (is_wp_error($res)) {
                    return $res;
                }

                if (is_array($res) && !empty($res['modified']) && isset($res['blocks'])) {
                    $post_data['content'] = serialize_blocks($res['blocks']);
                }
            }
            unset($post_data);
        }

        $counts = self::import_posts($posts, $media_map, $url_map);

        // For batch sync, update block relationships after all posts are created
        if ($is_batch && function_exists('parse_blocks') && function_exists('serialize_blocks')) {
            $relationship_updates = 0;

            foreach ($posts as $post_data) {
                $slug = isset($post_data['slug']) ? (string) $post_data['slug'] : '';
                $post_type = isset($post_data['post_type']) ? (string) $post_data['post_type'] : '';

                $slug = sanitize_title($slug);
                $post_type = sanitize_key($post_type);

                if ($slug === '' || $post_type === '') {
                    continue;
                }

                // Find production post by slug
                $prod_posts = get_posts([
                    'name' => $slug,
                    'post_type' => $post_type,
                    'post_status' => 'any',
                    'posts_per_page' => 1,
                ]);

                if (empty($prod_posts) || !isset($prod_posts[0]->ID)) {
                    continue;
                }

                $prod_id = (int) $prod_posts[0]->ID;
                if ($prod_id <= 0) {
                    continue;
                }

                $prod_post = get_post($prod_id);
                if (!$prod_post || !isset($prod_post->post_content)) {
                    continue;
                }

                $current_content = (string) $prod_post->post_content;
                if ($current_content === '') {
                    continue;
                }

                // Remap relationships in blocks (non-strict mode)
                $blocks = parse_blocks($current_content);
                $res = self::remap_acf_post_refs_in_blocks($blocks, false);

                if (is_wp_error($res)) {
                    continue;
                }

                if (!is_array($res) || empty($res['modified']) || !isset($res['blocks'])) {
                    continue;
                }

                $new_content = serialize_blocks($res['blocks']);

                if ($new_content !== $current_content) {
                    wp_update_post([
                        'ID' => $prod_id,
                        'post_content' => $new_content,
                    ]);

                    $relationship_updates++;
                }
            }
        }

        return [
            'sent' => count($posts),
            'created' => isset($counts['created']) ? (int) $counts['created'] : 0,
            'updated' => isset($counts['updated']) ? (int) $counts['updated'] : 0,
        ];
    }

    /**
     * Recursively remap ACF post references in Gutenberg blocks.
     *
     * Finds ACF block data and remaps relationship field IDs from stage to production.
     *
     * @param array $blocks Parsed blocks array.
     * @param bool  $strict If true, return error when referenced post not found.
     * @return array|WP_Error Array with 'blocks' and 'modified' flag, or error.
     */
    private static function remap_acf_post_refs_in_blocks($blocks, $strict = false)
    {
        if (!is_array($blocks)) {
            return [
                'blocks' => $blocks,
                'modified' => false,
            ];
        }

        $modified = false;

        foreach ($blocks as &$block) {
            // Remap block data (ACF fields)
            if (isset($block['attrs']) && is_array($block['attrs']) && isset($block['attrs']['data']) && is_array($block['attrs']['data'])) {
                $before = $block['attrs']['data'];
                $remapped = self::remap_acf_post_refs_in_block_data($block['attrs']['data'], $strict, 0);

                if (is_wp_error($remapped)) {
                    return $remapped;
                }

                if ($before !== $remapped) {
                    $modified = true;
                }

                $block['attrs']['data'] = $remapped;
            }

            // Recurse into inner blocks
            if (isset($block['innerBlocks']) && is_array($block['innerBlocks']) && !empty($block['innerBlocks'])) {
                $inner_res = self::remap_acf_post_refs_in_blocks($block['innerBlocks'], $strict);

                if (is_wp_error($inner_res)) {
                    return $inner_res;
                }

                if (is_array($inner_res) && isset($inner_res['blocks'])) {
                    if (!empty($inner_res['modified'])) {
                        $modified = true;
                    }
                    $block['innerBlocks'] = $inner_res['blocks'];
                }
            }
        }

        return [
            'blocks' => $blocks,
            'modified' => $modified,
        ];
    }

    /**
     * Recursively remap ACF post reference IDs in block data.
     *
     * Detects ACF relationship/post_object/page_link fields by their field key
     * and remaps stage IDs to production IDs.
     *
     * @param array $data  Block data to process.
     * @param bool  $strict If true, return error when referenced post not found.
     * @param int   $depth Current recursion depth.
     * @return array|WP_Error Remapped data or error.
     */
    private static function remap_acf_post_refs_in_block_data($data, $strict = false, $depth = 0)
{
    if ($depth > 12 || !is_array($data)) {
        return $data;
    }

    foreach ($data as $key => &$value) {
        if (!is_string($key) || $key === '') {
            if (is_array($value)) {
                $nested = self::remap_acf_post_refs_in_block_data($value, $strict, $depth + 1);
                if (is_wp_error($nested)) {
                    return $nested;
                }
                $value = $nested;
            }
            continue;
        }

        $field_def = null;
        $underscore = '_' . $key;

        if (isset($data[$underscore]) && is_string($data[$underscore]) && preg_match('/^field_[a-z0-9]+$/', $data[$underscore]) === 1) {
            $field_def = self::get_acf_field_def_by_key((string) $data[$underscore]);
        }

        $type = '';
        $is_multiple = false;
        $is_page_link_url = false;

        if (is_array($field_def) && !empty($field_def['type'])) {
            $type = (string) $field_def['type'];

            if (isset($field_def['multiple'])) {
                $is_multiple = (bool) $field_def['multiple'];
            }

            if ($type === 'page_link' && isset($field_def['return_format']) && $field_def['return_format'] === 'url') {
                $is_page_link_url = true;
            }
        }

        $is_relationship = ($type === 'relationship');
        $is_single_ref = in_array($type, ['post_object', 'page_link'], true) && !$is_page_link_url && !$is_multiple;
        $is_multi_ref = in_array($type, ['post_object', 'page_link'], true) && !$is_page_link_url && $is_multiple;

        $allowed_post_types = [];
        if (is_array($field_def) && isset($field_def['post_type']) && is_array($field_def['post_type'])) {
            $allowed_post_types = array_values(array_filter($field_def['post_type'], 'is_string'));
        }

        $filter_stage_id = static function ($sid) use ($allowed_post_types) {
            $sid = (int) $sid;
            if ($sid <= 0) {
                return 0;
            }

            $pt = get_post_type($sid);
            if (empty($pt)) {
                return 0;
            }

            if (in_array($pt, ['acf-field', 'acf-field-group'], true)) {
                return 0;
            }

            if (!empty($allowed_post_types) && !in_array($pt, $allowed_post_types, true)) {
                return 0;
            }

            return $sid;
        };

        if ($is_relationship || $is_multi_ref) {
            if (is_array($value)) {
                $stage_ids = [];

                if (self::is_numeric_array($value)) {
                    foreach ($value as $raw_id) {
                        $sid = 0;

                        if (is_int($raw_id)) {
                            $sid = (int) $raw_id;
                        } elseif (is_string($raw_id) && ctype_digit($raw_id)) {
                            $sid = (int) $raw_id;
                        }

                        $sid = $filter_stage_id($sid);
                        if ($sid > 0) {
                            $stage_ids[] = $sid;
                        }
                    }
                } else {
                    foreach ($value as $item) {
                        if (is_array($item) && isset($item['ID'])) {
                            $raw = $item['ID'];
                            $sid = 0;

                            if (is_int($raw)) {
                                $sid = (int) $raw;
                            } elseif (is_string($raw) && ctype_digit($raw)) {
                                $sid = (int) $raw;
                            }

                            $sid = $filter_stage_id($sid);
                            if ($sid > 0) {
                                $stage_ids[] = $sid;
                            }
                        }
                    }
                }

                $stage_ids = array_values(array_unique(array_map('intval', $stage_ids)));

                if (!empty($stage_ids)) {
                    $mapped_ids = [];
                    $missing = [];

                    foreach ($stage_ids as $sid) {
                        $pid = self::find_production_id_by_stage_id($sid, null);

                        if ($pid) {
                            $mapped_ids[] = (string) ((int) $pid);
                        } else {
                            if ($strict && $is_relationship) {
                                $missing[] = $sid;
                            } else {
                                $mapped_ids[] = (string) $sid;
                            }
                        }
                    }

                    if (!empty($missing) && $strict && $is_relationship) {
                        return new WP_Error(
                            'sync_relationship_missing',
                            sprintf(
                                __('❌ Синхронізацію зупинено: На Production відсутні залежні пости для Stage ID: %s (поле: %s).', 'sync-stage-to-prod'),
                                implode(', ', array_map('intval', $missing)),
                                $key
                            ),
                            [
                                'field' => $key,
                                'missing_stage_ids' => $missing,
                            ]
                        );
                    }

                    $mapped_ids = array_values(array_unique($mapped_ids));
                    $value = $mapped_ids;
                    continue;
                }
            }
        } elseif ($is_single_ref) {
            $sid = 0;

            if (is_int($value)) {
                $sid = (int) $value;
            } elseif (is_string($value) && ctype_digit($value)) {
                $sid = (int) $value;
            } elseif (is_array($value) && isset($value['ID'])) {
                $raw = $value['ID'];
                if (is_int($raw)) {
                    $sid = (int) $raw;
                } elseif (is_string($raw) && ctype_digit($raw)) {
                    $sid = (int) $raw;
                }
            }

            $sid = $filter_stage_id($sid);

            if ($sid > 0) {
                $pid = self::find_production_id_by_stage_id($sid, null);

                if ($pid) {
                    $value = (string) ((int) $pid);
                    continue;
                }

                continue;
            }
        }

        if (is_array($value)) {
            $nested = self::remap_acf_post_refs_in_block_data($value, $strict, $depth + 1);
            if (is_wp_error($nested)) {
                return $nested;
            }
            $value = $nested;
        }
    }

    return $data;
}


    /**
     * Get ACF field definition by field key.
     *
     * @param string $field_key ACF field key (e.g., 'field_abc123').
     * @return array|null Field definition array or null if not found.
     */
    private static function get_acf_field_def_by_key($field_key)
    {
        if (!is_string($field_key) || $field_key === '') {
            return null;
        }

        if (function_exists('acf_get_field')) {
            $field = acf_get_field($field_key);
            if (is_array($field) && !empty($field)) {
                return $field;
            }
        }

        return null;
    }

    /**
     * Handle ACF options import endpoint.
     *
     * Imports ACF options pages and archive settings with media remapping.
     *
     * @param WP_REST_Request $request Request with options payload.
     * @return array|WP_Error Import results with counts.
     */
    public static function handle_options(WP_REST_Request $request)
    {
        $data = $request->get_json_params();

        if (!is_array($data)) {
            return new WP_Error(
                'sync_bad_payload',
                __('Невірний формат даних.', 'sync-stage-to-prod'),
                ['status' => 400]
            );
        }

        // Import inline media if present
        $media_result = self::maybe_import_media_from_payload($data);
        if (is_wp_error($media_result)) {
            return $media_result;
        }

        $media_map = isset($media_result['map']) && is_array($media_result['map']) ? $media_result['map'] : self::get_media_map_option();
        $url_map = self::build_media_url_map($media_map);

        $options_payload = [];
        if (isset($data['options'])) {
            if (is_object($data['options'])) {
                $options_payload = (array) $data['options'];
            } elseif (is_array($data['options'])) {
                $options_payload = $data['options'];
            }
        }

        $counts = self::import_options($options_payload, $media_map, $url_map);

        return [
            'success' => true,
            'groups_updated' => isset($counts['groups_updated']) ? (int) $counts['groups_updated'] : 0,
            'fields_updated' => isset($counts['fields_updated']) ? (int) $counts['fields_updated'] : 0,
        ];
    }

    /**
     * Handle media import endpoint.
     *
     * Downloads and imports media attachments from stage to production.
     *
     * @param WP_REST_Request $request Request with media payload.
     * @return array|WP_Error Import results with ID mapping.
     */
    public static function handle_media(WP_REST_Request $request)
    {
        $data = $request->get_json_params();
        if (!is_array($data)) {
            return new WP_Error(
                'sync_bad_payload',
                __('Невірний формат даних.', 'sync-stage-to-prod'),
                ['status' => 400]
            );
        }

        $media = [];
        if (isset($data['media']) && is_array($data['media'])) {
            $media = $data['media'];
        }

        $result = self::import_media($media);

        if (is_wp_error($result)) {
            return $result;
        }

        return [
            'sent' => count($media),
            'created' => isset($result['created']) ? (int) $result['created'] : 0,
            'updated' => isset($result['updated']) ? (int) $result['updated'] : 0,
            'map' => isset($result['map']) && is_array($result['map']) ? $result['map'] : [],
        ];
    }

    /**
     * Handle media deletion endpoint.
     *
     * Deletes media attachments on production that were removed from stage.
     *
     * @param WP_REST_Request $request Request with deleted stage IDs.
     * @return array|WP_Error Deletion results with count.
     */
    public static function handle_media_delete(WP_REST_Request $request)
    {
        $data = $request->get_json_params();

        if (!is_array($data)) {
            return new WP_Error(
                'sync_bad_payload',
                __('Невірний формат даних.', 'sync-stage-to-prod'),
                ['status' => 400]
            );
        }

        $deleted_stage_ids = [];
        if (isset($data['deleted_stage_ids']) && is_array($data['deleted_stage_ids'])) {
            $deleted_stage_ids = $data['deleted_stage_ids'];
        }

        $deleted = self::delete_media_by_stage_ids($deleted_stage_ids);

        if (is_wp_error($deleted)) {
            return $deleted;
        }

        return [
            'deleted' => (int) $deleted,
        ];
    }

    /**
     * Handle ACF relationship validation endpoint.
     *
     * Checks if all referenced posts exist on production before syncing.
     * Used by stage to prevent sync failures.
     *
     * @param WP_REST_Request $request Request with relationships to validate.
     * @return WP_REST_Response Validation results with missing posts.
     */
    public static function handle_validate_relationships(WP_REST_Request $request)
    {
        $rid = self::get_rid();

        $params = $request->get_json_params();

        if (empty($params['relationships']) || !is_array($params['relationships'])) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'No relationships provided for validation',
            ], 400);
        }

        $relationships = $params['relationships'];
        $missing = [];

        foreach ($relationships as $rel) {
            if (empty($rel['post_type']) || empty($rel['slug'])) {
                continue;
            }

            $post_type = sanitize_key($rel['post_type']);
            $slug = sanitize_title($rel['slug']);

            if (!post_type_exists($post_type)) {
                continue;
            }

            $existing = get_posts([
                'post_type' => $post_type,
                'name' => $slug,
                'posts_per_page' => 1,
                'post_status' => 'any',
                'fields' => 'ids',
            ]);

            if (empty($existing)) {
                if (!isset($missing[$post_type])) {
                    $missing[$post_type] = [];
                }
                $missing[$post_type][] = $slug;
            }
        }

        return new WP_REST_Response([
            'success' => true,
            'missing' => $missing,
        ], 200);
    }

    /**
     * Handle ACF relationship updates endpoint.
     *
     * Updates ACF relationship fields after all posts are created on production.
     * Used in two-stage sync for cross-references between syncing post types.
     *
     * @param WP_REST_Request $request Request with relationship updates.
     * @return WP_REST_Response Update results with counts.
     */
    public static function handle_update_relationships($request)
    {
        $rid = self::get_rid();

        $body = $request->get_body();
        $data = json_decode($body, true);

        if (!is_array($data) || empty($data['updates'])) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Invalid request: missing updates array',
            ], 400);
        }

        $updates = $data['updates'];
        $updated_count = 0;
        $failed_count = 0;

        foreach ($updates as $update_index => $update_data) {
            if (empty($update_data['post_name']) || empty($update_data['post_type']) || empty($update_data['relationships'])) {
                $failed_count++;
                continue;
            }

            // Find post by slug and type
            $posts = get_posts([
                'name' => $update_data['post_name'],
                'post_type' => $update_data['post_type'],
                'post_status' => 'any',
                'posts_per_page' => 1,
            ]);

            if (empty($posts)) {
                $failed_count++;
                continue;
            }

            $post_id = (int) $posts[0]->ID;
            $relationships = $update_data['relationships'];

            // Restore relationships to ACF fields
            if (self::restore_relationships_to_post($post_id, $relationships, $update_data['post_type'])) {
                $updated_count++;
            } else {
                $failed_count++;
            }
        }

        return new WP_REST_Response([
            'success' => true,
            'updated' => $updated_count,
            'failed' => $failed_count,
        ], 200);
    }

    /**
     * Restore ACF relationship fields to a post.
     *
     * Maps stage post slugs to production IDs and updates ACF fields.
     * Handles both regular ACF fields and Gutenberg block fields.
     *
     * @param int    $post_id          Production post ID.
     * @param array  $relationships    Relationship data by field path.
     * @param string $syncing_post_type Post type being synced.
     * @return bool True if any fields were updated.
     */
    private static function restore_relationships_to_post($post_id, $relationships, $syncing_post_type)
    {
        $updated = false;

        foreach ($relationships as $field_path => $stage_posts) {
            $items = $stage_posts;
            $is_single = false;

            if (is_array($stage_posts) && isset($stage_posts['items'])) {
                $items = $stage_posts['items'];
                $is_single = !empty($stage_posts['is_single']);
            }

            if (!is_array($items)) {
                continue;
            }

            // Map stage posts to production IDs
            $prod_ids = [];

            foreach ($items as $stage_post_data) {
                if (!is_array($stage_post_data) || empty($stage_post_data['slug'])) {
                    continue;
                }

                $slug = $stage_post_data['slug'];
                $post_type = $stage_post_data['post_type'] ?? $syncing_post_type;

                $mapped_id = self::find_post_by_slug($slug, $post_type);

                if ($mapped_id) {
                    $prod_ids[] = $mapped_id;
                }
            }

            $prod_ids = array_values(array_unique(array_map('intval', $prod_ids)));

            if (empty($prod_ids)) {
                continue;
            }

            $new_value = $is_single ? $prod_ids[0] : $prod_ids;

            // Update field based on type (block or regular ACF field)
            if (strpos($field_path, 'block.') === 0) {
                // Gutenberg block field
                if (self::update_block_relationships($post_id, $field_path, $prod_ids, $new_value, $is_single)) {
                    $updated = true;
                }
            } elseif (strpos($field_path, '.') !== false) {
                // Nested ACF field
                if (self::update_nested_relationship_field($post_id, $field_path, $new_value, $prod_ids, $is_single)) {
                    $updated = true;
                }
            } else {
                // Regular ACF field
                $current_value = get_field($field_path, $post_id);

                if (is_array($current_value) && !$is_single) {
                    $merged = array_values(array_unique(array_merge($current_value, $prod_ids)));
                    update_field($field_path, $merged, $post_id);
                } else {
                    update_field($field_path, $new_value, $post_id);
                }

                $updated = true;
            }
        }

        return $updated;
    }

    /**
     * Update nested ACF field value.
     *
     * Navigates through field path (e.g., 'group.subfield.value') and updates
     * the target field, merging with existing values if array.
     *
     * @param int   $post_id   Post ID.
     * @param string $field_path Dot-separated field path.
     * @param mixed  $new_value  New value to set.
     * @param array  $prod_ids   Production IDs for merging.
     * @param bool   $is_single  If true, set single value instead of array.
     * @return bool True if field was updated.
     */
    private static function update_nested_relationship_field($post_id, $field_path, $new_value, $prod_ids, $is_single)
    {
        $parts = explode('.', $field_path);
        $top_field = array_shift($parts);

        if ($top_field === '' || empty($parts)) {
            return false;
        }

        $top_value = get_field($top_field, $post_id);
        if (!is_array($top_value)) {
            $top_value = [];
        }

        // Navigate to nested field
        $current = &$top_value;
        $last = array_pop($parts);

        foreach ($parts as $part) {
            if ($part === '') {
                return false;
            }

            $key = ctype_digit((string) $part) ? (int) $part : $part;

            if (!isset($current[$key]) || !is_array($current[$key])) {
                $current[$key] = [];
            }

            $current = &$current[$key];
        }

        if ($last === null || $last === '') {
            return false;
        }

        $final_key = ctype_digit((string) $last) ? (int) $last : $last;
        $existing = isset($current[$final_key]) ? $current[$final_key] : null;

        // Merge with existing array or set new value
        if (is_array($existing) && is_array($new_value) && !$is_single) {
            $current[$final_key] = array_values(array_unique(array_merge($existing, $prod_ids)));
        } else {
            $current[$final_key] = $new_value;
        }

        return update_field($top_field, $top_value, $post_id) ? true : false;
    }

    /**
     * Handle post ID mapping endpoint.
     *
     * Maps stage post IDs to production IDs by slug.
     * Used for remapping relationships to non-syncing post types.
     *
     * @param WP_REST_Request $request Request with posts to map.
     * @return WP_REST_Response Mapping results.
     */
    public static function handle_post_map($request)
    {
        $rid = self::get_rid();

        $body = $request->get_body();
        $data = json_decode($body, true);

        if (!is_array($data)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Invalid request: data is not array',
            ], 400);
        }

        if (empty($data['posts'])) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Invalid request: missing posts array',
            ], 400);
        }

        $posts_by_type = $data['posts'];
        $map = [];

        foreach ($posts_by_type as $post_type => $posts_data) {
            if (!is_array($posts_data)) {
                continue;
            }
            foreach ($posts_data as $post_data) {
                if (!is_array($post_data) || !isset($post_data['id']) || !isset($post_data['slug'])) {
                    continue;
                }

                $stage_id = (int) $post_data['id'];
                $slug = sanitize_title($post_data['slug']);

                if ($stage_id <= 0 || empty($slug)) {
                    continue;
                }

                // Find production post by slug
                $prod_id = null;
                try {
                    $prod_id = self::find_mapped_post_id($stage_id, $slug, $post_type);
                } catch (Exception $e) {
                    continue;
                }

                if ($prod_id) {
                    $map[$stage_id] = $prod_id;
                }
            }
        }

        return new WP_REST_Response([
            'success' => true,
            'data' => [
                'map' => $map,
            ],
        ], 200);
    }

    /**
     * Update ACF relationship field in Gutenberg block.
     *
     * Parses post content, finds target block, updates field, and saves content.
     *
     * @param int    $post_id   Post ID.
     * @param string $field_path Block field path (e.g., 'block.blockName.field.path').
     * @param array  $prod_ids   Production IDs to set.
     * @param mixed  $new_value  New value (single ID or array).
     * @param bool   $is_single  If true, set single value.
     * @return bool True if block was updated.
     */
    private static function update_block_relationships($post_id, $field_path, $prod_ids, $new_value, $is_single)
    {
        // Parse field_path: "block.{blockName}.{nested.field.path}"
        $parts = explode('.', $field_path);

        if (count($parts) < 3 || $parts[0] !== 'block') {
            return false;
        }

        $block_name = $parts[1];
        $field_parts = array_slice($parts, 2);

        // Get post content
        $post = get_post($post_id);
        if (!$post || !function_exists('parse_blocks')) {
            return false;
        }

        // Parse and update blocks
        $blocks = parse_blocks($post->post_content);
        $modified = false;

        foreach ($blocks as &$block) {
            if (self::update_block_field_recursive($block, $block_name, $field_parts, $prod_ids, $new_value, $is_single)) {
                $modified = true;
            }
        }

        if (!$modified) {
            return false;
        }

        // Rebuild content and update post
        $new_content = serialize_blocks($blocks);

        wp_update_post([
            'ID' => $post_id,
            'post_content' => $new_content,
        ]);

        return true;
    }

    /**
     * Recursively find and update field in block tree.
     *
     * @param array  $block           Block to process (by reference).
     * @param string $target_block_name Target block name.
     * @param array  $field_parts      Field path parts.
     * @param array  $prod_ids         Production IDs to set.
     * @param mixed  $new_value        New value.
     * @param bool   $is_single        If true, set single value.
     * @return bool True if block was updated.
     */
    private static function update_block_field_recursive(&$block, $target_block_name, $field_parts, $prod_ids, $new_value, $is_single)
    {
        if (!is_array($block)) {
            return false;
        }

        $updated = false;

        // Check if this is the target block
        $current_block_name = isset($block['blockName']) ? $block['blockName'] : '';

        if ($current_block_name === $target_block_name) {
            // Navigate to nested field and update
            if (isset($block['attrs']['data'])) {
                $current = &$block['attrs']['data'];

                // Navigate through nested path (all parts except last)
                $nested_parts = array_slice($field_parts, 0, -1);

                foreach ($nested_parts as $part) {
                    if (!isset($current[$part])) {
                        return false;
                    }
                    $current = &$current[$part];
                }

                // Get final field name
                $final_field = end($field_parts);

                if (isset($current[$final_field]) && is_array($current[$final_field]) && !$is_single) {
                    // Merge with existing IDs
                    $existing = $current[$final_field];
                    $merged = array_unique(array_merge($existing, $prod_ids));
                    $current[$final_field] = array_values($merged);
                    $updated = true;
                } elseif (is_array($current)) {
                    // Set new value
                    $current[$final_field] = $new_value;
                    $updated = true;
                }
            }
        }

        // Recurse into inner blocks
        if (isset($block['innerBlocks']) && is_array($block['innerBlocks'])) {
            foreach ($block['innerBlocks'] as &$inner_block) {
                if (self::update_block_field_recursive($inner_block, $target_block_name, $field_parts, $prod_ids, $new_value, $is_single)) {
                    $updated = true;
                }
            }
        }

        return $updated;
    }

    /**
     * Find production post ID by stage ID or slug.
     *
     * First tries to find by _stage_post_id meta, then falls back to slug lookup.
     *
     * @param int    $stage_id  Stage post ID.
     * @param string $slug      Post slug.
     * @param string $post_type Post type.
     * @return int|null Production post ID or null if not found.
     */
    private static function find_mapped_post_id($stage_id, $slug, $post_type)
    {
        // First try: find by _stage_post_id meta
        if ($stage_id > 0) {
            $posts = get_posts([
                'post_type' => $post_type,
                'post_status' => 'any',
                'posts_per_page' => 1,
                'meta_query' => [
                    [
                        'key' => '_stage_post_id',
                        'value' => $stage_id,
                        'compare' => '=',
                    ],
                ],
            ]);

            if (!empty($posts)) {
                return $posts[0]->ID;
            }
        }

        // Second try: find by slug (for posts that existed before sync system)
        if (!empty($slug)) {
            $posts = get_posts([
                'name' => $slug,
                'post_type' => $post_type,
                'post_status' => 'any',
                'posts_per_page' => 1,
            ]);

            if (!empty($posts)) {
                // Update meta for future lookups
                update_post_meta($posts[0]->ID, '_stage_post_id', $stage_id);
                return $posts[0]->ID;
            }
        }

        return null;
    }

    /**
     * Remap ACF relationship IDs by slug (unused in current implementation).
     *
     * @deprecated Legacy method for relationship remapping.
     */
    private static function remap_relationship_ids_by_slug($data, $block_name = '', $post_slug = '', $is_batch = false, $depth = 0)
    {
        if ($depth > 12 || !is_array($data)) {
            return $data;
        }

        foreach ($data as $key => &$value) {
            if (is_array($value)) {
                // Check if this is a relationship field
                if (self::is_relationship_field($value)) {
                    $prod_ids = [];

                    // Map each relationship by slug
                    foreach ($value as $relationship_data) {
                        if (!is_array($relationship_data) || empty($relationship_data['slug'])) {
                            continue;
                        }

                        $slug = $relationship_data['slug'];
                        $post_type = $relationship_data['post_type'] ?? null;

                        // Find post on production by slug
                        $prod_id = self::find_post_by_slug($slug, $post_type);

                        if ($prod_id) {
                            $prod_ids[] = (string) $prod_id;
                        } else {
                            if ($is_batch) {
                                // Batch: skip silently
                            } else {
                                // Single: return error
                                return new WP_Error(
                                    'sync_relationship_missing',
                                    sprintf(
                                        __('❌ Синхронізацію зупинено: На Production відсутній пост "%s" (тип: %s).', 'sync-stage-to-prod'),
                                        $slug,
                                        $post_type ?: 'any'
                                    ),
                                    ['missing_slug' => $slug, 'post_type' => $post_type]
                                );
                            }
                        }
                    }

                    $value = $prod_ids;
                } else {
                    // Recurse
                    $remapped = self::remap_relationship_ids_by_slug($value, $block_name, $post_slug, $is_batch, $depth + 1);
                    if (is_wp_error($remapped)) {
                        return $remapped;
                    }
                    $value = $remapped;
                }
            }
        }

        return $data;
    }

    /**
     * Check if array is a relationship field (array of objects with 'slug').
     *
     * @param mixed $arr Value to check.
     * @return bool True if relationship field.
     */
    private static function is_relationship_field($arr)
    {
        if (!is_array($arr) || empty($arr)) {
            return false;
        }

        $first = reset($arr);
        return is_array($first) && isset($first['slug']);
    }

    /**
     * Find post by slug and post type.
     *
     * @param string      $slug      Post slug.
     * @param string|null $post_type Post type or 'any'.
     * @return int|null Post ID or null if not found.
     */
    private static function find_post_by_slug($slug, $post_type)
    {
        if (empty($slug)) {
            return null;
        }

        $posts = get_posts([
            'name' => $slug,
            'post_type' => $post_type ?: 'any',
            'post_status' => 'any',
            'posts_per_page' => 1,
            'fields' => 'ids',
        ]);

        return !empty($posts) ? (int) $posts[0] : null;
    }

    /**
     * Import inline media from payload if present.
     *
     * @param array $data Request data.
     * @return array|WP_Error Import results with media map.
     */
    private static function maybe_import_media_from_payload($data)
    {
        if (!is_array($data)) {
            return [
                'created' => 0,
                'updated' => 0,
                'map' => self::get_media_map_option(),
            ];
        }

        $media_items = [];
        if (isset($data['media']) && is_array($data['media'])) {
            $media_items = $data['media'];
        }

        if (empty($media_items)) {
            return [
                'created' => 0,
                'updated' => 0,
                'map' => self::get_media_map_option(),
            ];
        }

        $result = self::import_media($media_items);

        if (is_wp_error($result)) {
            return $result;
        }

        return $result;
    }

    /**
     * Get media ID mapping from option.
     *
     * @return array Stage ID => Production ID mapping.
     */
    private static function get_media_map_option()
    {
        $map = get_option('sync_stp_media_map', []);
        if (!is_array($map)) {
            $map = [];
        }

        $out = [];
        foreach ($map as $k => $v) {
            $sid = (string) $k;
            $pid = (int) $v;
            if ($sid !== '' && $pid > 0) {
                $out[$sid] = $pid;
            }
        }

        return $out;
    }

    /**
     * Build URL mapping from media ID mapping.
     *
     * Maps stage media URLs to production URLs for content replacement.
     *
     * @param array $media_map Stage ID => Production ID mapping.
     * @return array Stage URL => Production URL mapping.
     */
    private static function build_media_url_map($media_map)
    {
        $url_map = [];

        if (empty($media_map) || !is_array($media_map)) {
            return $url_map;
        }

        foreach ($media_map as $stage_id => $prod_id) {
            $pid = (int) $prod_id;
            if ($pid <= 0 || get_post_type($pid) !== 'attachment') {
                continue;
            }

            $stage_url = get_post_meta($pid, 'sync_stp_stage_url', true);
            if (!is_string($stage_url) || trim($stage_url) === '') {
                continue;
            }

            $prod_url = wp_get_attachment_url($pid);
            if (!is_string($prod_url) || trim($prod_url) === '') {
                continue;
            }

            $stage_url = trim($stage_url);
            $prod_url = trim($prod_url);

            $url_map[$stage_url] = $prod_url;

            // Add http/https variants
            $http_variant = preg_replace('#^https://#i', 'http://', $stage_url);
            $https_variant = preg_replace('#^http://#i', 'https://', $stage_url);

            if (is_string($http_variant) && $http_variant !== $stage_url) {
                $url_map[$http_variant] = $prod_url;
            }

            if (is_string($https_variant) && $https_variant !== $stage_url) {
                $url_map[$https_variant] = $prod_url;
            }
        }

        return $url_map;
    }

    /**
     * Replace media URLs in string.
     *
     * @param string $content Content to process.
     * @param array  $url_map URL mapping.
     * @return string Processed content.
     */
    private static function replace_media_urls_in_string($content, $url_map)
    {
        if (!is_string($content) || $content === '' || empty($url_map) || !is_array($url_map)) {
            return $content;
        }

        foreach ($url_map as $from => $to) {
            if (!is_string($from) || $from === '' || !is_string($to) || $to === '') {
                continue;
            }
            $content = str_replace($from, $to, $content);
        }

        return $content;
    }

    /**
     * Recursively replace media URLs in any value.
     *
     * @param mixed $value   Value to process.
     * @param array $url_map URL mapping.
     * @param int   $depth   Current recursion depth.
     * @return mixed Processed value.
     */
    private static function replace_media_urls_in_value($value, $url_map, $depth = 0)
    {
        if ($depth > 12) {
            return $value;
        }

        if (empty($url_map) || !is_array($url_map)) {
            return $value;
        }

        if (is_string($value)) {
            return self::replace_media_urls_in_string($value, $url_map);
        }

        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = self::replace_media_urls_in_value($v, $url_map, $depth + 1);
            }
            return $out;
        }

        if (is_object($value)) {
            return self::replace_media_urls_in_value((array) $value, $url_map, $depth + 1);
        }

        return $value;
    }

    /**
     * Replace media IDs in any value using Sync_Media class.
     *
     * @param mixed $value     Value to process.
     * @param array $media_map Media ID mapping.
     * @return mixed Processed value.
     */
    private static function replace_media_in_value($value, $media_map)
    {
        if (empty($media_map) || !is_array($media_map)) {
            return $value;
        }

        if (class_exists('Sync_Media') && method_exists('Sync_Media', 'replace_ids_in_value')) {
            $result = Sync_Media::replace_ids_in_value($value, $media_map);
            return $result;
        }

        $result = self::fallback_replace_media_in_value($value, $media_map, 0);
        return $result;
    }

    /**
     * Fallback media ID replacement (when Sync_Media unavailable).
     *
     * @param mixed $value     Value to process.
     * @param array $media_map Media ID mapping.
     * @param int   $depth     Current recursion depth.
     * @return mixed Processed value.
     */
    private static function fallback_replace_media_in_value($value, $media_map, $depth = 0)
    {
        if ($depth > 12) {
            return $value;
        }

        // Replace integer IDs
        if (is_int($value)) {
            $key = (string) $value;
            if ($value > 0 && isset($media_map[$key])) {
                return (int) $media_map[$key];
            }
            return $value;
        }

        // Replace string IDs
        if (is_string($value) && ctype_digit($value)) {
            $sid = (int) $value;
            $key = (string) $sid;
            if ($sid > 0 && isset($media_map[$key])) {
                return (string) ((int) $media_map[$key]);
            }
            return $value;
        }

        // Recurse into arrays
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = self::fallback_replace_media_in_value($v, $media_map, $depth + 1);
            }

            // Handle 'ID' and 'id' keys
            if (isset($out['ID']) && is_numeric($out['ID'])) {
                $id = (int) $out['ID'];
                if ($id > 0 && isset($media_map[(string) $id])) {
                    $out['ID'] = (int) $media_map[(string) $id];
                }
            }

            if (isset($out['id']) && is_numeric($out['id'])) {
                $id = (int) $out['id'];
                if ($id > 0 && isset($media_map[(string) $id])) {
                    $out['id'] = (int) $media_map[(string) $id];
                }
            }

            return $out;
        }

        // Recurse into objects
        if (is_object($value)) {
            return self::fallback_replace_media_in_value((array) $value, $media_map, $depth + 1);
        }

        return $value;
    }

    /**
     * Replace both media IDs and URLs in a value.
     *
     * @param mixed $value     Value to process.
     * @param array $media_map Media ID mapping.
     * @param array $url_map   URL mapping.
     * @return mixed Processed value.
     */
    private static function replace_media_in_value_full($value, $media_map, $url_map)
    {
        $value = self::replace_media_in_value($value, $media_map);
        $value = self::replace_media_urls_in_value($value, $url_map, 0);
        return $value;
    }

    /**
     * Replace media IDs in string content using regex.
     *
     * Replaces IDs in:
     * - JSON "id" attributes
     * - wp-image-{id} classes
     * - data-id attributes
     * - attachment_id attributes
     *
     * @param string $content   Content to process.
     * @param array  $media_map Media ID mapping.
     * @return string Processed content.
     */
    private static function replace_media_in_string_by_ids($content, $media_map)
    {
        if (!is_string($content) || $content === '' || empty($media_map) || !is_array($media_map)) {
            return $content;
        }

        foreach ($media_map as $stage_id => $prod_id) {
            $sid = (int) $stage_id;
            $pid = (int) $prod_id;

            if ($sid <= 0 || $pid <= 0) {
                continue;
            }

            $content = preg_replace('/"id"\s*:\s*' . $sid . '\b/', '"id":' . $pid, $content);
            $content = preg_replace('/\bwp-image-' . $sid . '\b/', 'wp-image-' . $pid, $content);
            $content = str_replace('data-id="' . $sid . '"', 'data-id="' . $pid . '"', $content);
            $content = preg_replace('/attachment_id"\s*:\s*' . $sid . '\b/', 'attachment_id":' . $pid, $content);
        }

        return $content;
    }

    /**
     * Replace media IDs and URLs in post content.
     *
     * Also processes Gutenberg blocks if functions available.
     *
     * @param string $content   Post content.
     * @param array  $media_map Media ID mapping.
     * @param array  $url_map   URL mapping.
     * @return string Processed content.
     */
    private static function replace_media_in_content($content, $media_map, $url_map)
    {
        if (!is_string($content) || $content === '') {
            return $content;
        }

        $content = self::replace_media_urls_in_string($content, $url_map);
        $content = self::replace_media_in_string_by_ids($content, $media_map);

        // Process blocks if available
        if (function_exists('parse_blocks') && function_exists('serialize_blocks')) {
            $blocks = parse_blocks($content);
            $blocks = self::replace_media_in_blocks($blocks, $media_map, $url_map);
            $content = serialize_blocks($blocks);
        }

        return $content;
    }

    /**
     * Recursively replace media IDs and URLs in Gutenberg blocks.
     *
     * @param array $blocks    Parsed blocks array.
     * @param array $media_map Media ID mapping.
     * @param array $url_map   URL mapping.
     * @return array Processed blocks.
     */
    private static function replace_media_in_blocks($blocks, $media_map, $url_map)
    {
        if (!is_array($blocks) || empty($blocks)) {
            return $blocks;
        }
        foreach ($blocks as &$block) {
            $block_name = isset($block['blockName']) ? (string) $block['blockName'] : 'unknown';

            // Replace in block attributes
            if (isset($block['attrs']) && is_array($block['attrs'])) {
                $block['attrs'] = self::replace_media_in_value_full($block['attrs'], $media_map, $url_map);

                // Replace in ACF data
                if (isset($block['attrs']['data']) && is_array($block['attrs']['data'])) {
                    $block['attrs']['data'] = self::replace_media_in_value_full($block['attrs']['data'], $media_map, $url_map);
                }

                // Replace specific media attributes
                if (isset($block['attrs']['id'])) {
                    $before_id = $block['attrs']['id'];
                    $block['attrs']['id'] = self::replace_media_int($block['attrs']['id'], $media_map);
                }

                if (isset($block['attrs']['ids']) && is_array($block['attrs']['ids'])) {
                    $before_ids = $block['attrs']['ids'];
                    foreach ($block['attrs']['ids'] as &$id) {
                        $id = self::replace_media_int($id, $media_map);
                    }
                    unset($id);
                }

                if (isset($block['attrs']['mediaId'])) {
                    $before = $block['attrs']['mediaId'];
                    $block['attrs']['mediaId'] = self::replace_media_int($block['attrs']['mediaId'], $media_map);
                }
            }

            // Replace in innerHTML
            if (isset($block['innerHTML']) && is_string($block['innerHTML'])) {
                $block['innerHTML'] = self::replace_media_urls_in_string($block['innerHTML'], $url_map);
                $block['innerHTML'] = self::replace_media_in_string_by_ids($block['innerHTML'], $media_map);
            }

            // Replace in innerContent
            if (isset($block['innerContent']) && is_array($block['innerContent'])) {
                $inner = [];
                foreach ($block['innerContent'] as $piece) {
                    if (is_string($piece)) {
                        $piece = self::replace_media_urls_in_string($piece, $url_map);
                        $piece = self::replace_media_in_string_by_ids($piece, $media_map);
                    }
                    $inner[] = $piece;
                }
                $block['innerContent'] = $inner;
            }

            // Recurse into inner blocks
            if (isset($block['innerBlocks']) && is_array($block['innerBlocks']) && !empty($block['innerBlocks'])) {
                $block['innerBlocks'] = self::replace_media_in_blocks($block['innerBlocks'], $media_map, $url_map);
            }
        }

        return $blocks;
    }

    /**
     * Replace single media ID integer.
     *
     * Checks if ID already exists on production to avoid double-remapping.
     *
     * @param int   $id        Media ID to replace.
     * @param array $media_map Media ID mapping.
     * @return int Remapped ID or original if not in map.
     */
    private static function replace_media_int($id, $media_map)
    {
        $id = (int) $id;
        if ($id <= 0 || empty($media_map) || !is_array($media_map)) {
            return $id;
        }

        // If ID already exists as production ID, don't remap
        if (in_array($id, $media_map, true)) {
            return $id;
        }

        if (isset($media_map[$id])) {
            $new_id = (int) $media_map[$id];
            return $new_id;
        }

        $key = (string) $id;
        if (isset($media_map[$key])) {
            $new_id = (int) $media_map[$key];
            return $new_id;
        }

        return $id;
    }

    /**
     * Rebuild Yoast SEO indexable for a post.
     *
     * @param int $post_id Post ID.
     * @return void
     */
    private static function maybe_rebuild_yoast_post_indexable($post_id)
    {
        $post_id = (int) $post_id;
        if ($post_id <= 0) {
            return;
        }

        if (!function_exists('YoastSEO')) {
            return;
        }

        if (!class_exists('Yoast\WP\SEO\Integrations\Watchers\Indexable_Post_Watcher')) {
            return;
        }

        try {
            $yoast = YoastSEO();

            if (!is_object($yoast) || !isset($yoast->classes) || !is_object($yoast->classes) || !method_exists($yoast->classes, 'get')) {
                return;
            }

            $watcher = $yoast->classes->get('Yoast\WP\SEO\Integrations\Watchers\Indexable_Post_Watcher');

            if (!is_object($watcher) || !method_exists($watcher, 'build_indexable')) {
                return;
            }

            $watcher->build_indexable($post_id);
        } catch (Throwable $e) {
            return;
        }
    }

    /**
     * Import taxonomy terms with metadata and ACF fields.
     *
     * @param array $items     Terms data array.
     * @param array $media_map Media ID mapping.
     * @param array $url_map   URL mapping.
     * @return array Import counts (created, updated).
     */
    private static function import_terms($items, $media_map = [], $url_map = [])
    {
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $seen = 0;

        if (empty($items) || !is_array($items)) {
            return [
                'created' => 0,
                'updated' => 0,
            ];
        }

        foreach ($items as $item) {
            $seen++;

            $taxonomy = '';
            if (isset($item['taxonomy'])) {
                $taxonomy = sanitize_key($item['taxonomy']);
            }

            $slug_value = '';
            if (isset($item['slug']) && is_string($item['slug'])) {
                $slug_value = $item['slug'];
            }

            $name_value = '';
            if (isset($item['name']) && is_string($item['name'])) {
                $name_value = $item['name'];
            }

            if ($taxonomy === '' || !taxonomy_exists($taxonomy)) {
                $skipped++;
                continue;
            }

            $slug = sanitize_title($slug_value);
            if ($slug === '') {
                $skipped++;
                continue;
            }

            $name = $name_value !== '' ? wp_strip_all_tags($name_value) : $slug;

            $term_id = 0;
            $existing = get_term_by('slug', $slug, $taxonomy);

            if ($existing && !is_wp_error($existing)) {
                $updated++;
                $term_id = (int) $existing->term_id;

                if ($name !== '' && $existing->name !== $name) {
                    $updated_term = wp_update_term($term_id, $taxonomy, ['name' => $name]);
                    if (!is_wp_error($updated_term) && isset($updated_term['term_id'])) {
                        $term_id = (int) $updated_term['term_id'];
                    }
                }
            } else {
                $created_term = wp_insert_term($name, $taxonomy, ['slug' => $slug]);

                if (is_wp_error($created_term) || !isset($created_term['term_id'])) {
                    $skipped++;
                    continue;
                }

                $created++;
                $term_id = (int) $created_term['term_id'];
            }

            if ($term_id <= 0) {
                $skipped++;
                continue;
            }

            // Import term meta
            $meta = [];
            if (isset($item['meta']) && is_array($item['meta'])) {
                $meta = $item['meta'];
            }

            foreach ($meta as $key => $value) {
                $meta_key = is_string($key) ? sanitize_key($key) : '';
                if ($meta_key === '') {
                    continue;
                }
                $value = Sync_Validator::decode_unicode_escapes($value);
                $value = self::replace_media_in_value_full($value, $media_map, $url_map);
                $sanitized_value = Sync_Validator::sanitize_meta_value($value);
                update_term_meta($term_id, $meta_key, $sanitized_value);
            }

            // Import ACF fields
            if (function_exists('update_field')) {
                $acf_fields = [];
                if (isset($item['acf']) && is_array($item['acf'])) {
                    $acf_fields = $item['acf'];
                }

                if (!empty($acf_fields)) {
                    foreach ($acf_fields as $field_name => $value) {
                        if (!is_string($field_name) || $field_name === '') {
                            continue;
                        }

                        $safe_field_name = sanitize_key($field_name);
                        if ($safe_field_name === '') {
                            continue;
                        }
                        $value = Sync_Validator::decode_unicode_escapes($value);
                        $value = self::replace_media_in_value_full($value, $media_map, $url_map);
                        $sanitized_value = Sync_Validator::sanitize_acf_field($value, $safe_field_name, 'term_' . $term_id);

                        $update_result = update_field($safe_field_name, $sanitized_value, 'term_' . $term_id);
                    }
                }
            }
        }

        return [
            'created' => $created,
            'updated' => $updated,
        ];
    }

    /**
     * Import posts with content, ACF fields, taxonomies, and featured images.
     *
     * @param array $items     Posts data array.
     * @param array $media_map Media ID mapping.
     * @param array $url_map   URL mapping.
     * @return array Import counts (created, updated).
     */
    private static function import_posts($items, $media_map = [], $url_map = [])
    {
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $seen = 0;

        if (empty($items) || !is_array($items)) {
            return [
                'created' => 0,
                'updated' => 0,
            ];
        }

        $primary_meta_keys = [
            '_yoast_wpseo_primary_category',
            'rank_math_primary_category',
        ];

        foreach ($items as $item) {
            $seen++;

            $post_type = '';
            if (isset($item['post_type'])) {
                $post_type = sanitize_key($item['post_type']);
            }

            $slug_value = '';
            if (isset($item['slug']) && is_string($item['slug'])) {
                $slug_value = $item['slug'];
            }

            $title_value = '';
            if (isset($item['title']) && is_string($item['title'])) {
                $title_value = $item['title'];
            }

            $content_value = '';
            if (isset($item['content']) && is_string($item['content'])) {
                $content_value = $item['content'];
            }

            $excerpt_value = '';
            if (isset($item['excerpt']) && is_string($item['excerpt'])) {
                $excerpt_value = $item['excerpt'];
            }

            $status_value = 'publish';
            if (isset($item['status']) && is_string($item['status'])) {
                $status_value = $item['status'];
            }

            $has_featured_image = array_key_exists('featured_image', $item);
            $featured_image = 0;
            if ($has_featured_image) {
                $featured_image = (int) $item['featured_image'];
            }

            $author_login = '';
            if (isset($item['author_login']) && is_string($item['author_login'])) {
                $author_login = sanitize_user($item['author_login']);
            }

            if ($post_type === '' || !post_type_exists($post_type)) {
                $skipped++;
                continue;
            }

            $slug = sanitize_title($slug_value);
            if ($slug === '') {
                $skipped++;
                continue;
            }

            // Remap featured image ID
            if ($has_featured_image) {
                $featured_image = self::replace_media_int($featured_image, $media_map);
            }

            // Replace media in content
            $content_value = self::replace_media_in_content($content_value, $media_map, $url_map);

            if (!empty($content_value) && function_exists('parse_blocks') && function_exists('serialize_blocks')) {
                $blocks = parse_blocks((string) $content_value);
                if (is_array($blocks)) {
                    $blocks = self::replace_media_in_blocks($blocks, $media_map, $url_map);
                    $content_value = serialize_blocks($blocks);
                }
            }

            $title = $title_value !== '' ? wp_strip_all_tags($title_value) : $slug;
            $content = (string) $content_value;
            $excerpt = wp_strip_all_tags($excerpt_value);
            $status = sanitize_key($status_value);

            if (!in_array($status, ['publish', 'draft', 'pending', 'private', 'future'], true)) {
                $status = 'publish';
            }

            // Find author by login
            $author_id = 0;
            if (!empty($author_login)) {
                $author = get_user_by('login', $author_login);
                if ($author && isset($author->ID)) {
                    $author_id = (int) $author->ID;
                }
            }

            // Check if post already exists
            $existing = get_page_by_path($slug, OBJECT, $post_type);
            $post_id = 0;

            if ($existing && $existing instanceof WP_Post) {
                // Update existing post
                $post_id = (int) $existing->ID;

                $update_arr = [
                    'ID' => $post_id,
                    'post_title' => $title,
                    'post_content' => $content,
                    'post_excerpt' => $excerpt,
                    'post_status' => $status,
                ];

                if ($author_id > 0) {
                    $update_arr['post_author'] = $author_id;
                }

                $res = wp_update_post($update_arr, true);
                if (is_wp_error($res)) {
                    $skipped++;
                    continue;
                }

                $updated++;

                // Update stage ID meta
                if (!empty($item['id'])) {
                    $current_stage_id = get_post_meta($post_id, '_stage_post_id', true);
                    if (empty($current_stage_id) || (int) $current_stage_id !== (int) $item['id']) {
                        update_post_meta($post_id, '_stage_post_id', (int) $item['id']);
                    }
                }
            } else {
                // Create new post
                $insert_arr = [
                    'post_type' => $post_type,
                    'post_name' => $slug,
                    'post_title' => $title,
                    'post_content' => $content,
                    'post_excerpt' => $excerpt,
                    'post_status' => $status,
                ];

                if ($author_id > 0) {
                    $insert_arr['post_author'] = $author_id;
                }

                $new_id = wp_insert_post($insert_arr, true);
                if (is_wp_error($new_id) || !$new_id) {
                    $skipped++;
                    continue;
                }

                $post_id = (int) $new_id;
                $created++;

                if (!empty($item['id'])) {
                    update_post_meta($post_id, '_stage_post_id', (int) $item['id']);
                }
            }

            if ($post_id <= 0) {
                $skipped++;
                continue;
            }

            // Set or remove featured image
            if ($has_featured_image) {
                if ($featured_image > 0 && get_post_type($featured_image) === 'attachment') {
                    set_post_thumbnail($post_id, $featured_image);
                } else {
                    delete_post_thumbnail($post_id);
                }
            }

            // Import post meta
            $meta = [];
            if (isset($item['meta']) && is_array($item['meta'])) {
                $meta = $item['meta'];
            }

            if (!empty($meta)) {
                foreach ($meta as $key => $value) {
                    $meta_key = is_string($key) ? sanitize_key($key) : '';
                    if ($meta_key === '') {
                        continue;
                    }

                    // Skip primary category meta (handled separately)
                    if (in_array($meta_key, $primary_meta_keys, true)) {
                        continue;
                    }
                    $value = Sync_Validator::decode_unicode_escapes($value);
                    $value = self::replace_media_in_value_full($value, $media_map, $url_map);

                    if (class_exists('Sync_Validator') && method_exists('Sync_Validator', 'sanitize_meta_value')) {
                        $sanitized_value = Sync_Validator::sanitize_meta_value($value);
                    } else {
                        $sanitized_value = $value;
                    }

                    update_post_meta($post_id, $meta_key, $sanitized_value);
                }
            }

            // Import ACF fields
            if (function_exists('update_field')) {
                $acf_fields = [];
                if (isset($item['acf']) && is_array($item['acf'])) {
                    $acf_fields = $item['acf'];
                }

                if (!empty($acf_fields)) {
                    foreach ($acf_fields as $field_name => $value) {
                        if (!is_string($field_name) || $field_name === '') {
                            continue;
                        }

                        $selector = (string) $field_name;

                        $is_field_key = preg_match('/^field_[a-z0-9]+$/', $selector) === 1;
                        $is_field_name = preg_match('/^[a-zA-Z0-9_]+$/', $selector) === 1;

                        if (!$is_field_key && !$is_field_name) {
                            continue;
                        }
                        $value = Sync_Validator::decode_unicode_escapes($value);
                        $value = self::replace_media_in_value_full($value, $media_map, $url_map);

                        if (class_exists('Sync_Validator') && method_exists('Sync_Validator', 'sanitize_acf_field')) {
                            $sanitized_value = Sync_Validator::sanitize_acf_field($value, $selector, $post_id);
                        } else {
                            $sanitized_value = $value;
                        }

                        update_field($selector, $sanitized_value, $post_id);
                    }
                }
            }

            // Import taxonomy terms
            $tax_input = [];
            if (isset($item['tax_input']) && is_array($item['tax_input'])) {
                $tax_input = $item['tax_input'];
            }

            if (!empty($tax_input)) {
                foreach ($tax_input as $taxonomy => $slugs) {
                    $taxonomy_key = sanitize_key($taxonomy);

                    if (!taxonomy_exists($taxonomy_key)) {
                        continue;
                    }

                    if (!is_array($slugs)) {
                        continue;
                    }

                    $term_ids = [];

                    foreach ($slugs as $slug_item) {
                        if (!is_string($slug_item)) {
                            continue;
                        }

                        $term_slug = sanitize_title($slug_item);
                        if ($term_slug === '') {
                            continue;
                        }

                        $term = get_term_by('slug', $term_slug, $taxonomy_key);
                        if ($term && !is_wp_error($term)) {
                            $term_ids[] = (int) $term->term_id;
                        }
                    }

                    if (!empty($term_ids)) {
                        wp_set_object_terms($post_id, $term_ids, $taxonomy_key);
                    } else {
                        wp_set_object_terms($post_id, [], $taxonomy_key);
                    }

                    // Set primary category if applicable
                    if ($taxonomy_key === 'category') {
                        $primary_slug = '';
                        if (isset($item['primary_category_slug']) && is_string($item['primary_category_slug'])) {
                            $primary_slug = sanitize_title($item['primary_category_slug']);
                        }

                        if ($primary_slug !== '') {
                            $primary_term = get_term_by('slug', $primary_slug, 'category');
                            if ($primary_term && !is_wp_error($primary_term)) {
                                $primary_id = (int) $primary_term->term_id;
                                foreach ($primary_meta_keys as $meta_key) {
                                    update_post_meta($post_id, $meta_key, $primary_id);
                                }
                            }
                        }
                    }
                }
            }

            // Rebuild Yoast SEO indexable if available
            self::maybe_rebuild_yoast_post_indexable($post_id);
        }

        return [
            'created' => $created,
            'updated' => $updated,
        ];
    }

    /**
     * Import ACF options pages and archive settings.
     *
     * @param array $options_payload Options data.
     * @param array $media_map       Media ID mapping.
     * @param array $url_map         URL mapping.
     * @return array Import counts (groups_updated, fields_updated).
     */
    private static function import_options($options_payload, $media_map = [], $url_map = [])
    {
        $groups_updated = 0;
        $fields_updated = 0;

        if (!function_exists('update_field')) {
            return [
                'groups_updated' => 0,
                'fields_updated' => 0,
            ];
        }

        $can_validate = class_exists('Sync_Validator') && method_exists('Sync_Validator', 'sanitize_acf_field');

        // Import ACF options page
        if (isset($options_payload['page_settings']) && is_array($options_payload['page_settings'])) {
            $page_data = $options_payload['page_settings'];

            if (isset($page_data['fields']) && is_array($page_data['fields']) && !empty($page_data['fields'])) {
                $context = 'options';

                if (isset($page_data['menu_slug']) && is_string($page_data['menu_slug']) && $page_data['menu_slug'] !== '') {
                    $context = sanitize_key($page_data['menu_slug']);
                }

                $count_before = $fields_updated;

                foreach ($page_data['fields'] as $field_name => $value) {
                    if (!is_string($field_name) || $field_name === '') {
                        continue;
                    }

                    $selector = (string) $field_name;

                    $is_field_key = preg_match('/^field_[a-z0-9]+$/', $selector) === 1;
                    $is_field_name = preg_match('/^[a-zA-Z0-9_]+$/', $selector) === 1;

                    if (!$is_field_key && !$is_field_name) {
                        continue;
                    }

                    // Remap post references in options
                    $remap = self::maybe_remap_option_post_reference($value, $selector, $context);
                    if (isset($remap['should_update']) && $remap['should_update'] === false) {
                        continue;
                    }
                    if (isset($remap['value'])) {
                        $value = $remap['value'];
                    }
                    $value = Sync_Validator::decode_unicode_escapes($value);
                    $value = self::replace_media_in_value_full($value, $media_map, $url_map);

                    if ($can_validate) {
                        $sanitized_value = Sync_Validator::sanitize_acf_field($value, $selector, $context);
                    } else {
                        $sanitized_value = $value;
                    }

                    $updated_flag = update_field($selector, $sanitized_value, $context);

                    if ($updated_flag === true) {
                        $fields_updated++;
                    } elseif (function_exists('get_field_object')) {
                        $field_obj = get_field_object($selector, $context, false);
                        if (is_array($field_obj) && !empty($field_obj['key'])) {
                            $fields_updated++;
                        }
                    }
                }

                if ($fields_updated > $count_before) {
                    $groups_updated++;
                }
            }
        }

        // Import archive settings
        if (isset($options_payload['archives']) && is_array($options_payload['archives'])) {
            foreach ($options_payload['archives'] as $post_type => $archive_data) {
                if (!is_array($archive_data)) {
                    continue;
                }

                if (!isset($archive_data['fields']) || !is_array($archive_data['fields']) || empty($archive_data['fields'])) {
                    continue;
                }

                $context = '';

                if (isset($archive_data['menu_slug']) && is_string($archive_data['menu_slug']) && $archive_data['menu_slug'] !== '') {
                    $context = sanitize_key($archive_data['menu_slug']);
                } else {
                    $context = sanitize_key($post_type) . '-archive';
                }

                if ($context === '') {
                    continue;
                }

                $count_before = $fields_updated;

                foreach ($archive_data['fields'] as $field_name => $value) {
                    if (!is_string($field_name) || $field_name === '') {
                        continue;
                    }

                    $selector = (string) $field_name;

                    $is_field_key = preg_match('/^field_[a-z0-9]+$/', $selector) === 1;
                    $is_field_name = preg_match('/^[a-zA-Z0-9_]+$/', $selector) === 1;

                    if (!$is_field_key && !$is_field_name) {
                        continue;
                    }

                    // Remap post references in options
                    $remap = self::maybe_remap_option_post_reference($value, $selector, $context);
                    if (isset($remap['should_update']) && $remap['should_update'] === false) {
                        continue;
                    }
                    if (isset($remap['value'])) {
                        $value = $remap['value'];
                    }
                    $value = Sync_Validator::decode_unicode_escapes($value);
                    $value = self::replace_media_in_value_full($value, $media_map, $url_map);

                    if ($can_validate) {
                        $sanitized_value = Sync_Validator::sanitize_acf_field($value, $selector, $context);
                    } else {
                        $sanitized_value = $value;
                    }

                    $updated_flag = update_field($selector, $sanitized_value, $context);

                    if ($updated_flag === true) {
                        $fields_updated++;
                    } elseif (function_exists('get_field_object')) {
                        $field_obj = get_field_object($selector, $context, false);
                        if (is_array($field_obj) && !empty($field_obj['key'])) {
                            $fields_updated++;
                        }
                    }
                }

                if ($fields_updated > $count_before) {
                    $groups_updated++;
                }
            }
        }

        return [
            'groups_updated' => $groups_updated,
            'fields_updated' => $fields_updated,
        ];
    }

    /**
     * Remap ACF post references in option fields.
     *
     * Maps stage post IDs to production IDs for post_object/relationship fields.
     * If referenced posts don't exist on production, skips field update.
     *
     * @param mixed  $value      Field value.
     * @param string $field_name Field name or key.
     *  * @param string $context    ACF context (e.g., 'options').
      * @return array Array with 'value' and 'should_update' keys.
      */
    private static function maybe_remap_option_post_reference($value, $field_name, $context)
    {
        if (!function_exists('get_field_object')) {
            return [
                'value' => $value,
                'should_update' => true,
            ];
        }

        if (!method_exists(__CLASS__, 'find_production_id_by_stage_id')) {
            return [
                'value' => $value,
                'should_update' => false,
            ];
        }

        $field_obj = get_field_object($field_name, $context, false);
        if (!is_array($field_obj) || empty($field_obj['type'])) {
            return [
                'value' => $value,
                'should_update' => true,
            ];
        }

        $type = (string) $field_obj['type'];
        if (!in_array($type, ['post_object', 'relationship', 'page_link'], true)) {
            return [
                'value' => $value,
                'should_update' => true,
            ];
        }

        if ($type === 'page_link' && isset($field_obj['return_format']) && $field_obj['return_format'] === 'url') {
            return [
                'value' => $value,
                'should_update' => true,
            ];
        }

        $stage_ids = self::extract_stage_post_ids_from_value($value);
        if (empty($stage_ids)) {
            return [
                'value' => $value,
                'should_update' => true,
            ];
        }

        $post_type_hint = null;

        if (isset($field_obj['post_type'])) {
            $configured_post_type = $field_obj['post_type'];

            if (is_string($configured_post_type) && $configured_post_type !== '') {
                $post_type_hint = $configured_post_type;
            } elseif (is_array($configured_post_type) && count($configured_post_type) === 1) {
                $single_post_type = reset($configured_post_type);
                if (is_string($single_post_type) && $single_post_type !== '') {
                    $post_type_hint = $single_post_type;
                }
            }
        }

        $mapped_ids = [];
        foreach ($stage_ids as $stage_id) {
            $prod_id = self::find_production_id_by_stage_id((int) $stage_id, $post_type_hint);
            if ($prod_id) {
                $mapped_ids[] = (int) $prod_id;
            }
        }

        $mapped_ids = array_values(array_unique($mapped_ids));

        if (empty($mapped_ids)) {
            return [
                'value' => $value,
                'should_update' => false,
            ];
        }

        $is_multiple = false;
        if (isset($field_obj['multiple'])) {
            $is_multiple = (bool) $field_obj['multiple'];
        }

        if (($type === 'post_object' || $type === 'page_link') && !$is_multiple) {
            return [
                'value' => $mapped_ids[0],
                'should_update' => true,
            ];
        }

        return [
            'value' => $mapped_ids,
            'should_update' => true,
        ];
    }
    /**
     * Extract post IDs from any value (for option field remapping).
     *
     * @param mixed $value Value to extract IDs from.
     * @param int   $depth Current recursion depth.
     * @return int[] Array of post IDs.
     */
    private static function extract_stage_post_ids_from_value($value, $depth = 0)
    {
        if ($depth > 12) {
            return [];
        }

        $ids = [];

        if (is_int($value) && $value > 0) {
            $ids[] = (int) $value;
        } elseif (is_string($value) && ctype_digit($value)) {
            $ids[] = (int) $value;
        } elseif (is_object($value) && isset($value->ID) && is_numeric($value->ID)) {
            $id = (int) $value->ID;
            if ($id > 0) {
                $ids[] = $id;
            }
        } elseif (is_array($value)) {
            if (isset($value['ID']) && is_numeric($value['ID'])) {
                $id = (int) $value['ID'];
                if ($id > 0) {
                    $ids[] = $id;
                }
            } elseif (isset($value['id']) && is_numeric($value['id'])) {
                $id = (int) $value['id'];
                if ($id > 0) {
                    $ids[] = $id;
                }
            } else {
                foreach ($value as $item) {
                    $ids = array_merge($ids, self::extract_stage_post_ids_from_value($item, $depth + 1));
                }
            }
        }

        $ids = array_values(array_unique(array_filter($ids, static function ($v) {
            return is_int($v) && $v > 0;
        })));

        return $ids;
    }
    /**
     * Import media attachments from stage.
     *
     * Downloads media files, creates/updates attachments, and tracks mapping.
     *
     * @param array $items Media data array.
     * @return array|WP_Error Import results with mapping.
     */
    private static function import_media($items)
    {
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $seen = 0;
        $downloaded = 0;
        $reused = 0;

        if (empty($items) || !is_array($items)) {
            return [
                'created' => 0,
                'updated' => 0,
                'map' => [],
            ];
        }

        $allowed = self::get_allowed_media_source_context();
        if (is_wp_error($allowed)) {
            return $allowed;
        }

        if (!function_exists('media_handle_sideload')) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
        }

        if (!function_exists('wp_generate_attachment_metadata')) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        $map = get_option('sync_stp_media_map', []);
        if (!is_array($map)) {
            $map = [];
        }

        $out_map = $map;

        global $wpdb;

        foreach ($items as $item) {
            $seen++;

            if (!is_array($item)) {
                $skipped++;
                continue;
            }

            $stage_id = isset($item['stage_id']) ? (int) $item['stage_id'] : 0;

            $source_url = '';
            if (isset($item['source_url']) && is_string($item['source_url'])) {
                $source_url = esc_url_raw(trim($item['source_url']));
            }

            if ($stage_id <= 0 || $source_url === '') {
                $skipped++;
                continue;
            }

            $parsed = wp_parse_url($source_url);
            if (!is_array($parsed) || empty($parsed['scheme']) || !in_array($parsed['scheme'], ['http', 'https'], true)) {
                $skipped++;
                continue;
            }

            if (empty($parsed['host']) || !is_string($parsed['host'])) {
                $skipped++;
                continue;
            }

            $incoming_host = strtolower(trim((string) $parsed['host']));
            if ($incoming_host === '' || $incoming_host !== $allowed['host']) {
                $skipped++;
                continue;
            }

            if (isset($parsed['port'])) {
                $port = (int) $parsed['port'];
                if (!in_array($port, [80, 443], true)) {
                    $skipped++;
                    continue;
                }
            }

            if (!self::is_public_host($incoming_host)) {
                $skipped++;
                continue;
            }

            $title = isset($item['title']) && is_string($item['title']) ? $item['title'] : '';
            $caption = isset($item['caption']) && is_string($item['caption']) ? $item['caption'] : '';
            $description = isset($item['description']) && is_string($item['description']) ? $item['description'] : '';
            $alt = isset($item['alt']) && is_string($item['alt']) ? $item['alt'] : '';

            $incoming_file_size = isset($item['file_size']) ? (int) $item['file_size'] : 0;
            $incoming_file_mtime = isset($item['file_mtime']) ? (int) $item['file_mtime'] : 0;

            $existing_prod_id = 0;
            $key = (string) $stage_id;
            $found_by_filename = false;

            if (isset($map[$key])) {
                $candidate = (int) $map[$key];
                if ($candidate > 0 && get_post_type($candidate) === 'attachment') {
                    $existing_prod_id = $candidate;
                }
            }

            if ($existing_prod_id === 0) {
                $filename = wp_basename($source_url);

                if ($filename !== '') {
                    $like = '%/' . $wpdb->esc_like($filename);
                    $query = $wpdb->prepare(
                        "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value LIKE %s",
                        $like
                    );

                    $results = $wpdb->get_results($query);

                    if (!empty($results)) {
                        foreach ($results as $row) {
                            $meta_value = isset($row->meta_value) ? (string) $row->meta_value : '';
                            if ($meta_value === '' || wp_basename($meta_value) !== $filename) {
                                continue;
                            }

                            $found_id = (int) $row->post_id;
                            if ($found_id <= 0 || get_post_type($found_id) !== 'attachment') {
                                continue;
                            }

                            $claimed_stage_id = (int) get_post_meta($found_id, 'sync_stp_stage_id', true);
                            if ($claimed_stage_id > 0 && $claimed_stage_id !== $stage_id) {
                                continue;
                            }

                            if ($incoming_file_size > 0) {
                                $prod_file = get_attached_file($found_id);
                                if (is_string($prod_file) && $prod_file !== '' && file_exists($prod_file)) {
                                    $prod_size = (int) filesize($prod_file);
                                    if ($prod_size > 0 && $prod_size !== $incoming_file_size) {
                                        continue;
                                    }
                                }
                            }

                            $existing_prod_id = $found_id;
                            $found_by_filename = true;

                            $map[$key] = $found_id;
                            $out_map[$key] = $found_id;
                            break;
                        }
                    }
                }
            }

            $needs_download = ($existing_prod_id === 0);

            if ($existing_prod_id > 0 && !$found_by_filename) {
                $prev_mtime = (int) get_post_meta($existing_prod_id, 'sync_stp_stage_file_mtime', true);
                $prev_size = (int) get_post_meta($existing_prod_id, 'sync_stp_stage_file_size', true);

                if ($incoming_file_mtime > 0 && $prev_mtime > 0 && $incoming_file_mtime === $prev_mtime) {
                    $needs_download = false;
                }

                if ($incoming_file_size > 0 && $prev_size > 0) {
                    if ($incoming_file_size !== $prev_size) {
                        $needs_download = true;
                    } elseif ($incoming_file_mtime <= 0 || $prev_mtime <= 0) {
                        $needs_download = false;
                    }
                }
            }

            if (!$needs_download && $existing_prod_id > 0) {
                wp_update_post([
                    'ID' => $existing_prod_id,
                    'post_title' => wp_strip_all_tags($title),
                    'post_excerpt' => $caption,
                    'post_content' => wp_kses_post($description),
                ]);

                if ($alt !== '') {
                    update_post_meta($existing_prod_id, '_wp_attachment_image_alt', sanitize_text_field($alt));
                }

                update_post_meta($existing_prod_id, 'sync_stp_stage_id', $stage_id);
                update_post_meta($existing_prod_id, 'sync_stp_stage_url', $source_url);
                update_post_meta($existing_prod_id, 'sync_stp_stage_file_size', $incoming_file_size);
                update_post_meta($existing_prod_id, 'sync_stp_stage_file_mtime', $incoming_file_mtime);

                $out_map[$key] = $existing_prod_id;
                $updated++;
                $reused++;

                continue;
            }

            $tmp = self::safe_download_file_no_redirect($source_url, 60);
            if (is_wp_error($tmp)) {
                $skipped++;
                continue;
            }

            $downloaded++;

            $filename = '';
            $path = wp_parse_url($source_url, PHP_URL_PATH);
            if (is_string($path) && $path !== '') {
                $filename = wp_basename($path);
            }

            if ($filename === '') {
                $filename = 'stage-file-' . $stage_id;
            }

            $file = [
                'name' => sanitize_file_name($filename),
                'tmp_name' => $tmp,
            ];

            $new_id = media_handle_sideload($file, 0, wp_strip_all_tags($title));

            if (is_wp_error($new_id) || !$new_id) {
                if (is_string($tmp) && $tmp !== '' && file_exists($tmp)) {
                    @unlink($tmp);
                }
                $skipped++;
                continue;
            }

            $new_id = (int) $new_id;

            wp_update_post([
                'ID' => $new_id,
                'post_title' => wp_strip_all_tags($title),
                'post_excerpt' => $caption,
                'post_content' => wp_kses_post($description),
            ]);

            if ($alt !== '') {
                update_post_meta($new_id, '_wp_attachment_image_alt', sanitize_text_field($alt));
            }

            update_post_meta($new_id, 'sync_stp_stage_id', $stage_id);
            update_post_meta($new_id, 'sync_stp_stage_url', $source_url);
            update_post_meta($new_id, 'sync_stp_stage_file_size', $incoming_file_size);
            update_post_meta($new_id, 'sync_stp_stage_file_mtime', $incoming_file_mtime);

            if ($existing_prod_id > 0) {
                wp_delete_attachment($existing_prod_id, true);
                $updated++;
            } else {
                $created++;
            }

            $out_map[$key] = $new_id;
        }

        update_option('sync_stp_media_map', $out_map, false);

        return [
            'created' => $created,
            'updated' => $updated,
            'map' => $out_map,
        ];
    }
    /**
     * Delete media attachments by stage IDs.
     *
     * Removes attachments from production that were deleted on stage.
     *
     * @param int[] $stage_ids Stage attachment IDs to delete.
     * @return int Count of deleted attachments.
     */
    private static function delete_media_by_stage_ids($stage_ids)
    {
        if (empty($stage_ids) || !is_array($stage_ids)) {
            return 0;
        }

        $map = get_option('sync_stp_media_map', []);
        if (!is_array($map) || empty($map)) {
            return 0;
        }

        $values = [];
        foreach ($map as $k => $v) {
            $pid = (int) $v;
            if ($pid > 0) {
                $values[] = $pid;
            }
        }
        $usage = array_count_values($values);

        $deleted = 0;

        foreach ($stage_ids as $sid) {
            $sid = (int) $sid;
            if ($sid <= 0) {
                continue;
            }

            $key = (string) $sid;

            if (!isset($map[$key])) {
                continue;
            }

            $prod_id = (int) $map[$key];

            if ($prod_id > 0 && isset($usage[$prod_id]) && (int) $usage[$prod_id] > 1) {
                unset($map[$key]);
                $usage[$prod_id] = (int) $usage[$prod_id] - 1;
                continue;
            }

            if ($prod_id > 0 && get_post_type($prod_id) === 'attachment') {
                $ok = wp_delete_attachment($prod_id, true);
                if ($ok) {
                    $deleted++;
                }
            }

            unset($map[$key]);
            if ($prod_id > 0 && isset($usage[$prod_id])) {
                unset($usage[$prod_id]);
            }
        }

        update_option('sync_stp_media_map', $map, false);

        return $deleted;
    }
    /**
     * Get allowed media source configuration.
     *
     * Validates that source URL is properly configured and secure.
     *
     * @return array|WP_Error Array with 'base' and 'host' or error.
     */
    private static function get_allowed_media_source_context()
    {
        $options = get_option(SYNC_STP_OPT_KEY, []);

        $base = '';
        if (isset($options['source_url']) && is_string($options['source_url'])) {
            $base = trim($options['source_url']);
        }

        if ($base === '') {
            return new WP_Error(
                'sync_no_source_url',
                __('На Prod потрібно вказати URL Stage/Dev у полі Source URL (для дозволу завантаження медіа).', 'sync-stage-to-prod'),
                ['status' => 400]
            );
        }

        $valid_url = '';

        if (class_exists('Sync_Validator') && method_exists('Sync_Validator', 'validate_url')) {
            $validated = Sync_Validator::validate_url($base);
            if (is_string($validated) && trim($validated) !== '') {
                $valid_url = trim($validated);
            }
        } else {
            $fallback = esc_url_raw($base);
            if (is_string($fallback) && trim($fallback) !== '') {
                $valid_url = trim($fallback);
            }
        }

        if ($valid_url === '') {
            return new WP_Error(
                'sync_bad_source_url',
                __('Некоректний URL Stage/Dev у налаштуваннях (Source URL).', 'sync-stage-to-prod'),
                ['status' => 400]
            );
        }

        $parsed = wp_parse_url($valid_url);
        if (!is_array($parsed)) {
            return new WP_Error(
                'sync_bad_source_url',
                __('Некоректний URL Stage/Dev у налаштуваннях (Source URL).', 'sync-stage-to-prod'),
                ['status' => 400]
            );
        }

        $scheme = isset($parsed['scheme']) && is_string($parsed['scheme']) ? strtolower(trim($parsed['scheme'])) : '';
        if (!in_array($scheme, ['http', 'https'], true)) {
            return new WP_Error(
                'sync_bad_source_scheme',
                __('Некоректний scheme у URL Stage/Dev (Source URL). Дозволено лише http/https.', 'sync-stage-to-prod'),
                ['status' => 400]
            );
        }

        if (isset($parsed['user']) || isset($parsed['pass'])) {
            return new WP_Error(
                'sync_bad_source_userinfo',
                __('URL Stage/Dev (Source URL) не повинен містити user:pass.', 'sync-stage-to-prod'),
                ['status' => 400]
            );
        }

        if (isset($parsed['port'])) {
            $port = (int) $parsed['port'];
            if (!in_array($port, [80, 443], true)) {
                return new WP_Error(
                    'sync_bad_source_port',
                    __('URL Stage/Dev (Source URL) містить заборонений порт. Дозволено лише 80/443.', 'sync-stage-to-prod'),
                    ['status' => 400]
                );
            }
        }

        $host = isset($parsed['host']) && is_string($parsed['host']) ? strtolower(trim($parsed['host'])) : '';
        if ($host === '') {
            return new WP_Error(
                'sync_bad_source_host',
                __('Некоректний host у URL Stage/Dev (Source URL).', 'sync-stage-to-prod'),
                ['status' => 400]
            );
        }

        if (!self::is_public_host($host)) {
            return new WP_Error(
                'sync_source_host_private',
                __('Host Stage/Dev (Source URL) резолвиться у private/reserved IP. Це заборонено.', 'sync-stage-to-prod'),
                ['status' => 400]
            );
        }
        return [
            'base' => $valid_url,
            'host' => $host,
        ];
    }
    /**
     * Check if hostname resolves to public IP addresses only.
     *
     * @param string $host Hostname to check.
     * @return bool True if all IPs are public.
     */
    private static function is_public_host($host)
    {
        if (!is_string($host) || trim($host) === '') {
            return false;
        }

        $host = trim($host);

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return self::is_public_ip($host);
        }

        $ips = [];

        if (function_exists('gethostbynamel')) {
            $a = gethostbynamel($host);
            if (is_array($a) && !empty($a)) {
                foreach ($a as $ip) {
                    if (is_string($ip) && $ip !== '') {
                        $ips[] = $ip;
                    }
                }
            }
        }

        if (function_exists('dns_get_record')) {
            $aaaa = @dns_get_record($host, DNS_AAAA);
            if (is_array($aaaa) && !empty($aaaa)) {
                foreach ($aaaa as $row) {
                    if (is_array($row) && isset($row['ipv6']) && is_string($row['ipv6']) && $row['ipv6'] !== '') {
                        $ips[] = $row['ipv6'];
                    }
                }
            }
        }

        $ips = array_values(array_unique($ips));
        if (empty($ips)) {
            return false;
        }

        foreach ($ips as $ip) {
            if (!self::is_public_ip($ip)) {
                return false;
            }
        }

        return true;
    }
    /**
     * Check if IP address is public (not private/reserved).
     *
     * @param string $ip IP address to check.
     * @return bool True if public IP.
     */
    private static function is_public_ip($ip)
    {
        if (!is_string($ip) || trim($ip) === '') {
            return false;
        }

        $ip = trim($ip);

        $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
        $valid = filter_var($ip, FILTER_VALIDATE_IP, $flags);

        return $valid !== false;
    }
    /**
     * Safely download file without following redirects.
     *
     * Security measure to prevent SSRF attacks.
     *
     * @param string $url     URL to download.
     * @param int    $timeout Download timeout in seconds.
     * @return string|WP_Error Path to downloaded file or error.
     */
    private static function safe_download_file_no_redirect($url, $timeout = 60)
    {
        if (!is_string($url) || trim($url) === '') {
            return new WP_Error('sync_bad_url', __('Некоректний URL для завантаження.', 'sync-stage-to-prod'));
        }

        $url = trim($url);

        if (!function_exists('wp_safe_remote_get')) {
            return new WP_Error('sync_no_http', __('HTTP функції недоступні.', 'sync-stage-to-prod'));
        }

        if (!function_exists('wp_tempnam')) {
            return new WP_Error('sync_no_tmp', __('Неможливо створити тимчасовий файл.', 'sync-stage-to-prod'));
        }

        $allowed = self::get_allowed_media_source_context();
        if (is_wp_error($allowed)) {
            return $allowed;
        }

        $parsed = wp_parse_url($url);
        if (!is_array($parsed)) {
            return new WP_Error('sync_bad_url', __('Некоректний URL для завантаження.', 'sync-stage-to-prod'));
        }

        $scheme = isset($parsed['scheme']) && is_string($parsed['scheme']) ? strtolower(trim($parsed['scheme'])) : '';
        if (!in_array($scheme, ['http', 'https'], true)) {
            return new WP_Error('sync_bad_scheme', __('Некоректний URL для завантаження (scheme).', 'sync-stage-to-prod'));
        }

        if (isset($parsed['user']) || isset($parsed['pass'])) {
            return new WP_Error('sync_bad_userinfo', __('URL для завантаження не повинен містити user:pass.', 'sync-stage-to-prod'));
        }

        $host = isset($parsed['host']) && is_string($parsed['host']) ? strtolower(trim($parsed['host'])) : '';
        if ($host === '') {
            return new WP_Error('sync_bad_host', __('Некоректний URL для завантаження (host).', 'sync-stage-to-prod'));
        }

        if ($host !== $allowed['host']) {
            return new WP_Error('sync_host_not_allowed', __('Host для завантаження не в allowlist.', 'sync-stage-to-prod'));
        }

        if (isset($parsed['port'])) {
            $port = (int) $parsed['port'];
            if (!in_array($port, [80, 443], true)) {
                return new WP_Error('sync_bad_port', __('URL для завантаження містить заборонений порт.', 'sync-stage-to-prod'));
            }
        }

        if (!self::is_public_host($host)) {
            return new WP_Error('sync_host_private', __('Host для завантаження резолвиться у private/reserved IP.', 'sync-stage-to-prod'));
        }

        $filename_seed = '';
        $path = wp_parse_url($url, PHP_URL_PATH);
        if (is_string($path) && $path !== '') {
            $filename_seed = wp_basename($path);
        }
        if ($filename_seed === '') {
            $filename_seed = 'sync-stp-download';
        }

        $tmp = wp_tempnam($filename_seed);
        if (!is_string($tmp) || $tmp === '') {
            return new WP_Error('sync_tmp_failed', __('Неможливо створити тимчасовий файл.', 'sync-stage-to-prod'));
        }

        $response = wp_safe_remote_get($url, [
            'timeout' => (int) $timeout,
            'stream' => true,
            'filename' => $tmp,
            'redirection' => 0,
            'reject_unsafe_urls' => true,
        ]);

        if (is_wp_error($response)) {
            @unlink($tmp);
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);

        if (in_array($code, [301, 302, 303, 307, 308], true)) {
            @unlink($tmp);
            return new WP_Error('sync_redirect_blocked', __('Редіректи заборонені під час завантаження.', 'sync-stage-to-prod'));
        }

        if ($code < 200 || $code >= 300) {
            @unlink($tmp);
            return new WP_Error(
                'sync_download_http',
                sprintf(__('Помилка завантаження: HTTP %d', 'sync-stage-to-prod'), $code)
            );
        }

        if (!file_exists($tmp)) {
            return new WP_Error('sync_tmp_missing', __('Тимчасовий файл не створено.', 'sync-stage-to-prod'));
        }

        $size = (int) filesize($tmp);
        if ($size <= 0) {
            @unlink($tmp);
            return new WP_Error('sync_download_empty', __('Завантажено порожній файл.', 'sync-stage-to-prod'));
        }

        return $tmp;
    }

    /**
     * Get unique request ID for tracking.
     *
     * @return string Request ID.
     */
    private static function get_rid()
    {
        if (self::$rid !== '') {
            return self::$rid;
        }

        $rid = '';
        if (function_exists('wp_generate_uuid4')) {
            $rid = (string) wp_generate_uuid4();
        }

        if ($rid === '') {
            $rid = (string) uniqid('sync_stp_', true);
        }

        self::$rid = $rid;

        return self::$rid;
    }
    /**
     * Remap relationship IDs in block data (legacy method).
     *
     * @deprecated Legacy implementation, kept for compatibility.
     */
    private static function remap_relationship_ids_in_block_data($data, $block_name = '', $post_slug = '', $depth = 0)
    {
        if ($depth > 12 || !is_array($data)) {
            return $data;
        }

        foreach ($data as $key => &$value) {
            if (is_array($value)) {
                if (self::is_numeric_array($value) && !empty($value)) {
                    $first = reset($value);

                    if (is_numeric($first)) {
                        $stage_ids = array_map('intval', $value);
                        $prod_ids = [];

                        foreach ($stage_ids as $stage_id) {
                            $post_type = null;

                            if (function_exists('acf_get_field')) {
                                $field_definition = acf_get_field($key);
                            } elseif (function_exists('get_field_object')) {
                                $field_definition = get_field_object($key, false, false);
                            } else {
                                $field_definition = null;
                            }

                            if (is_array($field_definition) && !empty($field_definition['post_type'])) {
                                $configured_post_type = $field_definition['post_type'];
                                if (is_string($configured_post_type) && $configured_post_type !== '') {
                                    $post_type = $configured_post_type;
                                } elseif (is_array($configured_post_type) && count($configured_post_type) === 1) {
                                    $single_post_type = reset($configured_post_type);
                                    if (is_string($single_post_type) && $single_post_type !== '') {
                                        $post_type = $single_post_type;
                                    }
                                }
                            }

                            $prod_id = self::find_production_id_by_stage_id($stage_id, $post_type);

                            if ($prod_id) {
                                $prod_ids[] = (string) $prod_id;
                            } else {
                                return new WP_Error(
                                    'sync_relationship_mapping_failed',
                                    sprintf(
                                        __('Не вдалося знайти пост на Production для зв\'язку. Stage ID: %d, тип: %s, поле: %s. Спочатку синхронізуйте залежні пости або синхронізуйте обидва типи постів одночасно (якщо є перехресні зв\'язки).', 'sync-stage-to-prod'),
                                        $stage_id,
                                        $post_type ?: 'any',
                                        $key
                                    ),
                                    [
                                        'stage_id' => $stage_id,
                                        'post_type' => $post_type,
                                        'field_key' => $key,
                                        'block_name' => $block_name,
                                    ]
                                );
                            }
                        }

                        if (!empty($prod_ids)) {
                            $value = $prod_ids;
                        }
                    } else {
                        $remapped = self::remap_relationship_ids_in_block_data($value, $block_name, $post_slug, $depth + 1);
                        if (is_wp_error($remapped)) {
                            return $remapped;
                        }
                        $value = $remapped;
                    }
                } else {
                    $remapped = self::remap_relationship_ids_in_block_data($value, $block_name, $post_slug, $depth + 1);
                    if (is_wp_error($remapped)) {
                        return $remapped;
                    }
                    $value = $remapped;
                }
            }
        }

        return $data;
    }

    /**
     * Find production post ID by stage post ID stored in meta.
     * If not found by meta, tries to find by querying /post-map endpoint data.
     *
     * @param int $stage_id Stage post ID
     * @param string|null $post_type Post type hint (optional)
     * @return int|null Production post ID or null if not found
     */
    private static function find_production_id_by_stage_id($stage_id, $post_type = null)
    {
        if ($stage_id <= 0) {
            return null;
        }
        $args = [
            'post_type' => $post_type ? $post_type : 'any',
            'post_status' => 'any',
            'posts_per_page' => 1,
            'meta_query' => [
                [
                    'key' => '_stage_post_id',
                    'value' => $stage_id,
                    'compare' => '=',
                ],
            ],
            'fields' => 'ids',
        ];

        $posts = get_posts($args);

        if (!empty($posts)) {
            return (int) $posts[0];
        }

        $cached_map = get_transient('sync_stp_temp_post_map');
        if (is_array($cached_map) && isset($cached_map[$stage_id])) {
            return (int) $cached_map[$stage_id];
        }

        return null;
    }

    /**
     * Check if array is numeric (sequential integer keys starting from 0).
     *
     * @param array $arr
     * @return bool
     */
    private static function is_numeric_array($arr)
    {
        if (!is_array($arr) || empty($arr)) {
            return false;
        }

        $keys = array_keys($arr);
        return $keys === range(0, count($arr) - 1);
    }
    /**
     * Sanitize log context by removing sensitive data.
     *
     * @param mixed $value Value to sanitize.
     * @param int   $depth Current recursion depth.
     * @return array Sanitized array.
     */
    private static function sanitize_log_context($value, $depth = 0)
    {
        if ($depth > 6) {
            return [];
        }

        if (is_object($value)) {
            $value = (array) $value;
        }

        if (!is_array($value)) {
            return [];
        }

        $out = [];

        foreach ($value as $k => $v) {
            $key = is_string($k) ? $k : '';
            if ($key === '') {
                continue;
            }

            $lower = strtolower($key);
            if (in_array($lower, ['auth_token', 'token', 'secret', 'password'], true)) {
                $out[$key] = '[redacted]';
                continue;
            }

            if (is_string($v)) {
                $s = wp_strip_all_tags($v);
                if (strlen($s) > 800) {
                    $s = substr($s, 0, 800) . '...';
                }
                $out[$key] = $s;
                continue;
            }

            if (is_int($v) || is_float($v) || is_bool($v) || is_null($v)) {
                $out[$key] = $v;
                continue;
            }

            if (is_array($v) || is_object($v)) {
                $out[$key] = self::sanitize_log_context($v, $depth + 1);
                continue;
            }

            $out[$key] = '[unserializable]';
        }

        return $out;
    }
}