<?php

if (! defined('ABSPATH')) {
    exit;
}

class Ileben_Plantas_Admin
{
    private $repository;
    private $api_client;

    public function __construct($repository, $api_client)
    {
        $this->repository = $repository;
        $this->api_client = $api_client;
    }

    public function register()
    {
        add_action('admin_menu', array($this, 'register_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));

        add_action('admin_post_ileben_plantas_save_plant', array($this, 'handle_save_plant'));
        add_action('admin_post_ileben_plantas_delete_plant', array($this, 'handle_delete_plant'));
        add_action('admin_post_ileben_plantas_import_csv', array($this, 'handle_import_csv'));
        add_action('admin_post_ileben_plantas_download_csv_sample', array($this, 'handle_download_csv_sample'));
        add_action('admin_post_ileben_plantas_sync_api', array($this, 'handle_sync_api'));

        add_action('ileben_plantas_cron_sync', array($this, 'run_sync'));
    }

    public function register_menu()
    {
        add_menu_page(
            'Plantas',
            'Plantas',
            ILEBEN_PLANTAS_CAPABILITY,
            'ileben-plantas',
            array($this, 'render_list_page'),
            'dashicons-admin-home',
            26
        );

        add_submenu_page(
            'ileben-plantas',
            'Listado de Plantas',
            'Listado',
            ILEBEN_PLANTAS_CAPABILITY,
            'ileben-plantas',
            array($this, 'render_list_page')
        );

        add_submenu_page(
            'ileben-plantas',
            'Nueva Planta',
            'Nueva Planta',
            ILEBEN_PLANTAS_CAPABILITY,
            'ileben-plantas-new',
            array($this, 'render_form_page')
        );

        add_submenu_page(
            'ileben-plantas',
            'Importar CSV',
            'Importar CSV',
            ILEBEN_PLANTAS_CAPABILITY,
            'ileben-plantas-import',
            array($this, 'render_import_page')
        );

        add_submenu_page(
            'ileben-plantas',
            'Sincronizar API',
            'Sincronizar API',
            ILEBEN_PLANTAS_CAPABILITY,
            'ileben-plantas-sync',
            array($this, 'render_sync_page')
        );
    }

    public function enqueue_assets($hook)
    {
        if (strpos((string) $hook, 'ileben-plantas') === false) {
            return;
        }

        wp_enqueue_style(
            'ileben-plantas-bootstrap',
            'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css',
            array(),
            '5.3.3'
        );

        wp_enqueue_script(
            'ileben-plantas-bootstrap',
            'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js',
            array(),
            '5.3.3',
            true
        );

        wp_enqueue_style(
            'ileben-plantas-admin',
            ILEBEN_PLANTAS_URL . 'assets/css/admin.css',
            array('ileben-plantas-bootstrap'),
            ILEBEN_PLANTAS_VERSION
        );

        wp_enqueue_media();

        wp_enqueue_script(
            'ileben-plantas-admin',
            ILEBEN_PLANTAS_URL . 'assets/js/admin.js',
            array('jquery'),
            ILEBEN_PLANTAS_VERSION,
            true
        );
    }

    public function render_list_page()
    {
        $this->guard_permission();

        $page = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;
        $filters = array(
            'search' => sanitize_text_field($_GET['s'] ?? ''),
            'estado' => sanitize_text_field($_GET['estado'] ?? ''),
            'orderby' => sanitize_text_field($_GET['orderby'] ?? ''),
        );

        $result = $this->repository->query($filters, $page, 20);

        echo '<div class="wrap ileben-admin">';
        echo '<h1 class="mb-3">Plantas</h1>';
        $this->render_flash();

        echo '<div class="card mb-4"><div class="card-body">';
        echo '<form class="row g-3" method="get">';
        echo '<input type="hidden" name="page" value="ileben-plantas" />';
        echo '<div class="col-md-4"><label class="form-label">Buscar</label><input class="form-control" type="text" name="s" value="' . esc_attr($filters['search']) . '" /></div>';
        echo '<div class="col-md-3"><label class="form-label">Estado</label>';
        echo '<select class="form-select" name="estado"><option value="">Todos</option>';
        foreach ($this->repository->get_states() as $state_key => $state_label) {
            echo '<option value="' . esc_attr($state_key) . '" ' . selected($filters['estado'], $state_key, false) . '>' . esc_html($state_label) . '</option>';
        }
        echo '</select></div>';
        echo '<div class="col-md-3"><label class="form-label">Orden</label>';
        echo '<select class="form-select" name="orderby">';
        echo '<option value="">Mas recientes</option>';
        echo '<option value="nombre_asc" ' . selected($filters['orderby'], 'nombre_asc', false) . '>Nombre A-Z</option>';
        echo '<option value="precio_asc" ' . selected($filters['orderby'], 'precio_asc', false) . '>Precio menor</option>';
        echo '<option value="precio_desc" ' . selected($filters['orderby'], 'precio_desc', false) . '>Precio mayor</option>';
        echo '</select></div>';
        echo '<div class="col-md-2 d-flex align-items-end"><button class="btn btn-primary w-100" type="submit">Filtrar</button></div>';
        echo '</form></div></div>';

        echo '<div class="card"><div class="table-responsive">';
        echo '<table class="table table-striped table-hover mb-0">';
        echo '<thead><tr><th>ID</th><th>Nombre</th><th>Precio</th><th>Banos</th><th>Dormitorios</th><th>Estado</th><th>Acciones</th></tr></thead><tbody>';

        if (empty($result['items'])) {
            echo '<tr><td colspan="7" class="text-center py-4">No hay plantas registradas.</td></tr>';
        } else {
            foreach ($result['items'] as $item) {
                $edit_url = add_query_arg(
                    array(
                        'page' => 'ileben-plantas-new',
                        'id' => (int) $item['id'],
                    ),
                    admin_url('admin.php')
                );

                echo '<tr>';
                echo '<td>' . (int) $item['id'] . '</td>';
                echo '<td>' . esc_html($item['nombre']) . '</td>';
                echo '<td>$ ' . number_format((float) $item['precio'], 2, '.', ',') . '</td>';
                echo '<td>' . (int) $item['banos'] . '</td>';
                echo '<td>' . (int) $item['dormitorios'] . '</td>';
                echo '<td><span class="badge text-bg-secondary">' . esc_html($this->repository->get_states()[$item['estado']] ?? $item['estado']) . '</span></td>';
                echo '<td>';
                echo '<a class="btn btn-sm btn-outline-primary me-2" href="' . esc_url($edit_url) . '">Editar</a>';
                echo '<form style="display:inline-block" method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(\'Deseas eliminar esta planta?\');">';
                echo '<input type="hidden" name="action" value="ileben_plantas_delete_plant" />';
                echo '<input type="hidden" name="id" value="' . (int) $item['id'] . '" />';
                wp_nonce_field('ileben_plantas_delete_' . (int) $item['id']);
                echo '<button class="btn btn-sm btn-outline-danger" type="submit">Eliminar</button>';
                echo '</form>';
                echo '</td>';
                echo '</tr>';
            }
        }

        echo '</tbody></table></div></div>';

        $this->render_pagination($result['page'], $result['pages']);

        echo '</div>';
    }

    public function render_form_page()
    {
        $this->guard_permission();

        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        $item = $id ? $this->repository->find($id) : null;

        $defaults = array(
            'id' => 0,
            'external_id' => '',
            'nombre' => '',
            'descripcion' => '',
            'precio' => 0,
            'banos' => 0,
            'dormitorios' => 0,
            'metros_cuadrados' => 0,
            'tipologia' => '',
            'planta_label' => '',
            'orientacion' => '',
            'superficie_interior' => 0,
            'terraza_m2' => 0,
            'superficie_total' => 0,
            'fotos' => '[]',
            'brochure' => '',
            'cotizacion_url' => '',
            'estado' => 'disponible',
        );

        $plant = wp_parse_args((array) $item, $defaults);
        $photos = json_decode((string) $plant['fotos'], true);
        $image_url = '';

        if (is_array($photos) && ! empty($photos)) {
            $image_url = (string) $photos[0];
        } elseif (is_string($plant['fotos']) && $plant['fotos'] !== '') {
            $image_url = (string) $plant['fotos'];
        }

        $brochure_url = (string) ($plant['brochure'] ?? '');
        $cotizacion_url = (string) ($plant['cotizacion_url'] ?? '');

        echo '<div class="wrap ileben-admin">';
        echo '<h1 class="mb-3">' . ($id ? 'Editar Planta' : 'Nueva Planta') . '</h1>';
        $this->render_flash();

        echo '<div class="card"><div class="card-body">';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="ileben_plantas_save_plant" />';
        echo '<input type="hidden" name="id" value="' . (int) $plant['id'] . '" />';
        wp_nonce_field('ileben_plantas_save');

        echo '<div class="row g-3">';
        echo '<div class="col-md-4"><label class="form-label">External ID</label><input class="form-control" type="text" name="external_id" value="' . esc_attr($plant['external_id']) . '" /></div>';
        echo '<div class="col-md-8"><label class="form-label">Nombre *</label><input class="form-control" type="text" name="nombre" required value="' . esc_attr($plant['nombre']) . '" /></div>';
        echo '<div class="col-12"><label class="form-label">Descripcion</label><textarea class="form-control" name="descripcion" rows="4">' . esc_textarea($plant['descripcion']) . '</textarea></div>';
        echo '<div class="col-md-3"><label class="form-label">Precio</label><input class="form-control" step="0.01" min="0" type="number" name="precio" value="' . esc_attr((string) $plant['precio']) . '" /></div>';
        echo '<div class="col-md-3"><label class="form-label">Banos</label><input class="form-control" min="0" type="number" name="banos" value="' . esc_attr((string) $plant['banos']) . '" /></div>';
        echo '<div class="col-md-3"><label class="form-label">Dormitorios</label><input class="form-control" min="0" type="number" name="dormitorios" value="' . esc_attr((string) $plant['dormitorios']) . '" /></div>';
        echo '<div class="col-md-3"><label class="form-label">Metros cuadrados</label><input class="form-control" step="0.01" min="0" type="number" name="metros_cuadrados" value="' . esc_attr((string) $plant['metros_cuadrados']) . '" /></div>';
        echo '<div class="col-md-4"><label class="form-label">Tipologia</label><input class="form-control" type="text" name="tipologia" value="' . esc_attr((string) $plant['tipologia']) . '" placeholder="1 dormitorio + 1 bano" /></div>';
        echo '<div class="col-md-4"><label class="form-label">Planta (label)</label><input class="form-control" type="text" name="planta_label" value="' . esc_attr((string) $plant['planta_label']) . '" placeholder="A: 501 al 701" /></div>';
        echo '<div class="col-md-4"><label class="form-label">Orientacion</label><input class="form-control" type="text" name="orientacion" value="' . esc_attr((string) $plant['orientacion']) . '" placeholder="Poniente" /></div>';
        echo '<div class="col-md-4"><label class="form-label">Superficie interior (m2)</label><input class="form-control" step="0.01" min="0" type="number" name="superficie_interior" value="' . esc_attr((string) $plant['superficie_interior']) . '" /></div>';
        echo '<div class="col-md-4"><label class="form-label">Terraza (m2)</label><input class="form-control" step="0.01" min="0" type="number" name="terraza_m2" value="' . esc_attr((string) $plant['terraza_m2']) . '" /></div>';
        echo '<div class="col-md-4"><label class="form-label">Superficie total (m2)</label><input class="form-control" step="0.01" min="0" type="number" name="superficie_total" value="' . esc_attr((string) $plant['superficie_total']) . '" /></div>';
        echo '<div class="col-md-4"><label class="form-label">Estado</label><select class="form-select" name="estado">';
        foreach ($this->repository->get_states() as $state_key => $state_label) {
            echo '<option value="' . esc_attr($state_key) . '" ' . selected($plant['estado'], $state_key, false) . '>' . esc_html($state_label) . '</option>';
        }
        echo '</select></div>';

        echo '<div class="col-12">';
        echo '<label class="form-label">Imagen</label>';
        echo '<input type="hidden" name="fotos" id="ileben_image_url" value="' . esc_attr($image_url) . '" />';
        echo '<div class="d-flex gap-2 mb-2">';
        echo '<button type="button" class="btn btn-outline-primary" id="ileben_select_image">Seleccionar/Subir imagen</button>';
        echo '<button type="button" class="btn btn-outline-secondary" id="ileben_remove_image">Quitar imagen</button>';
        echo '</div>';
        echo '<div id="ileben_image_preview_wrapper">';
        if ($image_url !== '') {
            echo '<img id="ileben_image_preview" src="' . esc_url($image_url) . '" alt="Preview" style="max-width: 240px; height: auto; border-radius: 8px;" />';
        } else {
            echo '<img id="ileben_image_preview" src="" alt="Preview" style="display:none; max-width: 240px; height: auto; border-radius: 8px;" />';
        }
        echo '</div>';
        echo '<p class="text-muted mb-0">Solo se permite una imagen por planta.</p>';
        echo '</div>';

        echo '<div class="col-12">';
        echo '<label class="form-label">Brochure (archivo opcional)</label>';
        echo '<input type="hidden" name="brochure" id="ileben_brochure_url" value="' . esc_attr($brochure_url) . '" />';
        echo '<div class="d-flex gap-2 mb-2">';
        echo '<button type="button" class="btn btn-outline-primary" id="ileben_select_brochure">Seleccionar/Subir brochure</button>';
        echo '<button type="button" class="btn btn-outline-secondary" id="ileben_remove_brochure">Quitar brochure</button>';
        echo '</div>';
        echo '<div id="ileben_brochure_preview_wrapper">';
        if ($brochure_url !== '') {
            echo '<a id="ileben_brochure_preview" href="' . esc_url($brochure_url) . '" target="_blank" rel="noopener">Ver brochure actual</a>';
        } else {
            echo '<a id="ileben_brochure_preview" href="#" target="_blank" rel="noopener" style="display:none;">Ver brochure actual</a>';
        }
        echo '</div>';
        echo '<p class="text-muted mb-0">Puedes subir PDF u otro archivo descargable.</p>';
        echo '</div>';

        echo '<div class="col-12">';
        echo '<label class="form-label">URL de cotizacion (opcional)</label>';
        echo '<input class="form-control" type="url" name="cotizacion_url" value="' . esc_attr($cotizacion_url) . '" placeholder="https://..." />';
        echo '<p class="text-muted mb-0">Si queda vacio, se usara COTIZA_URL desde el archivo .env.</p>';
        echo '</div>';

        echo '<div class="col-12"><button type="submit" class="btn btn-primary">Guardar</button></div>';
        echo '</div></form></div></div></div>';
    }

    public function render_import_page()
    {
        $this->guard_permission();

        echo '<div class="wrap ileben-admin">';
        echo '<h1 class="mb-3">Importar CSV</h1>';
        $this->render_flash();

        echo '<div class="card"><div class="card-body">';
        $sample_url = wp_nonce_url(
            admin_url('admin-post.php?action=ileben_plantas_download_csv_sample'),
            'ileben_plantas_download_csv_sample'
        );

        echo '<p class="text-muted">Columnas esperadas: external_id, nombre, descripcion, precio, banos, dormitorios, metros_cuadrados, tipologia, planta_label, orientacion, superficie_interior, terraza_m2, superficie_total, foto, brochure, cotizacion_url, estado.</p>';
        echo '<p><a class="btn btn-outline-secondary" href="' . esc_url($sample_url) . '">Descargar CSV de ejemplo</a></p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" enctype="multipart/form-data">';
        echo '<input type="hidden" name="action" value="ileben_plantas_import_csv" />';
        wp_nonce_field('ileben_plantas_import_csv');
        echo '<div class="mb-3"><input class="form-control" type="file" name="csv_file" accept=".csv,text/csv" required /></div>';
        echo '<button type="submit" class="btn btn-primary">Importar</button>';
        echo '</form></div></div></div>';
    }

    public function render_sync_page()
    {
        $this->guard_permission();

        echo '<div class="wrap ileben-admin">';
        echo '<h1 class="mb-3">Sincronizacion con API</h1>';
        $this->render_flash();

        echo '<div class="card"><div class="card-body">';
        echo '<p class="text-muted">Ejecuta una sincronizacion manual de plantas desde la API configurada.</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="ileben_plantas_sync_api" />';
        wp_nonce_field('ileben_plantas_sync_api');
        echo '<button class="btn btn-primary" type="submit">Sincronizar ahora</button>';
        echo '</form></div></div></div>';
    }



    public function handle_save_plant()
    {
        $this->guard_permission();
        check_admin_referer('ileben_plantas_save');

        $payload = array(
            'id' => (int) ($_POST['id'] ?? 0),
            'external_id' => sanitize_text_field($_POST['external_id'] ?? ''),
            'nombre' => sanitize_text_field($_POST['nombre'] ?? ''),
            'descripcion' => sanitize_textarea_field($_POST['descripcion'] ?? ''),
            'precio' => (float) ($_POST['precio'] ?? 0),
            'banos' => (int) ($_POST['banos'] ?? 0),
            'dormitorios' => (int) ($_POST['dormitorios'] ?? 0),
            'metros_cuadrados' => (float) ($_POST['metros_cuadrados'] ?? 0),
            'tipologia' => sanitize_text_field($_POST['tipologia'] ?? ''),
            'planta_label' => sanitize_text_field($_POST['planta_label'] ?? ''),
            'orientacion' => sanitize_text_field($_POST['orientacion'] ?? ''),
            'superficie_interior' => (float) ($_POST['superficie_interior'] ?? 0),
            'terraza_m2' => (float) ($_POST['terraza_m2'] ?? 0),
            'superficie_total' => (float) ($_POST['superficie_total'] ?? 0),
            'fotos' => sanitize_textarea_field($_POST['fotos'] ?? ''),
            'brochure' => esc_url_raw($_POST['brochure'] ?? ''),
            'cotizacion_url' => esc_url_raw($_POST['cotizacion_url'] ?? ''),
            'estado' => sanitize_text_field($_POST['estado'] ?? ''),
        );

        if (empty($payload['nombre'])) {
            $this->redirect_with_flash('ileben-plantas-new', 'error', 'El nombre es obligatorio.');
        }

        $result_id = $this->repository->save($payload);
        if (! $result_id) {
            $this->redirect_with_flash('ileben-plantas-new', 'error', 'No se pudo guardar la planta.');
        }

        $this->redirect_with_flash('ileben-plantas', 'success', 'Planta guardada correctamente.');
    }

    public function handle_delete_plant()
    {
        $this->guard_permission();

        $id = (int) ($_POST['id'] ?? 0);
        check_admin_referer('ileben_plantas_delete_' . $id);

        if (! $id) {
            $this->redirect_with_flash('ileben-plantas', 'error', 'ID invalido.');
        }

        $deleted = $this->repository->delete($id);
        if ($deleted === false) {
            $this->redirect_with_flash('ileben-plantas', 'error', 'No se pudo eliminar la planta.');
        }

        $this->redirect_with_flash('ileben-plantas', 'success', 'Planta eliminada correctamente.');
    }

    public function handle_import_csv()
    {
        $this->guard_permission();
        check_admin_referer('ileben_plantas_import_csv');

        if (empty($_FILES['csv_file']) || ! is_array($_FILES['csv_file'])) {
            $this->redirect_with_flash('ileben-plantas-import', 'error', 'No se recibio el archivo CSV.');
        }

        $file = $_FILES['csv_file'];
        if ((int) ($file['error'] ?? 1) !== 0) {
            $this->redirect_with_flash('ileben-plantas-import', 'error', 'Error al subir el archivo.');
        }

        $tmp_name = $file['tmp_name'] ?? '';
        if (! $tmp_name || ! is_uploaded_file($tmp_name)) {
            $this->redirect_with_flash('ileben-plantas-import', 'error', 'Archivo no valido.');
        }

        $csv = fopen($tmp_name, 'r');
        if (! $csv) {
            $this->redirect_with_flash('ileben-plantas-import', 'error', 'No se pudo abrir el archivo CSV.');
        }

        $headers = fgetcsv($csv);
        if (! $headers) {
            fclose($csv);
            $this->redirect_with_flash('ileben-plantas-import', 'error', 'CSV vacio o sin cabeceras.');
        }

        $headers = array_map(
            function ($header) {
                $header = trim((string) $header);
                $header = preg_replace('/^\xEF\xBB\xBF/', '', $header);
                return strtolower($header);
            },
            $headers
        );

        $inserted = 0;
        $updated = 0;
        $errors = 0;

        while (($row = fgetcsv($csv)) !== false) {
            if (count($row) === 1 && trim((string) $row[0]) === '') {
                continue;
            }

            $item = array();
            foreach ($headers as $index => $header) {
                $item[$header] = isset($row[$index]) ? trim((string) $row[$index]) : '';
            }

            if (empty($item['foto']) && ! empty($item['fotos'])) {
                $item['foto'] = trim((string) $item['fotos']);
            }

            if (! empty($item['foto'])) {
                $item['fotos'] = $item['foto'];
            }

            if (empty($item['brochure']) && ! empty($item['brochure_url'])) {
                $item['brochure'] = trim((string) $item['brochure_url']);
            }

            if (empty($item['cotizacion_url']) && ! empty($item['cotiza_url'])) {
                $item['cotizacion_url'] = trim((string) $item['cotiza_url']);
            }

            if (empty($item['external_id']) || empty($item['nombre'])) {
                $errors++;
                continue;
            }

            $existing_id = $this->repository->find_id_by_external_id($item['external_id']);
            $result_id = $this->repository->upsert_by_external_id($item);

            if (! $result_id) {
                $errors++;
                continue;
            }

            if ($existing_id) {
                $updated++;
            } else {
                $inserted++;
            }
        }

        fclose($csv);

        $message = sprintf(
            'Importacion completada. Insertados: %d, Actualizados: %d, Errores: %d.',
            $inserted,
            $updated,
            $errors
        );

        $this->redirect_with_flash('ileben-plantas-import', 'success', $message);
    }

    public function handle_download_csv_sample()
    {
        $this->guard_permission();
        check_admin_referer('ileben_plantas_download_csv_sample');

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="ileben-plantas-ejemplo.csv"');

        $out = fopen('php://output', 'w');
        if (! $out) {
            wp_die('No se pudo generar el CSV de ejemplo.');
        }

        fputcsv($out, array('external_id', 'nombre', 'descripcion', 'precio', 'banos', 'dormitorios', 'metros_cuadrados', 'tipologia', 'planta_label', 'orientacion', 'superficie_interior', 'terraza_m2', 'superficie_total', 'foto', 'brochure', 'cotizacion_url', 'estado'));
        fputcsv($out, array('APT-001', 'Departamento Norte', 'Planta con vista al jardin', '120000', '1', '1', '44.78', '1 dormitorio + 1 bano', 'A: 501 al 701', 'Poniente', '41.00', '3.78', '44.78', 'https://example.com/imagen.jpg', 'https://example.com/brochure.pdf', 'https://example.com/cotiza', 'disponible'));

        fclose($out);
        exit;
    }

    public function handle_sync_api()
    {
        $this->guard_permission();
        check_admin_referer('ileben_plantas_sync_api');

        $report = $this->run_sync();

        if (isset($report['error'])) {
            $this->redirect_with_flash('ileben-plantas-sync', 'error', $report['error']);
        }

        $message = sprintf(
            'Sincronizacion completada. Insertados: %d, Actualizados: %d, Errores: %d.',
            (int) $report['inserted'],
            (int) $report['updated'],
            (int) $report['errors']
        );

        $this->redirect_with_flash('ileben-plantas-sync', 'success', $message);
    }



    public function run_sync()
    {
        $items = $this->api_client->fetch_plants();

        if (is_wp_error($items)) {
            return array(
                'error' => $items->get_error_message(),
                'inserted' => 0,
                'updated' => 0,
                'errors' => 0,
            );
        }

        if (! is_array($items)) {
            return array(
                'error' => 'Respuesta de API invalida.',
                'inserted' => 0,
                'updated' => 0,
                'errors' => 0,
            );
        }

        $inserted = 0;
        $updated = 0;
        $errors = 0;

        foreach ($items as $item) {
            $payload = $this->api_client->map_api_item($item);
            if (empty($payload['external_id']) || empty($payload['nombre'])) {
                $errors++;
                continue;
            }

            $existing_id = $this->repository->find_id_by_external_id($payload['external_id']);
            $result = $this->repository->upsert_by_external_id($payload);

            if (! $result) {
                $errors++;
                continue;
            }

            if ($existing_id) {
                $updated++;
            } else {
                $inserted++;
            }
        }

        return array(
            'inserted' => $inserted,
            'updated' => $updated,
            'errors' => $errors,
        );
    }

    private function render_pagination($current_page, $total_pages)
    {
        if ($total_pages <= 1) {
            return;
        }

        echo '<nav class="mt-4"><ul class="pagination">';

        for ($i = 1; $i <= $total_pages; $i++) {
            $url = add_query_arg('paged', $i);
            $active = $i === (int) $current_page ? ' active' : '';
            echo '<li class="page-item' . esc_attr($active) . '">';
            echo '<a class="page-link" href="' . esc_url($url) . '">' . (int) $i . '</a>';
            echo '</li>';
        }

        echo '</ul></nav>';
    }

    private function render_flash()
    {
        $message = isset($_GET['ip_message']) ? sanitize_text_field($_GET['ip_message']) : '';
        $type = isset($_GET['ip_type']) ? sanitize_text_field($_GET['ip_type']) : 'success';

        if (! $message) {
            return;
        }

        $classes = 'alert-success';
        if ($type === 'error') {
            $classes = 'alert-danger';
        }

        echo '<div class="alert ' . esc_attr($classes) . '" role="alert">' . esc_html($message) . '</div>';
    }

    private function redirect_with_flash($page, $type, $message)
    {
        $url = add_query_arg(
            array(
                'page' => $page,
                'ip_type' => $type,
                'ip_message' => $message,
            ),
            admin_url('admin.php')
        );

        wp_safe_redirect($url);
        exit;
    }

    private function guard_permission()
    {
        if (! current_user_can(ILEBEN_PLANTAS_CAPABILITY)) {
            wp_die('No tienes permisos para acceder a esta seccion.');
        }
    }
}
