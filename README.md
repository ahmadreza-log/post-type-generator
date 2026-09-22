# Post Type Generator

Create custom post types from the WordPress admin. They stay registered while this plugin is active, and you can export the same registration as PHP.

**Requires** WordPress 6.0+ and PHP 8.0+. **License:** GPL-2.0-or-later (`license.txt`).

## Installation

1. Copy this folder to `wp-content/plugins/post-type-generator`, or upload the zip on the Plugins screen.
2. Activate **Post Type Generator**.
3. Open **Tools → Post Type Generator**. Add and edit open in a dialog on that screen.

## Usage

The form collects:

* Singular and plural labels
* Post type key: lowercase letters, numbers, and underscores, at most 20 characters, fixed after creation
* Public visibility, admin screens, the REST API, an archive, hierarchy, and exclusion from search
* Rewrite slug and the permalink front base
* Editor features and taxonomies that are already registered
* A Dashicon and an optional admin menu position
* An enabled or disabled state, without deleting the saved settings

Saving, updating, and deleting stay on this screen. The request is sent in the background, then the list reloads so the Tools menu still shows this page.

Deleting a type does not delete its posts. They stay hidden until that key is registered again.

## Export

**Export PHP** prints a standalone `register_post_type()` file. Use it only if this plugin will be turned off. WordPress ignores a second registration of the same key.

## Hook

```php
add_filter('ptg_post_type_args', function (array $args, array $type): array {
    return $args;
}, 10, 2);
```

The filter receives the `register_post_type()` arguments and the saved definition.

## Layout

```
post-type-generator.php          Bootstrap
uninstall.php                    Deletes settings, keeps posts
license.txt                      GPL-2.0-or-later
readme.txt                       WordPress.org readme
includes/Plugin.php              Lifecycle
includes/Store.php               Option storage
includes/Registrar.php           init registration
includes/Code.php                PHP export
includes/Admin.php               wp-admin screen
admin/css/admin.css              Admin layout
admin/js/admin.js                Dialog, icon picker, copy, confirm
languages/                       Translations
```

Classes live in the `PostTypeGenerator` namespace: `Plugin`, `Store`, `Registrar`, `Code`, and `Admin`. Options and hooks use the `ptg_` prefix so they do not collide with other plugins.

Definitions are stored in the `ptg_post_types` option. The next request after each save rebuilds the rewrite rules.

## Translations

Persian ships with the plugin as `languages/post-type-generator-fa_IR.l10n.php`.

WordPress 6.5 and later prefers that PHP file. Translators start from `languages/post-type-generator.pot`.

## Privacy

Nothing is sent to an external server. Definitions stay in your options table.

## Publish on GitHub

From this folder:

```bash
git init
git add .
git commit -m "Add Post Type Generator."
gh repo create ahmadreza-log/post-type-generator --public --source=. --remote=origin --push
```

If the repository already exists on GitHub:

```bash
git remote add origin https://github.com/ahmadreza-log/post-type-generator.git
git branch -M main
git push -u origin main
```

## Changelog

### 1.0.0

* Create, edit, disable, delete, and export custom post types
* Add and edit open in a dialog on the Tools screen
* Persian translation
