<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Media synchronization handler.
 *
 * Manages collection, export, import, and ID remapping of media attachments
 * during Stage to Production synchronization.
 */
class Sync_Media
{
    /**
     * Collect all media attachment IDs referenced in posts, terms, and options.
     *
     * Scans through posts, their content, ACF fields, taxonomy terms, and options
     * to find all image attachment IDs that need to be synced.
     *
     * @param array $post_types Post types to scan for media references.
     * @return int[] Array of unique attachment IDs sorted numerically.
     */
    public static function collect_ids_for_sync($post_types)
    {
        // Sanitize and validate post types
        $types = is_array($post_types) ? $post_types : [];
        $types = array_values(array_unique(array_map('sanitize_key', $types)));
        $types = array_values(array_filter($types, static function ($t) {
            return $t !== '' && $t !== 'attachment' && post_type_exists($t);
        }));

        $ids = [];
        $post_ids = self::get_post_ids($types);

        // Collect media from posts
        foreach ($post_ids as $post_id) {
            self::collect_from_post((int) $post_id, $ids);
        }

        // Collect media from taxonomy terms
        $terms = self::get_terms_for_posts($post_ids, $types);
        foreach ($terms as $term) {
            self::collect_from_term($term, $ids);
        }

        // Collect media from ACF options pages
        $option_post_ids = apply_filters('sync_stp_option_post_ids', [], $types);
        $option_post_ids = is_array($option_post_ids) ? $option_post_ids : [];
        $option_post_ids = array_values(array_unique(array_map('sanitize_text_field', $option_post_ids)));
        $option_post_ids = array_values(array_filter($option_post_ids, static function ($v) {
            return $v !== '';
        }));

        foreach ($option_post_ids as $opt_id) {
            self::collect_from_acf_post_id($opt_id, $ids);
        }

        // Convert to sorted array of integers
        $out = array_keys($ids);
        $out = array_map('intval', $out);
        $out = array_values(array_filter($out, static function ($v) {
            return $v > 0;
        }));

        sort($out);

        return $out;
    }

    /**
     * Find attachment ID by its file path stored in _wp_attached_file meta.
     *
     * Used for matching attachments between stage and production environments.
     *
     * @param string $attached_file Relative file path from _wp_attached_file meta.
     * @param string $mime          Optional MIME type for additional filtering.
     * @return int Attachment ID or 0 if not found.
     */
    private static function find_attachment_id_by_attached_file($attached_file, $mime = '')
    {
        $attached_file = is_string($attached_file) ? ltrim($attached_file, '/') : '';
        $mime = is_string($mime) ? sanitize_text_field($mime) : '';

        if ($attached_file === '') {
            return 0;
        }

        global $wpdb;

        // Search with MIME type filter if provided
        if ($mime !== '') {
            $sql = "
                SELECT p.ID
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->postmeta} pm
                    ON pm.post_id = p.ID
                    AND pm.meta_key = '_wp_attached_file'
                WHERE p.post_type = 'attachment'
                  AND pm.meta_value = %s
                  AND p.post_mime_type = %s
                ORDER BY p.ID DESC
                LIMIT 1
            ";
            return (int) $wpdb->get_var($wpdb->prepare($sql, $attached_file, $mime));
        }

        // Search without MIME type filter
        $sql = "
            SELECT p.ID
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm
                ON pm.post_id = p.ID
                AND pm.meta_key = '_wp_attached_file'
            WHERE p.post_type = 'attachment'
              AND pm.meta_value = %s
            ORDER BY p.ID DESC
            LIMIT 1
        ";

        return (int) $wpdb->get_var($wpdb->prepare($sql, $attached_file));
    }

    /**
     * Export media attachment data for synchronization.
     *
     * Prepares attachment data including URLs, metadata, and file information
     * for transfer to production environment.
     *
     * @param int[] $attachment_ids Array of attachment IDs to export.
     * @return array[] Array of attachment data arrays ready for sync.
     */
    public static function export_media($attachment_ids)
    {
        // Sanitize and validate attachment IDs
        $ids = is_array($attachment_ids) ? $attachment_ids : [];
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $ids = array_values(array_filter($ids, static function ($v) {
            return $v > 0;
        }));

        $items = [];

        foreach ($ids as $id) {
            // Only export image attachments
            if (!self::is_image_attachment_id($id)) {
                continue;
            }

            $url = wp_get_attachment_url($id);
            if (!is_string($url) || $url === '') {
                continue;
            }

            $post = get_post($id);
            $attached_file = get_post_meta($id, '_wp_attached_file', true);
            $attached_file = is_string($attached_file) ? $attached_file : '';
            if (!$post) {
                continue;
            }

            $alt = get_post_meta($id, '_wp_attachment_image_alt', true);
            $alt = is_string($alt) ? $alt : '';

            $path = wp_parse_url($url, PHP_URL_PATH);
            $file = is_string($path) ? wp_basename($path) : '';
            $file = is_string($file) ? $file : '';
            $file = $file !== '' ? sanitize_file_name($file) : '';

            $items[] = [
                'id' => (int) $id,
                'url' => esc_url_raw($url),
                'file' => $file,
                'mime' => get_post_mime_type($id),
                'title' => (string) $post->post_title,
                'caption' => (string) $post->post_excerpt,
                'description' => (string) $post->post_content,
                'alt' => $alt,
                'attached_file' => $attached_file,
            ];
        }

        return $items;
    }

    /**
     * Find existing attachment on production by file path or filename.
     *
     * First tries exact match by _wp_attached_file, then falls back to
     * filename matching to find corresponding attachment on production.
     *
     * @param string $attached_file Relative file path from stage.
     * @param string $file          Filename for fallback search.
     * @return int Attachment ID or 0 if not found.
     */
    private static function find_existing_attachment_id($attached_file, $file)
    {
        $attached_file = is_string($attached_file) ? ltrim($attached_file, '/') : '';
        $file = is_string($file) ? sanitize_file_name($file) : '';

        // Try exact path match first
        if ($attached_file !== '') {
            $q = new WP_Query([
                'post_type' => 'attachment',
                'post_status' => 'inherit',
                'posts_per_page' => 5,
                'fields' => 'ids',
                'no_found_rows' => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
                'meta_query' => [
                    [
                        'key' => '_wp_attached_file',
                        'value' => $attached_file,
                        'compare' => '=',
                    ],
                ],
            ]);

            if (!empty($q->posts) && is_array($q->posts)) {
                foreach ($q->posts as $id) {
                    $id = (int) $id;
                    if ($id > 0 && self::is_image_attachment_id($id)) {
                        return $id;
                    }
                }
            }
        }

        // Fallback to filename match
        if ($file !== '') {
            $q2 = new WP_Query([
                'post_type' => 'attachment',
                'post_status' => 'inherit',
                'posts_per_page' => 20,
                'fields' => 'ids',
                'no_found_rows' => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
                'meta_query' => [
                    [
                        'key' => '_wp_attached_file',
                        'value' => $file,
                        'compare' => 'LIKE',
                    ],
                ],
            ]);

            if (!empty($q2->posts) && is_array($q2->posts)) {
                foreach ($q2->posts as $id) {
                    $id = (int) $id;
                    if ($id <= 0) {
                        continue;
                    }
                    $af = get_post_meta($id, '_wp_attached_file', true);
                    $af = is_string($af) ? $af : '';
                    if ($af === '') {
                        continue;
                    }
                    // Verify basename matches
                    if (wp_basename($af) !== $file) {
                        continue;
                    }
                    if (self::is_image_attachment_id($id)) {
                        return $id;
                    }
                }
            }
        }

        return 0;
    }

    /**
     * Import media attachments to production environment.
     *
     * Downloads media from stage URLs, creates/updates attachments on production,
     * and returns mapping of stage IDs to production IDs.
     *
     * @param array[] $items Array of media data exported from stage.
     * @return array Results with 'created', 'updated' counts and ID 'map'.
     */
    public static function import_media($items)
    {
        $list = is_array($items) ? $items : [];
        $created = 0;
        $updated = 0;
        $map = [];

        if (empty($list)) {
            return ['created' => 0, 'updated' => 0, 'map' => []];
        }

        // Load WordPress file handling functions
        if (!function_exists('download_url')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        if (!function_exists('wp_handle_sideload')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        if (!function_exists('wp_generate_attachment_metadata')) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        foreach ($list as $item) {
            $old_id = isset($item['id']) ? (int) $item['id'] : 0;
            $url = isset($item['url']) ? (string) $item['url'] : '';
            $mime = isset($item['mime']) ? (string) $item['mime'] : '';
            $title = isset($item['title']) ? (string) $item['title'] : '';
            $caption = isset($item['caption']) ? (string) $item['caption'] : '';
            $description = isset($item['description']) ? (string) $item['description'] : '';
            $alt = isset($item['alt']) ? (string) $item['alt'] : '';

            $file = isset($item['file']) ? (string) $item['file'] : '';
            $file = $file !== '' ? sanitize_file_name($file) : '';

            $attached_file = isset($item['attached_file']) ? (string) $item['attached_file'] : '';
            $attached_file = ltrim($attached_file, '/');

            if ($old_id <= 0) {
                continue;
            }

            $url_valid = Sync_Validator::validate_url($url);
            if (!is_string($url_valid)) {
                continue;
            }

            // Only process image MIME types
            if ($mime === '' || strpos($mime, 'image/') !== 0) {
                continue;
            }

            // Check if attachment already exists on production
            $found_id = self::find_existing_attachment_id($attached_file, $file);
            if ($found_id > 0) {
                // Update existing attachment metadata
                wp_update_post([
                    'ID' => $found_id,
                    'post_title' => sanitize_text_field($title),
                    'post_excerpt' => wp_kses_post($caption),
                    'post_content' => wp_kses_post($description),
                ]);
                update_post_meta($found_id, '_wp_attachment_image_alt', sanitize_text_field($alt));

                $map[$old_id] = $found_id;
                $updated++;
                continue;
            }

            // Download file from stage
            $tmp = download_url($url_valid);
            if (is_wp_error($tmp)) {
                continue;
            }

            // Preserve original directory structure
            $subdir = '';
            if ($attached_file !== '') {
                $dir = wp_normalize_path(dirname($attached_file));
                if ($dir !== '' && $dir !== '.' && $dir !== '/') {
                    $subdir = '/' . trim($dir, '/');
                }
            }

            // Apply upload directory filter to maintain folder structure
            $upload_dir_filter = null;
            if ($subdir !== '') {
                $upload_dir_filter = static function ($uploads) use ($subdir) {
                    if (!is_array($uploads)) {
                        return $uploads;
                    }
                    $uploads['subdir'] = $subdir;
                    $uploads['path'] = rtrim((string) $uploads['basedir'], '/') . $subdir;
                    $uploads['url'] = rtrim((string) $uploads['baseurl'], '/') . $subdir;
                    return $uploads;
                };
                add_filter('upload_dir', $upload_dir_filter);
            }

            $name = $file !== '' ? $file : wp_basename(wp_parse_url($url_valid, PHP_URL_PATH));
            $name = is_string($name) && $name !== '' ? sanitize_file_name($name) : ('image-' . $old_id);

            $file_arr = [
                'name' => $name,
                'tmp_name' => $tmp,
            ];

            $overrides = ['test_form' => false];
            $sideload = wp_handle_sideload($file_arr, $overrides);

            if ($upload_dir_filter !== null) {
                remove_filter('upload_dir', $upload_dir_filter);
            }

            if (!empty($sideload['error'])) {
                @unlink($tmp);
                continue;
            }

            // Create new attachment
            $attachment = [
                'post_title' => sanitize_text_field($title),
                'post_excerpt' => wp_kses_post($caption),
                'post_content' => wp_kses_post($description),
                'post_status' => 'inherit',
                'post_mime_type' => isset($sideload['type']) ? (string) $sideload['type'] : $mime,
            ];

            $new_id = wp_insert_attachment($attachment, $sideload['file'], 0);
            if (is_wp_error($new_id) || (int) $new_id <= 0) {
                continue;
            }

            $new_id = (int) $new_id;
            $map[$old_id] = $new_id;

            // Generate and update attachment metadata
            $meta = wp_generate_attachment_metadata($new_id, $sideload['file']);
            if (is_array($meta)) {
                wp_update_attachment_metadata($new_id, $meta);
            }

            update_post_meta($new_id, '_wp_attachment_image_alt', sanitize_text_field($alt));

            $created++;
        }

        return ['created' => $created, 'updated' => $updated, 'map' => $map];
    }

    /**
     * Replace stage attachment IDs with production IDs in any value.
     *
     * Recursively processes arrays, objects, strings, and integers to replace
     * all occurrences of stage IDs with their production counterparts.
     *
     * @param mixed $value Value containing attachment IDs to remap.
     * @param array $map   Mapping of stage IDs to production IDs.
     * @return mixed Value with remapped attachment IDs.
     */
    public static function replace_ids_in_value($value, $map)
    {
        $m = is_array($map) ? $map : [];
        if (empty($m)) {
            return $value;
        }
        return self::replace_recursive($value, $m, 0);
    }

    /**
     * Get all post IDs for specified post types.
     *
     * Retrieves posts in batches to handle large datasets efficiently.
     *
     * @param string[] $types Post type slugs.
     * @return int[] Array of post IDs.
     */
    private static function get_post_ids($types)
    {
        if (empty($types)) {
            return [];
        }

        $ids = [];
        $paged = 1;

        do {
            $q = new WP_Query([
                'post_type' => $types,
                'post_status' => 'any',
                'posts_per_page' => 500,
                'paged' => $paged,
                'fields' => 'ids',
                'no_found_rows' => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
                'ignore_sticky_posts' => true,
            ]);

            if (!empty($q->posts) && is_array($q->posts)) {
                foreach ($q->posts as $id) {
                    $ids[] = (int) $id;
                }
            }

            $has_more = !empty($q->posts) && count($q->posts) === 500;
            $paged++;
        } while ($has_more);

        $ids = array_values(array_unique(array_filter($ids, static function ($v) {
            return $v > 0;
        })));

        return $ids;
    }

    /**
     * Get all taxonomy terms assigned to specified posts.
     *
     * @param int[]    $post_ids Post IDs to get terms for.
     * @param string[] $types    Post types to determine taxonomies.
     * @return WP_Term[] Array of unique term objects.
     */
    private static function get_terms_for_posts($post_ids, $types)
    {
        $ids = is_array($post_ids) ? $post_ids : [];
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $ids = array_values(array_filter($ids, static function ($v) {
            return $v > 0;
        }));

        if (empty($ids)) {
            return [];
        }

        // Collect all taxonomies for these post types
        $taxes = [];
        foreach ($types as $t) {
            $t = sanitize_key($t);
            if ($t === '' || !post_type_exists($t)) {
                continue;
            }
            $t_taxes = get_object_taxonomies($t, 'names');
            if (!empty($t_taxes) && is_array($t_taxes)) {
                foreach ($t_taxes as $tx) {
                    $tx = sanitize_key($tx);
                    if ($tx !== '') {
                        $taxes[] = $tx;
                    }
                }
            }
        }

        $taxes = array_values(array_unique($taxes));
        if (empty($taxes)) {
            return [];
        }

        // Get all terms assigned to these posts
        $terms = wp_get_object_terms($ids, $taxes, ['fields' => 'all']);
        if (is_wp_error($terms) || empty($terms) || !is_array($terms)) {
            return [];
        }

        // Deduplicate terms by taxonomy:term_id key
        $unique = [];
        foreach ($terms as $term) {
            if (!($term instanceof WP_Term)) {
                continue;
            }
            $k = $term->taxonomy . ':' . (int) $term->term_id;
            $unique[$k] = $term;
        }

        return array_values($unique);
    }

    /**
     * Collect media IDs from a single post.
     *
     * Scans featured image, post content, blocks, ACF fields, and WooCommerce
     * product galleries for attachment references.
     *
     * @param int   $post_id Post ID to scan.
     * @param array $ids     Reference to array collecting attachment IDs.
     * @return void
     */
    private static function collect_from_post($post_id, &$ids)
    {
        $before_count = count($ids);

        // Collect featured image
        $thumb = (int) get_post_thumbnail_id($post_id);
        if ($thumb > 0 && self::is_image_attachment_id($thumb)) {
            $ids[$thumb] = true;
        }

        // Collect from post content
        $content = (string) get_post_field('post_content', $post_id);
        if ($content !== '') {
            self::collect_from_content($content, $ids);

            // Collect from Gutenberg blocks
            if (function_exists('parse_blocks')) {
                $blocks = parse_blocks($content);
                $before_blocks = count($ids);
                self::collect_from_blocks($blocks, $ids, 0);
                $from_blocks = count($ids) - $before_blocks;
            }
        }

        // Collect from ACF fields
        if (function_exists('get_fields')) {
            $fields = get_fields($post_id, false);
            if (is_array($fields)) {
                $before_acf = count($ids);
                self::collect_from_value($fields, $ids, 0);
                $from_acf = count($ids) - $before_acf;
            }
        }

        // Collect from WooCommerce product gallery
        $gallery = get_post_meta($post_id, '_product_image_gallery', true);
        if (is_string($gallery) && $gallery !== '') {
            $parts = explode(',', $gallery);
            foreach ($parts as $p) {
                $pid = (int) trim($p);
                if ($pid > 0 && self::is_image_attachment_id($pid)) {
                    $ids[$pid] = true;
                }
            }
        }

        $after_count = count($ids);
        $total = $after_count - $before_count;
    }

    /**
     * Collect media IDs from a taxonomy term.
     *
     * Scans ACF fields and term meta for attachment references.
     *
     * @param WP_Term|mixed $term Term object to scan.
     * @param array         $ids  Reference to array collecting attachment IDs.
     * @return void
     */
    private static function collect_from_term($term, &$ids)
    {
        if (!($term instanceof WP_Term)) {
            return;
        }

        if (function_exists('get_fields')) {
            $fields = get_fields($term);
            if (is_array($fields)) {
                self::collect_from_value($fields, $ids, 0);
            }
        } else {
            $meta = get_term_meta((int) $term->term_id);
            if (is_array($meta)) {
                self::collect_from_value($meta, $ids, 0);
            }
        }
    }

    /**
     * Collect media IDs from ACF options page or custom post ID.
     *
     * @param string|int $post_id ACF post ID (e.g., 'options', 'option', or numeric ID).
     * @param array      $ids     Reference to array collecting attachment IDs.
     * @return void
     */
    private static function collect_from_acf_post_id($post_id, &$ids)
    {
        if (!function_exists('get_fields')) {
            return;
        }
        $fields = get_fields($post_id, false);
        if (is_array($fields)) {
            self::collect_from_value($fields, $ids, 0);
        }
    }

    /**
     * Collect media IDs from post content HTML.
     *
     * Scans for:
     * - wp-image-{id} CSS classes
     * - Block editor "id" attributes in comments
     * - "ids" arrays in block attributes
     *
     * @param string $content Post content HTML.
     * @param array  $ids     Reference to array collecting attachment IDs.
     * @return void
     */
    private static function collect_from_content($content, &$ids)
    {
        // Match wp-image-{id} classes
        $m1 = [];
        if (preg_match_all('/wp-image-([0-9]+)/', $content, $m1) && !empty($m1[1])) {
            foreach ($m1[1] as $v) {
                $id = (int) $v;
                if ($id > 0 && self::is_image_attachment_id($id)) {
                    $ids[$id] = true;
                }
            }
        }

        // Match block editor "id" attributes in comments
        $m2 = [];
        if (preg_match_all('/<!--\s*wp:(?:image|cover|video|audio|file)\s+{[^}]*"id"\s*:\s*([0-9]+)/', $content, $m2) && !empty($m2[1])) {
            foreach ($m2[1] as $v) {
                $id = (int) $v;
                if ($id > 0 && self::is_image_attachment_id($id)) {
                    $ids[$id] = true;
                }
            }
        }

        // Match "ids" arrays in block attributes
        $m3 = [];
        if (preg_match_all('/"ids"\s*:\s*\[([0-9,\s]+)\]/', $content, $m3) && !empty($m3[1])) {
            foreach ($m3[1] as $chunk) {
                $nums = preg_split('/\s*,\s*/', trim((string) $chunk));
                if (!is_array($nums)) {
                    continue;
                }
                foreach ($nums as $n) {
                    $id = (int) $n;
                    if ($id > 0 && self::is_image_attachment_id($id)) {
                        $ids[$id] = true;
                    }
                }
            }
        }
    }

    /**
     * Recursively collect media IDs from any value (ACF fields, meta, etc.).
     *
     * Handles integers, numeric strings, comma-separated ID lists, arrays, and objects.
     *
     * @param mixed $value Value to scan for attachment IDs.
     * @param array $ids   Reference to array collecting attachment IDs.
     * @param int   $depth Current recursion depth (prevents infinite loops).
     * @return void
     */
    private static function collect_from_value($value, &$ids, $depth)
    {
        // Prevent infinite recursion
        if ($depth > 15) {
            return;
        }

        // Handle integer IDs
        if (is_int($value)) {
            if ($value > 0 && self::is_image_attachment_id($value)) {
                $ids[$value] = true;
            }
            return;
        }

        // Handle numeric non-string values
        if (is_numeric($value) && !is_string($value)) {
            $v = (int) $value;
            if ($v > 0 && self::is_image_attachment_id($v)) {
                $ids[$v] = true;
            }
            return;
        }

        // Handle string values
        if (is_string($value)) {
            // Numeric string (single ID)
            if (ctype_digit($value)) {
                $v = (int) $value;
                if ($v > 0 && self::is_image_attachment_id($v)) {
                    $ids[$v] = true;
                }
                return;
            }

            // Comma-separated ID list
            if (preg_match('/^\d+(?:\s*,\s*\d+)+$/', $value)) {
                $parts = preg_split('/\s*,\s*/', $value);
                if (is_array($parts)) {
                    foreach ($parts as $p) {
                        $v = (int) $p;
                        if ($v > 0 && self::is_image_attachment_id($v)) {
                            $ids[$v] = true;
                        }
                    }
                }
            }
            return;
        }

        // Handle arrays (recurse into each element)
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                self::collect_from_value($v, $ids, $depth + 1);
            }
            return;
        }

        // Handle objects (convert to array and recurse)
        if (is_object($value)) {
            self::collect_from_value((array) $value, $ids, $depth + 1);
            return;
        }
    }

    /**
     * Recursively collect media IDs from Gutenberg blocks.
     *
     * Scans block attributes, innerHTML, innerContent, and nested blocks for
     * attachment references. Handles core blocks, ACF blocks, and reusable blocks.
     *
     * @param array $blocks Parsed block array from parse_blocks().
     * @param array $ids    Reference to array collecting attachment IDs.
     * @param int   $depth  Current recursion depth.
     * @return void
     */
    private static function collect_from_blocks($blocks, &$ids, $depth = 0)
    {
        // Prevent infinite recursion
        if ($depth > 20) {
            return;
        }

        if (!is_array($blocks) || empty($blocks)) {
            return;
        }

        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }

            $name = isset($block['blockName']) && is_string($block['blockName']) ? $block['blockName'] : '';
            $attrs = isset($block['attrs']) && is_array($block['attrs']) ? $block['attrs'] : [];

            if ($name !== '') {
                // Core media blocks (image, cover, media-text)
                if (in_array($name, ['core/image', 'core/cover', 'core/media-text'], true)) {
                    if (isset($attrs['id'])) {
                        self::collect_from_value($attrs['id'], $ids, 0);
                    }
                    if (isset($attrs['mediaId'])) {
                        self::collect_from_value($attrs['mediaId'], $ids, 0);
                    }
                }

                // Core gallery block
                if ($name === 'core/gallery') {
                    if (isset($attrs['ids'])) {
                        self::collect_from_value($attrs['ids'], $ids, 0);
                    }
                }

                // Reusable blocks (recursively load and parse)
                if ($name === 'core/block' && isset($attrs['ref'])) {
                    $ref = (int) $attrs['ref'];
                    if ($ref > 0) {
                        $ref_content = (string) get_post_field('post_content', $ref);
                        if ($ref_content !== '' && function_exists('parse_blocks')) {
                            $ref_blocks = parse_blocks($ref_content);
                            self::collect_from_blocks($ref_blocks, $ids, $depth + 1);
                        }
                    }
                }

                // ACF blocks
                if (strpos($name, 'acf/') === 0) {
                    if (isset($attrs['data'])) {
                        $before = count($ids);
                        self::collect_from_block_value($attrs['data'], $ids, 0);
                        $collected = count($ids) - $before;
                    }
                    self::collect_from_block_value($attrs, $ids, 0);
                }

                // Custom blocks (non-core, non-ACF)
                if (strpos($name, 'core/') !== 0 && strpos($name, 'acf/') !== 0) {
                    if (isset($attrs['data'])) {
                        $before = count($ids);
                        self::collect_from_block_value($attrs['data'], $ids, 0);
                        $collected = count($ids) - $before;
                    }
                    $before_attrs = count($ids);
                    self::collect_from_block_value($attrs, $ids, 0);
                    $collected_attrs = count($ids) - $before_attrs;
                }
            }

            // Scan block HTML content
            if (isset($block['innerHTML']) && is_string($block['innerHTML']) && $block['innerHTML'] !== '') {
                self::collect_from_content($block['innerHTML'], $ids);
                self::collect_from_block_value($block['innerHTML'], $ids, 0);
            }

            // Scan innerContent array
            if (isset($block['innerContent']) && is_array($block['innerContent'])) {
                foreach ($block['innerContent'] as $piece) {
                    if (is_string($piece) && $piece !== '') {
                        self::collect_from_content($piece, $ids);
                        self::collect_from_block_value($piece, $ids, 0);
                    } else {
                        self::collect_from_block_value($piece, $ids, 0);
                    }
                }
            }

            // Recursively process nested blocks
            if (isset($block['innerBlocks']) && is_array($block['innerBlocks']) && !empty($block['innerBlocks'])) {
                self::collect_from_blocks($block['innerBlocks'], $ids, $depth + 1);
            }
        }
    }

    /**
     * Collect media IDs from block attribute values.
     *
     * Similar to collect_from_value but with additional block-specific patterns:
     * - wp-image-{id} classes
     * - JSON "id" attributes
     * - URLs (converted to attachment IDs)
     * - Special handling for block value structures
     *
     * @param mixed $value Block attribute value.
     * @param array $ids   Reference to array collecting attachment IDs.
     * @param int   $depth Current recursion depth.
     * @return void
     */
    private static function collect_from_block_value($value, &$ids, $depth = 0)
    {
        // Prevent infinite recursion
        if ($depth > 15) {
            return;
        }

        // Handle integer IDs
        if (is_int($value)) {
            if ($value > 0 && self::is_image_attachment_id($value)) {
                $ids[$value] = true;
            }
            return;
        }

        // Handle numeric non-string values
        if (is_numeric($value) && !is_string($value)) {
            $v = (int) $value;
            if ($v > 0 && self::is_image_attachment_id($v)) {
                $ids[$v] = true;
            }
            return;
        }

        // Handle string values
        if (is_string($value)) {
            if ($value === '') {
                return;
            }

            // Numeric string (single ID)
            if (ctype_digit($value)) {
                $id = (int) $value;
                if ($id > 0 && self::is_image_attachment_id($id)) {
                    $ids[$id] = true;
                }
                return;
            }

            // wp-image-{id} classes
            if (preg_match_all('/wp-image-([0-9]+)/', $value, $m) && !empty($m[1])) {
                foreach ($m[1] as $v) {
                    $id = (int) $v;
                    if ($id > 0 && self::is_image_attachment_id($id)) {
                        $ids[$id] = true;
                    }
                }
            }

            // JSON "id" attributes
            if (preg_match_all('/"id"\s*:\s*([0-9]+)/', $value, $m2) && !empty($m2[1])) {
                foreach ($m2[1] as $v) {
                    $id = (int) $v;
                    if ($id > 0 && self::is_image_attachment_id($id)) {
                        $ids[$id] = true;
                    }
                }
            }

            // URL patterns (convert to attachment ID)
            if (preg_match_all('#https?://[^\s"\'\)<>]+#i', $value, $m3) && !empty($m3[0])) {
                foreach ($m3[0] as $url) {
                    $aid = 0;
                    if (function_exists('attachment_url_to_postid')) {
                        $aid = (int) attachment_url_to_postid($url);
                    }
                    if ($aid > 0 && self::is_image_attachment_id($aid)) {
                        $ids[$aid] = true;
                    }
                }
            }

            // Relative upload paths
            if (strpos($value, '/wp-content/uploads/') !== false) {
                $home = function_exists('home_url') ? home_url('/') : '';
                if ($home !== '') {
                    $candidate = $value;
                    if (strpos($candidate, 'http') !== 0) {
                        $candidate = rtrim($home, '/') . '/' . ltrim($candidate, '/');
                    }

                    $aid = 0;
                    if (function_exists('attachment_url_to_postid')) {
                        $aid = (int) attachment_url_to_postid($candidate);
                    }
                    if ($aid > 0 && self::is_image_attachment_id($aid)) {
                        $ids[$aid] = true;
                    }
                }
            }

            return;
        }

        // Handle arrays
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                // Special handling for ACF field structures
                if ($k === 'value' && isset($value['type']) && is_numeric($v)) {
                    $vid = (int) $v;
                    if ($vid > 0 && self::is_image_attachment_id($vid)) {
                        $ids[$vid] = true;
                    }
                }

                // Priority keys likely to contain attachment IDs
                if ($k === 'ID' || $k === 'id' || $k === 'mediaId' || $k === 'image' || $k === 'featured_image' || $k === 'icon' || $k === 'svg') {
                    self::collect_from_block_value($v, $ids, $depth + 1);
                    continue;
                }

                // URL/src keys
                if (($k === 'url' || $k === 'src') && is_string($v) && $v !== '') {
                    self::collect_from_block_value($v, $ids, $depth + 1);
                    continue;
                }

                self::collect_from_block_value($v, $ids, $depth + 1);
            }
            return;
        }

        // Handle objects (convert to array and recurse)
        if (is_object($value)) {
            self::collect_from_block_value((array) $value, $ids, $depth + 1);
            return;
        }
    }

    /**
     * Check if a post ID is an image attachment.
     *
     * @param int $id Post ID to check.
     * @return bool True if ID is an image attachment, false otherwise.
     */
    private static function is_image_attachment_id($id)
    {
        $pid = (int) $id;
        if ($pid <= 0) {
            return false;
        }
        if (get_post_type($pid) !== 'attachment') {
            return false;
        }
        $mime = get_post_mime_type($pid);
        if (!is_string($mime) || $mime === '') {
            return false;
        }
        return strpos($mime, 'image/') === 0;
    }

    /**
     * Find attachment ID by filename.
     *
     * Searches for attachments where _wp_attached_file basename matches the filename.
     * Optionally filters by MIME type.
     *
     * @param string $filename Filename to search for.
     * @param string $mime     Optional MIME type filter.
     * @return int Attachment ID or 0 if not found.
     */
    private static function find_attachment_id_by_filename($filename, $mime = '')
    {
        global $wpdb;

        $filename = sanitize_file_name((string) $filename);
        if ($filename === '') {
            return 0;
        }

        $like = '%/' . $wpdb->esc_like($filename);

        $sql = "
            SELECT p.ID
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
            WHERE p.post_type = 'attachment'
              AND p.post_status = 'inherit'
              AND pm.meta_key = '_wp_attached_file'
              AND (pm.meta_value = %s OR pm.meta_value LIKE %s)
            ORDER BY p.ID DESC
            LIMIT 50
        ";

        $ids = $wpdb->get_col($wpdb->prepare($sql, $filename, $like));
        if (empty($ids) || !is_array($ids)) {
            return 0;
        }

        $mime_norm = strtolower(trim(explode(';', (string) $mime)[0]));

        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id <= 0) {
                continue;
            }

            $path = get_attached_file($id);
            if (!is_string($path) || $path === '') {
                continue;
            }

            // Verify exact filename match
            if (wp_basename($path) !== $filename) {
                continue;
            }

            // Verify MIME type if specified (except SVG)
            if ($mime_norm !== '' && $mime_norm !== 'image/svg+xml') {
                $pm = (string) get_post_mime_type($id);
                if ($pm !== $mime_norm) {
                    continue;
                }
            }

            return $id;
        }

        return 0;
    }

    /**
     * Get attachment filename from _wp_attached_file or URL.
     *
     * @param int $attachment_id Attachment post ID.
     * @return string Sanitized filename or empty string if not found.
     */
    private static function get_attachment_basename($attachment_id)
    {
        $aid = (int) $attachment_id;
        if ($aid <= 0) {
            return '';
        }

        // Try _wp_attached_file meta first
        $attached = get_post_meta($aid, '_wp_attached_file', true);
        $attached = is_string($attached) ? $attached : '';
        if ($attached !== '') {
            return sanitize_file_name(wp_basename($attached));
        }

        // Fallback to URL
        $url = wp_get_attachment_url($aid);
        if (!is_string($url) || $url === '') {
            return '';
        }

        $path = wp_parse_url($url, PHP_URL_PATH);
        $file = is_string($path) ? wp_basename($path) : '';
        $file = is_string($file) ? $file : '';

        return $file !== '' ? sanitize_file_name($file) : '';
    }

    /**
     * Recursively replace attachment IDs in a value.
     *
     * Handles:
     * - Integer IDs
     * - String IDs (numeric)
     * - Comma-separated ID lists
     * - Arrays and objects (with special handling for 'ID' keys)
     *
     * Uses static cache to normalize ID map once per call for performance.
     *
     * @param mixed $value Value to process.
     * @param array $map   Mapping of old IDs to new IDs.
     * @param int   $depth Current recursion depth.
     * @return mixed Value with replaced IDs.
     */
    private static function replace_recursive($value, $map, $depth)
    {
        // Prevent infinite recursion
        if ($depth > 15) {
            return $value;
        }

        // Normalize map once and cache it (for both int and string keys)
        static $normalized_maps = [];
        $map_hash = md5(serialize(array_keys($map)));

        if (!isset($normalized_maps[$map_hash])) {
            $normalized = [];
            foreach ($map as $old => $new) {
                $old_int = (int) $old;
                $new_int = (int) $new;
                if ($old_int > 0 && $new_int > 0) {
                    $normalized[$old_int] = $new_int;
                    $normalized[(string) $old_int] = $new_int;
                }
            }
            $normalized_maps[$map_hash] = $normalized;
        }
        $map = $normalized_maps[$map_hash];

        // Replace integer IDs
        if (is_int($value) && $value > 0) {
            if (isset($map[$value])) {
                $new_id = (int) $map[$value];
                return $new_id;
            }
            $key_str = (string) $value;
            if (isset($map[$key_str])) {
                $new_id = (int) $map[$key_str];
                return $new_id;
            }
            return $value;
        }

        // Replace numeric string IDs
        if (is_string($value) && ctype_digit($value)) {
            $id = (int) $value;
            if ($id > 0) {
                if (isset($map[$id])) {
                    $new_id = (string) ((int) $map[$id]);
                    return $new_id;
                }
                if (isset($map[$value])) {
                    $new_id = (string) ((int) $map[$value]);
                    return $new_id;
                }
            }
            return $value;
        }

        // Replace comma-separated ID lists
        if (is_string($value) && preg_match('/^\d+(?:\s*,\s*\d+)+$/', $value)) {
            $parts = preg_split('/\s*,\s*/', $value);
            if (is_array($parts)) {
                $new = [];
                $changed = false;
                foreach ($parts as $p) {
                    $id = (int) $p;
                    if ($id > 0) {
                        if (isset($map[$id])) {
                            $new[] = (string) ((int) $map[$id]);
                            $changed = true;
                        } elseif (isset($map[(string) $id])) {
                            $new[] = (string) ((int) $map[(string) $id]);
                            $changed = true;
                        } else {
                            $new[] = (string) $id;
                        }
                    }
                }
                $result = implode(',', $new);
                return $result;
            }
        }

        // Replace in arrays (recurse and handle 'ID' key specially)
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = self::replace_recursive($v, $map, $depth + 1);
            }

            // Special handling for 'ID' key (common in WP objects)
            if (isset($out['ID']) && is_numeric($out['ID'])) {
                $id = (int) $out['ID'];
                if ($id > 0 && isset($map[$id])) {
                    $out['ID'] = (int) $map[$id];
                } elseif ($id > 0 && isset($map[(string) $id])) {
                    $out['ID'] = (int) $map[(string) $id];
                }
            }

            return $out;
        }

        // Replace in objects (convert to array, recurse, convert back)
        if (is_object($value)) {
            $arr = (array) $value;
            $arr = self::replace_recursive($arr, $map, $depth + 1);
            return (object) $arr;
        }

        return $value;
    }
}