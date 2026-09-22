<?php
/**
 * Registers saved post types on init.
 *
 * Disabled rows, empty labels, reserved keys, and keys already registered
 * by another plugin or the theme are skipped. A theme type such as `spell`
 * therefore keeps its own registration.
 *
 * After registration, a pending flush flag triggers `flush_rewrite_rules()`.
 * The call is made from `init`, and WordPress defers the actual write until
 * `wp_loaded`, so types registered later in `init` are included.
 *
 * Developers can replace the argument array for one type:
 *
 *     add_filter('ptg_post_type_args', function (array $args, array $type): array {
 *         $args['menu_position'] = 26;
 *         return $args;
 *     }, 10, 2);
 *
 * The filter receives the `register_post_type()` arguments and the saved definition.
 *
 * @package PostTypeGenerator
 */

declare(strict_types=1);

namespace PostTypeGenerator;

defined('ABSPATH') || exit;

final class Registrar
{
    /**
     * Hook registration and the editor title placeholder.
     *
     * Registration runs at priority 11 so theme types registered at the
     * default priority 10 win when a key collides.
     *
     * @return void
     */
    public static function boot(): void
    {
        add_action('init', [self::class, 'register'], 11);
        add_filter('enter_title_here', [self::class, 'titlePlaceholder'], 10, 2);
    }

    /**
     * Register every enabled definition, then flush permalinks if requested.
     *
     * @return void
     */
    public static function register(): void
    {
        foreach (Store::all() as $type) {
            if (empty($type['enabled'])) {
                continue;
            }

            $slug = (string) $type['slug'];

            if (
                $slug === ''
                || $type['singular'] === ''
                || $type['plural'] === ''
                || !preg_match('/^[a-z][a-z0-9_]{0,19}$/', $slug)
                || str_starts_with($slug, 'wp_')
                || in_array($slug, Store::reserved(), true)
                || post_type_exists($slug)
            ) {
                continue;
            }

            $args = apply_filters('ptg_post_type_args', self::args($type), $type);

            if (!is_array($args)) {
                continue;
            }

            register_post_type($slug, $args);
        }

        if (get_option(Store::FLUSH)) {
            delete_option(Store::FLUSH);
            flush_rewrite_rules();
        }
    }

    /**
     * Build the argument array passed to `register_post_type()`.
     *
     * Public types are publicly queryable and can appear in nav menus.
     * Admin UI flags follow `show_ui`. The archive slug matches the rewrite
     * base. Taxonomies that are not registered yet are omitted. `menu_position`
     * is included only when the author set a number.
     *
     * @param array<string, mixed> $type Saved definition.
     * @return array<string, mixed> Arguments for `register_post_type()`.
     */
    public static function args(array $type): array
    {
        $slug = (string) $type['slug'];
        $base = (string) $type['rewrite_slug'];

        if ($base === '') {
            $base = $slug;
        }

        $taxonomies = [];

        foreach ($type['taxonomies'] as $taxonomy) {
            $taxonomy = (string) $taxonomy;

            if ($taxonomy !== '' && taxonomy_exists($taxonomy)) {
                $taxonomies[] = $taxonomy;
            }
        }

        $args = [
            'labels' => self::labels($type),
            'description' => (string) $type['description'],
            'public' => (bool) $type['public'],
            'publicly_queryable' => (bool) $type['public'],
            'show_ui' => (bool) $type['show_ui'],
            'show_in_menu' => (bool) $type['show_ui'],
            'show_in_admin_bar' => (bool) $type['show_ui'],
            'show_in_nav_menus' => (bool) $type['public'],
            'show_in_rest' => (bool) $type['show_in_rest'],
            'rest_base' => $slug,
            'has_archive' => $type['has_archive'] ? $base : false,
            'hierarchical' => (bool) $type['hierarchical'],
            'exclude_from_search' => (bool) $type['exclude_from_search'],
            'menu_icon' => (string) $type['menu_icon'],
            'supports' => array_values($type['supports']),
            'taxonomies' => $taxonomies,
            'rewrite' => [
                'slug' => $base,
                'with_front' => (bool) $type['with_front'],
                'pages' => true,
                'feeds' => (bool) $type['has_archive'],
            ],
            'query_var' => $type['public'] ? $slug : false,
            'capability_type' => 'post',
            'map_meta_cap' => true,
            'can_export' => true,
            'delete_with_user' => false,
        ];

        if ($type['menu_position'] !== null) {
            $args['menu_position'] = (int) $type['menu_position'];
        }

        return $args;
    }

    /**
     * Build the label set from the singular and plural names.
     *
     * The names themselves are stored as the author typed them. Surrounding
     * phrases such as "Add New %s" are translated with the plugin text domain,
     * so a Persian site shows Persian chrome around an English or Persian name.
     * Export bakes the translated phrases in as plain strings.
     *
     * @param array<string, mixed> $type Saved definition.
     * @return array<string, string> Labels for `register_post_type()`.
     */
    public static function labels(array $type): array
    {
        $singular = (string) $type['singular'];
        $plural = (string) $type['plural'];

        return [
            'name' => $plural,
            'singular_name' => $singular,
            'menu_name' => $plural,
            'name_admin_bar' => $singular,
            'add_new' => __('Add New', 'post-type-generator'),
            /* translators: %s: singular post type name. */
            'add_new_item' => sprintf(__('Add New %s', 'post-type-generator'), $singular),
            /* translators: %s: singular post type name. */
            'edit_item' => sprintf(__('Edit %s', 'post-type-generator'), $singular),
            /* translators: %s: singular post type name. */
            'new_item' => sprintf(__('New %s', 'post-type-generator'), $singular),
            /* translators: %s: singular post type name. */
            'view_item' => sprintf(__('View %s', 'post-type-generator'), $singular),
            /* translators: %s: plural post type name. */
            'view_items' => sprintf(__('Browse %s', 'post-type-generator'), $plural),
            /* translators: %s: plural post type name. */
            'search_items' => sprintf(__('Search %s', 'post-type-generator'), $plural),
            /* translators: %s: plural post type name. */
            'not_found' => sprintf(__('No %s found.', 'post-type-generator'), $plural),
            /* translators: %s: plural post type name. */
            'not_found_in_trash' => sprintf(__('No %s found in Trash.', 'post-type-generator'), $plural),
            /* translators: %s: singular post type name. */
            'parent_item_colon' => sprintf(__('Parent %s:', 'post-type-generator'), $singular),
            /* translators: %s: plural post type name. */
            'all_items' => sprintf(__('All %s', 'post-type-generator'), $plural),
            /* translators: %s: singular post type name. */
            'archives' => sprintf(__('%s Archives', 'post-type-generator'), $singular),
            /* translators: %s: singular post type name. */
            'attributes' => sprintf(__('%s Attributes', 'post-type-generator'), $singular),
            /* translators: %s: singular post type name. */
            'insert_into_item' => sprintf(__('Insert into %s', 'post-type-generator'), $singular),
            /* translators: %s: singular post type name. */
            'uploaded_to_this_item' => sprintf(__('Uploaded to this %s', 'post-type-generator'), $singular),
            'featured_image' => __('Featured image', 'post-type-generator'),
            'set_featured_image' => __('Set featured image', 'post-type-generator'),
            'remove_featured_image' => __('Remove featured image', 'post-type-generator'),
            'use_featured_image' => __('Use as featured image', 'post-type-generator'),
            /* translators: %s: plural post type name. */
            'filter_items_list' => sprintf(__('Filter %s list', 'post-type-generator'), $plural),
            'filter_by_date' => __('Filter by date', 'post-type-generator'),
            /* translators: %s: plural post type name. */
            'items_list_navigation' => sprintf(__('%s list navigation', 'post-type-generator'), $plural),
            /* translators: %s: plural post type name. */
            'items_list' => sprintf(__('%s list', 'post-type-generator'), $plural),
            /* translators: %s: singular post type name. */
            'item_published' => sprintf(__('%s published.', 'post-type-generator'), $singular),
            /* translators: %s: singular post type name. */
            'item_published_privately' => sprintf(__('%s published privately.', 'post-type-generator'), $singular),
            /* translators: %s: singular post type name. */
            'item_reverted_to_draft' => sprintf(__('%s reverted to draft.', 'post-type-generator'), $singular),
            /* translators: %s: singular post type name. */
            'item_trashed' => sprintf(__('%s trashed.', 'post-type-generator'), $singular),
            /* translators: %s: singular post type name. */
            'item_scheduled' => sprintf(__('%s scheduled.', 'post-type-generator'), $singular),
            /* translators: %s: singular post type name. */
            'item_updated' => sprintf(__('%s updated.', 'post-type-generator'), $singular),
            /* translators: %s: singular post type name. */
            'item_link' => sprintf(__('%s Link', 'post-type-generator'), $singular),
            /* translators: %s: singular post type name. */
            'item_link_description' => sprintf(__('A link to a %s.', 'post-type-generator'), $singular),
        ];
    }

    /**
     * Replace the "Add title" placeholder on generated edit screens.
     *
     * Other post types keep the title WordPress passed in.
     *
     * @param string $title Placeholder WordPress was about to print.
     * @param mixed  $post  Post being edited. Ignored unless it is a WP_Post.
     * @return string Placeholder shown in the title field.
     */
    public static function titlePlaceholder(string $title, $post): string
    {
        if (!$post instanceof \WP_Post) {
            return $title;
        }

        $type = Store::get($post->post_type);

        if ($type === null || $type['singular'] === '') {
            return $title;
        }

        return sprintf(
            /* translators: %s: singular post type name. */
            __('Add %s title', 'post-type-generator'),
            $type['singular']
        );
    }
}
