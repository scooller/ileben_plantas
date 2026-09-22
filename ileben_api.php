<?php

/**
 * Plugin Name: API Leben
 * Description: Gestiona plantas de edificios con CRUD, importacion CSV, sincronizacion API y shortcode con filtros.
 * Version: 0.2.4
 * Author: iLeben
 * License: GPL-3.0-or-later
 * Text Domain: ileben-api
 */

if (! defined('ABSPATH')) {
    exit;
}

define('ILEBEN_API_VERSION', '0.2.4');
define('ILEBEN_API_FILE', __FILE__);
define('ILEBEN_API_PATH', plugin_dir_path(__FILE__));
define('ILEBEN_API_URL', plugin_dir_url(__FILE__));
define('ILEBEN_API_DB_VERSION', '1.7.0');
define('ILEBEN_API_CAPABILITY', 'manage_ileben_api');

require_once ILEBEN_API_PATH . 'includes/class-ileben-api-plugin.php';
require_once ILEBEN_API_PATH . 'includes/class-ileben-api-repository.php';
require_once ILEBEN_API_PATH . 'includes/class-ileben-api-client.php';
require_once ILEBEN_API_PATH . 'admin/class-ileben-api-admin.php';
require_once ILEBEN_API_PATH . 'public/class-ileben-shortcode.php';
require_once ILEBEN_API_PATH . 'public/class-ileben-cf7-integration.php';

register_activation_hook(ILEBEN_API_FILE, array('Ileben_Api_Plugin', 'activate'));
register_deactivation_hook(ILEBEN_API_FILE, array('Ileben_Api_Plugin', 'deactivate'));

function ileben_api_run_plugin()
{
    $plugin = new Ileben_Api_Plugin();
    $plugin->run();
}

ileben_api_run_plugin();

/**
 * AJAX Handler: Obtener proyectos desde la API
 */
function ileben_api_ajax_get_proyectos()
{
    ob_start();

    check_ajax_referer('ileben_api_get_proyectos', 'nonce', true);

    if (! current_user_can(ILEBEN_API_CAPABILITY)) {
        ob_end_clean();
        wp_send_json_error('No tienes permisos.', 403);
    }

    $endpoint = isset($_POST['endpoint']) ? esc_url_raw(trim((string) wp_unslash($_POST['endpoint']))) : '';

    $api_client = new Ileben_Api_Client();
    $proyectos = $api_client->fetch_proyectos($endpoint);

    ob_end_clean();

    if (is_wp_error($proyectos)) {
        wp_send_json_error($proyectos->get_error_message(), 400);
    }

    if (! is_array($proyectos) || empty($proyectos)) {
        wp_send_json_error('No se obtuvieron proyectos válidos.', 400);
    }

    // Re-indexar para garantizar array JSON (no objeto) y eliminar campos innecesarios
    $result = array_values(array_map(function ($p) {
        return array(
            'id'     => $p['id'] ?? '',
            'nombre' => $p['name'] ?? $p['nombre'] ?? 'Sin nombre',
        );
    }, $proyectos));

    wp_send_json_success($result);
}

add_action('wp_ajax_ileben_api_get_proyectos', 'ileben_api_ajax_get_proyectos');
