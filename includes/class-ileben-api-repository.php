<?php

if (! defined('ABSPATH')) {
    exit;
}

class Ileben_Api_Repository
{
    private $table_name;

    public function __construct()
    {
        $this->table_name = Ileben_Api_Plugin::get_table_name();
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
        $precio = (float) ($data['precio'] ?? 0);
        if ($precio <= 0) {
            $precio = $precio_lista > 0 ? $precio_lista : $precio_base;
        }

        return array(
            'id' => isset($data['id']) ? (int) $data['id'] : 0,
            'external_id' => sanitize_text_field($data['external_id'] ?? ''),
            'nombre' => sanitize_text_field($data['nombre'] ?? ''),
            'descripcion' => sanitize_textarea_field($data['descripcion'] ?? ''),
            'precio' => $precio,
            'precio_base' => $precio_base,
            'precio_lista' => $precio_lista,
            'banos' => max(0, (int) ($data['banos'] ?? 0)),
            'dormitorios' => max(0, (int) ($data['dormitorios'] ?? 0)),
            'metros_cuadrados' => (float) ($data['metros_cuadrados'] ?? 0),
            'tipologia' => sanitize_text_field($data['tipologia'] ?? ''),
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

    private function get_formats($payload)
    {
        $map = array(
            'external_id' => '%s',
            'nombre' => '%s',
            'descripcion' => '%s',
            'precio' => '%f',
            'precio_base' => '%f',
            'precio_lista' => '%f',
            'banos' => '%d',
            'dormitorios' => '%d',
            'metros_cuadrados' => '%f',
            'tipologia' => '%s',
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
}
