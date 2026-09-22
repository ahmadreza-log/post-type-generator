<?php
/**
 * Admin screens for creating, editing, deleting, and exporting post types.
 *
 * One Tools screen is registered:
 *
 * - `post-type-generator` lists saved types. Add and edit open in a dialog
 *   on that same screen. `post-type-generator-new` only redirects there.
 *
 * Styles and scripts are files inside this plugin. Nothing is loaded from a
 * CDN or another server. Success notices are dismissible and appear only on
 * these screens.
 * The dialog saves and deletes through admin-ajax, then the browser loads
 * this screen again with a GET request so the admin menu keeps the current
 * page. A no-JavaScript POST still uses `handleRequest()` on `load-{$hook}`
 * before HTML. A failed save keeps the submitted row in memory and redisplays
 * the form with an error. The post type key is immutable after creation.
 *
 * Capability required for every screen and POST: `manage_options`.
 *
 * @package PostTypeGenerator
 */

declare(strict_types=1);

namespace PostTypeGenerator;

defined('ABSPATH') || exit;

final class Admin
{
    /**
     * Capability required to open the screens and to save or delete a type.
     */
    private const CAPABILITY = 'manage_options';

    /**
     * Validation or delete error for the current request. Empty when there is none.
     */
    private static string $error = '';

    /**
     * Key of the type being edited. Empty on the create form.
     */
    private static string $original = '';

    /**
     * Submitted definition kept after a failed save so the form is not cleared.
     *
     * @var array<string, mixed>|null
     */
    private static ?array $draft = null;

    /**
     * Register the Tools screens and capture POST before they render.
     *
     * The list lives at Tools → Post Type Generator. Add and edit stay in a
     * dialog on that screen. Notices stay on this screen. There is no
     * top-level menu, dashboard widget, or site-wide admin nag.
     *
     * @return void
     */
    public static function boot(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue']);
        add_action('wp_ajax_ptg_manage', [self::class, 'ajax']);
        add_filter('submenu_file', [self::class, 'highlight']);
    }

    /**
     * Mark this screen in the Tools submenu.
     *
     * The menu link points at tools.php, but the screen is served by
     * admin.php. WordPress opens Tools and leaves every child unmarked, so
     * the sidebar no longer shows which page is open. Returning the list
     * slug selects that item after a save reload as well.
     *
     * @param string|null $file Submenu slug WordPress was going to select.
     * @return string|null
     */
    public static function highlight(?string $file): ?string
    {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash((string) $_GET['page'])) : '';

        if ($page === 'post-type-generator' || $page === 'post-type-generator-new') {
            return 'post-type-generator';
        }

        return $file;
    }

    /**
     * Add the list screen under Tools.
     *
     * The form posts back to this screen. `handleRequest()` runs on
     * `load-{$hook}` before HTML, so a successful save can redirect.
     * The old Add screen slug stays registered with no parent menu so a
     * saved link still opens this dialog.
     *
     * @return void
     */
    public static function menu(): void
    {
        $hook = add_management_page(
            __('Post Type Generator', 'post-type-generator'),
            __('Post Type Generator', 'post-type-generator'),
            self::CAPABILITY,
            'post-type-generator',
            [self::class, 'renderScreen']
        );

        $hidden = add_submenu_page(
            '',
            __('Add Post Type', 'post-type-generator'),
            __('Add Post Type', 'post-type-generator'),
            self::CAPABILITY,
            'post-type-generator-new',
            [self::class, 'renderNew']
        );

        if (is_string($hidden)) {
            add_action('load-' . $hidden, [self::class, 'renderNew']);
        }

        if (is_string($hook)) {
            add_action('load-' . $hook, [self::class, 'handleRequest']);
        }
    }

    /**
     * Load the admin stylesheet and script on generator screens only.
     *
     * Versions follow the file modification time so a local edit is not cached.
     * The script receives `ptg.ajax` for the save request, plus `ptg.confirm`
     * and `ptg.copied` for the delete confirmation and the copy button.
     *
     * @param string $hook Current admin page hook suffix.
     * @return void
     */
    public static function enqueue(string $hook): void
    {
        if (!str_contains($hook, 'post-type-generator')) {
            return;
        }

        $css = PTG_DIR . 'admin/css/admin.css';
        $js = PTG_DIR . 'admin/js/admin.js';

        wp_enqueue_style(
            'ptg-admin',
            PTG_URL . 'admin/css/admin.css',
            [],
            is_readable($css) ? (string) filemtime($css) : PTG_VERSION
        );

        wp_enqueue_script(
            'ptg-admin',
            PTG_URL . 'admin/js/admin.js',
            [],
            is_readable($js) ? (string) filemtime($js) : PTG_VERSION,
            true
        );

        wp_localize_script('ptg-admin', 'ptg', [
            'ajax' => admin_url('admin-ajax.php'),
            'confirm' => __('Delete this post type? Saved posts stay in the database, but they stay hidden until this key is registered again.', 'post-type-generator'),
            'copied' => __('Copied.', 'post-type-generator'),
            'failed' => __('The post type could not be saved. Please try again.', 'post-type-generator'),
        ]);
    }

    /**
     * Save or delete a type from a same-screen POST.
     *
     * GET requests return immediately. A missing or bad nonce stops the
     * request with WordPress's own failure screen. Success redirects to the
     * list with `ptg_notice`. Failure stores the message and the draft for
     * the renderer that runs next.
     *
     * @return void
     */
    public static function handleRequest(): void
    {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
            return;
        }

        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to manage post types.', 'post-type-generator'));
        }

        check_admin_referer('ptg_manage', 'ptg_nonce');

        $action = isset($_POST['ptg_action']) ? sanitize_key(wp_unslash((string) $_POST['ptg_action'])) : '';

        if ($action === 'delete') {
            self::handleDelete();
            return;
        }

        if ($action === 'save') {
            self::handleSave();
        }
    }

    /**
     * Save or delete from admin-ajax, then tell the browser to reload this screen.
     *
     * A normal form POST rebuilds the admin menu before the redirect and the
     * current page disappears from the sidebar. This request does not render
     * that menu. The JSON `url` is a GET of the list, so the reload paints
     * the menu with this screen selected.
     *
     * @return void
     */
    public static function ajax(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_send_json_error([
                'message' => __('You do not have permission to manage post types.', 'post-type-generator'),
            ], 403);
        }

        check_ajax_referer('ptg_manage', 'ptg_nonce');

        $action = isset($_POST['ptg_action']) ? sanitize_key(wp_unslash((string) $_POST['ptg_action'])) : '';
        $result = $action === 'delete' ? self::discard() : self::commit();

        if (is_wp_error($result)) {
            wp_send_json_error([
                'message' => $result->get_error_message(),
            ]);
        }

        wp_send_json_success([
            'url' => self::pageUrl(['ptg_notice' => $result]),
        ]);
    }

    /**
     * Render the list. Add and edit stay in the dialog on this screen.
     *
     * `action=edit`, `action=export`, and `ptg_open=add` open that dialog
     * after the list is painted. Any other query still shows the list.
     *
     * @return void
     */
    public static function renderScreen(): void
    {
        self::guard();

        $action = isset($_GET['action']) ? sanitize_key(wp_unslash((string) $_GET['action'])) : '';
        $slug = isset($_GET['slug']) ? sanitize_key(wp_unslash((string) $_GET['slug'])) : '';
        $open = isset($_GET['ptg_open']) ? sanitize_key(wp_unslash((string) $_GET['ptg_open'])) : '';

        if ($action === 'edit' || $action === 'export') {
            $open = $slug;
        }

        self::renderList($open);
    }

    /**
     * Send the retired Add screen back to the list with the dialog open.
     *
     * Runs on `load-{$hook}` so the redirect happens before the admin header.
     *
     * @return void
     */
    public static function renderNew(): void
    {
        self::guard();
        wp_safe_redirect(self::pageUrl(['ptg_open' => 'add']));
        exit;
    }

    private static function handleSave(): void
    {
        $result = self::commit();

        if (is_wp_error($result)) {
            return;
        }

        wp_safe_redirect(self::pageUrl(['ptg_notice' => $result]));
        exit;
    }

    private static function handleDelete(): void
    {
        $result = self::discard();

        if (is_wp_error($result)) {
            self::$error = $result->get_error_message();
            return;
        }

        wp_safe_redirect(self::pageUrl(['ptg_notice' => $result]));
        exit;
    }

    /**
     * Store the submitted definition.
     *
     * A validation error is kept on this request so a no-JavaScript POST can
     * paint the dialog again. The Ajax caller reads the same error object.
     *
     * @return string|\WP_Error Notice code `created` or `updated`.
     */
    private static function commit(): string|\WP_Error
    {
        $input = wp_unslash($_POST);

        if (!is_array($input)) {
            return new \WP_Error(
                'ptg_input',
                __('The post type could not be saved. Please try again.', 'post-type-generator')
            );
        }

        $original = isset($input['original_slug']) ? sanitize_key((string) $input['original_slug']) : '';
        $draft = Store::fromRequest($input);
        $result = Store::save($draft, $original);

        if (is_wp_error($result)) {
            self::$error = $result->get_error_message();
            self::$draft = $draft;
            self::$original = $original;

            return $result;
        }

        return $original === '' ? 'created' : 'updated';
    }

    /**
     * Remove one saved definition. Posts that use the key stay in the database.
     *
     * @return string|\WP_Error Notice code `deleted`.
     */
    private static function discard(): string|\WP_Error
    {
        $slug = '';

        if (isset($_POST['original_slug'])) {
            $slug = sanitize_key(wp_unslash((string) $_POST['original_slug']));
        }

        if ($slug === '' && isset($_POST['slug'])) {
            $slug = sanitize_key(wp_unslash((string) $_POST['slug']));
        }

        if ($slug === '' || !Store::delete($slug)) {
            return new \WP_Error(
                'ptg_missing',
                __('This post type no longer exists.', 'post-type-generator')
            );
        }

        return 'deleted';
    }

    /**
     * Paint the list and the add/edit dialog.
     *
     * `$focus` is `add`, a saved slug, or empty. A failed save keeps the
     * submitted row in the dialog instead of the focused slug.
     *
     * @param string $focus Dialog to open after render. Empty leaves it closed.
     * @return void
     */
    private static function renderList(string $focus): void
    {
        $types = Store::all();

        uasort($types, static function (array $a, array $b): int {
            return strnatcasecmp((string) $a['plural'], (string) $b['plural']);
        });

        $counts = self::counts();
        $stored = null;
        $missing = false;

        if (self::$draft !== null) {
            $type = self::$draft;
            $original = self::$original;
            $hold = true;
        } elseif ($focus !== '' && $focus !== 'add') {
            $stored = Store::get($focus);
            $missing = $stored === null;
            $type = $stored ?? Store::defaults();
            $original = $missing ? '' : $focus;
            $hold = !$missing;
        } else {
            $type = Store::defaults();
            $original = '';
            $hold = $focus === 'add';
        }

        self::header(__('Post Type Generator', 'post-type-generator'), true);

        if ($missing) {
            echo '<div class="notice notice-error"><p>' . esc_html__('This post type could not be found.', 'post-type-generator') . '</p></div>';
        }

        echo '<p class="ptg-intro">' . esc_html__('Create a custom post type here. It is registered while this plugin is active, and you can export the PHP if you want to keep it in code.', 'post-type-generator') . '</p>';

        if ($types === []) {
            echo '<div class="ptg-empty">';
            echo '<span class="dashicons dashicons-layout" aria-hidden="true"></span>';
            echo '<p>' . esc_html__('No post types yet.', 'post-type-generator') . '</p>';
            echo '<p><a class="button button-primary" href="#ptg-dialog" data-ptg-add>' . esc_html__('Add Post Type', 'post-type-generator') . '</a></p>';
            echo '</div>';
        } else {
            echo '<p class="ptg-toolbar"><button type="button" class="button" data-ptg-bundle>' . esc_html__('Export PHP', 'post-type-generator') . '</button></p>';
            echo '<div class="ptg-table-wrap">';
            echo '<table class="wp-list-table widefat striped ptg-table">';
            echo '<thead><tr>';
            echo '<th class="ptg-col-name" scope="col">' . esc_html__('Post type', 'post-type-generator') . '</th>';
            echo '<th class="ptg-col-key" scope="col">' . esc_html__('Key', 'post-type-generator') . '</th>';
            echo '<th class="ptg-col-status" scope="col">' . esc_html__('Status', 'post-type-generator') . '</th>';
            echo '<th class="ptg-col-count" scope="col">' . esc_html__('Entries', 'post-type-generator') . '</th>';
            echo '</tr></thead><tbody>';

            foreach ($types as $slug => $row) {
                $icon = self::iconClass((string) $row['menu_icon']);
                $count = $counts[$slug] ?? 0;
                $entries = admin_url('edit.php?post_type=' . rawurlencode($slug));
                $view = (!empty($row['enabled']) && !empty($row['has_archive']) && !empty($row['public']))
                    ? get_post_type_archive_link($slug)
                    : false;
                $ready = !empty($row['show_ui']) && !empty($row['enabled']) && post_type_exists($slug);

                echo '<tr>';
                echo '<td class="ptg-title">';
                echo '<script type="application/json" class="ptg-record">' . self::json(self::record($row, $slug)) . '</script>';
                echo '<div class="ptg-identity">';
                echo '<span class="dashicons ' . esc_attr($icon) . '" aria-hidden="true"></span>';
                echo '<div>';
                echo '<button type="button" class="ptg-name" data-ptg-edit><strong>' . esc_html((string) $row['plural']) . '</strong><span class="description">' . esc_html((string) $row['singular']) . '</span></button>';
                echo '<div class="ptg-actions">';
                echo '<button type="button" data-ptg-edit>' . esc_html__('Settings', 'post-type-generator') . '</button>';

                if ($ready) {
                    echo '<a href="' . esc_url($entries) . '">' . esc_html__('Entries', 'post-type-generator') . '</a>';
                }

                if (is_string($view) && $view !== '') {
                    echo '<a href="' . esc_url($view) . '">' . esc_html__('View archive', 'post-type-generator') . '</a>';
                }

                echo '<button type="button" data-ptg-edit data-ptg-jump="ptg-code">' . esc_html__('Export PHP', 'post-type-generator') . '</button>';
                echo '<form method="post" class="ptg-ajax" action="' . esc_url(self::pageUrl()) . '">';
                wp_nonce_field('ptg_manage', 'ptg_nonce');
                echo '<input type="hidden" name="slug" value="' . esc_attr($slug) . '">';
                echo '<button type="submit" class="button-link-delete" name="ptg_action" value="delete" data-ptg-confirm>' . esc_html__('Delete', 'post-type-generator') . '</button>';
                echo '</form>';
                echo '</div></div></div></td>';
                echo '<td class="ptg-key-cell"><code class="ptg-key">' . esc_html($slug) . '</code></td>';
                echo '<td class="ptg-status"><span class="ptg-pills">';
                echo '<span class="ptg-pill' . (!empty($row['enabled']) ? ' is-on' : ' is-off') . '">' . esc_html(!empty($row['enabled']) ? __('Active', 'post-type-generator') : __('Inactive', 'post-type-generator')) . '</span>';
                echo '<span class="ptg-pill">' . esc_html(self::visibilityLabel($row)) . '</span>';

                if (!empty($row['has_archive'])) {
                    echo '<span class="ptg-pill is-on">' . esc_html__('Archive', 'post-type-generator') . '</span>';
                }

                echo '</span></td>';
                echo '<td class="ptg-count">';

                if ($ready) {
                    echo '<a href="' . esc_url($entries) . '">' . esc_html(number_format_i18n($count)) . '</a>';
                } else {
                    echo esc_html(number_format_i18n($count));
                }

                echo '</td>';
                echo '</tr>';
            }

            echo '</tbody></table></div>';
            echo '<script type="application/json" id="ptg-bundle">' . self::json(Code::bundle($types)) . '</script>';
        }

        echo '<script type="application/json" id="ptg-blank">' . self::json(self::record(Store::defaults(), '')) . '</script>';
        self::renderDialog($type, $original, $stored, $hold);
        self::footer();
    }

    /**
     * Render the add and edit form inside a modal dialog.
     *
     * The dialog stays closed until a button opens it, unless `$hold` is
     * set for a requested edit or a failed save.
     *
     * @param array<string, mixed> $type     Definition shown in the form.
     * @param string               $original Saved key. Empty when creating.
     * @param array<string, mixed>|null $stored Saved row when editing without an error.
     * @param bool                 $hold    Open the dialog as soon as the page loads.
     * @return void
     */
    private static function renderDialog(array $type, string $original, ?array $stored, bool $hold): void
    {
        $editing = $original !== '';
        $code = ($stored !== null && self::$error === '') ? Code::bundle([$original => $stored]) : '';

        echo '<dialog id="ptg-dialog" class="ptg-dialog"';
        echo $hold ? ' data-ptg-hold' : '';
        echo ' data-fresh="' . esc_attr(__('New Post Type', 'post-type-generator')) . '"';
        echo ' data-saved="' . esc_attr(__('Edit Post Type', 'post-type-generator')) . '"';
        echo ' data-export="' . esc_attr(__('Export PHP', 'post-type-generator')) . '"';
        echo ' data-create="' . esc_attr(__('Create Post Type', 'post-type-generator')) . '"';
        echo ' data-update="' . esc_attr(__('Save Post Type', 'post-type-generator')) . '"';
        echo ' data-active="' . esc_attr(__('Active', 'post-type-generator')) . '"';
        echo ' data-idle="' . esc_attr(__('Inactive', 'post-type-generator')) . '"';
        echo ' data-public="' . esc_attr(__('Public', 'post-type-generator')) . '"';
        echo ' data-admin="' . esc_attr(__('Admin only', 'post-type-generator')) . '"';
        echo ' data-hidden="' . esc_attr(__('Hidden', 'post-type-generator')) . '"';
        echo ' data-none="' . esc_attr(__('None', 'post-type-generator')) . '"';
        echo '>';
        echo '<div class="ptg-dialog-bar">';
        echo '<h2 id="ptg-dialog-title">' . esc_html($editing ? __('Edit Post Type', 'post-type-generator') : __('New Post Type', 'post-type-generator')) . '</h2>';
        echo '<button type="button" class="ptg-dialog-close" data-ptg-close aria-label="' . esc_attr__('Close', 'post-type-generator') . '"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span></button>';
        echo '</div>';
        echo '<div class="ptg-dialog-body">';

        if (self::$draft !== null && self::$error !== '') {
            echo '<div class="notice notice-error inline"><p>' . esc_html(self::$error) . '</p></div>';
        }

        echo '<form method="post" action="' . esc_url(self::pageUrl()) . '" class="ptg-editor ptg-ajax" id="ptg-editor">';
        wp_nonce_field('ptg_manage', 'ptg_nonce');
        echo '<input type="hidden" id="ptg-original" name="original_slug" value="' . esc_attr($original) . '">';

        echo '<div class="ptg-layout">';
        echo '<div class="ptg-main">';
        self::renderBasic($type, $editing);
        self::renderVisibility($type);
        self::renderFeatures($type);
        self::renderTaxonomies($type);
        self::renderMenu($type);
        echo '</div>';
        echo '<div class="ptg-side">';
        self::renderSaveBox($type, $editing, $original);
        self::renderSummary($type, $original);
        echo '</div>';
        echo '</div>';

        echo '<div class="ptg-export" id="ptg-export"' . ($code === '' ? ' hidden' : '') . '>';
        self::renderExportBox($code);
        echo '</div>';
        echo '</form>';
        echo '</div></dialog>';
    }

    /**
     * @param array<string, mixed> $type
     */
    private static function renderBasic(array $type, bool $editing): void
    {
        self::openBox(__('Basic', 'post-type-generator'));
        echo '<table class="form-table" role="presentation"><tbody>';

        self::textRow(
            'ptg-singular',
            'singular',
            __('Singular name', 'post-type-generator'),
            (string) $type['singular'],
            __('Book', 'post-type-generator'),
            true
        );

        self::textRow(
            'ptg-plural',
            'plural',
            __('Plural name', 'post-type-generator'),
            (string) $type['plural'],
            __('Books', 'post-type-generator'),
            true
        );

        echo '<tr><th scope="row"><label for="ptg-description">' . esc_html__('Description', 'post-type-generator') . '</label></th><td>';
        echo '<textarea id="ptg-description" name="description" class="large-text" rows="3">' . esc_textarea((string) $type['description']) . '</textarea>';
        echo '<p class="description">' . esc_html__('Shown in the admin and used as the post type description.', 'post-type-generator') . '</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="ptg-slug">' . esc_html__('Post type key', 'post-type-generator') . '</label></th><td>';
        echo '<input id="ptg-slug" name="slug" type="text" class="regular-text code" value="' . esc_attr((string) $type['slug']) . '" maxlength="20" spellcheck="false" autocomplete="off" required' . ($editing ? ' readonly' : '') . '>';
        echo '<p class="description">' . esc_html__('Lowercase English letters, numbers, and underscores. Maximum 20 characters. It cannot be changed later.', 'post-type-generator') . '</p>';
        echo '</td></tr>';

        echo '</tbody></table>';
        self::closeBox();
    }

    /**
     * @param array<string, mixed> $type
     */
    private static function renderVisibility(array $type): void
    {
        self::openBox(__('Visibility', 'post-type-generator'));
        echo '<ul class="ptg-choices">';
        self::checkField('enabled', __('Enabled', 'post-type-generator'), __('Register this post type. Turn this off to keep the settings without showing it on the site.', 'post-type-generator'), !empty($type['enabled']));
        self::checkField('public', __('Public', 'post-type-generator'), __('Visible on the site and in search.', 'post-type-generator'), !empty($type['public']));
        self::checkField('show_ui', __('Show in admin', 'post-type-generator'), __('Show the list and edit screens.', 'post-type-generator'), !empty($type['show_ui']));
        self::checkField('show_in_rest', __('Show in REST', 'post-type-generator'), __('Required for the block editor.', 'post-type-generator'), !empty($type['show_in_rest']));
        self::checkField('has_archive', __('Has archive', 'post-type-generator'), __('Public archive page for this post type.', 'post-type-generator'), !empty($type['has_archive']));
        self::checkField('hierarchical', __('Hierarchical', 'post-type-generator'), __('Parent and child items, similar to Pages.', 'post-type-generator'), !empty($type['hierarchical']));
        self::checkField('exclude_from_search', __('Exclude from search', 'post-type-generator'), __('Leave this type out of site search.', 'post-type-generator'), !empty($type['exclude_from_search']));
        echo '</ul>';

        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row"><label for="ptg-rewrite">' . esc_html__('URL slug', 'post-type-generator') . '</label></th><td>';
        echo '<input id="ptg-rewrite" name="rewrite_slug" type="text" class="regular-text code" value="' . esc_attr((string) $type['rewrite_slug']) . '" spellcheck="false" autocomplete="off">';
        echo '<p class="description">' . esc_html__('Used in the permalink. Leave empty to use the post type key.', 'post-type-generator') . '</p>';
        echo '</td></tr>';
        echo '</tbody></table>';

        echo '<ul class="ptg-choices">';
        self::checkField('with_front', __('With front', 'post-type-generator'), __('Include the permalink front base, such as /blog/.', 'post-type-generator'), !empty($type['with_front']));
        echo '</ul>';
        self::closeBox();
    }

    /**
     * @param array<string, mixed> $type
     */
    private static function renderFeatures(array $type): void
    {
        self::openBox(__('Features', 'post-type-generator'));
        echo '<p class="description">' . esc_html__('Choose what the edit screen supports. Page attributes are added automatically when the type is hierarchical.', 'post-type-generator') . '</p>';
        echo '<ul class="ptg-choices ptg-choices-compact">';

        $selected = is_array($type['supports']) ? $type['supports'] : [];

        foreach (self::supportChoices() as $key => $label) {
            echo '<li class="ptg-choice"><label class="ptg-check"><input type="checkbox" name="supports[]" value="' . esc_attr($key) . '"' . checked(in_array($key, $selected, true), true, false) . '><span>' . esc_html($label) . '</span></label></li>';
        }

        echo '</ul>';
        self::closeBox();
    }

    /**
     * @param array<string, mixed> $type
     */
    private static function renderTaxonomies(array $type): void
    {
        self::openBox(__('Taxonomies', 'post-type-generator'));
        echo '<p class="description">' . esc_html__('Attach existing taxonomies.', 'post-type-generator') . '</p>';

        $taxes = get_taxonomies(['show_ui' => true], 'objects');
        $skip = ['nav_menu', 'link_category', 'wp_pattern_category', 'wp_theme', 'wp_template_part_area'];

        foreach ($skip as $key) {
            unset($taxes[$key]);
        }

        uasort($taxes, static function ($a, $b): int {
            return strnatcasecmp((string) ($a->labels->name ?? $a->name), (string) ($b->labels->name ?? $b->name));
        });

        $selected = is_array($type['taxonomies']) ? $type['taxonomies'] : [];

        if ($taxes === [] && $selected === []) {
            echo '<p>' . esc_html__('No taxonomies with an admin screen are available.', 'post-type-generator') . '</p>';
            self::closeBox();
            return;
        }

        echo '<ul class="ptg-choices ptg-choices-compact">';

        foreach ($taxes as $taxonomy) {
            $name = (string) $taxonomy->name;
            echo '<li class="ptg-choice"><label class="ptg-check"><input type="checkbox" name="taxonomies[]" value="' . esc_attr($name) . '"' . checked(in_array($name, $selected, true), true, false) . '>';
            echo '<span>' . esc_html((string) ($taxonomy->labels->name ?? $name)) . ' <code class="ptg-key">' . esc_html($name) . '</code></span></label></li>';
        }

        foreach ($selected as $taxonomy) {
            $taxonomy = (string) $taxonomy;

            if ($taxonomy === '' || isset($taxes[$taxonomy])) {
                continue;
            }

            echo '<li class="ptg-choice"><label class="ptg-check"><input type="checkbox" name="taxonomies[]" value="' . esc_attr($taxonomy) . '" checked><span><code class="ptg-key">' . esc_html($taxonomy) . '</code></span></label></li>';
        }

        echo '</ul>';
        self::closeBox();
    }

    /**
     * @param array<string, mixed> $type
     */
    private static function renderMenu(array $type): void
    {
        $icon = self::iconClass((string) $type['menu_icon']);
        $position = $type['menu_position'];
        $number = (is_int($position) && $position >= 0) ? (string) $position : '';

        self::openBox(__('Menu', 'post-type-generator'));
        echo '<label for="ptg-menu-icon"><strong>' . esc_html__('Menu icon', 'post-type-generator') . '</strong></label>';
        echo '<div class="ptg-icon-field">';
        echo '<span id="ptg-icon-preview" class="dashicons ptg-icon-preview ' . esc_attr($icon) . '" aria-hidden="true"></span>';
        echo '<input id="ptg-menu-icon" name="menu_icon" type="text" class="regular-text code" value="' . esc_attr($icon) . '" spellcheck="false" autocomplete="off">';
        echo '</div>';
        echo '<p class="description">' . esc_html__('Dashicon class, for example dashicons-book.', 'post-type-generator') . '</p>';
        echo '<p class="ptg-icon-search"><input id="ptg-icon-search" type="search" class="regular-text" placeholder="' . esc_attr__('Search icons', 'post-type-generator') . '"></p>';
        echo '<div class="ptg-icon-grid" data-ptg-icons>';

        foreach (self::icons() as $class) {
            $selected = $class === $icon;
            echo '<button type="button" class="button ptg-icon' . ($selected ? ' is-selected' : '') . '" data-icon="' . esc_attr($class) . '" aria-pressed="' . ($selected ? 'true' : 'false') . '" title="' . esc_attr($class) . '">';
            echo '<span class="dashicons ' . esc_attr($class) . '" aria-hidden="true"></span>';
            echo '</button>';
        }

        echo '</div>';

        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row"><label for="ptg-position">' . esc_html__('Menu position', 'post-type-generator') . '</label></th><td>';
        echo '<input id="ptg-position" name="menu_position" type="number" class="small-text" min="0" max="999" step="1" value="' . esc_attr($number) . '">';
        echo '<p class="description">' . esc_html__('Optional. Lower numbers sit higher in the admin menu. Leave empty for the default position.', 'post-type-generator') . '</p>';
        echo '</td></tr>';
        echo '</tbody></table>';
        self::closeBox();
    }

    /**
     * @param array<string, mixed> $type
     */
    private static function renderSaveBox(array $type, bool $editing, string $original): void
    {
        self::openBox(__('Save', 'post-type-generator'));
        $label = $editing
            ? __('Save Post Type', 'post-type-generator')
            : __('Create Post Type', 'post-type-generator');

        echo '<button type="submit" class="button button-primary button-large" id="ptg-submit" name="ptg_action" value="save">' . esc_html($label) . '</button>';

        $links = $editing && !empty($type['show_ui']) && !empty($type['enabled']) && post_type_exists($original);
        $entries = $links ? admin_url('edit.php?post_type=' . rawurlencode($original)) : '#';
        $entry = $links ? admin_url('post-new.php?post_type=' . rawurlencode($original)) : '#';

        echo '<p class="ptg-side-links"' . ($links ? '' : ' hidden') . '>';
        echo '<a class="button" id="ptg-entries" href="' . esc_url($entries) . '">' . esc_html__('Open entries', 'post-type-generator') . '</a>';
        echo '<a class="button" id="ptg-entry" href="' . esc_url($entry) . '">' . esc_html__('Add entry', 'post-type-generator') . '</a>';
        echo '</p>';

        echo '<div class="ptg-danger"' . ($editing ? '' : ' hidden') . '>';
        echo '<button type="submit" class="button-link-delete" name="ptg_action" value="delete" data-ptg-confirm formnovalidate>' . esc_html__('Delete post type', 'post-type-generator') . '</button>';
        echo '<p class="description">' . esc_html__('Saved posts stay in the database.', 'post-type-generator') . '</p>';
        echo '</div>';

        self::closeBox();
    }

    /**
     * @param array<string, mixed> $type
     */
    private static function renderSummary(array $type, string $original): void
    {
        $base = (string) ($type['rewrite_slug'] !== '' ? $type['rewrite_slug'] : $type['slug']);
        $archive = !empty($type['has_archive']) && $base !== '' ? '/' . $base . '/' : __('None', 'post-type-generator');
        $supports = is_array($type['supports']) ? $type['supports'] : [];
        $labels = [];

        foreach (self::supportChoices() as $key => $label) {
            if (in_array($key, $supports, true)) {
                $labels[] = $label;
            }
        }

        $key = $original !== '' ? $original : (string) $type['slug'];

        self::openBox(__('Summary', 'post-type-generator'));
        echo '<dl class="ptg-summary">';
        self::summaryRow(__('Key', 'post-type-generator'), $key !== '' ? $key : '—', $key !== '', 'key');
        self::summaryRow(__('Status', 'post-type-generator'), !empty($type['enabled']) ? __('Active', 'post-type-generator') : __('Inactive', 'post-type-generator'), false, 'status');
        self::summaryRow(__('Visibility', 'post-type-generator'), self::visibilityLabel($type), false, 'visibility');
        self::summaryRow(__('Archive', 'post-type-generator'), $archive, str_starts_with($archive, '/'), 'archive');
        echo '<div><dt>' . esc_html__('Features', 'post-type-generator') . '</dt><dd class="ptg-tags" data-ptg-sum="features">';

        if ($labels === []) {
            echo esc_html__('None', 'post-type-generator');
        } else {
            foreach ($labels as $label) {
                echo '<span>' . esc_html($label) . '</span>';
            }
        }

        echo '</dd></div>';
        echo '</dl>';
        self::closeBox();
    }

    private static function renderExportBox(string $code): void
    {
        self::openBox(__('Generated PHP', 'post-type-generator'));
        echo '<p class="description">' . esc_html__('Paste this into a plugin or functions.php only if this plugin will be turned off. While Post Type Generator is active, the post type is already registered.', 'post-type-generator') . '</p>';
        self::renderCode($code);
        self::closeBox();
    }

    private static function renderCode(string $code): void
    {
        echo '<p><button type="button" class="button" data-ptg-copy="ptg-code">' . esc_html__('Copy code', 'post-type-generator') . '</button></p>';
        echo '<textarea id="ptg-code" class="ptg-code" rows="14" spellcheck="false" dir="ltr">' . esc_textarea($code) . '</textarea>';
    }

    private static function summaryRow(string $label, string $value, bool $code = false, string $mark = ''): void
    {
        echo '<div><dt>' . esc_html($label) . '</dt><dd' . ($mark !== '' ? ' data-ptg-sum="' . esc_attr($mark) . '"' : '') . '>';
        echo $code ? '<code class="ptg-key">' . esc_html($value) . '</code>' : esc_html($value);
        echo '</dd></div>';
    }

    private static function textRow(string $id, string $name, string $label, string $value, string $placeholder, bool $required): void
    {
        echo '<tr><th scope="row"><label for="' . esc_attr($id) . '">' . esc_html($label) . '</label></th><td>';
        echo '<input id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" type="text" class="regular-text" value="' . esc_attr($value) . '" placeholder="' . esc_attr($placeholder) . '"' . ($required ? ' required' : '') . '>';
        echo '</td></tr>';
    }

    private static function checkField(string $name, string $label, string $description, bool $checked): void
    {
        echo '<li class="ptg-choice"><label class="ptg-check"><input type="checkbox" name="' . esc_attr($name) . '" value="1"' . checked($checked, true, false) . '><span>' . esc_html($label) . '</span></label>';

        if ($description !== '') {
            echo '<p class="description">' . esc_html($description) . '</p>';
        }

        echo '</li>';
    }

    private static function openBox(string $title): void
    {
        echo '<section class="postbox ptg-card"><div class="postbox-header"><h2>' . esc_html($title) . '</h2></div><div class="inside">';
    }

    private static function closeBox(): void
    {
        echo '</div></section>';
    }

    private static function header(string $title, bool $add = false): void
    {
        echo '<div class="wrap">';
        echo '<h1 class="wp-heading-inline">' . esc_html($title) . '</h1>';

        if ($add) {
            echo ' <a href="#ptg-dialog" class="page-title-action" data-ptg-add>' . esc_html__('Add Post Type', 'post-type-generator') . '</a>';
        }

        echo '<hr class="wp-header-end">';
        self::notices();
    }

    private static function footer(): void
    {
        echo '</div>';
    }

    private static function notices(): void
    {
        if (self::$error !== '' && self::$draft === null) {
            echo '<div class="notice notice-error"><p>' . esc_html(self::$error) . '</p></div>';
        }

        $code = isset($_GET['ptg_notice']) ? sanitize_key(wp_unslash((string) $_GET['ptg_notice'])) : '';
        $messages = [
            'created' => __('Post type created.', 'post-type-generator'),
            'updated' => __('Post type updated.', 'post-type-generator'),
            'deleted' => __('Post type deleted. Saved posts stay in the database.', 'post-type-generator'),
        ];

        if (!isset($messages[$code])) {
            return;
        }

        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($messages[$code]) . '</p></div>';
    }

    /**
     * @param array<string, mixed> $type
     */
    private static function visibilityLabel(array $type): string
    {
        if (!empty($type['public'])) {
            return __('Public', 'post-type-generator');
        }

        if (!empty($type['show_ui'])) {
            return __('Admin only', 'post-type-generator');
        }

        return __('Hidden', 'post-type-generator');
    }

    /**
     * @return array<string, string>
     */
    private static function supportChoices(): array
    {
        return [
            'title' => __('Title', 'post-type-generator'),
            'editor' => __('Editor', 'post-type-generator'),
            'excerpt' => __('Excerpt', 'post-type-generator'),
            'thumbnail' => __('Featured image', 'post-type-generator'),
            'comments' => __('Comments', 'post-type-generator'),
            'trackbacks' => __('Trackbacks', 'post-type-generator'),
            'revisions' => __('Revisions', 'post-type-generator'),
            'author' => __('Author', 'post-type-generator'),
            'page-attributes' => __('Page attributes', 'post-type-generator'),
            'custom-fields' => __('Custom fields', 'post-type-generator'),
            'post-formats' => __('Post formats', 'post-type-generator'),
        ];
    }

    /**
     * @return list<string>
     */
    private static function icons(): array
    {
        $names = [
            'admin-post', 'admin-page', 'admin-media', 'admin-comments', 'admin-users',
            'admin-tools', 'admin-settings', 'admin-site', 'admin-home', 'admin-appearance',
            'admin-plugins', 'admin-generic', 'book', 'book-alt', 'portfolio', 'category',
            'tag', 'cart', 'products', 'store', 'tickets-alt', 'calendar', 'calendar-alt',
            'location', 'location-alt', 'camera', 'format-image', 'format-gallery',
            'format-video', 'format-audio', 'microphone', 'megaphone', 'email', 'phone',
            'groups', 'businessperson', 'id', 'awards', 'star-filled', 'heart', 'flag',
            'sticky', 'edit', 'welcome-write-blog', 'media-document', 'media-text',
            'analytics', 'chart-bar', 'chart-pie', 'feedback', 'testimonial', 'format-quote',
            'format-chat', 'clipboard', 'list-view', 'grid-view', 'screenoptions', 'layout',
            'editor-table', 'database', 'archive', 'building', 'money-alt', 'coffee',
            'palmtree', 'airplane', 'car', 'hammer', 'art', 'games', 'shield', 'superhero-alt',
            'pets', 'nametag', 'universal-access', 'welcome-learn-more', 'album',
        ];

        return array_map(static function (string $name): string {
            return 'dashicons-' . $name;
        }, $names);
    }

    private static function iconClass(string $icon): string
    {
        return preg_match('/^dashicons-[a-z0-9-]+$/', $icon) ? $icon : 'dashicons-admin-post';
    }

    /**
     * Count stored posts for each generated key.
     *
     * Disabled types are included, because `wp_count_posts()` only counts a
     * key that is registered on this request. The query is limited to our
     * keys and uses a placeholder for each one.
     *
     * @return array<string, int> Map of post type key => count, excluding auto-drafts.
     */
    private static function counts(): array
    {
        global $wpdb;

        $counts = [];

        foreach (array_keys(Store::all()) as $slug) {
            $counts[$slug] = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status <> %s",
                $slug,
                'auto-draft'
            ));
        }

        return $counts;
    }

    /**
     * @param array<string, string> $args
     */
    private static function pageUrl(array $args = []): string
    {
        return add_query_arg(
            array_merge(['page' => 'post-type-generator'], $args),
            admin_url('admin.php')
        );
    }

    /**
     * Shape one definition for the dialog script.
     *
     * @param array<string, mixed> $type Saved or default definition.
     * @param string               $slug Saved key. Empty for a new type.
     * @return array<string, mixed> Values the dialog script can apply to the form.
     */
    private static function record(array $type, string $slug): array
    {
        $ready = $slug !== '' && !empty($type['show_ui']) && !empty($type['enabled']) && post_type_exists($slug);
        $position = $type['menu_position'];

        return [
            'slug' => (string) $type['slug'],
            'singular' => (string) $type['singular'],
            'plural' => (string) $type['plural'],
            'description' => (string) $type['description'],
            'enabled' => !empty($type['enabled']),
            'public' => !empty($type['public']),
            'show_ui' => !empty($type['show_ui']),
            'show_in_rest' => !empty($type['show_in_rest']),
            'has_archive' => !empty($type['has_archive']),
            'hierarchical' => !empty($type['hierarchical']),
            'exclude_from_search' => !empty($type['exclude_from_search']),
            'rewrite_slug' => (string) $type['rewrite_slug'],
            'with_front' => !empty($type['with_front']),
            'menu_icon' => (string) $type['menu_icon'],
            'menu_position' => is_int($position) && $position >= 0 ? $position : '',
            'supports' => array_values(is_array($type['supports']) ? $type['supports'] : []),
            'taxonomies' => array_values(is_array($type['taxonomies']) ? $type['taxonomies'] : []),
            'code' => $slug !== '' ? Code::bundle([$slug => $type]) : '',
            'entries' => $ready ? admin_url('edit.php?post_type=' . rawurlencode($slug)) : '',
            'entry' => $ready ? admin_url('post-new.php?post_type=' . rawurlencode($slug)) : '',
        ];
    }

    /**
     * Encode a dialog payload so it can sit inside a script tag.
     *
     * @param mixed $value Value to encode.
     * @return string JSON text, or `{}` when encoding fails.
     */
    private static function json(mixed $value): string
    {
        $encoded = wp_json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        return is_string($encoded) ? $encoded : '{}';
    }

    private static function guard(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to manage post types.', 'post-type-generator'));
        }
    }
}
