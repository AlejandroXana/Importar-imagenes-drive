<?php
/**
 * Plugin Name: Drive Media Importer
 * Description: Importa imágenes desde Google Drive (Workspace) directamente a la biblioteca de medios de WordPress.
 * Version: 1.0.0
 * Author: Xana
 * Text Domain: drive-media-importer
 * Requires PHP: 7.4
 */

defined('ABSPATH') || exit;

define('DMI_VERSION', '1.0.0');
define('DMI_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('DMI_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once DMI_PLUGIN_DIR . 'includes/class-dmi-google-drive.php';
require_once DMI_PLUGIN_DIR . 'includes/class-dmi-admin.php';

function dmi_init() {
    DMI_Admin::instance();
}
add_action('plugins_loaded', 'dmi_init');

register_activation_hook(__FILE__, function () {
    add_option('dmi_client_id', '');
    add_option('dmi_client_secret', '');
    add_option('dmi_tokens', '');
});

register_deactivation_hook(__FILE__, function () {
    delete_option('dmi_client_id');
    delete_option('dmi_client_secret');
    delete_option('dmi_tokens');
});
