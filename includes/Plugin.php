<?php
/**
 * Plugin lifecycle.
 *
 * Connects translation loading, post type registration, the admin screens,
 * activation, deactivation, and the Plugins-screen action link.
 *
 * @package PostTypeGenerator
 */

declare(strict_types=1);

namespace PostTypeGenerator;

defined('ABSPATH') || exit;

final class Plugin
{
    /**
     * Attach runtime hooks after WordPress has loaded plugins.
     *
     * Translations are queued for `init` priority 0 so every later callback
     * can translate strings. Registrar attaches its own `init` callback.
     * Admin is started only when this request is for wp-admin and the class
     * file was included.
     *
     * @return void
     */
    public static function boot(): void
    {
        add_action('init', [self::class, 'loadTextdomain'], 0);
        Registrar::boot();

        if (is_admin() && class_exists(Admin::class)) {
            Admin::boot();
        }

        add_filter('plugin_action_links_' . plugin_basename(PTG_FILE), [self::class, 'actionLinks']);
    }

    /**
     * Load the plugin translation for the current locale.
     *
     * WordPress 6.5 and newer prefer `languages/post-type-generator-{$locale}.l10n.php`
     * when that file sits beside the `.mo` path passed here. Persian is shipped
     * as `post-type-generator-fa_IR.l10n.php`.
     *
     * @return void
     */
    public static function loadTextdomain(): void
    {
        // phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- Persian ships in this plugin until a WordPress.org language pack exists.
        load_plugin_textdomain(
            'post-type-generator',
            false,
            dirname(plugin_basename(PTG_FILE)) . '/languages'
        );
    }

    /**
     * Create the storage option and schedule a rewrite flush.
     *
     * Existing definitions are left untouched. The flush flag is consumed on
     * the next request, after `init` has registered the saved types.
     *
     * @return void
     */
    public static function activate(): void
    {
        if (get_option(Store::OPTION, null) === null) {
            add_option(Store::OPTION, []);
        }

        Store::markFlush();
    }

    /**
     * Drop generated rewrite rules when the plugin is turned off.
     *
     * The saved definitions stay in the database. Posts are not deleted.
     * The next front-end request rebuilds permalinks without these types.
     * Reactivating the plugin schedules a new flush from `activate()`.
     *
     * @return void
     */
    public static function deactivate(): void
    {
        delete_option(Store::FLUSH);
        delete_option('rewrite_rules');
    }

    /**
     * Add a Manage link on the Plugins screen.
     *
     * The link opens the generator list. WordPress still appends the core
     * Activate, Deactivate, and Delete links.
     *
     * @param array<int, string> $links HTML links already registered for this plugin.
     * @return array<int, string> Links with Manage placed first.
     */
    public static function actionLinks(array $links): array
    {
        $url = admin_url('admin.php?page=post-type-generator');
        $link = '<a href="' . esc_url($url) . '">' . esc_html__('Manage', 'post-type-generator') . '</a>';
        array_unshift($links, $link);

        return $links;
    }
}
