<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Sync_Service
 *
 * Runs sync from Stage/Dev site to Prod site via REST endpoints.
 * Responsibilities:
 * - Collect posts/terms/options data (including ACF fields).
 * - Collect media IDs used across that data, sync media first, then remap attachment IDs.
 * - Send terms, posts (in chunks), and options to Prod.
 * - Track already-synced stage media IDs and detect deleted attachments to clean up on Prod.
 */
class Sync_Service
{
    /**
     * Post meta keys that must never be exported.
     *
     * @var string[]
     */
    private static $blocked_post_meta_keys = [
        '_edit_lock',
        '_edit_last',
        '_thumbnail_id',
        '_wp_attached_file',
        '_wp_attachment_metadata',
    ];

    /**
     * Term meta keys that must never be exported.
     *
     * @var string[]
     */
    private static $blocked_term_meta_keys = [
        '_edit_lock',
        '_edit_last',
    ];

    /**
     * Post types excluded from syncing (system/internal post types and ACF internals).
     *
     * @var string[]
     */
    private static $excluded_post_types = [
        'attachment',
        'revision',
        'nav_menu_item',
        'customize_changeset',
        'oembed_cache',
        'user_request',
        'wp_block',
        'wp_template',
        'wp_template_part',
        'wp_navigation',
        'wp_global_styles',
        'acf-field-group',
        'acf-field',
    ];

    /**
     * Allowed Yoast meta prefixes that start with "_" (private meta),
     * which we still want to export.
     *
     * @var string[]
     */
    private static $yoast_post_meta_prefixes = [
        '_yoast_wpseo_',
        'rank_math_',  // Rank Math SEO meta
    ];

    /**
     * Main sync runner (Stage -> Prod).
     *
     * Flow:
     * 1) Validate mode/options (must run only from Stage/Dev).
     * 2) Resolve and validate post types to sync.
     * 3) Build payloads: posts, terms (only used terms), and options.
     * 4) Collect all attachment IDs referenced in payloads, sync media first.
     * 5) Remap stage attachment IDs to prod attachment IDs in payloads.
     * 6) Send terms, posts (chunked), options.
     * 7) Persist synced media IDs and delete removed ones on Prod.
     *
     * @param mixed $post_types Array of post type slugs selected by user.
     * @return array|WP_Error Sync result or error.
     */
    public static function run_sync($post_types)
    {
        $options = get_option(SYNC_STP_OPT_KEY, []);

        // Determine current environment mode
        $mode = 'stage';
        if (isset($options['mode']) && $options['mode'] === 'prod') {
            $mode = 'prod';
        }

        // Only allow sync from stage/dev environment
        if ($mode !== 'stage') {
            return new WP_Error(
                'sync_wrong_mode',
                __('Синхронізацію можна запускати тільки в режимі Stage/Dev.', 'sync-stage-to-prod')
            );
        }

        // Validate target production URL
        $target_url = '';
        if (isset($options['target_url']) && is_string($options['target_url'])) {
            $target_url = trim($options['target_url']);
        }

        if ($target_url === '') {
            return new WP_Error(
                'sync_no_url',
                __('Не вказано URL прод-сайта.', 'sync-stage-to-prod')
            );
        }

        // Validate authentication token
        $auth_token = '';
        if (isset($options['auth_token']) && is_string($options['auth_token'])) {
            $auth_token = trim($options['auth_token']);
        }

        if ($auth_token === '') {
            return new WP_Error(
                'sync_no_token',
                __('Не вказано секретний токен.', 'sync-stage-to-prod')
            );
        }

        // Build API endpoint URLs
        $endpoint_path = '/wp-json/sync-stp/v1';
        if (isset($options['endpoint_path']) && is_string($options['endpoint_path'])) {
            $endpoint_path = Sync_Validator::validate_endpoint_path($options['endpoint_path']);
        }

        $target_base = rtrim($target_url, '/');
        $endpoint_base = $target_base . rtrim($endpoint_path, '/');

        // Resolve and validate post types for sync
        $resolved_post_types = self::resolve_post_types($post_types);
        if (empty($resolved_post_types)) {
            return new WP_Error(
                'sync_no_post_types',
                __('Не вибрано жодного типу записів для синхронізації.', 'sync-stage-to-prod')
            );
        }

        // Build posts data for selected post types
        $posts_data = self::build_posts_data($resolved_post_types);
        if (empty($posts_data)) {
            return new WP_Error(
                'sync_no_posts',
                __('Немає записів для синхронізації.', 'sync-stage-to-prod')
            );
        }

        // Include post types referenced in ACF relationships to syncing list
        $all_relationship_types = [];
        foreach ($posts_data as $post_data) {
            $relationships = self::collect_relationship_references([$post_data]);
            foreach ($relationships as $rel) {
                if (!empty($rel['post_type']) && !in_array($rel['post_type'], $resolved_post_types, true)) {
                    $all_relationship_types[] = $rel['post_type'];
                }
            }
        }
        $all_relationship_types = array_unique($all_relationship_types);
        if (!empty($all_relationship_types)) {
            $resolved_post_types = array_merge($resolved_post_types, $all_relationship_types);
            $resolved_post_types = array_unique($resolved_post_types);
            // Rebuild posts data with additional relationship types
            $posts_data = self::build_posts_data($resolved_post_types);
        }

        // Validate that all ACF relationship references exist on production
        $relationship_check = self::validate_relationships($endpoint_base, $auth_token, $posts_data);
        if (is_wp_error($relationship_check)) {
            return $relationship_check;
        }

        // Collect taxonomies used by the selected post types and extract only actually used terms
        $taxonomies = [];
        foreach ($resolved_post_types as $pt) {
            $tax = get_object_taxonomies($pt);
            if (is_array($tax)) {
                $taxonomies = array_merge($taxonomies, $tax);
            }
        }
        $taxonomies = array_values(array_unique($taxonomies));

        $used_term_slugs = self::extract_used_term_slugs($posts_data);
        $terms_data = self::build_terms_data($taxonomies, $used_term_slugs);
        $options_data = self::build_options_data($resolved_post_types);

        // Sync media first (attachments referenced by ACF/meta/featured/content),
        // then remap all stage IDs to the returned prod IDs in payloads
        $media_ids = self::collect_media_ids($terms_data, $posts_data, $options_data);
        $media_map = [];
        $media_sent_total = 0;
        $media_created_total = 0;
        $media_updated_total = 0;
        $media_deleted_total = 0;

        if (!empty($media_ids)) {
            $media_sync = self::sync_media($endpoint_base, $auth_token, $media_ids);

            if (is_wp_error($media_sync)) {
                return $media_sync;
            }

            $media_map = isset($media_sync['map']) && is_array($media_sync['map']) ? $media_sync['map'] : [];
            $media_sent_total = isset($media_sync['sent']) ? (int) $media_sync['sent'] : 0;
            $media_created_total = isset($media_sync['created']) ? (int) $media_sync['created'] : 0;
            $media_updated_total = isset($media_sync['updated']) ? (int) $media_sync['updated'] : 0;
        }

        // Detect attachments that were previously synced but are now deleted on stage
        // and trigger deletion on production side
        $deleted_stage_ids = self::get_deleted_stage_media_ids($media_ids);
        if (!empty($deleted_stage_ids)) {
            $deleted_resp = self::sync_deleted_media($endpoint_base, $auth_token, $deleted_stage_ids);
            if (is_wp_error($deleted_resp)) {
                return $deleted_resp;
            }
            $media_deleted_total = isset($deleted_resp['deleted']) ? (int) $deleted_resp['deleted'] : 0;
        }

        // Remap stage media IDs to production IDs in all payloads
        if (!empty($media_map)) {
            $terms_data = self::remap_terms_data($terms_data, $media_map);
            $posts_data = self::remap_posts_data($posts_data, $media_map);
            $options_data = self::remap_options_data($options_data, $media_map);
        }

        // Remap post IDs for relationships to non-syncing post types
        // This allows syncing posts with relationships to existing production posts
        $post_ids = self::collect_external_post_ids($posts_data, $resolved_post_types);
        $post_map = [];

        if (!empty($post_ids)) {
            $post_map = self::fetch_production_post_map($endpoint_base, $auth_token, $post_ids);

            if (is_wp_error($post_map)) {
                return $post_map;
            }

            if (!empty($post_map)) {
                $posts_data = self::remap_post_relationships($posts_data, $post_map);
            }
        }

        // Send terms payload to production
        $terms_sent_total = 0;
        $terms_created_total = 0;
        $terms_updated_total = 0;

        if (!empty($terms_data)) {
            $tax_url = $endpoint_base . '/taxonomies';
            $tax_payload = [
                'terms' => $terms_data,
            ];

            $tax_response = self::send_to_remote($tax_url, $auth_token, $tax_payload);

            if (!$tax_response['success']) {
                return new WP_Error('sync_tax_failed', $tax_response['message']);
            }

            if (isset($tax_response['data']) && is_array($tax_response['data'])) {
                $tax_data = $tax_response['data'];
                if (isset($tax_data['sent'])) {
                    $terms_sent_total += (int) $tax_data['sent'];
                }
                if (isset($tax_data['created'])) {
                    $terms_created_total += (int) $tax_data['created'];
                }
                if (isset($tax_data['updated'])) {
                    $terms_updated_total += (int) $tax_data['updated'];
                }
            }
        }

        // Separate relationships to syncing post types for two-stage sync
        // Stage 1: Create posts without cross-references to other syncing types (e.g., team ↔ clinic)
        // Stage 2: Update posts with relationships after all syncing types exist on production
        $posts_with_cross_refs = [];
        
        foreach ($posts_data as $index => $post_data) {
            $cross_refs = self::extract_and_remove_syncing_type_relationships($posts_data[$index], $resolved_post_types);
            if (!empty($cross_refs)) {
                $posts_with_cross_refs[$index] = [
                    'post_name' => $post_data['slug'],
                    'post_type' => $post_data['post_type'],
                    'relationships' => $cross_refs,
                ];
            }
        }

        // Send posts payload in smaller chunks to reduce request size and avoid timeouts
        $total_sent = 0;
        $total_created = 0;
        $total_updated = 0;

        $chunks = array_chunk($posts_data, 50);

        foreach ($chunks as $chunk) {
            $posts_url = $endpoint_base . '/posts';
            $posts_payload = [
                'posts' => $chunk,
                'is_batch' => count($resolved_post_types) > 1, // Batch if multiple types
            ];

            $posts_response = self::send_to_remote($posts_url, $auth_token, $posts_payload);

            if (!$posts_response['success']) {
                return new WP_Error('sync_posts_failed', $posts_response['message']);
            }

            if (isset($posts_response['data']) && is_array($posts_response['data'])) {
                $data = $posts_response['data'];
                if (isset($data['sent'])) {
                    $total_sent += (int) $data['sent'];
                }
                if (isset($data['created'])) {
                    $total_created += (int) $data['created'];
                }
                if (isset($data['updated'])) {
                    $total_updated += (int) $data['updated'];
                }
            }
        }

        // Stage 2: Update posts with cross-type relationships
        // Now all syncing post types exist on production, so we can safely add cross-references
        if (!empty($posts_with_cross_refs)) {
            $relationship_chunks = array_chunk(array_values($posts_with_cross_refs), 20);
            foreach ($relationship_chunks as $rel_chunk) {
                $updates = [];
                foreach ($rel_chunk as $post_update) {
                    $updates[] = [
                        'post_name' => $post_update['post_name'],
                        'post_type' => $post_update['post_type'],
                        'relationships' => $post_update['relationships'],
                    ];
                }
                $rel_url = $endpoint_base . '/update-relationships';
                $rel_payload = [
                    'updates' => $updates,
                ];
                
                $rel_response = self::send_to_remote($rel_url, $auth_token, $rel_payload);
                if (!$rel_response['success']) {
                    // Relationship update failed, but continue with sync
                    continue;
                }
            }
        }

        // Send options payload (ACF options + archive settings)
        $options_groups_updated = 0;
        $options_fields_updated = 0;

        if (!empty($options_data)) {
            $options_response = self::send_options($target_base, $endpoint_path, $auth_token, $options_data);

            if (is_wp_error($options_response)) {
                return new WP_Error('sync_options_failed', $options_response->get_error_message());
            }

            if (is_array($options_response)) {
                if (isset($options_response['groups_updated'])) {
                    $options_groups_updated = (int) $options_response['groups_updated'];
                }
                if (isset($options_response['fields_updated'])) {
                    $options_fields_updated = (int) $options_response['fields_updated'];
                }
            }
        }

        // Track stage media IDs that were synced at least once
        // This allows detecting later deletions on stage
        self::persist_synced_media_ids($media_ids);

        return [
            'sent' => $total_sent,
            'created' => $total_created,
            'updated' => $total_updated,
            'posts' => [
                'sent' => $total_sent,
                'created' => $total_created,
                'updated' => $total_updated,
            ],
            'terms' => [
                'sent' => $terms_sent_total,
                'created' => $terms_created_total,
                'updated' => $terms_updated_total,
            ],
            'options' => [
                'groups_updated' => $options_groups_updated,
                'fields_updated' => $options_fields_updated,
            ],
            'media' => [
                'sent' => $media_sent_total,
                'created' => $media_created_total,
                'updated' => $media_updated_total,
                'deleted' => $media_deleted_total,
            ],
        ];
    }

    /**
     * Sync a single post to production.
     *
     * This method handles synchronization of an individual post including:
     * - Validation of post ID and type
     * - Syncing related terms and media
     * - Two-stage sync (post creation, then relationship updates)
     *
     * @param int $post_id Post ID to sync.
     * @return array|WP_Error Sync result or error.
     */
    public static function sync_single_post($post_id)
    {
        $post_id = (int) $post_id;
        
        if ($post_id <= 0) {
            return new WP_Error(
                'sync_invalid_post_id',
                __('Невірний ID поста.', 'sync-stage-to-prod')
            );
        }

        $post = get_post($post_id);
        
        if (!$post) {
            return new WP_Error(
                'sync_post_not_found',
                __('Пост не знайдено.', 'sync-stage-to-prod')
            );
        }

        $post_type = $post->post_type;

        // Prevent syncing excluded post types (e.g., attachments, revisions)
        if (in_array($post_type, self::$excluded_post_types, true)) {
            return new WP_Error(
                'sync_excluded_post_type',
                __('Цей тип поста не можна синхронізувати.', 'sync-stage-to-prod')
            );
        }

        $options = get_option(SYNC_STP_OPT_KEY, []);

        // Determine current environment mode
        $mode = 'stage';
        if (isset($options['mode']) && $options['mode'] === 'prod') {
            $mode = 'prod';
        }

        // Only allow sync from stage/dev environment
        if ($mode !== 'stage') {
            return new WP_Error(
                'sync_wrong_mode',
                __('Синхронізацію можна запускати тільки в режимі Stage/Dev.', 'sync-stage-to-prod')
            );
        }

        // Validate target production URL
        $target_url = '';
        if (isset($options['target_url']) && is_string($options['target_url'])) {
            $target_url = trim($options['target_url']);
        }

        if ($target_url === '') {
            return new WP_Error(
                'sync_no_url',
                __('Не вказано URL прод-сайта.', 'sync-stage-to-prod')
            );
        }

        // Validate authentication token
        $auth_token = '';
        if (isset($options['auth_token']) && is_string($options['auth_token'])) {
            $auth_token = trim($options['auth_token']);
        }

        if ($auth_token === '') {
            return new WP_Error(
                'sync_no_token',
                __('Не вказано секретний токен.', 'sync-stage-to-prod')
            );
        }

        // Build API endpoint URLs
        $endpoint_path = '/wp-json/sync-stp/v1';
        if (isset($options['endpoint_path']) && is_string($options['endpoint_path'])) {
            $endpoint_path = Sync_Validator::validate_endpoint_path($options['endpoint_path']);
        }

        $target_base = rtrim($target_url, '/');
        $endpoint_base = $target_base . rtrim($endpoint_path, '/');

        // Build single post data payload
        $posts_data = self::build_single_post_data($post_id);

        if (empty($posts_data)) {
            return new WP_Error(
                'sync_no_data',
                __('Не вдалося зібрати дані поста.', 'sync-stage-to-prod')
            );
        }

        // Collect taxonomy terms actually used by this post
        $used_term_slugs = self::extract_used_term_slugs($posts_data);
        
        $taxonomies = get_object_taxonomies($post_type);
        $taxonomies = is_array($taxonomies) ? $taxonomies : [];
        
        $terms_data = self::build_terms_data($taxonomies, $used_term_slugs);

        // Sync media files and get ID mapping (stage ID → prod ID)
        $media_ids = self::collect_media_ids($terms_data, $posts_data, []);
        $media_map = [];
        $media_sent_total = 0;
        $media_created_total = 0;
        $media_updated_total = 0;

        if (!empty($media_ids)) {
            $media_sync = self::sync_media($endpoint_base, $auth_token, $media_ids);

            if (is_wp_error($media_sync)) {
                return $media_sync;
            }

            $media_map = isset($media_sync['map']) && is_array($media_sync['map']) ? $media_sync['map'] : [];
            $media_sent_total = isset($media_sync['sent']) ? (int) $media_sync['sent'] : 0;
            $media_created_total = isset($media_sync['created']) ? (int) $media_sync['created'] : 0;
            $media_updated_total = isset($media_sync['updated']) ? (int) $media_sync['updated'] : 0;
        }

        // Remap stage media IDs to production IDs in payloads
        if (!empty($media_map)) {
            $terms_data = self::remap_terms_data($terms_data, $media_map);
            $posts_data = self::remap_posts_data($posts_data, $media_map);
        }

        // Extract all ACF relationships for two-stage sync
        // Stage 1: Create post without relationships
        // Stage 2: Update relationships after post exists on production
        $posts_with_cross_refs = [];
        foreach ($posts_data as $index => $post_data) {
            $cross_refs = self::extract_and_remove_syncing_type_relationships($posts_data[$index], null);
            if (!empty($cross_refs)) {
                $posts_with_cross_refs[$index] = [
                    'post_name' => $post_data['slug'],
                    'post_type' => $post_data['post_type'],
                    'relationships' => $cross_refs,
                ];
            }
        }

        // Sync taxonomy terms
        $terms_sent = 0;
        $terms_created = 0;
        $terms_updated = 0;

        if (!empty($terms_data)) {
            $tax_url = $endpoint_base . '/taxonomies';
            $tax_payload = ['terms' => $terms_data];
            $tax_response = self::send_to_remote($tax_url, $auth_token, $tax_payload);

            if (!$tax_response['success']) {
                return new WP_Error('sync_tax_failed', $tax_response['message']);
            }

            if (isset($tax_response['data']) && is_array($tax_response['data'])) {
                $tax_data = $tax_response['data'];
                $terms_sent = isset($tax_data['sent']) ? (int) $tax_data['sent'] : 0;
                $terms_created = isset($tax_data['created']) ? (int) $tax_data['created'] : 0;
                $terms_updated = isset($tax_data['updated']) ? (int) $tax_data['updated'] : 0;
            }
        }

        // Send post data (single sync with strict validation)
        $posts_url = $endpoint_base . '/posts';
        $posts_payload = [
            'posts' => $posts_data,
            'is_batch' => false, // Single post sync - strict validation enabled
        ];
        $posts_response = self::send_to_remote($posts_url, $auth_token, $posts_payload);

        if (!$posts_response['success']) {
            return new WP_Error('sync_posts_failed', $posts_response['message']);
        }

        $posts_sent = 0;
        $posts_created = 0;
        $posts_updated = 0;

        if (isset($posts_response['data']) && is_array($posts_response['data'])) {
            $data = $posts_response['data'];
            $posts_sent = isset($data['sent']) ? (int) $data['sent'] : 0;
            $posts_created = isset($data['created']) ? (int) $data['created'] : 0;
            $posts_updated = isset($data['updated']) ? (int) $data['updated'] : 0;
        }

        // Track synced media IDs for future deletion detection
        if (!empty($media_ids)) {
            self::persist_synced_media_ids($media_ids);
        }
        
        // Stage 2: Update ACF relationships after post is created on production
        if (!empty($posts_with_cross_refs)) {
            $rel_url = $endpoint_base . '/update-relationships';
            $rel_payload = [
                'updates' => $posts_with_cross_refs,
            ];

            $rel_response = self::send_to_remote($rel_url, $auth_token, $rel_payload);

            if (!$rel_response['success']) {
                // Relationship update failed, but don't fail the entire sync
            }
        }

        return [
            'success' => true,
            'message' => sprintf(
                __('Пост "%s" успішно синхронізовано.', 'sync-stage-to-prod'),
                $post->post_title
            ),
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
            'media' => [
                'sent' => $media_sent_total,
                'created' => $media_created_total,
                'updated' => $media_updated_total,
            ],
        ];
    }
    /**
     * Build post data for a single post.
     *
     * Reads post data directly from database to bypass WordPress caches and filters.
     * Includes post meta, ACF fields, featured image, and taxonomy terms.
     *
     * @param int $post_id Post ID.
     * @return array Post data array wrapped in array for consistency with bulk methods.
     */
    private static function build_single_post_data($post_id)
    {
        global $wpdb;
        
        // Read post directly from DB to bypass all caches and filters
        $post_data_raw = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->posts} WHERE ID = %d",
            $post_id
        ));
        
        if (!$post_data_raw) {
            return [];
        }

        // Use raw data directly without creating WP_Post object
        // to avoid WordPress filters that may modify post_content
        $post_data = [
            'id' => $post_id,
            'post_type' => $post_data_raw->post_type,
            'slug' => $post_data_raw->post_name,
            'title' => $post_data_raw->post_title,
            'content' => $post_data_raw->post_content,
            'excerpt' => $post_data_raw->post_excerpt,
            'status' => $post_data_raw->post_status,
            'author_login' => get_the_author_meta('user_login', $post_data_raw->post_author),
        ];

        // Add featured image ID if exists
        $thumbnail_id = get_post_thumbnail_id($post_id);
        if ($thumbnail_id) {
            $post_data['featured_image'] = (int) $thumbnail_id;
        }

        // Collect post meta (filtered by allowed keys)
        $all_meta = get_post_meta($post_id);
        $meta = [];
        
        if (is_array($all_meta)) {
            foreach ($all_meta as $key => $values) {
                if (!self::should_export_post_meta_key($key)) {
                    continue;
                }
                
                if (is_array($values) && count($values) === 1) {
                    $meta[$key] = $values[0];
                } else {
                    $meta[$key] = $values;
                }
            }
        }
        
        if (!empty($meta)) {
            $post_data['meta'] = $meta;
        }

        // Collect ACF fields if ACF is active
        if (function_exists('get_fields')) {
            $acf_fields = get_fields($post_id, false);
            if (is_array($acf_fields) && !empty($acf_fields)) {
                $post_data['acf'] = $acf_fields;
            }
        }

        // Collect taxonomy terms assigned to this post
        $taxonomies = get_object_taxonomies($post_data_raw->post_type);
        $tax_input = [];
        
        if (is_array($taxonomies)) {
            foreach ($taxonomies as $taxonomy) {
                $terms = wp_get_object_terms($post_id, $taxonomy, ['fields' => 'slugs']);
                if (!is_wp_error($terms) && !empty($terms)) {
                    $tax_input[$taxonomy] = $terms;
                }
            }
        }
        
        if (!empty($tax_input)) {
            $post_data['tax_input'] = $tax_input;
        }

        return [$post_data];
    }

    /**
     * Sanitize and validate post type list.
     *
     * Filters out excluded/system post types (attachments, revisions, etc.)
     * and validates that post types exist and are syncable.
     *
     * @param mixed $post_types Raw post type input (array or single value).
     * @return string[] Array of valid, sanitized post type slugs.
     */
    private static function resolve_post_types($post_types)
    {
        // Normalize input to array of sanitized keys
        $input = [];
        if (is_array($post_types)) {
            foreach ($post_types as $pt) {
                if (!is_string($pt)) {
                    continue;
                }
                $k = sanitize_key($pt);
                if ($k !== '') {
                    $input[] = $k;
                }
            }
        }

        $input = array_values(array_unique($input));

        // Filter out excluded and invalid post types
        $out = [];
        foreach ($input as $pt) {
            if (!is_string($pt) || $pt === '') {
                continue;
            }
            $pt = sanitize_key($pt);
            if ($pt === '') {
                continue;
            }
            // Skip excluded post types (attachments, revisions, etc.)
            if (in_array($pt, self::$excluded_post_types, true)) {
                continue;
            }
            // Validate post type exists and is registered
            if (!Sync_Validator::is_valid_post_type($pt)) {
                continue;
            }
            $out[] = $pt;
        }

        return array_values(array_unique($out));
    }

    /**
     * Extract currently used term slugs from exported posts (tax_input).
     * Output format:
     * [
     *   'taxonomy_name' => ['slug-1', 'slug-2', ...],
     * ]
     *
     * @param mixed $posts_data
     * @return array<string,string[]>
     */
    private static function extract_used_term_slugs($posts_data)
    {
        $map = [];

        if (!is_array($posts_data) || empty($posts_data)) {
            return $map;
        }

        foreach ($posts_data as $p) {
            if (!is_array($p) || empty($p['tax_input']) || !is_array($p['tax_input'])) {
                continue;
            }

            foreach ($p['tax_input'] as $tax => $slugs) {
                $tax = sanitize_key((string) $tax);
                if ($tax === '' || !is_array($slugs)) {
                    continue;
                }

                foreach ($slugs as $slug) {
                    if (!is_string($slug)) {
                        continue;
                    }
                    $s = sanitize_title($slug);
                    if ($s !== '') {
                        if (!isset($map[$tax])) {
                            $map[$tax] = [];
                        }
                        $map[$tax][$s] = true;
                    }
                }
            }
        }

        $out = [];
        foreach ($map as $tax => $set) {
            $out[$tax] = array_values(array_unique(array_keys($set)));
        }

        return $out;
    }

    /**
     * Build terms payload for the given taxonomies but only for slugs that are actually used.
     * Exports:
     * - taxonomy, slug, name
     * - term meta (filtered)
     * - ACF fields (if ACF is available)
     *
     * @param mixed $taxonomies
     * @param mixed $used_term_slugs
     * @return array<int,array<string,mixed>>
     */
    private static function build_terms_data($taxonomies, $used_term_slugs)
    {
        $result = [];

        if (empty($taxonomies) || !is_array($taxonomies)) {
            return $result;
        }

        $used = is_array($used_term_slugs) ? $used_term_slugs : [];

        foreach ($taxonomies as $taxonomy) {
            if (!is_string($taxonomy)) {
                continue;
            }

            $taxonomy = sanitize_key($taxonomy);

            if ($taxonomy === '' || !taxonomy_exists($taxonomy)) {
                continue;
            }

            if (empty($used[$taxonomy]) || !is_array($used[$taxonomy])) {
                continue;
            }

            $slugs = array_values(array_unique(array_map('sanitize_title', $used[$taxonomy])));
            $slugs = array_values(array_filter($slugs, static function ($s) {
                return is_string($s) && $s !== '';
            }));

            if (empty($slugs)) {
                continue;
            }

            $slug_chunks = array_chunk($slugs, 200);

            foreach ($slug_chunks as $slug_chunk) {
                $terms = get_terms([
                    'taxonomy' => $taxonomy,
                    'hide_empty' => false,
                    'slug' => $slug_chunk,
                ]);

                if (is_wp_error($terms) || empty($terms)) {
                    continue;
                }

                foreach ($terms as $term) {
                    $item = [
                        'taxonomy' => $term->taxonomy,
                        'slug' => $term->slug,
                        'name' => $term->name,
                        'meta' => [],
                        'acf' => [],
                    ];

                    $meta = get_term_meta($term->term_id);
                    if (is_array($meta)) {
                        foreach ($meta as $key => $values) {
                            if (!is_string($key)) {
                                continue;
                            }
                            if (in_array($key, self::$blocked_term_meta_keys, true)) {
                                continue;
                            }
                            if (strpos($key, '_') === 0) {
                                continue;
                            }
                            $item['meta'][$key] = isset($values[0]) ? maybe_unserialize($values[0]) : '';
                        }
                    }

                    if (function_exists('get_fields')) {
                        $acf = get_fields('term_' . $term->term_id,false);
                        if (is_array($acf) && !empty($acf)) {
                            $item['acf'] = $acf;
                        }
                    }

                    $result[] = $item;
                }
            }
        }

        return $result;
    }

    /**
     * Decide if a post meta key should be exported.
     *
     * Rules:
     * - blocked keys are never exported
     * - keys starting with "_" are private: export only if they match an allowed Yoast prefix
     * - any other meta keys are exported
     *
     * @param mixed $key
     * @return bool
     */
    private static function should_export_post_meta_key($key)
    {
        if (!is_string($key) || $key === '') {
            return false;
        }

        if (in_array($key, self::$blocked_post_meta_keys, true)) {
            return false;
        }

        if (strpos($key, '_') === 0) {
            foreach (self::$yoast_post_meta_prefixes as $prefix) {
                if ($prefix !== '' && strpos($key, $prefix) === 0) {
                    return true;
                }
            }

            return false;
        }

        return true;
    }

    /**
     * Build posts payload for all selected post types.
     * Exports:
     * - post basics (type, slug, title, content, excerpt, status)
     * - featured image attachment ID
     * - post meta (filtered)
     * - ACF fields (if ACF is available)
     * - taxonomy terms as slugs (tax_input)
     *
     * @param mixed $post_types
     * @return array<int,array<string,mixed>>
     */
    private static function build_posts_data($post_types)
    {
        $result = [];

        if (empty($post_types) || !is_array($post_types)) {
            return $result;
        }

        foreach ($post_types as $post_type) {
            if (!is_string($post_type)) {
                continue;
            }

            $post_type = sanitize_key($post_type);

            if ($post_type === '' || !post_type_exists($post_type)) {
                continue;
            }

            $posts = get_posts([
                'post_type' => $post_type,
                'posts_per_page' => -1,
                'post_status' => 'any',
                'orderby' => 'ID',
                'order' => 'ASC',
            ]);

            if (empty($posts)) {
                continue;
            }

            foreach ($posts as $post) {
                $featured = (int) get_post_thumbnail_id($post->ID);

                $item = [
                    'id' => $post->ID, // Stage post ID for relationship mapping
                    'post_type' => $post->post_type,
                    'slug' => $post->post_name,
                    'title' => $post->post_title,
                    'content' => $post->post_content,
                    'excerpt' => $post->post_excerpt,
                    'status' => $post->post_status,
                    'author_login' => get_the_author_meta('user_login', $post->post_author),
                    'featured_image' => $featured > 0 ? $featured : 0,
                    'meta' => [],
                    'acf' => [],
                    'tax_input' => [],
                ];

                $meta = get_post_meta($post->ID);
                if (is_array($meta)) {
                    foreach ($meta as $key => $values) {
                        if (!is_string($key)) {
                            continue;
                        }
                        if (!self::should_export_post_meta_key($key)) {
                            continue;
                        }
                        $item['meta'][$key] = isset($values[0]) ? maybe_unserialize($values[0]) : '';
                    }
                }

                if (function_exists('get_fields')) {
                    $acf = get_fields($post->ID, false);
                    if (is_array($acf) && !empty($acf)) {
                        $item['acf'] = $acf;
                    }
                }

                $taxonomies = get_object_taxonomies($post->post_type);
                if (is_array($taxonomies)) {
                    foreach ($taxonomies as $taxonomy) {
                        if (!is_string($taxonomy)) {
                            continue;
                        }
                        $terms = wp_get_object_terms($post->ID, $taxonomy, ['fields' => 'slugs']);
                        if (!is_wp_error($terms) && !empty($terms)) {
                            $item['tax_input'][sanitize_key($taxonomy)] = $terms;
                        }
                    }
                }

                $result[] = $item;
            }
        }

        return $result;
    }

    /**
     * Build options payload:
     * - ACF options page fields ("options" or "acf-options")
     * - Archive pages/settings fields per post type based on common slug patterns
     *
     * @param mixed $post_types
     * @return array<string,mixed>
     */
    private static function build_options_data($post_types)
    {
        $result = [];

        if (!function_exists('get_fields')) {
            return $result;
        }

        $possible_main_slugs = [
            'options',
            'acf-options',
        ];

        $page_settings = [];
        $found_main_slug = '';

        foreach ($possible_main_slugs as $slug) {
            $fields = get_fields($slug);
            if (is_array($fields) && !empty($fields)) {
                $page_settings = $fields;
                $found_main_slug = $slug;
                break;
            }
        }

        if (!empty($page_settings) && $found_main_slug !== '') {
            $result['page_settings'] = [
                'menu_slug' => $found_main_slug,
                'fields' => $page_settings,
            ];
        }

        $archives = [];

        if (is_array($post_types)) {
            foreach ($post_types as $post_type) {
                if (!is_string($post_type)) {
                    continue;
                }

                $post_type = sanitize_key($post_type);

                if ($post_type === '' || !post_type_exists($post_type)) {
                    continue;
                }

                $possible_slugs = [
                    $post_type . '-archive',
                    'acf-options-' . $post_type . '-archive',
                    $post_type . '-settings',
                    'options-' . $post_type,
                    'post_type_archive_' . $post_type,
                ];

                $archive_fields = [];
                $found_slug = '';

                foreach ($possible_slugs as $slug) {
                    $fields = get_fields($slug);
                    if (is_array($fields) && !empty($fields)) {
                        $archive_fields = $fields;
                        $found_slug = $slug;
                        break;
                    }
                }

                if (!empty($archive_fields) && $found_slug !== '') {
                    $archives[$post_type] = [
                        'menu_slug' => $found_slug,
                        'fields' => $archive_fields,
                    ];
                }
            }
        }

        if (!empty($archives)) {
            $result['archives'] = $archives;
        }

        return $result;
    }

    /**
     * Collect all attachment IDs referenced in:
     * - Terms payload (ACF + meta)
     * - Posts payload (featured image + ACF + meta + content)
     * - Options payload (ACF)
     *
     * Then filter to only existing attachment posts.
     *
     * @param mixed $terms_data
     * @param mixed $posts_data
     * @param mixed $options_data
     * @return int[]
     */
    private static function collect_media_ids($terms_data, $posts_data, $options_data)
    {
        // Determine which post types are in posts_data
        $post_types = [];
        if (is_array($posts_data)) {
            foreach ($posts_data as $p) {
                if (is_array($p) && !empty($p['post_type'])) {
                    $post_types[] = $p['post_type'];
                }
            }
        }
        $post_types = array_values(array_unique($post_types));
        
        // Use Sync_Media to fully collect all media IDs
        // including Gutenberg blocks, ACF blocks, featured images, etc.
        if (class_exists('Sync_Media') && method_exists('Sync_Media', 'collect_ids_for_sync')) {
            $collected = Sync_Media::collect_ids_for_sync($post_types);
            return $collected;
        }
        
        // Fallback to old logic if Sync_Media is unavailable
        $ids = [];

        if (is_array($terms_data)) {
            foreach ($terms_data as $t) {
                if (!is_array($t)) {
                    continue;
                }
                if (isset($t['acf'])) {
                    $ids = array_merge($ids, self::extract_attachment_ids($t['acf']));
                }
                if (isset($t['meta'])) {
                    $ids = array_merge($ids, self::extract_attachment_ids($t['meta']));
                }
            }
        }

        if (is_array($posts_data)) {
            foreach ($posts_data as $p) {
                if (!is_array($p)) {
                    continue;
                }
                if (!empty($p['featured_image'])) {
                    $ids[] = (int) $p['featured_image'];
                }
                if (isset($p['acf'])) {
                    $ids = array_merge($ids, self::extract_attachment_ids($p['acf']));
                }
                if (isset($p['meta'])) {
                    $ids = array_merge($ids, self::extract_attachment_ids($p['meta']));
                }
                if (isset($p['content']) && is_string($p['content'])) {
                    $ids = array_merge($ids, self::extract_attachment_ids_from_content($p['content']));
                }
            }
        }

        if (is_array($options_data)) {
            if (isset($options_data['page_settings']['fields'])) {
                $ids = array_merge($ids, self::extract_attachment_ids($options_data['page_settings']['fields']));
            }
            if (isset($options_data['archives']) && is_array($options_data['archives'])) {
                foreach ($options_data['archives'] as $archive) {
                    if (is_array($archive) && isset($archive['fields'])) {
                        $ids = array_merge($ids, self::extract_attachment_ids($archive['fields']));
                    }
                }
            }
        }

        $out = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id <= 0) {
                continue;
            }
            $p = get_post($id);
            if (!$p || $p->post_type !== 'attachment') {
                continue;
            }
            $out[] = $id;
        }

        return array_values(array_unique($out));
    }

    /**
     * Recursively extract attachment IDs from an arbitrary value.
     * Handles:
     * - integers
     * - numeric strings
     * - arrays/objects (including keys "ID"/"id")
     *
     * @param mixed $value
     * @param int $depth
     * @return int[]
     */
    private static function extract_attachment_ids($value, $depth = 0)
    {
        if ($depth > 12) {
            return [];
        }

        $ids = [];

        if (is_int($value)) {
            $ids[] = $value;
            return $ids;
        }

        if (is_string($value) && ctype_digit($value)) {
            $ids[] = (int) $value;
            return $ids;
        }

        if (is_array($value)) {
            if (isset($value['ID']) && (is_int($value['ID']) || (is_string($value['ID']) && ctype_digit($value['ID'])))) {
                $ids[] = (int) $value['ID'];
            }
            if (isset($value['id']) && (is_int($value['id']) || (is_string($value['id']) && ctype_digit($value['id'])))) {
                $ids[] = (int) $value['id'];
            }

            foreach ($value as $k => $v) {
                $ids = array_merge($ids, self::extract_attachment_ids($v, $depth + 1));
            }

            return $ids;
        }

        if (is_object($value)) {
            return self::extract_attachment_ids((array) $value, $depth + 1);
        }

        return $ids;
    }

    /**
     * Extract attachment IDs from post content by scanning common patterns:
     * - Gutenberg JSON: "id":123
     * - Classic editor: wp-image-123
     * - HTML data attributes: data-id="123"
     * - Some block attrs: attachment_id":123
     *
     * @param mixed $content
     * @return int[]
     */
    private static function extract_attachment_ids_from_content($content)
    {
        if (!is_string($content) || $content === '') {
            return [];
        }

        $ids = [];

        if (preg_match_all('/"id"\s*:\s*(\d+)/', $content, $m)) {
            foreach ($m[1] as $id) {
                $ids[] = (int) $id;
            }
        }

        if (preg_match_all('/wp-image-(\d+)/', $content, $m2)) {
            foreach ($m2[1] as $id) {
                $ids[] = (int) $id;
            }
        }

        if (preg_match_all('/data-id="(\d+)"/', $content, $m3)) {
            foreach ($m3[1] as $id) {
                $ids[] = (int) $id;
            }
        }

        if (preg_match_all('/attachment_id"\s*:\s*(\d+)/', $content, $m4)) {
            foreach ($m4[1] as $id) {
                $ids[] = (int) $id;
            }
        }

        $out = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $out[] = $id;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Build payload for media items by attachment IDs.
     * Each item includes:
     * - stage_id
     * - source_url
     * - basic fields (title/caption/description/alt)
     * - file_size/file_mtime to support remote "skip download if unchanged" logic
     *
     * @param mixed $media_ids
     * @return array<int,array<string,mixed>>
     */
    private static function build_media_data($media_ids)
    {
        $items = [];

        if (empty($media_ids) || !is_array($media_ids)) {
            return $items;
        }

        foreach ($media_ids as $id) {
            $id = (int) $id;
            if ($id <= 0) {
                continue;
            }

            $att = get_post($id);
            if (!$att || $att->post_type !== 'attachment') {
                continue;
            }

            $url = wp_get_attachment_url($id);
            if (!is_string($url) || $url === '') {
                continue;
            }

            // Force HTTPS to avoid 301 redirects when PROD downloads
            $url = str_replace('http://', 'https://', $url);

            $meta = wp_get_attachment_metadata($id);
            $mime = get_post_mime_type($id);
            $alt = get_post_meta($id, '_wp_attachment_image_alt', true);

            $file = get_attached_file($id);
            $file_size = 0;
            $file_mtime = 0;

            if (is_string($file) && $file !== '' && file_exists($file)) {
                $file_size = (int) filesize($file);
                $file_mtime = (int) filemtime($file);
            }

            $items[] = [
                'stage_id' => $id,
                'source_url' => esc_url_raw($url),
                'mime_type' => is_string($mime) ? sanitize_text_field($mime) : '',
                'title' => is_string($att->post_title) ? $att->post_title : '',
                'caption' => is_string($att->post_excerpt) ? $att->post_excerpt : '',
                'description' => is_string($att->post_content) ? $att->post_content : '',
                'alt' => is_string($alt) ? $alt : '',
                'file_size' => $file_size,
                'file_mtime' => $file_mtime,
                'meta' => is_array($meta) ? $meta : [],
            ];
        }

        return $items;
    }

    /**
     * Send media sync requests to Prod in chunks.
     * Returns a combined stage_id => prod_id mapping.
     *
     * @param string $endpoint_base
     * @param string $token
     * @param mixed $media_ids
     * @return array|WP_Error
     */
    private static function sync_media($endpoint_base, $token, $media_ids)
    {
        $items = self::build_media_data($media_ids);
        if (empty($items)) {
            return [
                'sent' => 0,
                'created' => 0,
                'updated' => 0,
                'map' => [],
            ];
        }
        $chunks = array_chunk($items, 15);

        $sent_total = 0;
        $created_total = 0;
        $updated_total = 0;
        $map = [];

        foreach ($chunks as $chunk) {
            $url = $endpoint_base . '/media';
            $payload = [
                'media' => $chunk,
            ];

            $resp = self::send_to_remote($url, $token, $payload);

            if (!$resp['success']) {
                return new WP_Error('sync_media_failed', $resp['message']);
            }

            $data = isset($resp['data']) && is_array($resp['data']) ? $resp['data'] : [];

            if (isset($data['sent'])) {
                $sent_total += (int) $data['sent'];
            } else {
                $sent_total += count($chunk);
            }

            if (isset($data['created'])) {
                $created_total += (int) $data['created'];
            }

            if (isset($data['updated'])) {
                $updated_total += (int) $data['updated'];
            }

            if (isset($data['map']) && is_array($data['map'])) {
                foreach ($data['map'] as $stage_id => $prod_id) {
                    $sid = (int) $stage_id;
                    $pid = (int) $prod_id;
                    if ($sid > 0 && $pid > 0) {
                        $map[(string) $sid] = $pid;
                    }
                }
            }
        }
        return [
            'sent' => $sent_total,
            'created' => $created_total,
            'updated' => $updated_total,
            'map' => $map,
        ];
    }

    /**
     * Persist list of stage attachment IDs that have been synced at least once.
     * Stored in option "sync_stp_synced_media_ids".
     *
     * @param mixed $current_media_ids
     * @return void
     */
    private static function persist_synced_media_ids($current_media_ids)
    {
        if (empty($current_media_ids) || !is_array($current_media_ids)) {
            return;
        }

        $existing = get_option('sync_stp_synced_media_ids', []);
        if (!is_array($existing)) {
            $existing = [];
        }

        $next = array_values(array_unique(array_merge($existing, array_map('intval', $current_media_ids))));
        update_option('sync_stp_synced_media_ids', $next, false);
    }

    /**
     * Detect stage attachment IDs that were previously synced but are now missing (deleted).
     * Also updates the stored list by removing deleted ones.
     *
     * @param mixed $current_media_ids Not used directly here; kept for interface consistency.
     * @return int[]
     */
    private static function get_deleted_stage_media_ids($current_media_ids)
    {
        $existing = get_option('sync_stp_synced_media_ids', []);
        if (!is_array($existing) || empty($existing)) {
            return [];
        }

        $deleted = [];

        foreach ($existing as $id) {
            $id = (int) $id;
            if ($id <= 0) {
                continue;
            }

            $p = get_post($id);
            if ($p && $p->post_type === 'attachment') {
                continue;
            }

            $deleted[] = $id;
        }

        if (empty($deleted)) {
            return [];
        }

        $kept = [];
        foreach ($existing as $id) {
            $id = (int) $id;
            if ($id <= 0) {
                continue;
            }
            if (!in_array($id, $deleted, true)) {
                $kept[] = $id;
            }
        }

        update_option('sync_stp_synced_media_ids', array_values(array_unique($kept)), false);

        return array_values(array_unique($deleted));
    }

    /**
     * Notify Prod to delete attachments mapped from the provided stage IDs.
     *
     * @param string $endpoint_base
     * @param string $token
     * @param mixed $deleted_stage_ids
     * @return array|WP_Error
     */
    private static function sync_deleted_media($endpoint_base, $token, $deleted_stage_ids)
    {
        $ids = [];
        if (is_array($deleted_stage_ids)) {
            foreach ($deleted_stage_ids as $id) {
                $id = (int) $id;
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
        }

        $ids = array_values(array_unique($ids));
        if (empty($ids)) {
            return [
                'deleted' => 0,
            ];
        }

        $url = $endpoint_base . '/media/delete';
        $payload = [
            'deleted_stage_ids' => $ids,
        ];

        $resp = self::send_to_remote($url, $token, $payload);

        if (!$resp['success']) {
            return new WP_Error('sync_media_delete_failed', $resp['message']);
        }

        $data = isset($resp['data']) && is_array($resp['data']) ? $resp['data'] : [];

        return [
            'deleted' => isset($data['deleted']) ? (int) $data['deleted'] : 0,
        ];
    }

    /**
     * Remap attachment IDs inside terms payload using the media map (stage_id => prod_id).
     *
     * @param mixed $terms_data
     * @param mixed $media_map
     * @return mixed
     */
    private static function remap_terms_data($terms_data, $media_map)
    {
        if (!is_array($terms_data) || empty($media_map) || !is_array($media_map)) {
            return $terms_data;
        }

        foreach ($terms_data as $i => $t) {
            if (!is_array($t)) {
                continue;
            }
            if (isset($t['acf'])) {
                $terms_data[$i]['acf'] = self::replace_attachment_ids_in_value($t['acf'], $media_map);
            }
            if (isset($t['meta'])) {
                $terms_data[$i]['meta'] = self::replace_attachment_ids_in_value($t['meta'], $media_map);
            }
        }

        return $terms_data;
    }

    /**
     * Remap attachment IDs inside posts payload (featured image + ACF + meta + content).
     *
     * @param mixed $posts_data
     * @param mixed $media_map
     * @return mixed
     */
    private static function remap_posts_data($posts_data, $media_map)
    {
        if (!is_array($posts_data) || empty($media_map) || !is_array($media_map)) {
            return $posts_data;
        }

        foreach ($posts_data as $i => $p) {
            if (!is_array($p)) {
                continue;
            }

            if (!empty($p['featured_image'])) {
                $sid = (int) $p['featured_image'];
                $key = (string) $sid;
                if ($sid > 0 && isset($media_map[$key])) {
                    $posts_data[$i]['featured_image'] = (int) $media_map[$key];
                }
            }

            if (isset($p['acf'])) {
                $posts_data[$i]['acf'] = self::replace_attachment_ids_in_value($p['acf'], $media_map);
            }

            if (isset($p['meta'])) {
                $posts_data[$i]['meta'] = self::replace_attachment_ids_in_value($p['meta'], $media_map);
            }

            if (isset($p['content']) && is_string($p['content'])) {
                $posts_data[$i]['content'] = self::replace_attachment_ids_in_content($p['content'], $media_map);
            }
        }

        return $posts_data;
    }

    /**
     * Remap attachment IDs inside options payload (ACF options + archives).
     *
     * @param mixed $options_data
     * @param mixed $media_map
     * @return mixed
     */
    private static function remap_options_data($options_data, $media_map)
    {
        if (!is_array($options_data) || empty($media_map) || !is_array($media_map)) {
            return $options_data;
        }

        if (isset($options_data['page_settings']['fields'])) {
            $options_data['page_settings']['fields'] = self::replace_attachment_ids_in_value($options_data['page_settings']['fields'], $media_map);
        }

        if (isset($options_data['archives']) && is_array($options_data['archives'])) {
            foreach ($options_data['archives'] as $pt => $archive) {
                if (!is_array($archive) || !isset($archive['fields'])) {
                    continue;
                }
                $options_data['archives'][$pt]['fields'] = self::replace_attachment_ids_in_value($archive['fields'], $media_map);
            }
        }

        return $options_data;
    }

    /**
     * Collect post IDs from ACF relationships that reference non-syncing post types.
     * These need to be remapped to production IDs.
     *
     * @param array $posts_data
     * @param array $syncing_post_types
     * @return array Array of arrays: [['id' => 123, 'slug' => 'post-name', 'post_type' => 'clinic'], ...]
     */
    private static function collect_external_post_ids($posts_data, $syncing_post_types)
    {
        $external_ids = [];

        if (!is_array($posts_data) || empty($posts_data)) {
            return $external_ids;
        }

        foreach ($posts_data as $post_data) {
            // Check ACF fields
            if (isset($post_data['acf']) && is_array($post_data['acf'])) {
                $found = self::extract_post_ids_from_acf($post_data['acf'], $syncing_post_types);
                foreach ($found as $post_info) {
                    $key = $post_info['id'] . ':' . $post_info['post_type'];
                    $external_ids[$key] = $post_info;
                }
            }

            // Check Gutenberg blocks (ACF blocks store data in content)
            if (isset($post_data['content']) && is_string($post_data['content']) && function_exists('parse_blocks')) {
                $blocks = parse_blocks($post_data['content']);
                $found = self::extract_post_ids_from_blocks($blocks, $syncing_post_types);
                foreach ($found as $post_info) {
                    $key = $post_info['id'] . ':' . $post_info['post_type'];
                    $external_ids[$key] = $post_info;
                }
            }
        }

        return array_values($external_ids);
    }

    /**
     * Extract post IDs from Gutenberg blocks (recursively).
     *
     * @param array $blocks Parsed Gutenberg blocks
     * @param array $syncing_post_types
     * @return array Array of ['id' => int, 'slug' => string, 'post_type' => string]
     */
    private static function extract_post_ids_from_blocks($blocks, $syncing_post_types)
    {
        $found = [];

        if (!is_array($blocks)) {
            return $found;
        }

        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }

            // Check block attributes data (ACF blocks store data here)
            if (isset($block['attrs']['data']) && is_array($block['attrs']['data'])) {
                $block_found = self::extract_post_ids_from_acf($block['attrs']['data'], $syncing_post_types);
                $found = array_merge($found, $block_found);
            }

            // Recurse into inner blocks
            if (isset($block['innerBlocks']) && is_array($block['innerBlocks'])) {
                $inner_found = self::extract_post_ids_from_blocks($block['innerBlocks'], $syncing_post_types);
                $found = array_merge($found, $inner_found);
            }
        }

        return $found;
    }

    /**
     * Recursively extract post IDs from ACF fields that are NOT in syncing post types.
     *
     * @param mixed $value
     * @param array $syncing_post_types
     * @param int $depth
     * @return array Array of ['id' => int, 'slug' => string, 'post_type' => string]
     */
    private static function extract_post_ids_from_acf($value, $syncing_post_types, $depth = 0)
    {
        if ($depth > 12) {
            return [];
        }

        $found = [];

        if (is_array($value)) {
            foreach ($value as $item) {
                if (is_numeric($item)) {
                    $post_id = (int) $item;
                    $post_type = get_post_type($post_id);

                    // Only collect IDs for post types NOT being synced
                    if ($post_type && !in_array($post_type, $syncing_post_types, true)) {
                        $post = get_post($post_id);
                        if ($post) {
                            $found[] = [
                                'id' => $post_id,
                                'slug' => $post->post_name,
                                'post_type' => $post_type,
                            ];
                        }
                    }
                } elseif (is_array($item)) {
                    $found = array_merge($found, self::extract_post_ids_from_acf($item, $syncing_post_types, $depth + 1));
                }
            }
        }

        return $found;
    }

    /**
     * Fetch production post IDs by querying production API with stage post slugs.
     *
     * @param string $endpoint_base
     * @param string $auth_token
     * @param array $post_ids Array of ['id' => int, 'slug' => string, 'post_type' => string]
     * @return array|WP_Error stage_id => prod_id map
     */
    private static function fetch_production_post_map($endpoint_base, $auth_token, $post_ids)
    {
        if (empty($post_ids)) {
            return [];
        }

        // Group by post type: {post_type: [{id, slug}, ...]}
        $by_type = [];
        foreach ($post_ids as $post_info) {
            $post_type = $post_info['post_type'];
            if (!isset($by_type[$post_type])) {
                $by_type[$post_type] = [];
            }
            $by_type[$post_type][] = [
                'id' => $post_info['id'],
                'slug' => $post_info['slug'],
            ];
        }

        $url = $endpoint_base . '/post-map';
        $payload = ['posts' => $by_type];

        $response = self::send_to_remote($url, $auth_token, $payload);

        if (!$response['success']) {
            return new WP_Error('sync_post_map_failed', $response['message']);
        }

        // send_to_remote() puts the decoded JSON in $response['data']
        // Production returns: {"success": true, "data": {"map": {...}}}
        // So the map is in $response['data']['data']['map']
        $map = isset($response['data']['data']['map']) && is_array($response['data']['data']['map']) 
            ? $response['data']['data']['map'] 
            : [];

        return $map;
    }

    /**
     * Remap post IDs in ACF relationships from stage IDs to production IDs.
     *
     * @param array $posts_data
     * @param array $post_map stage_id => prod_id
     * @return array
     */
    private static function remap_post_relationships($posts_data, $post_map)
    {
        if (!is_array($posts_data) || empty($post_map)) {
            return $posts_data;
        }

        foreach ($posts_data as $i => $post_data) {
            // Remap IDs in ACF fields
            if (isset($post_data['acf']) && is_array($post_data['acf'])) {
                $posts_data[$i]['acf'] = self::replace_post_ids_in_value($post_data['acf'], $post_map);
            }

            // Remap IDs in Gutenberg blocks (ACF blocks store data in content)
            if (isset($post_data['content']) && is_string($post_data['content']) && function_exists('parse_blocks') && function_exists('serialize_blocks')) {
                $blocks = parse_blocks($post_data['content']);
                $remapped_blocks = self::remap_post_ids_in_blocks($blocks, $post_map);
                $posts_data[$i]['content'] = serialize_blocks($remapped_blocks);
            }
        }

        return $posts_data;
    }

    /**
     * Recursively replace post IDs in ACF values based on $post_map.
     *
     * @param mixed $value
     * @param array $post_map stage_id => prod_id
     * @param int $depth
     * @return mixed
     */
    private static function replace_post_ids_in_value($value, $post_map, $depth = 0)
    {
        if ($depth > 12) {
            return $value;
        }

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if (is_numeric($item)) {
                    $stage_id = (int) $item;
                    if (isset($post_map[$stage_id])) {
                        $prod_id = (int) $post_map[$stage_id];
                        $value[$key] = $prod_id;
                    } else {
                        $value[$key] = (int) $item;
                    }
                } elseif (is_array($item)) {
                    $value[$key] = self::replace_post_ids_in_value($item, $post_map, $depth + 1);
                }
            }
        }

        return $value;
    }

    /**
     * Recursively remap post IDs in Gutenberg blocks.
     *
     * @param array $blocks
     * @param array $post_map stage_id => prod_id
     * @return array
     */
    private static function remap_post_ids_in_blocks($blocks, $post_map)
    {
        if (!is_array($blocks)) {
            return $blocks;
        }

        foreach ($blocks as $i => $block) {
            if (!is_array($block)) {
                continue;
            }

            // Remap IDs in block attributes data (ACF blocks store data here)
            if (isset($block['attrs']['data']) && is_array($block['attrs']['data'])) {
                $blocks[$i]['attrs']['data'] = self::replace_post_ids_in_value($block['attrs']['data'], $post_map);
            }

            // Recurse into inner blocks
            if (isset($block['innerBlocks']) && is_array($block['innerBlocks'])) {
                $blocks[$i]['innerBlocks'] = self::remap_post_ids_in_blocks($block['innerBlocks'], $post_map);
            }
        }

        return $blocks;
    }

    /**
     * Recursively replace attachment IDs in any value based on $media_map.
     * This is used to remap stage attachment IDs into prod attachment IDs.
     *
     * @param mixed $value
     * @param mixed $media_map stage_id(string) => prod_id(int)
     * @param int $depth
     * @return mixed
     */
    private static function replace_attachment_ids_in_value($value, $media_map, $depth = 0)
    {
        if ($depth > 12) {
            return $value;
        }

        if (is_int($value)) {
            $key = (string) $value;
            if ($value > 0 && isset($media_map[$key])) {
                return (int) $media_map[$key];
            }
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            $sid = (int) $value;
            $key = (string) $sid;
            if ($sid > 0 && isset($media_map[$key])) {
                return (string) ((int) $media_map[$key]);
            }
            return $value;
        }

        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = self::replace_attachment_ids_in_value($v, $media_map, $depth + 1);
            }

            if (isset($out['ID']) && (is_int($out['ID']) || (is_string($out['ID']) && ctype_digit($out['ID'])))) {
                $sid = (int) $out['ID'];
                $key = (string) $sid;
                if ($sid > 0 && isset($media_map[$key])) {
                    $out['ID'] = (int) $media_map[$key];
                }
            }

            if (isset($out['id']) && (is_int($out['id']) || (is_string($out['id']) && ctype_digit($out['id'])))) {
                $sid = (int) $out['id'];
                $key = (string) $sid;
                if ($sid > 0 && isset($media_map[$key])) {
                    $out['id'] = (int) $media_map[$key];
                }
            }

            return $out;
        }

        if (is_object($value)) {
            return self::replace_attachment_ids_in_value((array) $value, $media_map, $depth + 1);
        }

        return $value;
    }

    /**
     * Replace attachment IDs inside post content.
     * Covers common patterns (Gutenberg JSON + wp-image-* class + data-id + attachment_id).
     *
     * @param mixed $content
     * @param mixed $media_map stage_id(string) => prod_id(int)
     * @return mixed
     */
    private static function replace_attachment_ids_in_content($content, $media_map)
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
            $content = preg_replace('/data-id="' . $sid . '"/', 'data-id="' . $pid . '"', $content);
            $content = preg_replace('/attachment_id"\s*:\s*' . $sid . '\b/', 'attachment_id":' . $pid, $content);
        }

        return $content;
    }

    /**
     * Send JSON payload to the remote endpoint.
     * Adds auth_token into body automatically.
     *
     * Response:
     * - success (bool)
     * - message (string)
     * - data (decoded JSON array)
     *
     * @param mixed $url
     * @param mixed $token
     * @param mixed $data
     * @return array{success:bool,message:string,data?:array}
     */
    public static function send_to_remote($url, $token, $data)
    {
        if (!is_string($url) || trim($url) === '') {
            return [
                'success' => false,
                'message' => __('Некоректний URL endpoint.', 'sync-stage-to-prod'),
            ];
        }

        if (!is_string($token) || trim($token) === '') {
            return [
                'success' => false,
                'message' => __('Некоректний токен авторизації.', 'sync-stage-to-prod'),
            ];
        }

        if (!is_array($data) || empty($data)) {
            return [
                'success' => false,
                'message' => __('Немає даних для відправки.', 'sync-stage-to-prod'),
            ];
        }

        $endpoint = esc_url_raw(trim($url));
        $auth_token = trim($token);

        $payload = array_merge($data, [
            'auth_token' => $auth_token,
        ]);

        $response = wp_remote_post($endpoint, [
            'timeout' => 120,
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' => sprintf(
                    __('Помилка запиту: %s', 'sync-stage-to-prod'),
                    esc_html($response->get_error_message())
                ),
            ];
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($code < 200 || $code >= 300) {
            // Try to decode JSON error for better readability
            $decoded_error = json_decode($body, true);
            $error_message = $body;
            
            if (is_array($decoded_error)) {
                if (isset($decoded_error['message'])) {
                    $error_message = $decoded_error['message'];
                } elseif (isset($decoded_error['code'])) {
                    $error_message = sprintf(
                        'Code: %s, Message: %s',
                        $decoded_error['code'],
                        isset($decoded_error['message']) ? $decoded_error['message'] : 'Unknown error'
                    );
                }
            }
            
            return [
                'success' => false,
                'message' => sprintf(
                    __('Помилка HTTP %1$d. %2$s', 'sync-stage-to-prod'),
                    (int) $code,
                    wp_strip_all_tags($error_message)
                ),
            ];
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return [
                'success' => false,
                'message' => __('Некоректна відповідь від сервера.', 'sync-stage-to-prod'),
            ];
        }

        $sent = 0;
        $created = 0;
        $updated = 0;

        if (isset($decoded['sent'])) {
            $sent = (int) $decoded['sent'];
        }
        if (isset($decoded['created'])) {
            $created = (int) $decoded['created'];
        }
        if (isset($decoded['updated'])) {
            $updated = (int) $decoded['updated'];
        }

        return [
            'success' => true,
            'message' => sprintf(
                __('Відправлено: %1$d, створено: %2$d, оновлено: %3$d', 'sync-stage-to-prod'),
                $sent,
                $created,
                $updated
            ),
            'data' => $decoded,
        ];
    }

    /**
     * Send options payload to Prod (separate endpoint).
     *
     * @param mixed $prod_url
     * @param mixed $endpoint_path
     * @param mixed $auth_token
     * @param mixed $options_data
     * @return array|WP_Error
     */
    private static function send_options($prod_url, $endpoint_path, $auth_token, $options_data)
    {
        if (!is_string($prod_url) || trim($prod_url) === '') {
            return new WP_Error('sync_invalid_url', __('Некоректний URL прод-сайта.', 'sync-stage-to-prod'));
        }

        if (!is_string($endpoint_path) || trim($endpoint_path) === '') {
            return new WP_Error('sync_invalid_endpoint', __('Некоректний шлях ендпоінта.', 'sync-stage-to-prod'));
        }

        if (!is_string($auth_token) || trim($auth_token) === '') {
            return new WP_Error('sync_invalid_token', __('Некоректний токен авторизації.', 'sync-stage-to-prod'));
        }

        if (!is_array($options_data) || empty($options_data)) {
            return new WP_Error('sync_no_options', __('Немає опцій для синхронізації.', 'sync-stage-to-prod'));
        }

        $base_url = rtrim($prod_url, '/');
        $path = rtrim($endpoint_path, '/');
        $endpoint = $base_url . $path . '/options';

        $response = wp_remote_post($endpoint, [
            'timeout' => 120,
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode([
                'auth_token' => $auth_token,
                'options' => $options_data,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        if (is_wp_error($response)) {
            return new WP_Error(
                'sync_http_error',
                sprintf(
                    __('Помилка HTTP запиту: %s', 'sync-stage-to-prod'),
                    $response->get_error_message()
                )
            );
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($code < 200 || $code >= 300) {
            return new WP_Error(
                'sync_bad_response',
                sprintf(
                    __('Помилка HTTP %1$d. Відповідь: %2$s', 'sync-stage-to-prod'),
                    (int) $code,
                    esc_html($body)
                )
            );
        }

        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            return new WP_Error('sync_invalid_json', __('Некоректна JSON відповідь від сервера.', 'sync-stage-to-prod'));
        }

        return $decoded;
    }

    /**
     * Extract and remove relationships to syncing post types from post data.
     * This allows two-stage sync: create posts first, then update relationships.
     *
     * @param array &$post_data Post data (modified by reference)
     * @param array $syncing_post_types Array of post types being synced together
     * @return array Extracted relationship data to restore later
     */
    private static function extract_and_remove_syncing_type_relationships(&$post_data, $syncing_post_types)
    {
        $extracted = [];

        if (isset($post_data['acf']) && is_array($post_data['acf'])) {
            self::remove_syncing_type_refs_from_value($post_data['acf'], $syncing_post_types, $extracted);
        }

        if (!empty($post_data['content']) && is_string($post_data['content']) && function_exists('parse_blocks') && function_exists('serialize_blocks')) {
            $blocks = parse_blocks($post_data['content']);
            $modified = false;

            foreach ($blocks as $i => $block) {
                if (!is_array($block)) {
                    continue;
                }

                if (self::remove_syncing_type_refs_from_block($blocks[$i], $syncing_post_types, $extracted)) {
                    $modified = true;
                }
            }

            if ($modified) {
                $post_data['content'] = serialize_blocks($blocks);
            }
        }

        return $extracted;
    }

    /**
     * Decide whether a relationship should be extracted based on post type.
     * When $syncing_post_types is empty, extract all relationship types.
     *
     * @param string $post_type
     * @param array|null $syncing_post_types
     * @return bool
     */
    private static function should_extract_relationship_type($post_type, $syncing_post_types)
    {
        if (!is_string($post_type) || $post_type === '' || $post_type === 'attachment') {
            return false;
        }

        if (empty($syncing_post_types)) {
            return true;
        }

        return in_array($post_type, $syncing_post_types, true);
    }
    /**
     * Normalize ACF relationship field key path.
     *
     * Handles two path formats:
     * - Block fields: "block.blockName.field.subfield" (preserves block name as-is)
     * - Regular fields: "group.field.subfield" (sanitizes all parts)
     *
     * @param mixed $path Dot-separated field path string.
     * @return string Normalized field path or empty string if invalid.
     */
    private static function normalize_relationship_field_key($path)
    {
        if (!is_string($path) || $path === '') {
            return '';
        }

        $parts = explode('.', $path);

        // Special handling for block fields (block.blockName.field.subfield)
        if (isset($parts[0]) && $parts[0] === 'block' && isset($parts[1])) {
            $block_name = (string) $parts[1]; // Preserve block name without sanitization
            $rest = array_slice($parts, 2);
            $rest = array_map('sanitize_key', $rest);
            $rest = array_values(array_filter($rest, static function ($part) {
                return $part !== '';
            }));

            $normalized = array_merge(['block', $block_name], $rest);
            return implode('.', $normalized);
        }

        // Regular field path - sanitize all parts
        $parts = array_map('sanitize_key', $parts);
        $parts = array_values(array_filter($parts, static function ($part) {
            return $part !== '';
        }));

        return implode('.', $parts);
    }

    /**
     * Convert relationship IDs in block data to slug-based format.
     * This is CRITICAL for Production to map relationships correctly.
     *
     * @param array &$data Block data (modified by reference)
     * @param array $syncing_post_types
     * @param array &$extracted
     * @param string $field_path
     * @return bool True if modified
     */
    private static function convert_block_relationships_to_slugs(&$data, $syncing_post_types, &$extracted = [], $field_path = '')
    {
        if (!is_array($data)) {
            return false;
        }

        $modified = false;

        foreach ($data as $key => &$value) {
            if (is_array($value)) {
                if (self::is_numeric_array($value)) {
                    $first_elem = reset($value);

                    if (is_array($first_elem)) {
                        if (self::convert_block_relationships_to_slugs($value, $syncing_post_types, $extracted, $field_path === '' ? (string) $key : $field_path . '.' . $key)) {
                            $modified = true;
                        }
                    } else {
                        $total = 0;
                        $sync_refs = [];
                        $has_non_sync = false;

                        foreach ($value as $id) {
                            if (!is_numeric($id)) {
                                $has_non_sync = true;
                                continue;
                            }

                            $post_id = (int) $id;
                            if ($post_id <= 0) {
                                $has_non_sync = true;
                                continue;
                            }

                            $post = get_post($post_id);
                            $total++;

                            if (!$post || $post->post_type === 'attachment') {
                                $has_non_sync = true;
                                continue;
                            }

                            if (!in_array($post->post_type, $syncing_post_types, true)) {
                                $has_non_sync = true;
                                continue;
                            }

                            $sync_refs[] = [
                                'slug' => $post->post_name,
                                'post_type' => $post->post_type,
                            ];
                        }

                        if ($total > 0 && !$has_non_sync && !empty($sync_refs)) {
                            $value = $sync_refs;
                            $modified = true;
                        }
                    }
                } else {
                    if (self::convert_block_relationships_to_slugs($value, $syncing_post_types, $extracted, $field_path === '' ? (string) $key : $field_path . '.' . $key)) {
                        $modified = true;
                    }
                }
            }
        }

        return $modified;
    }

    /**
     * Recursively remove relationships to syncing types from ACF value.
     *
     * @param mixed &$value ACF field value (modified by reference)
     * @param array $syncing_post_types Array of post types being synced
     * @param array &$extracted Accumulator for extracted data
     * @param string $field_path Path to current field for restoration
     * @return void
     */
    private static function remove_syncing_type_refs_from_value(&$value, $syncing_post_types, &$extracted, $field_path = '')
    {
        if (!is_array($value)) {
            return;
        }

        foreach ($value as $key => &$item) {
            $current_path = $field_path ? $field_path . '.' . $key : $key;

            if (!is_array($item)) {
                $post_id = self::extract_post_id_from_relationship_value($item);
                if ($post_id > 0) {
                    $post = get_post($post_id);
                    if ($post) {
                        if (self::should_extract_relationship_type($post->post_type, $syncing_post_types)) {
                            $extracted_key = self::normalize_relationship_field_key($current_path);
                            if ($extracted_key !== '') {
                                $entry = isset($extracted[$extracted_key]) && is_array($extracted[$extracted_key])
                                    ? $extracted[$extracted_key]
                                    : ['items' => [], 'is_single' => true];
                                $entry['is_single'] = true;
                                $entry['items'][] = [
                                    'slug' => $post->post_name,
                                    'post_type' => $post->post_type,
                                ];
                                $extracted[$extracted_key] = $entry;
                            }
                            $item = 0;
                        } 
                    }
                }
                continue;
            }

            if (self::is_numeric_array($item)) {
                $first_elem = reset($item);

                if (is_array($first_elem)) {
                    self::remove_syncing_type_refs_from_value($item, $syncing_post_types, $extracted, $current_path);
                    continue;
                }

                $sync_refs = [];
                $keep = [];

                foreach ($item as $raw_item) {
                    $post_id = self::extract_post_id_from_relationship_value($raw_item);
                    if ($post_id <= 0) {
                        $keep[] = $raw_item;
                        continue;
                    }
                    $post = get_post($post_id);
                    if ($post) {
                        if (self::should_extract_relationship_type($post->post_type, $syncing_post_types)) {
                            $sync_refs[] = [
                                'slug' => $post->post_name,
                                'post_type' => $post->post_type,
                            ];
                            continue;
                        }
                    }
                    $keep[] = $raw_item;
                }

                if (!empty($sync_refs)) {
                    $extracted_key = self::normalize_relationship_field_key($current_path);
                    if ($extracted_key !== '') {
                        $entry = isset($extracted[$extracted_key]) && is_array($extracted[$extracted_key])
                            ? $extracted[$extracted_key]
                            : ['items' => [], 'is_single' => false];

                        $entry['items'] = array_merge($entry['items'], $sync_refs);
                        $entry['is_single'] = false;

                        $seen = [];
                        $unique = [];
                        foreach ($entry['items'] as $ref) {
                            if (!is_array($ref) || empty($ref['slug']) || empty($ref['post_type'])) {
                                continue;
                            }
                            $k = $ref['post_type'] . ':' . $ref['slug'];
                            if (isset($seen[$k])) {
                                continue;
                            }
                            $seen[$k] = true;
                            $unique[] = $ref;
                        }
                        $entry['items'] = $unique;

                        $extracted[$extracted_key] = $entry;
                    }
                    $item = $keep;
                }

                continue;
            }

            self::remove_syncing_type_refs_from_value($item, $syncing_post_types, $extracted, $current_path);
        }
    }

    /**
     * Extract post ID from ACF relationship field value.
     *
     * ACF relationship fields can return different formats:
     * - Integer ID
     * - String ID
     * - WP_Post object with ID property
     * - Array with 'ID' or 'id' key
     *
     * @param mixed $value ACF relationship field value.
     * @return int Post ID or 0 if invalid.
     */
    private static function extract_post_id_from_relationship_value($value)
    {
        // Handle direct integer ID
        if (is_int($value)) {
            return $value > 0 ? $value : 0;
        }

        // Handle string ID (numeric string)
        if (is_string($value) && ctype_digit($value)) {
            $id = (int) $value;
            return $id > 0 ? $id : 0;
        }

        // Handle WP_Post object or stdClass with ID property
        if (is_object($value) && isset($value->ID) && is_numeric($value->ID)) {
            $id = (int) $value->ID;
            return $id > 0 ? $id : 0;
        }

        // Handle array formats (with 'ID' or 'id' key)
        if (is_array($value)) {
            // Try uppercase 'ID' key first
            if (isset($value['ID']) && is_numeric($value['ID'])) {
                $id = (int) $value['ID'];
                return $id > 0 ? $id : 0;
            }

            // Try lowercase 'id' key
            if (isset($value['id']) && is_numeric($value['id'])) {
                $id = (int) $value['id'];
                return $id > 0 ? $id : 0;
            }
        }

        // Invalid or unrecognized format
        return 0;
    }
    /**
     * Remove relationships to syncing types from Gutenberg block.
     *
     * @param array &$block Block data (modified by reference)
     * @param array $syncing_post_types Array of post types being synced
     * @param array &$extracted
     * @return bool True if block was modified 
     */
    private static function remove_syncing_type_refs_from_block(&$block, $syncing_post_types, &$extracted)
    {
        $modified = false;
        
        // Check block attributes data
        if (!empty($block['attrs']['data']) && is_array($block['attrs']['data'])) {
            $before = json_encode($block['attrs']['data']);
            $block_name = isset($block['blockName']) ? $block['blockName'] : 'unknown';            
            self::remove_syncing_type_refs_from_value(
                $block['attrs']['data'],
                $syncing_post_types,
                $extracted,
                'block.' . $block_name
            );
            $after = json_encode($block['attrs']['data']);
            
            if ($before !== $after) {
                $modified = true;
            }
        }

        // Recurse into inner blocks
        if (!empty($block['innerBlocks'])) {
            foreach ($block['innerBlocks'] as &$inner_block) {
                if (self::remove_syncing_type_refs_from_block($inner_block, $syncing_post_types, $extracted)) {
                    $modified = true;
                }
            }
        }

        return $modified;
    }

    /**
     * Check if array is numeric (all keys are integers starting from 0).
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
     * Collect all relationship post references from ACF fields in posts data.
     * Returns array of [{post_type, slug}, ...]
     *
     * @param mixed $posts_data
     * @return array
     */
    private static function collect_relationship_references($posts_data)
    {
        if (!is_array($posts_data) || empty($posts_data)) {
            return [];
        }

        $relationships = [];

        foreach ($posts_data as $post) {
            if (!is_array($post)) {
                continue;
            }

            $slug = $post['slug'] ?? 'unknown';
            // Check ACF fields
            if (!empty($post['acf'])) {
                self::extract_relationships_from_value($post['acf'], $relationships);
            }

            // Check Gutenberg blocks for ACF block relationships
            if (!empty($post['content']) && is_string($post['content']) && function_exists('parse_blocks')) {
                $blocks = parse_blocks($post['content']);
                self::extract_relationships_from_blocks($blocks, $relationships, 0);
            }
        }

        return array_values($relationships);
    }

    /**
     * Extract relationships from Gutenberg blocks (ACF blocks).
     *
     * @param array $blocks
     * @param array $relationships
     * @param int $depth
     * @param bool $should_log
     * @return void
     */
    private static function extract_relationships_from_blocks($blocks, &$relationships, $depth = 0, $should_log = false)
    {
        if ($depth > 20 || !is_array($blocks)) {
            return;
        }

        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }

            $name = isset($block['blockName']) && is_string($block['blockName']) ? $block['blockName'] : '';
            $attrs = isset($block['attrs']) && is_array($block['attrs']) ? $block['attrs'] : [];

            // Process ACF blocks (acf/* or custom ensuria/*)
            if ($name !== '' && (strpos($name, 'acf/') === 0 || strpos($name, 'core/') !== 0)) {
                // Check attrs['data'] for ACF block data
                if (isset($attrs['data']) && is_array($attrs['data'])) {
                    self::extract_relationships_from_value($attrs['data'], $relationships, 0);
                }
                
                // Also check attrs directly
                self::extract_relationships_from_value($attrs, $relationships, 0);
            }

            // Recurse into inner blocks
            if (isset($block['innerBlocks']) && is_array($block['innerBlocks']) && !empty($block['innerBlocks'])) {
                self::extract_relationships_from_blocks($block['innerBlocks'], $relationships, $depth + 1, $should_log);
            }
        }
    }

    /**
     * Recursively extract relationship post IDs from ACF field values.
     *
     * @param mixed $value
     * @param array $relationships Accumulator array
     * @param int $depth
     * @param bool $should_log
     * @return void
     */
    private static function extract_relationships_from_value($value, &$relationships, $depth = 0, $should_log = false)
    {
        if ($depth > 15) {
            return;
        }

        // ACF relationship field returns array of post IDs (integers or numeric strings)
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            $post_id = (int) $value;
            if ($post_id > 0) {
                $post = get_post($post_id);
                if ($post && $post->post_type !== 'attachment' && !in_array($post->post_type, self::$excluded_post_types, true)) {
                    $key = $post->post_type . ':' . $post->post_name;
                    $relationships[$key] = [
                        'post_type' => $post->post_type,
                        'slug' => $post->post_name,
                    ];
                }
            }
            return;
        }

        if (is_array($value)) {
            // Check if this is a post object array
            if (isset($value['ID']) && isset($value['post_type']) && isset($value['post_name'])) {
                $post_type = sanitize_key($value['post_type']);
                $slug = sanitize_title($value['post_name']);
                if ($post_type !== 'attachment' && !in_array($post_type, self::$excluded_post_types, true)) {
                    $key = $post_type . ':' . $slug;
                    $relationships[$key] = [
                        'post_type' => $post_type,
                        'slug' => $slug,
                    ];
                }
                return;
            }

            // Otherwise recurse through array
            foreach ($value as $k => $v) {
                self::extract_relationships_from_value($v, $relationships, $depth + 1);
            }
            return;
        }

        if (is_object($value)) {
            self::extract_relationships_from_value((array) $value, $relationships, $depth + 1);
            return;
        }
    }
    /**
     * Recursively extract all numeric IDs from a value.
     *
     * @param mixed $value
     * @param int $depth
     * @return array Array of post IDs
     */
    private static function extract_all_ids_from_value($value, $depth = 0)
    {
        if ($depth > 12) {
            return [];
        }

        $ids = [];

        if (is_numeric($value)) {
            $id = (int) $value;
            if ($id > 0) {
                $ids[] = $id;
            }
            return $ids;
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                $ids = array_merge($ids, self::extract_all_ids_from_value($item, $depth + 1));
            }
        }

        return $ids;
    }

    /**
     * Validate that all relationship posts exist on production before sync.
     *
     * @param string $endpoint_base
     * @param string $auth_token
     * @param mixed $posts_data
     * @return true|WP_Error True if valid, WP_Error with details if missing posts found
     */
    private static function validate_relationships($endpoint_base, $auth_token, $posts_data)
    {
        $relationships = self::collect_relationship_references($posts_data);

        if (empty($relationships)) {
            return true;
        }

        // Filter out relationships to ALL post types being synced (avoid circular dependencies)
        // E.g., when syncing team + clinic together, don't validate team→clinic or clinic→team
        $syncing_post_types = [];
        foreach ($posts_data as $item) {
            if (isset($item['post_type']) && !in_array($item['post_type'], $syncing_post_types)) {
                $syncing_post_types[] = $item['post_type'];
            }
        }

        if (!empty($syncing_post_types)) {
            $relationships = array_filter($relationships, function($rel) use ($syncing_post_types) {
                return !in_array($rel['post_type'], $syncing_post_types);
            });
        }

        if (empty($relationships)) {
            return true;
        }

        $url = $endpoint_base . '/validate-relationships';
        $payload = [
            'relationships' => $relationships,
        ];

        $response = self::send_to_remote($url, $auth_token, $payload);

        if (!$response['success']) {
            return new WP_Error('sync_relationship_validation_failed', $response['message']);
        }

        $data = isset($response['data']) && is_array($response['data']) ? $response['data'] : [];
        $missing = isset($data['missing']) && is_array($data['missing']) ? $data['missing'] : [];

        if (!empty($missing)) {
            $error_lines = [__('Не можна синхронізувати записи з відсутніми зв\'язками (ACF Relationship).', 'sync-stage-to-prod'), ''];
            $error_lines[] = __('Спочатку потрібно синхронізувати наступні записи:', 'sync-stage-to-prod');
            $error_lines[] = '';

            foreach ($missing as $post_type => $slugs) {
                $post_type_obj = get_post_type_object($post_type);
                $type_name = $post_type_obj ? $post_type_obj->labels->name : $post_type;
                
                $total = count($slugs);
                $display_slugs = array_slice($slugs, 0, 10);
                $remaining = $total - count($display_slugs);
                
                $error_lines[] = sprintf('• %s:', $type_name);
                foreach ($display_slugs as $slug) {
                    $error_lines[] = sprintf('  - %s', $slug);
                }
                
                if ($remaining > 0) {
                    $error_lines[] = sprintf('  ... та ще %d', $remaining);
                }
                
                $error_lines[] = '';
            }

            return new WP_Error('sync_missing_relationships', implode("\n", $error_lines));
        }

        return true;
    }
}
