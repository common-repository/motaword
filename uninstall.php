<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package    motaword
 */

// If uninstall not called from WordPress, then exit.
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

if (!current_user_can('delete_plugins')) {
    exit;
}

// Important: Check if the file is the one
// that was registered during the uninstall hook.
if (strpos(WP_UNINSTALL_PLUGIN, 'motaword.php') === false) {
    exit;
}

require plugin_dir_path(__FILE__) . 'includes/class-motaword.php';

$plugin = new MotaWord(plugin_basename(__FILE__));
$plugin->run();

MotaWord_API::clear_cache();
delete_option(MotaWord_Admin::$options['client_id']);
delete_option(MotaWord_Admin::$options['client_secret']);
delete_option(MotaWord_Admin::$options['sandbox']);
delete_option(MotaWord_Admin::$options['is_custom_fields']);
delete_option(MotaWord_Admin::$options['process_gutenberg_block_attributes']);
delete_option(MotaWord_Admin::$options['translate_slugs']);
delete_option(MotaWord_Admin::$options['active_token']);
delete_option(MotaWord_Admin::$options['active_project_info']);
delete_option(MotaWord_Admin::$options['active_widget_info']);
delete_option(MotaWord_Admin::$options['is_active_serve_enabled']);
delete_option(MotaWord_Admin::$options['is_insert_active_js']);
delete_option(MotaWord_Admin::$options['is_insert_for_admin_when_disabled']);
delete_option(MotaWord_Admin::$options['is_active_urlmode_query']);