<?php
/**
 * Plugin Name:       Post Type Generator
 * Plugin URI:        https://github.com/ahmadreza-log/post-type-generator
 * Description:       Create custom post types from the WordPress admin and export their registration code.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Ahmadreza Ebrahimi
 * Author URI:        https://ahmadreza.me
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       post-type-generator
 * Domain Path:       /languages
 *
 * Bootstrap for Post Type Generator.
 *
 * WordPress loads only this file. It defines the PTG_* constants, includes the
 * class files, and attaches activation, deactivation, and plugins_loaded.
 * Store, Registrar, Code, and Plugin always load. Admin loads only in wp-admin.
 *
 * Saved types live in the `ptg_post_types` option and are registered on `init`.
 * Deleting a type from the screen does not delete its posts.
 *
 * @package PostTypeGenerator
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

define('PTG_VERSION', '1.0.0');
define('PTG_FILE', __FILE__);
define('PTG_DIR', plugin_dir_path(__FILE__));
define('PTG_URL', plugin_dir_url(__FILE__));

require_once PTG_DIR . 'includes/Store.php';
require_once PTG_DIR . 'includes/Registrar.php';
require_once PTG_DIR . 'includes/Code.php';
require_once PTG_DIR . 'includes/Plugin.php';

if (is_admin()) {
    require_once PTG_DIR . 'includes/Admin.php';
}

register_activation_hook(__FILE__, [\PostTypeGenerator\Plugin::class, 'activate']);
register_deactivation_hook(__FILE__, [\PostTypeGenerator\Plugin::class, 'deactivate']);

add_action('plugins_loaded', [\PostTypeGenerator\Plugin::class, 'boot']);
