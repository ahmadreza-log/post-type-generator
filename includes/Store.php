<?php
/**
 * Saved post type definitions.
 *
 * Each definition is one associative array stored under `ptg_post_types`,
 * keyed by the post type key. The shape is fixed by `defaults()` and repaired
 * by `normalize()` whenever the option is read, so older rows gain new fields
 * without a migration.
 *
 * A successful save or delete sets the `ptg_flush_rewrite` flag. Registrar
 * consumes that flag on the following request and rebuilds permalinks after
 * every post type has been registered.
 *
 * Posts are never deleted from here. Removing a definition only stops
 * WordPress from registering that key.
 *
 * @package PostTypeGenerator
 */

declare(strict_types=1);

namespace PostTypeGenerator;

defined('ABSPATH') || exit;

final class Store
{
    /**
     * Option that holds every generated post type, keyed by its slug.
     */
    public const OPTION = 'ptg_post_types';

    /**
     * Flag consumed on the next request to rebuild rewrite rules.
     * Stored as the string `1` and not autoloaded.
     */
    public const FLUSH = 'ptg_flush_rewrite';

    /**
     * Feature keys WordPress accepts in `register_post_type()` `supports`.
     *
     * Unknown keys submitted from the admin form are discarded. `page-attributes`
     * is added automatically when the type is hierarchical.
     *
     * @return list<string> Allowed support keys, in screen order.
     */
    public static function supportKeys(): array
    {
        return [
            'title',
            'editor',
            'excerpt',
            'thumbnail',
            'comments',
            'trackbacks',
            'revisions',
            'author',
            'page-attributes',
            'custom-fields',
            'post-formats',
        ];
    }

    /**
     * Keys that must not be registered by this plugin.
     *
     * The list covers built-in post types plus query variables that collide
     * with WordPress routing (`author`, `order`, `theme`, and similar).
     * Anything that already exists at runtime is rejected separately by
     * `post_type_exists()`.
     *
     * @return list<string> Reserved post type keys.
     */
    public static function reserved(): array
    {
        return [
            'post',
            'page',
            'attachment',
            'revision',
            'nav_menu_item',
            'custom_css',
            'customize_changeset',
            'oembed_cache',
            'user_request',
            'wp_block',
            'wp_template',
            'wp_template_part',
            'wp_global_styles',
            'wp_navigation',
            'wp_font_family',
            'wp_font_face',
            'action',
            'author',
            'order',
            'theme',
        ];
    }

    /**
     * Field defaults for a new post type.
     *
     * The admin form starts from this array. A stored row is merged back to
     * the same keys in `normalize()`, so every reader can rely on the shape.
     *
     * @return array<string, mixed> Definition with empty labels and the usual public flags.
     */
    public static function defaults(): array
    {
        return [
            'slug' => '',
            'singular' => '',
            'plural' => '',
            'description' => '',
            'enabled' => true,
            'public' => true,
            'show_ui' => true,
            'show_in_rest' => true,
            'has_archive' => true,
            'hierarchical' => false,
            'exclude_from_search' => false,
            'rewrite_slug' => '',
            'with_front' => true,
            'menu_icon' => 'dashicons-admin-post',
            'menu_position' => null,
            'supports' => ['title', 'editor', 'thumbnail'],
            'taxonomies' => [],
        ];
    }

    /**
     * Return every saved definition, keyed by its post type key.
     *
     * Rows that are not arrays, or whose key sanitizes to an empty string,
     * are skipped. Each remaining row is passed through `normalize()`.
     *
     * @return array<string, array<string, mixed>> Map of slug => definition.
     */
    public static function all(): array
    {
        $stored = get_option(self::OPTION, []);

        if (!is_array($stored)) {
            return [];
        }

        $types = [];

        foreach ($stored as $key => $type) {
            if (!is_array($type)) {
                continue;
            }

            $normalized = self::normalize($type);
            $slug = $normalized['slug'] !== '' ? $normalized['slug'] : self::cleanSlug((string) $key);

            if ($slug === '') {
                continue;
            }

            $normalized['slug'] = $slug;
            $types[$slug] = $normalized;
        }

        return $types;
    }

    /**
     * Return one saved definition.
     *
     * @param string $slug Post type key, already sanitized.
     * @return array<string, mixed>|null Definition, or null when the key is unknown.
     */
    public static function get(string $slug): ?array
    {
        $all = self::all();

        return $all[$slug] ?? null;
    }

    /**
     * Build a definition from an admin POST body.
     *
     * Checkboxes that are absent are stored as false. The menu position is
     * null when the field is empty, the parsed integer when it is 0–999,
     * and -1 when the value is not a 1–3 digit number so `validate()` can
     * reject it. Hierarchical types always receive `page-attributes`.
     *
     * This method does not write the database and does not check uniqueness.
     *
     * @param array<string, mixed> $input Unslashed `$_POST` fields.
     * @return array<string, mixed> Normalized definition, possibly still invalid.
     */
    public static function fromRequest(array $input): array
    {
        $supports = [];

        if (isset($input['supports']) && is_array($input['supports'])) {
            foreach ($input['supports'] as $support) {
                $support = sanitize_key((string) $support);

                if (in_array($support, self::supportKeys(), true)) {
                    $supports[] = $support;
                }
            }
        }

        $taxonomies = [];

        if (isset($input['taxonomies']) && is_array($input['taxonomies'])) {
            foreach ($input['taxonomies'] as $taxonomy) {
                $taxonomy = sanitize_key((string) $taxonomy);

                if ($taxonomy !== '') {
                    $taxonomies[] = $taxonomy;
                }
            }
        }

        $position = null;
        $raw = isset($input['menu_position']) ? trim((string) $input['menu_position']) : '';

        if ($raw !== '') {
            $position = preg_match('/^\d{1,3}$/', $raw) ? (int) $raw : -1;
        }

        $type = self::normalize([
            'slug' => (string) ($input['slug'] ?? ''),
            'singular' => (string) ($input['singular'] ?? ''),
            'plural' => (string) ($input['plural'] ?? ''),
            'description' => (string) ($input['description'] ?? ''),
            'enabled' => !empty($input['enabled']),
            'public' => !empty($input['public']),
            'show_ui' => !empty($input['show_ui']),
            'show_in_rest' => !empty($input['show_in_rest']),
            'has_archive' => !empty($input['has_archive']),
            'hierarchical' => !empty($input['hierarchical']),
            'exclude_from_search' => !empty($input['exclude_from_search']),
            'rewrite_slug' => (string) ($input['rewrite_slug'] ?? ''),
            'with_front' => !empty($input['with_front']),
            'menu_icon' => (string) ($input['menu_icon'] ?? ''),
            'menu_position' => $position,
            'supports' => array_values(array_unique($supports)),
            'taxonomies' => array_values(array_unique($taxonomies)),
        ]);

        if ($type['hierarchical'] && !in_array('page-attributes', $type['supports'], true)) {
            $type['supports'][] = 'page-attributes';
        }

        if ($position === -1) {
            $type['menu_position'] = -1;
        }

        return $type;
    }

    /**
     * Insert or update one definition and schedule a rewrite flush.
     *
     * Taxonomies that are not registered on this request are removed before
     * the option is written. The post type key cannot change: `$original`
     * must be empty for a new type and must match `$type['slug']` for an edit.
     *
     * @param array<string, mixed> $type     Definition from `fromRequest()`.
     * @param string               $original Previous key. Empty when creating.
     * @return bool|\WP_Error True on success, or a translated error.
     */
    public static function save(array $type, string $original)
    {
        $validated = self::validate($type, $original);

        if (is_wp_error($validated)) {
            return $validated;
        }

        $type['taxonomies'] = array_values(array_filter(
            $type['taxonomies'],
            static function (string $taxonomy): bool {
                return $taxonomy !== '' && taxonomy_exists($taxonomy);
            }
        ));

        $all = self::all();
        $all[$type['slug']] = $type;
        update_option(self::OPTION, $all, true);
        self::markFlush();

        return true;
    }

    /**
     * Remove one definition and schedule a rewrite flush.
     *
     * Posts with this `post_type` value stay in the posts table.
     *
     * @param string $slug Post type key.
     * @return bool True when a row was removed. False when the key was unknown.
     */
    public static function delete(string $slug): bool
    {
        $all = self::all();

        if (!isset($all[$slug])) {
            return false;
        }

        unset($all[$slug]);
        update_option(self::OPTION, $all, true);
        self::markFlush();

        return true;
    }

    /**
     * Ask the next request to rebuild rewrite rules.
     *
     * The flag is not autoloaded. Registrar deletes it and calls
     * `flush_rewrite_rules()` after registration. Calling the flush during
     * the save request would miss a type that was not registered yet.
     *
     * @return void
     */
    public static function markFlush(): void
    {
        update_option(self::FLUSH, '1', false);
    }

    /**
     * Force one row into the `defaults()` shape.
     *
     * Labels are trimmed, the key is reduced to lowercase letters, digits,
     * and underscores, and the icon is coerced to a Dashicon class. An
     * explicit empty `supports` list is kept; a missing list falls back to
     * the defaults.
     *
     * @param array<string, mixed> $type Raw or previously saved row.
     * @return array<string, mixed> Clean definition.
     */
    private static function normalize(array $type): array
    {
        $defaults = self::defaults();
        $supports = $defaults['supports'];

        if (isset($type['supports']) && is_array($type['supports'])) {
            $supports = [];

            foreach ($type['supports'] as $support) {
                $support = sanitize_key((string) $support);

                if (in_array($support, self::supportKeys(), true)) {
                    $supports[] = $support;
                }
            }
        }

        $taxonomies = [];

        if (isset($type['taxonomies']) && is_array($type['taxonomies'])) {
            foreach ($type['taxonomies'] as $taxonomy) {
                $taxonomy = sanitize_key((string) $taxonomy);

                if ($taxonomy !== '') {
                    $taxonomies[] = $taxonomy;
                }
            }
        }

        $position = null;

        if (array_key_exists('menu_position', $type) && $type['menu_position'] !== null && $type['menu_position'] !== '') {
            $position = (int) $type['menu_position'];
        }

        return [
            'slug' => self::cleanSlug((string) ($type['slug'] ?? '')),
            'singular' => self::cleanLabel((string) ($type['singular'] ?? '')),
            'plural' => self::cleanLabel((string) ($type['plural'] ?? '')),
            'description' => self::cleanText((string) ($type['description'] ?? '')),
            'enabled' => array_key_exists('enabled', $type) ? (bool) $type['enabled'] : true,
            'public' => array_key_exists('public', $type) ? (bool) $type['public'] : true,
            'show_ui' => array_key_exists('show_ui', $type) ? (bool) $type['show_ui'] : true,
            'show_in_rest' => array_key_exists('show_in_rest', $type) ? (bool) $type['show_in_rest'] : true,
            'has_archive' => array_key_exists('has_archive', $type) ? (bool) $type['has_archive'] : true,
            'hierarchical' => !empty($type['hierarchical']),
            'exclude_from_search' => !empty($type['exclude_from_search']),
            'rewrite_slug' => sanitize_title((string) ($type['rewrite_slug'] ?? '')),
            'with_front' => array_key_exists('with_front', $type) ? (bool) $type['with_front'] : true,
            'menu_icon' => self::cleanIcon((string) ($type['menu_icon'] ?? '')),
            'menu_position' => $position,
            'supports' => array_values(array_unique($supports)),
            'taxonomies' => array_values(array_unique($taxonomies)),
        ];
    }

    /**
     * Reject keys and labels that must not be stored.
     *
     * The key must match `^[a-z][a-z0-9_]{0,19}$`, must not be reserved, and
     * must not belong to a post type this plugin does not already own.
     * Creating a duplicate key and renaming an existing key are both errors.
     * Singular and plural labels are required. Menu position, when set, must
     * be an integer from 0 through 999.
     *
     * @param array<string, mixed> $type     Definition to check.
     * @param string               $original Previous key. Empty when creating.
     * @return bool|\WP_Error True when the row may be stored.
     */
    private static function validate(array $type, string $original)
    {
        $slug = (string) $type['slug'];

        if ($slug === '') {
            return new \WP_Error(
                'ptg_slug',
                __('The key must use lowercase English letters, numbers, and underscores.', 'post-type-generator')
            );
        }

        if (!preg_match('/^[a-z][a-z0-9_]{0,19}$/', $slug)) {
            return new \WP_Error(
                'ptg_slug_format',
                __('The key must start with a letter, use only lowercase letters, numbers, and underscores, and be at most 20 characters.', 'post-type-generator')
            );
        }

        if ($original !== '' && $original !== $slug) {
            return new \WP_Error(
                'ptg_slug_locked',
                __('The post type key cannot be changed after it is created.', 'post-type-generator')
            );
        }

        if (str_starts_with($slug, 'wp_') || in_array($slug, self::reserved(), true)) {
            return new \WP_Error(
                'ptg_reserved',
                __('This key is reserved by WordPress.', 'post-type-generator')
            );
        }

        $owned = self::get($slug) !== null;

        if ($original === '' && ($owned || post_type_exists($slug))) {
            return new \WP_Error(
                'ptg_exists',
                __('A post type with this key is already registered.', 'post-type-generator')
            );
        }

        if ($original !== '' && !$owned) {
            return new \WP_Error(
                'ptg_missing',
                __('This post type no longer exists.', 'post-type-generator')
            );
        }

        if (post_type_exists($slug) && !$owned) {
            return new \WP_Error(
                'ptg_exists',
                __('A post type with this key is already registered.', 'post-type-generator')
            );
        }

        if ($type['singular'] === '' || $type['plural'] === '') {
            return new \WP_Error(
                'ptg_labels',
                __('Singular and plural names are required.', 'post-type-generator')
            );
        }

        $position = $type['menu_position'];

        if ($position !== null && ($position < 0 || $position > 999)) {
            return new \WP_Error(
                'ptg_position',
                __('Menu position must be a number from 0 to 999.', 'post-type-generator')
            );
        }

        return true;
    }

    /**
     * Reduce free text to a post type key.
     *
     * Letters are lowercased. Every run of characters outside `a-z` and `0-9`
     * becomes one underscore, and underscores at either end are removed.
     * Length is not truncated here; `validate()` rejects keys longer than
     * 20 characters so the author sees the error.
     *
     * @param string $slug Raw key from the form or the database.
     * @return string Sanitized key, possibly empty or longer than 20 characters.
     */
    private static function cleanSlug(string $slug): string
    {
        $slug = strtolower(trim($slug));
        $slug = (string) preg_replace('/[^a-z0-9]+/', '_', $slug);

        return trim($slug, '_');
    }

    /**
     * Sanitize a singular or plural label and cap it at 80 characters.
     *
     * Uses `mb_substr()` when the mbstring extension is present so Persian
     * labels are not cut in the middle of a character.
     *
     * @param string $value Raw label.
     * @return string Plain text label.
     */
    private static function cleanLabel(string $value): string
    {
        $value = sanitize_text_field($value);

        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, 80);
        }

        return substr($value, 0, 80);
    }

    /**
     * Sanitize the description and cap it at 300 characters.
     *
     * @param string $value Raw textarea value.
     * @return string Plain text description.
     */
    private static function cleanText(string $value): string
    {
        $value = sanitize_textarea_field($value);

        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, 300);
        }

        return substr($value, 0, 300);
    }

    /**
     * Coerce a menu icon to a Dashicon class.
     *
     * A bare name such as `book` becomes `dashicons-book`. Empty, incomplete,
     * or oversized values fall back to `dashicons-admin-post`.
     *
     * @param string $icon Raw class or short name.
     * @return string Class that matches `dashicons-[a-z0-9-]+`.
     */
    private static function cleanIcon(string $icon): string
    {
        $icon = strtolower(trim($icon));
        $icon = (string) preg_replace('/[^a-z0-9\-]/', '', $icon);

        if ($icon === '' || $icon === 'dashicons' || $icon === 'dashicons-') {
            return 'dashicons-admin-post';
        }

        if (!str_starts_with($icon, 'dashicons-')) {
            $icon = 'dashicons-' . ltrim($icon, '-');
        }

        if (strlen($icon) > 64 || !preg_match('/^dashicons-[a-z0-9-]+$/', $icon)) {
            return 'dashicons-admin-post';
        }

        return $icon;
    }
}
