=== Post Type Generator ===
Contributors: ahmadrezaebrahimi
Tags: custom post types, content, developer, export, tools
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Create custom post types from the WordPress admin and export their registration code.

== Description ==

Post Type Generator adds one screen under Tools. Add and edit open in a dialog on that screen. From there you can create a custom post type, change its labels and supports, turn it off without losing the settings, or export the `register_post_type()` call as PHP.

Definitions are stored in the `ptg_post_types` option on your site and registered on `init` while this plugin is active. The plugin does not contact any external server, does not load scripts or styles from a CDN, and does not add links or credits to the public site.

JavaScript and CSS in this plugin are the source. There is no build step and nothing is obfuscated.

Deleting a generated type does not delete its posts. They stay in the database and disappear from the admin until the same key is registered again.

= What you can set =

* Singular and plural labels
* Post type key (lowercase letters, numbers, underscores, at most 20 characters, fixed after creation)
* Public visibility, admin screens, the REST API, an archive, hierarchy, and search
* Rewrite slug
* Editor features and taxonomies that are already registered
* A Dashicon and an optional admin menu position

= Developers =

    add_filter('ptg_post_type_args', function (array $args, array $type): array {
        return $args;
    }, 10, 2);

The filter receives the arguments passed to `register_post_type()` and the saved definition.

Development source: https://github.com/ahmadreza-log/post-type-generator

== Installation ==

1. Upload the `post-type-generator` folder to `/wp-content/plugins/`, or install the zip from the Plugins screen.
2. Activate **Post Type Generator** through the Plugins screen.
3. Open **Tools → Post Type Generator**.
4. Click **Add Post Type**, fill in the names and key in the dialog, and save.
5. If an archive link returns a 404, open Settings → Permalinks and click Save Changes. The plugin also rebuilds permalinks on the request after each save.

== Frequently Asked Questions ==

= Does this plugin track users or phone home? =

No. It does not send data to any server. It does not embed advertisements. The only data it writes is the post type definition in your options table, plus a one-request flag used to refresh permalinks.

= Does it add a credit or link on the public site? =

No. Nothing is printed on the front of the site except the post type archive and singular URLs WordPress already generates for a public type.

= Does this replace a post type my theme already registered? =

No. Reserved keys and keys that already exist are rejected. Registration runs after the default `init` priority, so an existing type keeps its own callback.

= What happens to posts when I delete a type? =

They stay in the database. They are hidden until that same key is registered again.

= Can I change the key later? =

No. Create another type if you need a different key. Existing posts keep the old key.

= Do I need the exported PHP while this plugin is active? =

No. Use the export only when you are ready to deactivate this plugin and keep the registration in other code. WordPress ignores a second registration of the same key.

= Where are the admin screens? =

Under Tools, on one screen. Add and edit open in a dialog on that screen. There is no top-level menu and no dashboard widget.

== Privacy ==

This plugin does not track visitors or account holders, does not set its own cookies, and does not contact third-party services.

It stores the post type settings you submit (names, key, visibility, supports, taxonomies, icon, and menu position) in the WordPress options table. Those settings are removed when the plugin is deleted from the Plugins screen. Posts created with a generated type are not deleted.

== Changelog ==

= 1.0.0 =
* Create, edit, disable, delete, and export custom post types from Tools.
* Persian translation included.
* No remote assets, tracking, or public credit links.

== Upgrade Notice ==

= 1.0.0 =
First release.
