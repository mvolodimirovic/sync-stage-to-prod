<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Sync_Validator
 *
 * Helper class that validates and sanitizes data for the Stage -> Prod sync plugin.
 * Responsibilities:
 * - Validate/normalize REST endpoint path.
 * - Sanitize meta/ACF values safely (including nested arrays/objects).
 * - Validate auth token format.
 * - Validate URLs.
 * - Validate whether a post type / taxonomy is eligible for syncing.
 */
class Sync_Validator
{
    /**
     * Validate and normalize REST endpoint base path.
     *
     * Rules:
     * - Must be a string and not empty; otherwise returns default path.
     * - Must start with "/" and must not end with "/".
     * - Must start with one of allowed REST prefixes:
     *   - "/wp-json/"
     *   - "/{rest_prefix}/" where rest_prefix is rest_get_url_prefix()
     * - Must not contain ".." path traversal.
     * - Must not contain HTML/quote characters.
     * - Must match allowed characters set and have reasonable number of segments.
     *
     * @param mixed $path
     * @return string Normalized endpoint path, or default "/wp-json/sync-stp/v1" on invalid input.
     */
    public static function validate_endpoint_path($path)
    {
        $default_path = '/wp-json/sync-stp/v1';

        if (!is_string($path)) {
            return $default_path;
        }

        $path = trim($path);

        if ($path === '') {
            return $default_path;
        }

        if (strpos($path, '/') !== 0) {
            $path = '/' . $path;
        }

        $path = rtrim($path, '/');

        $rest_base = rest_get_url_prefix();

        $valid_prefixes = [
            '/wp-json/',
            '/' . $rest_base . '/',
        ];

        $is_valid = false;
        foreach ($valid_prefixes as $prefix) {
            if (strpos($path, $prefix) === 0) {
                $is_valid = true;
                break;
            }
        }

        if (!$is_valid) {
            return $default_path;
        }

        if (strpos($path, '..') !== false) {
            return $default_path;
        }

        if (preg_match('/[<>"\']/', $path)) {
            return $default_path;
        }

        if (!preg_match('#^/[a-zA-Z0-9/_.-]+$#', $path)) {
            return $default_path;
        }

        $parts = explode('/', trim($path, '/'));

        if (count($parts) < 2) {
            return $default_path;
        }

        if (count($parts) > 10) {
            return $default_path;
        }

        foreach ($parts as $part) {
            if ($part === '' || !preg_match('/^[a-zA-Z0-9_.-]+$/', $part)) {
                return $default_path;
            }
        }

        return $path;
    }

    /**
     * Decode WordPress wp_json_encode Unicode escape sequences that lost their backslash.
     *
     * Converts literal u003C → <, u003E → >, u0022 → ", u0027 → ', u0026 → &
     * These appear when wp_json_encode() HTML values go through double serialization
     * (e.g., ACF block data inside Gutenberg serialize_blocks, or nested meta serialization).
     *
     * @param mixed $value  Value to decode (string, array, or other).
     * @param int   $depth  Current recursion depth.
     * @return mixed Decoded value.
     */
    public static function decode_unicode_escapes($value, $depth = 0)
    {
        if ($depth > 12) {
            return $value;
        }

        if (is_string($value)) {
            // Only process strings that actually contain the broken pattern
            if (strpos($value, 'u00') === false && strpos($value, 'u003') === false) {
                return $value;
            }

            // Check if this looks like it has unicode escape sequences
            // Pattern: u followed by exactly 4 hex digits, appearing near HTML-like content
            if (!preg_match('/u00[0-9a-fA-F]{2}|u003[cCeE]/', $value)) {
                return $value;
            }

            // Decode known wp_json_encode Unicode escapes
            $search  = ['u003C', 'u003c', 'u003E', 'u003e', 'u0022', 'u0026', 'u0027'];
            $replace = ['<',     '<',     '>',     '>',     '"',     '&',     "'"];
            $decoded = str_replace($search, $replace, $value);

            // Also handle \uXXXX patterns (with backslash) that may remain
            if (strpos($decoded, '\\u00') !== false || strpos($decoded, '\\u003') !== false) {
                $decoded = preg_replace_callback(
                    '/\\\\u([0-9a-fA-F]{4})/',
                    function ($matches) {
                        $char = mb_convert_encoding(
                            pack('H*', $matches[1]),
                            'UTF-8',
                            'UTF-16BE'
                        );
                        return is_string($char) ? $char : $matches[0];
                    },
                    $decoded
                );
            }

            return is_string($decoded) ? $decoded : $value;
        }

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = self::decode_unicode_escapes($item, $depth + 1);
            }
            return $value;
        }

        if (is_object($value)) {
            return self::decode_unicode_escapes((array) $value, $depth + 1);
        }

        return $value;
    }

    /**
     * Sanitize any meta-like value recursively.
     *
     * Notes:
     * - Supports nested arrays/objects up to a max depth to avoid recursion issues.
     * - Preserves null/bool/int/float types.
     * - Strings are sanitized depending on whether they contain HTML or line breaks.
     * - Arrays are sanitized key-by-key and value-by-value.
     *
     * @param mixed $value
     * @param int $depth
     * @param bool $is_html If true, treats strings as HTML and uses wp_kses_post.
     * @return mixed
     */
    public static function sanitize_meta_value($value, $depth = 0, $is_html = false)
    {
        if ($depth > 10) {
            return '';
        }

        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return (int) $value;
        }

        if (is_float($value)) {
            return (float) $value;
        }

        if (is_string($value)) {
            return self::sanitize_string_value($value, $is_html);
        }

        if (is_array($value)) {
            return self::sanitize_array_value($value, $depth, $is_html);
        }

        if (is_object($value)) {
            return self::sanitize_meta_value((array) $value, $depth, $is_html);
        }

        return '';
    }

    /**
     * Sanitize a string value.
     *
     * Behavior:
     * - If it looks like a URL, validate scheme and return esc_url_raw for http/https.
     * - If HTML is expected or detected, return wp_kses_post.
     * - If it contains newlines, sanitize as textarea.
     * - Otherwise sanitize as plain text.
     *
     * @param string $value
     * @param bool $is_html
     * @return string
     */
    private static function sanitize_string_value($value, $is_html = false)
    {
        if ($value === '') {
            return '';
        }

        if (filter_var($value, FILTER_VALIDATE_URL)) {
            $sanitized = esc_url_raw($value);
            $parsed = wp_parse_url($sanitized);

            if (isset($parsed['scheme']) && in_array($parsed['scheme'], ['http', 'https'], true)) {
                return $sanitized;
            }

            return sanitize_text_field($value);
        }

        if ($is_html || self::contains_html($value)) {
            return wp_kses_post($value);
        }

        if (strpos($value, "\n") !== false || strpos($value, "\r") !== false) {
            return sanitize_textarea_field($value);
        }

        return sanitize_text_field($value);
    }

    /**
     * Sanitize array values recursively.
     * Uses sanitize_key for string keys to normalize keys used in meta/ACF-like arrays.
     *
     * @param array $value
     * @param int $depth
     * @param bool $is_html
     * @return array
     */
    private static function sanitize_array_value($value, $depth = 0, $is_html = false)
    {
        $sanitized = [];

        foreach ($value as $key => $item) {
            $sanitized_key = is_string($key) ? sanitize_key($key) : $key;
            $sanitized[$sanitized_key] = self::sanitize_meta_value($item, $depth + 1, $is_html);
        }

        return $sanitized;
    }

    /**
     * Detect whether a string contains HTML tags.
     *
     * @param mixed $value
     * @return bool
     */
    private static function contains_html($value)
    {
        if (!is_string($value)) {
            return false;
        }

        return $value !== strip_tags($value);
    }

    /**
     * Sanitize an ACF value using field type hints when available.
     *
     * If ACF field object can be resolved:
     * - wysiwyg/textarea => decode unicode escapes, then sanitize as HTML
     * - url => allow only http/https URLs
     * - email => sanitize_email
     * - number => numeric or 0
     * - true_false/checkbox => boolean
     * - image/file/gallery/repeater/group/flexible_content => recursive sanitization
     * Otherwise, falls back to generic recursive sanitization.
     *
     * @param mixed $value
     * @param string $field_name ACF field name/key for get_field_object lookup.
     * @param mixed $context Optional ACF context (post_id, 'term_123', 'user_456', etc.)
     * @return mixed
     */
    public static function sanitize_acf_field($value, $field_name = '', $context = false)
    {
        if (function_exists('get_field_object') && $field_name !== '') {
            $field_obj = $context !== false ? get_field_object($field_name, $context) : get_field_object($field_name);

            if ($field_obj && isset($field_obj['type'])) {
                $field_type = $field_obj['type'];

                switch ($field_type) {
                    case 'wysiwyg':
                    case 'textarea':
                        // FIX: Decode unicode escapes before sanitizing HTML
                        $value = self::decode_unicode_escapes($value);
                        return self::sanitize_meta_value($value, 0, true);

                    case 'url':
                        if (is_string($value)) {
                            $sanitized = esc_url_raw($value);
                            $parsed = wp_parse_url($sanitized);

                            if (isset($parsed['scheme']) && in_array($parsed['scheme'], ['http', 'https'], true)) {
                                return $sanitized;
                            }
                        }
                        return '';

                    case 'email':
                        return is_string($value) ? sanitize_email($value) : '';

                    case 'number':
                        return is_numeric($value) ? $value : 0;

                    case 'true_false':
                    case 'checkbox':
                        return (bool) $value;

                    case 'image':
                    case 'file':
                    case 'gallery':
                        // FIX: Decode unicode escapes in nested media structures
                        $value = self::decode_unicode_escapes($value);
                        return self::sanitize_meta_value($value, 0, false);

                    case 'repeater':
                    case 'group':
                    case 'flexible_content':
                    case 'clone':
                        // FIX: Decode unicode escapes in nested ACF structures
                        // This is critical for clone→group→repeater→editor chains
                        $value = self::decode_unicode_escapes($value);
                        return self::sanitize_meta_value($value, 0, false);

                    case 'icon_picker':
                        if (is_array($value)) {
                            if (isset($value['type']) && isset($value['value'])) {
                                $icon_value = $value['value'];
                                
                                if (is_array($icon_value)) {
                                    if (isset($icon_value['id'])) {
                                        $value['value'] = (int) $icon_value['id'];
                                    } elseif (isset($icon_value['ID'])) {
                                        $value['value'] = (int) $icon_value['ID'];
                                    }
                                }
                            }
                            return $value;
                        }
                        return $value;

                    default:
                        // FIX: Decode unicode escapes for any unknown field type
                        $value = self::decode_unicode_escapes($value);
                        return self::sanitize_meta_value($value, 0, false);
                }
            }
        }

        // FIX: Decode unicode escapes even when field type is unknown
        // This handles nested fields where get_field_object() can't resolve the type
        $value = self::decode_unicode_escapes($value);
        return self::sanitize_meta_value($value, 0, false);
    }

    /**
     * Validate auth token format used for sync requests.
     *
     * Rules:
     * - String length between 32 and 255.
     * - Allowed characters: alnum plus "_=+-"
     *
     * @param mixed $token
     * @return bool
     */
    public static function is_valid_auth_token($token)
    {
        if (!is_string($token)) {
            return false;
        }

        $token = trim($token);

        if (strlen($token) < 32) {
            return false;
        }

        if (strlen($token) > 255) {
            return false;
        }

        if (!preg_match('/^[a-zA-Z0-9_=+-]+$/', $token)) {
            return false;
        }

        return true;
    }

    /**
     * Validate and sanitize a URL (http/https) for configuration usage.
     *
     * Returns:
     * - Sanitized URL string on success
     * - false on failure
     *
     * @param mixed $url
     * @return string|false
     */
    public static function validate_url($url)
    {
        if (!is_string($url)) {
            return false;
        }

        $url = trim($url);

        if ($url === '') {
            return false;
        }

        $sanitized_url = esc_url_raw($url);

        if (!filter_var($sanitized_url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $parsed = wp_parse_url($sanitized_url);

        if (!isset($parsed['scheme']) || !isset($parsed['host'])) {
            return false;
        }

        if (!in_array($parsed['scheme'], ['http', 'https'], true)) {
            return false;
        }

        return $sanitized_url;
    }

    /**
     * Check whether a post type is valid to be synced.
     *
     * Rules:
     * - Must exist in WordPress.
     * - "attachment" is always rejected.
     * - Post type must be public or at least have show_ui = true.
     *
     * @param mixed $post_type
     * @return bool
     */
    public static function is_valid_post_type($post_type)
    {
        if (!is_string($post_type)) {
            return false;
        }

        $post_type = sanitize_key($post_type);

        if (!post_type_exists($post_type)) {
            return false;
        }

        if ($post_type === 'attachment') {
            return false;
        }

        $obj = get_post_type_object($post_type);
        if (!$obj) {
            return false;
        }

        if (!empty($obj->public)) {
            return true;
        }

        if (!empty($obj->show_ui)) {
            return true;
        }

        return false;
    }

    /**
     * Check whether a taxonomy is valid to be synced.
     *
     * Rules:
     * - Must exist in WordPress.
     * - Taxonomy must be public or at least have show_ui = true.
     *
     * @param mixed $taxonomy
     * @return bool
     */
    public static function is_valid_taxonomy($taxonomy)
    {
        if (!is_string($taxonomy)) {
            return false;
        }

        $taxonomy = sanitize_key($taxonomy);

        if (!taxonomy_exists($taxonomy)) {
            return false;
        }

        $obj = get_taxonomy($taxonomy);
        if (!$obj) {
            return false;
        }

        if (!empty($obj->public)) {
            return true;
        }

        if (!empty($obj->show_ui)) {
            return true;
        }

        return false;
    }
}