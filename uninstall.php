<?php
/**
 * Uninstall Post Type Generator.
 *
 * WordPress includes this file only when the plugin is deleted from the
 * Plugins screen. It removes the saved definitions and the flush flag, then
 * clears rewrite rules so the next request rebuilds permalinks.
 *
 * Posts that were created with a generated type are intentionally kept.
 * Their `post_type` column still holds the old key. Register that same key
 * again if those posts should reappear in wp-admin.
 *
 * @package PostTypeGenerator
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

delete_option('ptg_post_types');
delete_option('ptg_flush_rewrite');
delete_option('rewrite_rules');
