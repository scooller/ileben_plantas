<?php

if (! defined('ABSPATH')) {
    exit;
}

class Ileben_Api_Client
{
    const OPTION_KEY = 'ileben_api_settings';
    const SITE_CONFIG_OPTION_KEY = 'ileben_site_config';
    const MAX_API_PAGES = 300;

    public function get_settings()
    {
        $stored_settings = get_option(self::OPTION_KEY, array());
        if (! is_array($stored_settings)) {
            $stored_settings = array();
        }

        return $this->normalize_settings($stored_settings);
    }

    public function save_settings($settings)
    {
        $normalized = $this->normalize_settings(is_array($settings) ? $settings : array());
        update_option(self::OPTION_KEY, $normalized);

        return $normalized;
    }

    public function schedule_cron()
    {
        $settings = $this->get_settings();

        if (! empty($settings['cron_enabled'])) {
            if (! wp_next_scheduled('ileben_api_cron_sync')) {
                wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', 'ileben_api_cron_sync');
            }
        } else {
            wp_clear_scheduled_hook('ileben_api_cron_sync');
        }
    }

    public function fetch_site_config($endpoint_override = '')
    {
        $settings = $this->get_settings();
        $base = ! empty($endpoint_override) ? esc_url_raw(trim((string) $endpoint_override)) : $settings['api_endpoint'];

        if (empty($base)) {
            return new WP_Error('missing_endpoint', 'Debes configurar el endpoint API en la configuracion del plugin.');
        }

        $url = $this->build_request_url($base, 'site-config', '');
        $http_args = $this->get_request_args($settings);

        $decoded = $this->perform_request($url, $http_args);
        if (is_wp_error($decoded)) {
            return $decoded;
        }

        if (! is_array($decoded)) {
            return new WP_Error('api_parse_error', 'Respuesta de site-config no es un objeto JSON valido.');
        }

        return $decoded;
    }

    public function get_site_config()
    {
        $config = get_option(self::SITE_CONFIG_OPTION_KEY, array());
        return is_array($config) ? $config : array();
    }

    public function save_site_config($config)
    {
        if (! is_array($config)) {
            $config = array();
        }
        update_option(self::SITE_CONFIG_OPTION_KEY, $config);
        return $config;
    }

    public function fetch_proyectos($endpoint_override = '')
    {
        $settings = $this->get_settings();

        if (! empty($endpoint_override)) {
            $settings['api_endpoint'] = esc_url_raw(trim((string) $endpoint_override));
        }

        if (empty($settings['api_endpoint'])) {
            return new WP_Error('missing_endpoint', 'Debes configurar el endpoint API en la configuracion del plugin.');
        }

        $url = $this->build_request_url($settings['api_endpoint'], 'proyectos', '');
        $http_args = $this->get_request_args($settings);

        $all_items = array();
        $next_url = $url;
        $visited_pages = 0;

        while (! empty($next_url) && $visited_pages < self::MAX_API_PAGES) {
            $decoded = $this->perform_request($next_url, $http_args);
            if (is_wp_error($decoded)) {
                return $decoded;
            }

            if (isset($decoded['data']) && is_array($decoded['data'])) {
                $all_items = array_merge($all_items, $decoded['data']);
                $next_url = ! empty($decoded['next_page_url']) ? (string) $decoded['next_page_url'] : '';
                $visited_pages++;
                continue;
            }

            if (is_array($decoded) && ! isset($decoded['data'])) {
                return $decoded;
            }

            break;
        }

        return $all_items;
    }

    public function fetch_plants()
    {
        $settings = $this->get_settings();
        $proyecto_id = sanitize_text_field((string) ($settings['proyecto_id'] ?? ''));

        if (empty($settings['api_endpoint'])) {
            return new WP_Error('missing_endpoint', 'Debes configurar el endpoint API en la configuracion del plugin.');
        }

        $url = $this->build_request_url($settings['api_endpoint'], 'plantas', $proyecto_id);
        $http_args = $this->get_request_args($settings);

        $all_items = array();
        $next_url = $url;
        $visited_pages = 0;

        while (! empty($next_url) && $visited_pages < self::MAX_API_PAGES) {
            $decoded = $this->perform_request($next_url, $http_args);
            if (is_wp_error($decoded)) {
                return $decoded;
            }

            if (isset($decoded['data']) && is_array($decoded['data'])) {
                $all_items = array_merge($all_items, $decoded['data']);
                $next_url = ! empty($decoded['next_page_url']) ? (string) $decoded['next_page_url'] : '';
                $next_url = $this->ensure_proyecto_query($next_url, $proyecto_id);
                $visited_pages++;
                continue;
            }

            if (isset($decoded['plants']) && is_array($decoded['plants'])) {
                return $decoded['plants'];
            }

            if ($visited_pages === 0 && is_array($decoded)) {
                return $decoded;
            }

            break;
        }

        if ($visited_pages >= self::MAX_API_PAGES) {
            return new WP_Error('api_pagination_limit', 'La API supero el maximo de paginas permitidas para sincronizar.');
        }

        return $all_items;
    }

    private function ensure_proyecto_query($url, $proyecto_id)
    {
        $url = (string) $url;
        $proyecto_id = sanitize_text_field((string) $proyecto_id);
        if ($url === '' || $proyecto_id === '') {
            return $url;
        }

        $parts = wp_parse_url($url);
        if (! is_array($parts)) {
            return add_query_arg('proyecto_id', $proyecto_id, $url);
        }

        $existing_proyecto = '';
        if (! empty($parts['query'])) {
            parse_str((string) $parts['query'], $query_args);
            $existing_proyecto = sanitize_text_field((string) ($query_args['proyecto_id'] ?? ''));
        }

        if ($existing_proyecto !== '') {
            return $url;
        }

        return add_query_arg('proyecto_id', $proyecto_id, $url);
    }

    private function get_request_args($settings)
    {
        $headers = array('Accept' => 'application/json');
        
        // authorization header en minusculas como lo requiere la API
        if (! empty($settings['api_token'])) {
            $headers['authorization'] = 'Bearer ' . $settings['api_token'];
        }

        // origin header requerido por la API
        $parsed_home = wp_parse_url(home_url());
        $origin = (isset($parsed_home['scheme']) ? $parsed_home['scheme'] : 'http') . '://' . $parsed_home['host'];
        if (isset($parsed_home['port'])) {
            $origin .= ':' . $parsed_home['port'];
        }
        $headers['origin'] = $origin;

        return array(
            'timeout' => min(120, max(5, (int) $settings['timeout'])),
            'headers' => $headers,
        );
    }

    private function perform_request($url, $args)
    {
        $this->log_request('GET ' . $url);

        $response = wp_remote_get($url, $args);

        if (is_wp_error($response)) {
            $this->log_request('WP_Error: ' . $response->get_error_message());
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        $this->log_request('HTTP ' . $code . ' | Body (500 chars): ' . substr($body, 0, 500));

        if ($code < 200 || $code >= 300) {
            return new WP_Error('api_http_error', 'La API devolvio un codigo no valido: ' . $code . ' | ' . substr($body, 0, 200));
        }

        $decoded = json_decode($body, true);

        if (! is_array($decoded)) {
            return new WP_Error('api_parse_error', 'No se pudo interpretar la respuesta JSON. Body: ' . substr($body, 0, 200));
        }

        return $decoded;
    }

    private function log_request($message)
    {
        $log_dir = ILEBEN_API_PATH . 'logs';
        if (! file_exists($log_dir)) {
            @mkdir($log_dir, 0755, true);
        }
        $log_file = $log_dir . DIRECTORY_SEPARATOR . 'sync-' . gmdate('Y-m-d') . '.log';
        $timestamp = gmdate('Y-m-d H:i:s');
        @file_put_contents($log_file, "[{$timestamp}] [API] {$message}\n", FILE_APPEND | LOCK_EX);
    }

    private function build_request_url($base_url, $endpoint, $proyecto_id = '')
    {
        // Sanitizar URL base (remover trailing slash)
        $base = rtrim((string) $base_url, '/\\');
        
        // Construir URL: {base_url}/{endpoint}
        $url = $base . '/' . ltrim($endpoint, '/\\');

        // Agregar query parameter proyecto_id si no está vacío
        if ($proyecto_id !== '') {
            $url .= '?proyecto_id=' . urlencode((string) $proyecto_id);
        }

        return $url;
    }

    private function get_default_settings()
    {
        return array(
            'api_endpoint' => '',
            'api_token' => '',
            'proyecto_id' => '',
            'cotiza_url' => '',
            'timeout' => 15,
            'cron_enabled' => 0,
        );
    }

    private function normalize_settings($settings)
    {
        $settings = wp_parse_args($settings, $this->get_default_settings());

        return array(
            'api_endpoint' => esc_url_raw(trim((string) ($settings['api_endpoint'] ?? ''))),
            'api_token' => trim((string) ($settings['api_token'] ?? '')),
            'proyecto_id' => sanitize_text_field((string) ($settings['proyecto_id'] ?? '')),
            'cotiza_url' => esc_url_raw(trim((string) ($settings['cotiza_url'] ?? ''))),
            'timeout' => min(120, max(5, (int) ($settings['timeout'] ?? 15))),
            'cron_enabled' => ! empty($settings['cron_enabled']) ? 1 : 0,
        );
    }

    public function map_api_item($item)
    {
        if (! is_array($item)) {
            return array();
        }

        $dormitorios = $this->extract_number_from_programa($item['programa'] ?? '');
        $banos = $this->extract_number_from_programa($item['programa2'] ?? '');
        
        $estado = 'no_disponible';
        if (! empty($item['is_active'])) {
            $estado = empty($item['active_reservation']) ? 'disponible' : 'no_disponible';
        }

        $descripcion = '';
        if (isset($item['proyecto']['descripcion'])) {
            $descripcion = (string) $item['proyecto']['descripcion'];
        }

        $precio_base = (float) ($item['precio_base'] ?? 0);
        $precio_lista = (float) ($item['precio_lista'] ?? 0);
        $precio = $precio_lista > 0 ? $precio_lista : $precio_base;
        $cover_image = $this->extract_image_url($item, 'cover_image_url', 'cover_image_media');
        $interior_image = $this->extract_image_url($item, 'interior_image_url', 'interior_image_media');

        return array(
            'external_id' => (string) ($item['salesforce_product_id'] ?? ''),
            'nombre' => (string) ($item['name'] ?? ''),
            'descripcion' => $descripcion,
            'precio' => $precio,
            'precio_base' => $precio_base,
            'precio_lista' => $precio_lista,
            'dormitorios' => $dormitorios,
            'banos' => $banos,
            'metros_cuadrados' => (float) ($item['superficie_vendible'] ?? $item['superficie_total_principal'] ?? 0),
            'estado' => $estado,
            'tipologia' => (string) ($item['programa'] ?? ''),
            'planta_label' => (string) ($item['product_code'] ?? ''),
            'orientacion' => (string) ($item['orientacion'] ?? ''),
            // Keep DB compatibility by storing util surface in the existing column.
            'superficie_interior' => (float) ($item['superficie_util'] ?? $item['superficie_interior'] ?? 0),
            'terraza_m2' => (float) ($item['superficie_terraza'] ?? 0),
            'superficie_total' => (float) ($item['superficie_total_principal'] ?? 0),
            'foto_portada' => $cover_image,
            'foto_interior' => $interior_image,
            'cotizacion_url' => (string) ($item['cotizacion_url'] ?? $item['cotiza_url'] ?? ''),
        );
    }

    private function extract_image_url($item, $direct_key, $media_key)
    {
        $direct_url = esc_url_raw((string) ($item[$direct_key] ?? ''));
        if ($direct_url !== '') {
            return $direct_url;
        }

        $media = $item[$media_key] ?? null;
        if (! is_array($media)) {
            return '';
        }

        $candidates = array(
            (string) ($media['url'] ?? ''),
            (string) ($media['large_url'] ?? ''),
            (string) ($media['medium_url'] ?? ''),
            (string) ($media['thumbnail_url'] ?? ''),
        );

        foreach ($candidates as $candidate) {
            $candidate = esc_url_raw($candidate);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return '';
    }

    private function extract_number_from_programa($programa)
    {
        if (empty($programa)) {
            return 0;
        }

        if (preg_match('/(\d+)/', (string) $programa, $matches)) {
            return (int) $matches[1];
        }

        $programa_lower = strtolower((string) $programa);
        if (strpos($programa_lower, 'st') !== false || strpos($programa_lower, 'studio') !== false) {
            return 0;
        }

        return 0;
    }
}
