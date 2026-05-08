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
        $hours = $this->get_cron_interval_hours($settings);
        $schedule_key = $this->get_cron_schedule_key($hours);

        if (! empty($settings['cron_enabled'])) {
            $event = function_exists('wp_get_scheduled_event') ? wp_get_scheduled_event('ileben_api_cron_sync') : false;

            if (! $event || (isset($event->schedule) && $event->schedule !== $schedule_key)) {
                wp_clear_scheduled_hook('ileben_api_cron_sync');
                wp_schedule_event(time() + ($hours * HOUR_IN_SECONDS), $schedule_key, 'ileben_api_cron_sync');
            }
        } else {
            wp_clear_scheduled_hook('ileben_api_cron_sync');
        }
    }

    public function register_cron_schedules($schedules)
    {
        if (! is_array($schedules)) {
            $schedules = array();
        }

        $settings = $this->get_settings();
        $hours = $this->get_cron_interval_hours($settings);
        $schedule_key = $this->get_cron_schedule_key($hours);

        if (! isset($schedules[$schedule_key])) {
            $schedules[$schedule_key] = array(
                'interval' => $hours * HOUR_IN_SECONDS,
                'display' => sprintf('Cada %d hora(s) - iLeben API', $hours),
            );
        }

        return $schedules;
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

            return new WP_Error('api_format_error', 'La respuesta de plantas no cumple el formato esperado (data/next_page_url).');
        }

        if ($visited_pages >= self::MAX_API_PAGES) {
            return new WP_Error('api_pagination_limit', 'La API supero el maximo de paginas permitidas para sincronizar.');
        }

        return $all_items;
    }

    public function submit_contact_submission($channel, $fields, $turnstile_token = '')
    {
        $settings = $this->get_settings();

        if (empty($settings['api_endpoint'])) {
            return array(
                'success' => false,
                'status_code' => 0,
                'message' => 'Debes configurar el endpoint API en la configuracion del plugin.',
                'errors' => array(),
                'data' => array(),
                'request_payload' => array(),
            );
        }

        $channel = sanitize_text_field((string) $channel);
        $fields = $this->normalize_contact_submission_fields(is_array($fields) ? $fields : array());

        $payload = array(
            'channel' => $channel,
            'fields' => $fields,
        );

        $turnstile_token = sanitize_text_field((string) $turnstile_token);
        if ($turnstile_token !== '') {
            $payload['turnstile_token'] = $turnstile_token;
        }

        $url = $this->build_request_url($settings['api_endpoint'], 'contact-submissions', '');
        $http_args = $this->get_request_args($settings);
        $http_args['method'] = 'POST';
        $http_args['headers']['Content-Type'] = 'application/json';
        $http_args['body'] = wp_json_encode($payload);

        $this->log_request('POST ' . $url . ' | Payload: ' . substr((string) $http_args['body'], 0, 500));
        $response = wp_remote_post($url, $http_args);

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'status_code' => 0,
                'message' => $response->get_error_message(),
                'errors' => array(),
                'data' => array(),
                'request_payload' => $payload,
            );
        }

        $status_code = (int) wp_remote_retrieve_response_code($response);
        $response_body = (string) wp_remote_retrieve_body($response);
        $decoded = json_decode($response_body, true);
        $decoded = is_array($decoded) ? $decoded : array();

        $message = sanitize_text_field((string) ($decoded['message'] ?? ''));
        if ($message === '') {
            $message = $status_code >= 200 && $status_code < 300
                ? 'Contacto enviado correctamente.'
                : 'No se pudo enviar el contacto al API.';
        }

        $errors = array();
        if (isset($decoded['errors']) && is_array($decoded['errors'])) {
            $errors = $decoded['errors'];
        }

        $data = array();
        if (isset($decoded['data']) && is_array($decoded['data'])) {
            $data = $decoded['data'];
        }

        $remote_id = (int) ($decoded['id'] ?? 0);
        if ($remote_id > 0) {
            $data['id'] = $remote_id;
        }

        $this->log_request('POST HTTP ' . $status_code . ' | Body (500 chars): ' . substr($response_body, 0, 500));

        return array(
            'success' => $status_code >= 200 && $status_code < 300,
            'status_code' => $status_code,
            'message' => $message,
            'errors' => $errors,
            'data' => $data,
            'raw_body' => $response_body,
            'request_payload' => $payload,
        );
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

    private function normalize_contact_submission_fields($fields)
    {
        if (! is_array($fields)) {
            return array();
        }

        $normalized_fields = array();
        foreach ($fields as $key => $value) {
            $field_key = sanitize_key((string) $key);
            if ($field_key === '') {
                continue;
            }

            if (is_array($value)) {
                $value = implode(', ', array_map('sanitize_text_field', $value));
            }

            $normalized_fields[$field_key] = sanitize_text_field((string) $value);
        }

        if (empty($normalized_fields['rango'])) {
            foreach (array('rango-renta', 'rango_renta') as $alias) {
                $alias_key = sanitize_key($alias);
                if (! empty($normalized_fields[$alias_key])) {
                    $normalized_fields['rango'] = $normalized_fields[$alias_key];
                    break;
                }
            }
        }

        return $normalized_fields;
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
            'cron_interval_hours' => 1,
            'price_display_mode' => 'base',
            'show_cover_image' => 1,
            'use_api_favicon' => 1,
        );
    }

    private function normalize_settings($settings)
    {
        $settings = wp_parse_args($settings, $this->get_default_settings());

        $price_display_mode = sanitize_key((string) ($settings['price_display_mode'] ?? 'base'));
        if (! in_array($price_display_mode, array('base', 'final'), true)) {
            $price_display_mode = 'base';
        }

        return array(
            'api_endpoint' => esc_url_raw(trim((string) ($settings['api_endpoint'] ?? ''))),
            'api_token' => trim((string) ($settings['api_token'] ?? '')),
            'proyecto_id' => sanitize_text_field((string) ($settings['proyecto_id'] ?? '')),
            'cotiza_url' => esc_url_raw(trim((string) ($settings['cotiza_url'] ?? ''))),
            'timeout' => min(120, max(5, (int) ($settings['timeout'] ?? 15))),
            'cron_enabled' => ! empty($settings['cron_enabled']) ? 1 : 0,
            'cron_interval_hours' => min(24, max(1, (int) ($settings['cron_interval_hours'] ?? 1))),
            'price_display_mode' => $price_display_mode,
            'show_cover_image' => ! isset($settings['show_cover_image']) || ! empty($settings['show_cover_image']) ? 1 : 0,
            'use_api_favicon' => ! isset($settings['use_api_favicon']) || ! empty($settings['use_api_favicon']) ? 1 : 0,
        );
    }

    private function get_cron_interval_hours($settings)
    {
        return min(24, max(1, (int) ($settings['cron_interval_hours'] ?? 1)));
    }

    private function get_cron_schedule_key($hours)
    {
        return 'ileben_api_every_' . (int) $hours . '_hours';
    }

    public function map_api_item($item)
    {
        if (! is_array($item)) {
            return array();
        }

        $programa = (string) ($item['programa2'] ?? $item['programa'] ?? '');
        $dormitorios = $this->extract_programa_rooms($programa, 'd');
        $banos = $this->extract_programa_rooms($programa, 'b');

        $estado = 'disponible';
        if (empty($item['is_available']) || ! empty($item['unidad_sale'])) {
            $estado = 'no_disponible';
        }

        if (! empty($item['is_paid']) || ! empty($item['completed_reservation']) || ! empty($item['completed_payment'])) {
            $estado = 'no_disponible';
        }

        $descripcion = (string) ($item['descripcion'] ?? '');
        if (isset($item['proyecto']['descripcion'])) {
            $descripcion = (string) $item['proyecto']['descripcion'];
        }

        $precio_base = (float) ($item['precio_base'] ?? 0);
        $precio_final = (float) ($item['precio_final'] ?? $item['precioFinal'] ?? 0);
        $precio_lista = (float) ($item['precio_lista'] ?? 0);
        if ($precio_lista <= 0 && $precio_final > 0) {
            $precio_lista = $precio_final;
        }
        $precio = $precio_lista > 0 ? $precio_lista : $precio_base;
        if ($precio <= 0) {
            $precio = $precio_base;
        }

        $cover_image = $this->extract_image_url(
            $item,
            array('cover_image_url', 'imageUrl', 'proyectoImageUrl'),
            array('cover_image_media', 'interior_image_media')
        );
        $interior_image = $this->extract_image_url(
            $item,
            array('interior_image_url', 'detailImageUrl', 'salesforce_interior_image_url'),
            array('interior_image_media', 'cover_image_media')
        );

        $nombre = sanitize_text_field((string) ($item['name'] ?? ''));
        $tipologia = sanitize_text_field((string) ($item['programa'] ?? ''));
        $tipo_producto = $this->normalize_tipo_producto((string) ($item['tipo_producto'] ?? ''));
        $planta_label = sanitize_text_field((string) ($item['name'] ?? ''));

        return array(
            'external_id' => (string) ($item['salesforce_product_id'] ?? ''),
            'nombre' => $nombre,
            'descripcion' => $descripcion,
            'precio' => $precio,
            'precio_base' => $precio_base,
            'precio_lista' => $precio_lista,
            'dormitorios' => $dormitorios,
            'banos' => $banos,
            'metros_cuadrados' => (float) ($item['superficie_total_principal'] ?? 0),
            'estado' => $estado,
            'tipologia' => $tipologia,
            'tipo_producto' => $tipo_producto,
            'planta_label' => $planta_label,
            'orientacion' => (string) ($item['orientacion'] ?? ''),
            'superficie_interior' => (float) ($item['superficie_util'] ?? $item['superficie_interior'] ?? 0),
            'terraza_m2' => (float) ($item['superficie_terraza'] ?? 0),
            'superficie_total' => (float) ($item['superficie_total_principal'] ?? 0),
            'foto_portada' => $cover_image,
            'foto_interior' => $interior_image,
            'cotizacion_url' => '',
        );
    }

    private function extract_image_url($item, $direct_keys, $media_keys)
    {
        $keys = is_array($direct_keys) ? $direct_keys : array($direct_keys);
        foreach ($keys as $direct_key) {
            $direct_url = esc_url_raw((string) ($item[$direct_key] ?? ''));
            if ($direct_url !== '') {
                return $direct_url;
            }
        }

        $media_key_list = is_array($media_keys) ? $media_keys : array($media_keys);
        foreach ($media_key_list as $media_key) {
            $media = $item[$media_key] ?? null;
            if (! is_array($media)) {
                continue;
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
        }

        return '';
    }

    private function extract_programa_rooms($programa, $type)
    {
        $programa = strtolower((string) $programa);
        $type = strtolower((string) $type);
        if ($programa === '' || ($type !== 'd' && $type !== 'b')) {
            return 0;
        }

        if (preg_match('/(\d+)\s*' . preg_quote($type, '/') . '\b/', $programa, $matches)) {
            return (int) $matches[1];
        }

        return 0;
    }

    private function normalize_tipo_producto($tipo_producto)
    {
        $tipo_producto = sanitize_text_field((string) $tipo_producto);
        if ($tipo_producto === '') {
            return '';
        }

        $normalized = strtolower(str_replace(array(' ', '_'), '-', $tipo_producto));
        if ($normalized === 'rango' || $normalized === 'rango-renta') {
            return 'Rango';
        }

        return $tipo_producto;
    }
}
