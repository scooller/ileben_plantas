<?php

if (! defined('ABSPATH')) {
    exit;
}

class Ileben_Api_Plugin
{
    public static function activate()
    {
        self::create_table();
        self::add_capabilities();
        add_option('ileben_api_db_version', ILEBEN_API_DB_VERSION);
    }

    public static function deactivate()
    {
        wp_clear_scheduled_hook('ileben_api_cron_sync');
    }

    public function run()
    {
        add_action('plugins_loaded', array($this, 'maybe_upgrade_database'));

        $repository = new Ileben_Api_Repository();
        $api_client = new Ileben_Api_Client();

        add_action('plugins_loaded', array($api_client, 'schedule_cron'));

        $admin = new Ileben_Api_Admin($repository, $api_client);
        $admin->register();

        $shortcode = new Ileben_Api_Shortcode($repository);
        $shortcode->register();
    }

    public function maybe_upgrade_database()
    {
        $current_version = get_option('ileben_api_db_version', '0.0.0');

        if (version_compare($current_version, ILEBEN_API_DB_VERSION, '<')) {
            self::create_table();
            update_option('ileben_api_db_version', ILEBEN_API_DB_VERSION);
        }
    }

    public static function get_table_name()
    {
        global $wpdb;
        return $wpdb->prefix . 'ileben_api';
    }

    private static function create_table()
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table_name = self::get_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table_name} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            external_id VARCHAR(100) NOT NULL DEFAULT '',
            nombre VARCHAR(191) NOT NULL,
            descripcion LONGTEXT NULL,
            precio DECIMAL(12,2) NOT NULL DEFAULT 0,
            precio_base DECIMAL(12,2) NOT NULL DEFAULT 0,
            precio_lista DECIMAL(12,2) NOT NULL DEFAULT 0,
            banos INT UNSIGNED NOT NULL DEFAULT 0,
            dormitorios INT UNSIGNED NOT NULL DEFAULT 0,
            metros_cuadrados DECIMAL(10,2) NOT NULL DEFAULT 0,
            tipologia VARCHAR(120) NULL,
            planta_label VARCHAR(120) NULL,
            orientacion VARCHAR(60) NULL,
            superficie_interior DECIMAL(10,2) NULL,
            terraza_m2 DECIMAL(10,2) NULL,
            superficie_total DECIMAL(10,2) NULL,
            foto_portada VARCHAR(255) NULL,
            foto_interior VARCHAR(255) NULL,
            brochure VARCHAR(255) NULL,
            cotizacion_url VARCHAR(255) NULL,
            estado VARCHAR(30) NOT NULL DEFAULT 'disponible',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY external_id (external_id),
            KEY idx_precio (precio),
            KEY idx_precio_base (precio_base),
            KEY idx_precio_lista (precio_lista),
            KEY idx_banos (banos),
            KEY idx_dormitorios (dormitorios),
            KEY idx_estado (estado),
            KEY idx_nombre (nombre)
        ) {$charset_collate};";

        dbDelta($sql);
    }

    private static function add_capabilities()
    {
        $roles = array('administrator', 'editor');

        foreach ($roles as $role_name) {
            $role = get_role($role_name);
            if ($role && ! $role->has_cap(ILEBEN_API_CAPABILITY)) {
                $role->add_cap(ILEBEN_API_CAPABILITY);
            }
        }
    }
}
