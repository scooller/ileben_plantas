<?php
/**
 * Plugin Name: iLeben Plantas
 * Description: Gestiona plantas de edificios con CRUD, importacion CSV, sincronizacion API y shortcode con filtros.
 * Version: 0.1.0
 * Author: iLeben
 * License: GPL-3.0-or-later
 * Text Domain: ileben-plantas
 */

if (! defined('ABSPATH')) {
    exit;
}

define('ILEBEN_PLANTAS_VERSION', '0.1.0');
define('ILEBEN_PLANTAS_FILE', __FILE__);
define('ILEBEN_PLANTAS_PATH', plugin_dir_path(__FILE__));
define('ILEBEN_PLANTAS_URL', plugin_dir_url(__FILE__));
define('ILEBEN_PLANTAS_DB_VERSION', '1.3.0');
define('ILEBEN_PLANTAS_CAPABILITY', 'manage_ileben_plantas');

require_once ILEBEN_PLANTAS_PATH . 'includes/class-ileben-plantas-plugin.php';
require_once ILEBEN_PLANTAS_PATH . 'includes/class-ileben-plantas-repository.php';
require_once ILEBEN_PLANTAS_PATH . 'includes/class-ileben-plantas-api-client.php';
require_once ILEBEN_PLANTAS_PATH . 'admin/class-ileben-plantas-admin.php';
require_once ILEBEN_PLANTAS_PATH . 'public/class-ileben-plantas-shortcode.php';

register_activation_hook(ILEBEN_PLANTAS_FILE, array('Ileben_Plantas_Plugin', 'activate'));
register_deactivation_hook(ILEBEN_PLANTAS_FILE, array('Ileben_Plantas_Plugin', 'deactivate'));

function ileben_plantas_run_plugin()
{
    $plugin = new Ileben_Plantas_Plugin();
    $plugin->run();
}

ileben_plantas_run_plugin();
