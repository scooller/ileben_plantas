<?php

if (! defined('ABSPATH')) {
    exit;
}

class Ileben_Api_Repository
{
    private $table_name;
    private $contact_sync_table_name;

    public function __construct()
    {
        $this->table_name = Ileben_Api_Plugin::get_table_name();
        $this->contact_sync_table_name = Ileben_Api_Plugin::get_contact_sync_table_name();
    }

    public function find($id)
    {
        global $wpdb;

        $sql = $wpdb->prepare("SELECT * FROM {$this->table_name} WHERE id = %d", $id);
        return $wpdb->get_row($sql, ARRAY_A);
    }

    public function delete($id)
    {
        global $wpdb;

        return $wpdb->delete(
            $this->table_name,
            array('id' => (int) $id),
            array('%d')
        );
    }

    public function delete_all()
    {
        global $wpdb;

        $sql = "DELETE FROM {$this->table_name}";
        return $wpdb->query($sql);
    }

    public function delete_not_in_external_ids($external_ids)
    {
        global $wpdb;

        if (! is_array($external_ids) || empty($external_ids)) {
            return 0;
        }

        $clean_ids = array();
        foreach ($external_ids as $external_id) {
            $external_id = sanitize_text_field((string) $external_id);
            if ($external_id !== '') {
                $clean_ids[] = $external_id;
            }
        }

        if (empty($clean_ids)) {
            return 0;
        }

        $placeholders = implode(', ', array_fill(0, count($clean_ids), '%s'));
        $sql = "DELETE FROM {$this->table_name} WHERE external_id NOT IN ({$placeholders})";
        $prepared = $wpdb->prepare($sql, $clean_ids);
        return $wpdb->query($prepared);
    }

    public function save($data)
    {
        global $wpdb;

        $now = current_time('mysql');
        $payload = $this->sanitize_data($data);
        $payload['updated_at'] = $now;

        if (! empty($payload['id'])) {
            $id = (int) $payload['id'];
            unset($payload['id']);

            $result = $wpdb->update(
                $this->table_name,
                $payload,
                array('id' => $id),
                $this->get_formats($payload),
                array('%d')
            );

            return $result !== false ? $id : false;
        }

        unset($payload['id']);
        $payload['created_at'] = $now;

        $result = $wpdb->insert(
            $this->table_name,
            $payload,
            $this->get_formats($payload)
        );

        if ($result === false) {
            return false;
        }

        return (int) $wpdb->insert_id;
    }

    public function upsert_by_external_id($data)
    {
        global $wpdb;

        $payload = $this->sanitize_data($data);

        if (empty($payload['external_id'])) {
            return false;
        }

        $existing_id = $this->find_id_by_external_id($payload['external_id']);
        if ($existing_id) {
            $payload['id'] = $existing_id;
            return $this->save($payload);
        }

        return $this->save($payload);
    }

    public function find_id_by_external_id($external_id)
    {
        global $wpdb;

        $sql = $wpdb->prepare(
            "SELECT id FROM {$this->table_name} WHERE external_id = %s",
            sanitize_text_field($external_id)
        );

        $id = $wpdb->get_var($sql);
        return $id ? (int) $id : 0;
    }

    public function get_states()
    {
        return array(
            'disponible' => 'Disponible',
            'no_disponible' => 'No disponible',
        );
    }

    public function get_tipo_producto_options()
    {
        global $wpdb;

        $sql = "SELECT DISTINCT tipo_producto FROM {$this->table_name} WHERE tipo_producto IS NOT NULL AND tipo_producto <> '' ORDER BY tipo_producto ASC";
        $rows = $wpdb->get_col($sql);

        if (! is_array($rows)) {
            return array();
        }

        $options = array();
        foreach ($rows as $row) {
            $value = sanitize_text_field((string) $row);
            if ($value !== '') {
                $options[$value] = $value;
            }
        }

        return $options;
    }

    public function query($filters = array(), $page = 1, $per_page = 12)
    {
        global $wpdb;

        $where = array('1=1');
        $params = array();

        if (! empty($filters['search'])) {
            $where[] = '(nombre LIKE %s OR descripcion LIKE %s)';
            $search = '%' . $wpdb->esc_like($filters['search']) . '%';
            $params[] = $search;
            $params[] = $search;
        }

        if ($filters['estado'] ?? '') {
            $where[] = 'estado = %s';
            $params[] = sanitize_text_field($filters['estado']);
        }

        if ($filters['tipologia'] ?? '') {
            $where[] = 'tipologia = %s';
            $params[] = sanitize_text_field($filters['tipologia']);
        }

        if ($filters['tipo_producto'] ?? '') {
            $where[] = 'tipo_producto = %s';
            $params[] = sanitize_text_field($filters['tipo_producto']);
        }

        if ($filters['planta_label'] ?? '') {
            $where[] = 'planta_label = %s';
            $params[] = sanitize_text_field($filters['planta_label']);
        }

        if (($filters['banos'] ?? '') !== '') {
            $where[] = 'banos >= %d';
            $params[] = max(0, (int) $filters['banos']);
        }

        if (($filters['dormitorios'] ?? '') !== '') {
            $where[] = 'dormitorios >= %d';
            $params[] = max(0, (int) $filters['dormitorios']);
        }

        if (($filters['precio_min'] ?? '') !== '') {
            $where[] = 'precio >= %f';
            $params[] = (float) $filters['precio_min'];
        }

        if (($filters['precio_max'] ?? '') !== '') {
            $where[] = 'precio <= %f';
            $params[] = (float) $filters['precio_max'];
        }

        $where_sql = implode(' AND ', $where);

        $count_sql = "SELECT COUNT(*) FROM {$this->table_name} WHERE {$where_sql}";
        $prepared_count_sql = $params ? $wpdb->prepare($count_sql, $params) : $count_sql;
        $total = (int) $wpdb->get_var($prepared_count_sql);

        $page = max(1, (int) $page);
        $per_page = max(1, (int) $per_page);
        $offset = ($page - 1) * $per_page;

        $order_by = 'updated_at DESC';
        if (($filters['orderby'] ?? '') === 'precio_asc') {
            $order_by = 'precio ASC';
        } elseif (($filters['orderby'] ?? '') === 'precio_desc') {
            $order_by = 'precio DESC';
        } elseif (($filters['orderby'] ?? '') === 'nombre_asc') {
            $order_by = 'nombre ASC';
        }

        $items_sql = "SELECT * FROM {$this->table_name} WHERE {$where_sql} ORDER BY {$order_by} LIMIT %d OFFSET %d";
        $items_params = array_merge($params, array($per_page, $offset));
        $prepared_items_sql = $wpdb->prepare($items_sql, $items_params);
        $items = $wpdb->get_results($prepared_items_sql, ARRAY_A);

        return array(
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $per_page,
            'pages' => (int) ceil($total / $per_page),
        );
    }

    public function get_contact_sync_states()
    {
        return array(
            'sent' => 'Enviado',
            'validation_error' => 'Error de validacion',
            'rate_limited' => 'Rate limited',
            'failed' => 'Fallido',
        );
    }

    public function save_contact_sync_log($data)
    {
        global $wpdb;

        $now = current_time('mysql');

        if (! empty($data['id'])) {
            $existing = $this->find_contact_sync_log((int) $data['id']);
            if (is_array($existing) && ! empty($existing)) {
                $data = array_merge($existing, $data);
            }
        }

        $payload = $this->sanitize_contact_sync_data($data);
        $payload['updated_at'] = $now;

        if (! empty($payload['id'])) {
            $id = (int) $payload['id'];
            unset($payload['id']);

            $result = $wpdb->update(
                $this->contact_sync_table_name,
                $payload,
                array('id' => $id),
                $this->get_contact_sync_formats($payload),
                array('%d')
            );

            return $result !== false ? $id : false;
        }

        unset($payload['id']);
        $payload['created_at'] = $now;

        $result = $wpdb->insert(
            $this->contact_sync_table_name,
            $payload,
            $this->get_contact_sync_formats($payload)
        );

        if ($result === false) {
            return false;
        }

        return (int) $wpdb->insert_id;
    }

    public function find_contact_sync_log($id)
    {
        global $wpdb;

        $sql = $wpdb->prepare("SELECT * FROM {$this->contact_sync_table_name} WHERE id = %d", (int) $id);
        return $wpdb->get_row($sql, ARRAY_A);
    }

    public function increment_contact_sync_retry($id)
    {
        global $wpdb;

        $sql = $wpdb->prepare(
            "UPDATE {$this->contact_sync_table_name} SET retries = retries + 1, updated_at = %s WHERE id = %d",
            current_time('mysql'),
            (int) $id
        );

        return $wpdb->query($sql);
    }

    public function query_contact_sync_logs($filters = array(), $page = 1, $per_page = 20)
    {
        global $wpdb;

        $where = array('1=1');
        $params = array();

        if (($filters['status'] ?? '') !== '') {
            $where[] = 'status = %s';
            $params[] = sanitize_text_field((string) $filters['status']);
        }

        if (($filters['channel'] ?? '') !== '') {
            $where[] = 'channel = %s';
            $params[] = sanitize_text_field((string) $filters['channel']);
        }

        if (($filters['email'] ?? '') !== '') {
            $where[] = 'contact_email LIKE %s';
            $params[] = '%' . $wpdb->esc_like((string) $filters['email']) . '%';
        }

        if (($filters['date_from'] ?? '') !== '') {
            $where[] = 'DATE(created_at) >= %s';
            $params[] = sanitize_text_field((string) $filters['date_from']);
        }

        if (($filters['date_to'] ?? '') !== '') {
            $where[] = 'DATE(created_at) <= %s';
            $params[] = sanitize_text_field((string) $filters['date_to']);
        }

        $where_sql = implode(' AND ', $where);
        $count_sql = "SELECT COUNT(*) FROM {$this->contact_sync_table_name} WHERE {$where_sql}";
        $prepared_count_sql = $params ? $wpdb->prepare($count_sql, $params) : $count_sql;
        $total = (int) $wpdb->get_var($prepared_count_sql);

        $page = max(1, (int) $page);
        $per_page = max(1, (int) $per_page);
        $offset = ($page - 1) * $per_page;

        $items_sql = "SELECT * FROM {$this->contact_sync_table_name} WHERE {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d";
        $items_params = array_merge($params, array($per_page, $offset));
        $prepared_items_sql = $wpdb->prepare($items_sql, $items_params);
        $items = $wpdb->get_results($prepared_items_sql, ARRAY_A);

        return array(
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $per_page,
            'pages' => (int) ceil($total / $per_page),
        );
    }

    public function get_contact_ids_by_filters($filters = array())
    {
        global $wpdb;

        $where = array('1=1');
        $params = array();

        if (($filters['status'] ?? '') !== '') {
            $where[] = 'status = %s';
            $params[] = sanitize_text_field((string) $filters['status']);
        }

        if (($filters['channel'] ?? '') !== '') {
            $where[] = 'channel = %s';
            $params[] = sanitize_text_field((string) $filters['channel']);
        }

        if (($filters['email'] ?? '') !== '') {
            $where[] = 'contact_email LIKE %s';
            $params[] = '%' . $wpdb->esc_like((string) $filters['email']) . '%';
        }

        if (($filters['date_from'] ?? '') !== '') {
            $where[] = 'DATE(created_at) >= %s';
            $params[] = sanitize_text_field((string) $filters['date_from']);
        }

        if (($filters['date_to'] ?? '') !== '') {
            $where[] = 'DATE(created_at) <= %s';
            $params[] = sanitize_text_field((string) $filters['date_to']);
        }

        $where_sql = implode(' AND ', $where);
        $sql = "SELECT id FROM {$this->contact_sync_table_name} WHERE {$where_sql} ORDER BY created_at DESC";
        $prepared_sql = $params ? $wpdb->prepare($sql, $params) : $sql;
        $results = $wpdb->get_col($prepared_sql);

        return array_map('intval', (array) $results);
    }

    public function bulk_update_contact_channel(array $ids, $new_channel)
    {
        global $wpdb;

        $channel = sanitize_text_field((string) $new_channel);
        if ($channel === '') {
            return 0;
        }

        $clean_ids = array_filter(array_map('intval', $ids), function ($id) {
            return $id > 0;
        });

        if (empty($clean_ids)) {
            return 0;
        }

        $now = current_time('mysql');
        $updated_count = 0;

        $placeholders = implode(',', array_fill(0, count($clean_ids), '%d'));
        $sql = $wpdb->prepare("SELECT id, payload_json FROM {$this->contact_sync_table_name} WHERE id IN ({$placeholders})", $clean_ids);
        $rows = $wpdb->get_results($sql, ARRAY_A);

        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $payload = json_decode((string) ($row['payload_json'] ?? '{}'), true);
            if (! is_array($payload)) {
                $payload = array();
            }
            $payload['channel'] = $channel;
            $payload_json = wp_json_encode($payload);

            $result = $wpdb->update(
                $this->contact_sync_table_name,
                array(
                    'channel' => $channel,
                    'payload_json' => $payload_json,
                    'updated_at' => $now,
                ),
                array('id' => $id),
                array('%s', '%s', '%s'),
                array('%d')
            );

            if ($result !== false) {
                $updated_count++;
            }
        }

        return $updated_count;
    }


    public function get_contact_sync_stats()
    {
        global $wpdb;

        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->contact_sync_table_name}");
        $sent = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->contact_sync_table_name} WHERE status = %s", 'sent'));
        $failed_today = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->contact_sync_table_name} WHERE status IN (%s, %s, %s) AND DATE(created_at) = %s",
            'failed',
            'validation_error',
            'rate_limited',
            current_time('Y-m-d')
        ));

        return array(
            'total' => $total,
            'sent' => $sent,
            'failed_today' => $failed_today,
            'error_rate' => $total > 0 ? round((($total - $sent) / $total) * 100, 2) : 0,
        );
    }

    private function sanitize_data($data)
    {
        $states = array_keys($this->get_states());

        $foto_portada = esc_url_raw((string) ($data['foto_portada'] ?? ''));
        $foto_interior = esc_url_raw((string) ($data['foto_interior'] ?? ''));

        if ($foto_portada === '' || $foto_interior === '') {
            $image_source = $data['foto'] ?? ($data['fotos'] ?? '');
            if (! empty($image_source)) {
                if (is_array($image_source)) {
                    if ($foto_portada === '') {
                        $foto_portada = esc_url_raw((string) ($image_source[0] ?? ''));
                    }
                    if ($foto_interior === '') {
                        $foto_interior = esc_url_raw((string) ($image_source[1] ?? ''));
                    }
                } else {
                    $raw = trim((string) $image_source);
                    $decoded = json_decode($raw, true);

                    if (is_array($decoded)) {
                        if ($foto_portada === '') {
                            $foto_portada = esc_url_raw((string) ($decoded[0] ?? ''));
                        }
                        if ($foto_interior === '') {
                            $foto_interior = esc_url_raw((string) ($decoded[1] ?? ''));
                        }
                    } else {
                        $split = preg_split('/[\r\n,]+/', $raw);
                        if (is_array($split)) {
                            if ($foto_portada === '') {
                                $foto_portada = esc_url_raw(trim((string) ($split[0] ?? '')));
                            }
                            if ($foto_interior === '') {
                                $foto_interior = esc_url_raw(trim((string) ($split[1] ?? '')));
                            }
                        }
                    }
                }
            }
        }
        $brochure = esc_url_raw((string) ($data['brochure'] ?? $data['brochure_url'] ?? ''));
        $cotizacion_url = esc_url_raw((string) ($data['cotizacion_url'] ?? $data['cotiza_url'] ?? ''));

        $estado = sanitize_text_field($data['estado'] ?? 'disponible');
        if (! in_array($estado, $states, true)) {
            $estado = 'disponible';
        }

        $precio_base = (float) ($data['precio_base'] ?? 0);
        $precio_lista = (float) ($data['precio_lista'] ?? 0);
        $precio_final = (float) ($data['precio_final'] ?? 0);
        $precio = (float) ($data['precio'] ?? 0);
        if ($precio <= 0) {
            $precio = $precio_final > 0 ? $precio_final : ($precio_lista > 0 ? $precio_lista : $precio_base);
        }

        return array(
            'id' => isset($data['id']) ? (int) $data['id'] : 0,
            'external_id' => sanitize_text_field($data['external_id'] ?? ''),
            'nombre' => sanitize_text_field($data['nombre'] ?? ''),
            'descripcion' => sanitize_textarea_field($data['descripcion'] ?? ''),
            'precio' => $precio,
            'precio_base' => $precio_base,
            'precio_lista' => $precio_lista,
            'precio_final' => $precio_final,
            'banos' => max(0, (int) ($data['banos'] ?? 0)),
            'dormitorios' => max(0, (int) ($data['dormitorios'] ?? 0)),
            'metros_cuadrados' => (float) ($data['metros_cuadrados'] ?? 0),
            'tipologia' => sanitize_text_field($data['tipologia'] ?? ''),
            'tipo_producto' => sanitize_text_field($data['tipo_producto'] ?? ''),
            'planta_label' => sanitize_text_field($data['planta_label'] ?? ''),
            'orientacion' => sanitize_text_field($data['orientacion'] ?? ''),
            'superficie_interior' => (float) ($data['superficie_interior'] ?? 0),
            'terraza_m2' => (float) ($data['terraza_m2'] ?? 0),
            'superficie_total' => (float) ($data['superficie_total'] ?? 0),
            'foto_portada' => $foto_portada,
            'foto_interior' => $foto_interior,
            'brochure' => $brochure,
            'cotizacion_url' => $cotizacion_url,
            'estado' => $estado,
        );
    }

    private function sanitize_contact_sync_data($data)
    {
        $states = array_keys($this->get_contact_sync_states());
        $status = sanitize_text_field((string) ($data['status'] ?? 'failed'));
        if (! in_array($status, $states, true)) {
            $status = 'failed';
        }

        $payload_json = '{}';
        if (isset($data['payload_json'])) {
            $raw_payload = (string) $data['payload_json'];
            $decoded_payload = json_decode($raw_payload, true);
            if (is_array($decoded_payload)) {
                $payload_json = wp_json_encode($decoded_payload);
            }
        }

        return array(
            'id' => isset($data['id']) ? (int) $data['id'] : 0,
            'form_id' => max(0, (int) ($data['form_id'] ?? 0)),
            'form_title' => sanitize_text_field((string) ($data['form_title'] ?? '')),
            'channel' => sanitize_text_field((string) ($data['channel'] ?? '')),
            'status' => $status,
            'contact_name' => sanitize_text_field((string) ($data['contact_name'] ?? '')),
            'contact_email' => sanitize_email((string) ($data['contact_email'] ?? '')),
            'payload_json' => $payload_json,
            'response_code' => (int) ($data['response_code'] ?? 0),
            'response_body' => sanitize_textarea_field((string) ($data['response_body'] ?? '')),
            'error_message' => sanitize_text_field((string) ($data['error_message'] ?? '')),
            'retries' => max(0, (int) ($data['retries'] ?? 0)),
            'remote_submission_id' => max(0, (int) ($data['remote_submission_id'] ?? 0)),
        );
    }

    private function get_formats($payload)
    {
        $map = array(
            'external_id' => '%s',
            'nombre' => '%s',
            'descripcion' => '%s',
            'precio' => '%f',
            'precio_base' => '%f',
            'precio_lista' => '%f',
            'precio_final' => '%f',
            'banos' => '%d',
            'dormitorios' => '%d',
            'metros_cuadrados' => '%f',
            'tipologia' => '%s',
            'tipo_producto' => '%s',
            'planta_label' => '%s',
            'orientacion' => '%s',
            'superficie_interior' => '%f',
            'terraza_m2' => '%f',
            'superficie_total' => '%f',
            'foto_portada' => '%s',
            'foto_interior' => '%s',
            'brochure' => '%s',
            'cotizacion_url' => '%s',
            'estado' => '%s',
            'created_at' => '%s',
            'updated_at' => '%s',
        );

        $formats = array();
        foreach ($payload as $key => $value) {
            $formats[] = $map[$key] ?? '%s';
        }

        return $formats;
    }

    private function get_contact_sync_formats($payload)
    {
        $map = array(
            'form_id' => '%d',
            'form_title' => '%s',
            'channel' => '%s',
            'status' => '%s',
            'contact_name' => '%s',
            'contact_email' => '%s',
            'payload_json' => '%s',
            'response_code' => '%d',
            'response_body' => '%s',
            'error_message' => '%s',
            'retries' => '%d',
            'remote_submission_id' => '%d',
            'created_at' => '%s',
            'updated_at' => '%s',
        );

        $formats = array();
        foreach ($payload as $key => $value) {
            $formats[] = $map[$key] ?? '%s';
        }

        return $formats;
    }
}
