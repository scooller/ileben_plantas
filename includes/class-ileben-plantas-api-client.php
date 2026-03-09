<?php

if (! defined('ABSPATH')) {
    exit;
}

class Ileben_Plantas_Api_Client
{
    public function get_settings()
    {
        return $this->get_env_settings();
    }

    public function schedule_cron()
    {
        $settings = $this->get_settings();

        if (! empty($settings['cron_enabled'])) {
            if (! wp_next_scheduled('ileben_plantas_cron_sync')) {
                wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', 'ileben_plantas_cron_sync');
            }
        } else {
            wp_clear_scheduled_hook('ileben_plantas_cron_sync');
        }
    }

    public function fetch_plants()
    {
        $settings = $this->get_settings();

        if (empty($settings['api_endpoint'])) {
            return new WP_Error('missing_endpoint', 'Debes configurar ENDPOINT_API en el archivo .env del plugin.');
        }

        $url = $settings['api_endpoint'];
        
        if (! empty($settings['proyecto_id'])) {
            $separator = (strpos($url, '?') === false) ? '?' : '&';
            $url .= $separator . 'proyecto_id=' . urlencode($settings['proyecto_id']);
        }

        $headers = array('Accept' => 'application/json');
        if (! empty($settings['api_token'])) {
            $headers['Authorization'] = 'Bearer ' . $settings['api_token'];
        }

        $response = wp_remote_get(
            $url,
            array(
                'timeout' => min(120, max(5, (int) $settings['timeout'])),
                'headers' => $headers,
            )
        );

        if (is_wp_error($response)) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return new WP_Error('api_http_error', 'La API devolvio un codigo no valido: ' . $code);
        }

        $body = wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);

        if (! is_array($decoded)) {
            return new WP_Error('api_parse_error', 'No se pudo interpretar la respuesta JSON de la API.');
        }

        if (isset($decoded['data']) && is_array($decoded['data'])) {
            return $decoded['data'];
        }

        if (isset($decoded['plants']) && is_array($decoded['plants'])) {
            return $decoded['plants'];
        }

        return $decoded;
    }

    private function get_env_settings()
    {
        $cron_defined_in_file = false;

        $settings = array(
            'api_endpoint' => '',
            'api_token' => '',
            'proyecto_id' => '',
            'cotiza_url' => '',
            'timeout' => 15,
            'cron_enabled' => 0,
        );

        $env_map = array(
            'ENDPOINT_API' => 'api_endpoint',
            'ENDPOINT_TOKEN' => 'api_token',
            'PROYECTO_ID' => 'proyecto_id',
            'COTIZA_URL' => 'cotiza_url',
            'TIMEOUT' => 'timeout',
            'CRON' => 'cron_enabled',
        );

        $env_file = ILEBEN_PLANTAS_PATH . '.env';
        if (is_readable($env_file)) {
            $lines = file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (is_array($lines)) {
                foreach ($lines as $line) {
                    $line = trim((string) $line);
                    if ($line === '' || strpos($line, '#') === 0) {
                        continue;
                    }

                    $parts = explode('=', $line, 2);
                    if (count($parts) !== 2) {
                        continue;
                    }

                    $key = trim($parts[0]);
                    $value = trim($parts[1]);
                    if (! isset($env_map[$key])) {
                        continue;
                    }

                    $mapped_key = $env_map[$key];
                    if ($mapped_key === 'timeout') {
                        $settings['timeout'] = max(5, (int) $value);
                        continue;
                    }
                    if ($mapped_key === 'cron_enabled') {
                        $cron_defined_in_file = true;
                        $settings['cron_enabled'] = in_array(strtolower($value), array('true', '1', 'yes', 'on'), true) ? 1 : 0;
                        continue;
                    }

                    $settings[$mapped_key] = $value;
                }
            }
        }

        if ($settings['api_endpoint'] === '') {
            $settings['api_endpoint'] = trim((string) getenv('ENDPOINT_API'));
        }

        if ($settings['api_token'] === '') {
            $settings['api_token'] = trim((string) getenv('ENDPOINT_TOKEN'));
        }

        if ($settings['proyecto_id'] === '') {
            $settings['proyecto_id'] = trim((string) getenv('PROYECTO_ID'));
        }

        if ($settings['cotiza_url'] === '') {
            $settings['cotiza_url'] = trim((string) getenv('COTIZA_URL'));
        }

        if ((int) $settings['timeout'] < 5) {
            $settings['timeout'] = min(120, max(5, (int) getenv('TIMEOUT')));
        } else {
            $settings['timeout'] = min(120, max(5, (int) $settings['timeout']));
        }

        if (! $cron_defined_in_file) {
            $cron_env = getenv('CRON');
            if ($cron_env !== false) {
                $settings['cron_enabled'] = in_array(strtolower((string) $cron_env), array('true', '1', 'yes', 'on'), true) ? 1 : 0;
            }
        }

        return $settings;
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

        return array(
            'external_id' => (string) ($item['salesforce_product_id'] ?? $item['id'] ?? ''),
            'nombre' => (string) ($item['name'] ?? ''),
            'descripcion' => $descripcion,
            'precio' => (float) ($item['precio_lista'] ?? $item['precio_base'] ?? 0),
            'dormitorios' => $dormitorios,
            'banos' => $banos,
            'metros_cuadrados' => (float) ($item['superficie_vendible'] ?? $item['superficie_total_principal'] ?? 0),
            'estado' => $estado,
            'tipologia' => (string) ($item['programa'] ?? ''),
            'planta_label' => (string) ($item['product_code'] ?? $item['name'] ?? ''),
            'orientacion' => (string) ($item['orientacion'] ?? ''),
            'superficie_interior' => (float) ($item['superficie_interior'] ?? 0),
            'terraza_m2' => (float) ($item['superficie_terraza'] ?? 0),
            'superficie_total' => (float) ($item['superficie_total_principal'] ?? 0),
            'cotizacion_url' => (string) ($item['cotizacion_url'] ?? $item['cotiza_url'] ?? ''),
        );
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
