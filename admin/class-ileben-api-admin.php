<?php

if (! defined('ABSPATH')) {
    exit;
}

class Ileben_Api_Admin
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

        add_action('admin_post_ileben_api_save_plant', array($this, 'handle_save_plant'));
        add_action('admin_post_ileben_api_delete_plant', array($this, 'handle_delete_plant'));
        add_action('admin_post_ileben_api_delete_all', array($this, 'handle_delete_all'));
        add_action('admin_post_ileben_api_import_csv', array($this, 'handle_import_csv'));
        add_action('admin_post_ileben_api_download_csv_sample', array($this, 'handle_download_csv_sample'));
        add_action('admin_post_ileben_api_sync_api', array($this, 'handle_sync_api'));
        add_action('admin_post_ileben_api_retry_contact_sync', array($this, 'handle_retry_contact_sync'));
        add_action('admin_post_ileben_api_sync_site_config', array($this, 'handle_sync_site_config'));
        add_action('admin_post_ileben_api_save_settings', array($this, 'handle_save_settings'));
        add_action('admin_post_ileben_api_toggle_favicon', array($this, 'handle_toggle_favicon'));

        add_action('wp_ajax_ileben_api_bulk_update_contact_channel', array($this, 'ajax_bulk_update_contact_channel'));
        add_action('wp_ajax_ileben_api_batch_resync_contacts', array($this, 'ajax_batch_resync_contacts'));
        add_action('wp_ajax_ileben_api_get_filtered_contact_ids', array($this, 'ajax_get_filtered_contact_ids'));

        add_action('ileben_api_cron_sync', array($this, 'run_sync'));
    }

    public function register_menu()
    {
        add_menu_page(
            'Leben Config API',
            'Leben API',
            ILEBEN_API_CAPABILITY,
            'ileben-api',
            array($this, 'render_list_page'),
            'dashicons-share-alt',
            26
        );

        add_submenu_page(
            'ileben-api',
            'Nueva Planta',
            'Nueva Planta',
            ILEBEN_API_CAPABILITY,
            'ileben-api-new',
            array($this, 'render_form_page')
        );

        add_submenu_page(
            'ileben-api',
            'Listado de Plantas',
            'Listado Plantas',
            ILEBEN_API_CAPABILITY,
            'ileben-api',
            array($this, 'render_list_page')
        );

        add_submenu_page(
            'ileben-api',
            'Importar CSV',
            'Importar CSV',
            ILEBEN_API_CAPABILITY,
            'ileben-api-import',
            array($this, 'render_import_page')
        );

        add_submenu_page(
            'ileben-api',
            'Sincronizar API',
            'Sincronizar API',
            ILEBEN_API_CAPABILITY,
            'ileben-api-sync',
            array($this, 'render_sync_page')
        );

        add_submenu_page(
            'ileben-api',
            'Sync Contactos',
            'Sync Contactos',
            ILEBEN_API_CAPABILITY,
            'ileben-api-contact-sync',
            array($this, 'render_contact_sync_page')
        );
    }

    public function enqueue_assets($hook)
    {
        if (strpos((string) $hook, 'ileben-api') === false) {
            return;
        }

        wp_enqueue_style(
            'ileben-api-bootstrap',
            'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css',
            array(),
            '5.3.3'
        );

        wp_enqueue_script(
            'ileben-api-bootstrap',
            'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js',
            array(),
            '5.3.3',
            true
        );

        wp_enqueue_style(
            'ileben-api-fontawesome',
            'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.1/css/all.min.css',
            array(),
            '7.0.1'
        );

        $admin_css_path = ILEBEN_API_PATH . 'assets/css/admin.css';
        $admin_css_version = file_exists($admin_css_path) ? (string) filemtime($admin_css_path) : ILEBEN_API_VERSION;

        wp_enqueue_style(
            'ileben-api-admin',
            ILEBEN_API_URL . 'assets/css/admin.css',
            array('ileben-api-bootstrap', 'ileben-api-fontawesome'),
            $admin_css_version
        );

        // Inyectar CSS de la configuración de la API para mostrar colores personalizados en el admin.
        $site_config = $this->api_client->get_site_config();
        $brand_color = sanitize_hex_color((string) ($site_config['brand_color'] ?? ''));
        if (! empty($brand_color)) {
            wp_add_inline_style('ileben-api-admin', ':root { --leben-brand-color: ' . $brand_color . '; }');
        }

        wp_enqueue_media();

        wp_enqueue_script(
            'ileben-api-admin',
            ILEBEN_API_URL . 'assets/js/admin.js',
            array('jquery', 'ileben-api-bootstrap'),
            ILEBEN_API_VERSION,
            true
        );

        wp_localize_script('ileben-api-admin', 'ileben_admin_vars', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce_bulk_channel' => wp_create_nonce('ileben_api_bulk_update_channel'),
            'nonce_batch_resync' => wp_create_nonce('ileben_api_batch_resync'),
            'nonce_get_ids' => wp_create_nonce('ileben_api_get_contact_ids'),
        ));

        // Inline script para verificar que los assets se cargan y vincular botones
        wp_add_inline_script('ileben-api-admin', "
console.log('[ileben-api inline] Script inline ejecutado');
console.log('[ileben-api inline] Buscando botones...');

function initContactButtons() {
    console.log('[ileben-api inline] initContactButtons ejecutado');
    var buttons = document.querySelectorAll('.ileben-edit-contact-btn');
    console.log('[ileben-api inline] Botones encontrados:', buttons.length);
    
    buttons.forEach(function(btn) {
        if (!btn.dataset.listenerAdded) {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                console.log('[ileben-api inline] Click detectado');
                if (typeof ilebenEditContactModal === 'function') {
                    ilebenEditContactModal(this);
                } else {
                    console.error('[ileben-api inline] ilebenEditContactModal no está definida');
                }
            });
            btn.dataset.listenerAdded = 'true';
        }
    });
}

// Ejecutar si DOM ya está listo
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initContactButtons);
} else {
    initContactButtons();
}

// Re-ejecutar cuando jQuery está listo (en caso de que se haya perdido)
if (typeof jQuery !== 'undefined') {
    jQuery(function() {
        console.log('[ileben-api inline] jQuery ready, re-inicializando botones');
        initContactButtons();
    });
}
        ", 'after');
    }

    public function render_list_page()
    {
        $this->guard_permission();

        $page = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;
        $filters = array(
            'search' => sanitize_text_field($_GET['s'] ?? ''),
            'estado' => sanitize_text_field($_GET['estado'] ?? ''),
            'tipo_producto' => sanitize_text_field($_GET['tipo_producto'] ?? ''),
            'orderby' => sanitize_text_field($_GET['orderby'] ?? ''),
        );

        $tipo_producto_options = $this->repository->get_tipo_producto_options();

        $result = $this->repository->query($filters, $page, 20);

        ob_start();
?>
        <div class="wrap ileben-admin">
            <h1 class="mb-3">Plantas</h1>
            <?php $this->render_flash(); ?>

            <div class="d-flex justify-content-end mb-3">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Esta accion eliminara todas las plantas. Deseas continuar?');">
                    <input type="hidden" name="action" value="ileben_api_delete_all" />
                    <?php wp_nonce_field('ileben_api_delete_all'); ?>
                    <button class="btn btn-outline-danger" type="submit">Eliminar todos</button>
                </form>
            </div>

            <div class="card mb-4">
                <div class="card-body">
                    <form class="row g-3" method="get">
                        <input type="hidden" name="page" value="ileben-api" />
                        <div class="col-md-3">
                            <label class="form-label">Buscar</label>
                            <input class="form-control" type="text" name="s" value="<?php echo esc_attr($filters['search']); ?>" />
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Estado</label>
                            <select class="form-select" name="estado">
                                <option value="">Todos</option>
                                <?php foreach ($this->repository->get_states() as $state_key => $state_label): ?>
                                    <option value="<?php echo esc_attr($state_key); ?>" <?php echo selected($filters['estado'], $state_key, false); ?>><?php echo esc_html($state_label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Tipo de planta</label>
                            <select class="form-select" name="tipo_producto">
                                <option value="">Todos</option>
                                <?php foreach ($tipo_producto_options as $tipo_producto): ?>
                                    <option value="<?php echo esc_attr($tipo_producto); ?>" <?php echo selected($filters['tipo_producto'], $tipo_producto, false); ?>><?php echo esc_html($tipo_producto); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Orden</label>
                            <select class="form-select" name="orderby">
                                <option value="">Mas recientes</option>
                                <option value="nombre_asc" <?php echo selected($filters['orderby'], 'nombre_asc', false); ?>>Nombre A-Z</option>
                                <option value="precio_asc" <?php echo selected($filters['orderby'], 'precio_asc', false); ?>>Precio menor</option>
                                <option value="precio_desc" <?php echo selected($filters['orderby'], 'precio_desc', false); ?>>Precio mayor</option>
                            </select>
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <button class="btn btn-primary w-100" type="submit">Filtrar</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card">
                <div class="table-responsive">
                    <table class="table table-striped table-hover mb-0">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Nombre</th>
                                <th>Tipo de planta</th>
                                <th>Precio Base</th>
                                <th>Precio Lista</th>
                                <th>Precio Final</th>
                                <th>Banos</th>
                                <th>Dormitorios</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($result['items'])): ?>
                                <tr>
                                    <td colspan="10" class="text-center py-4">No hay plantas registradas.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($result['items'] as $item): ?>
                                    <?php
                                    $edit_url = add_query_arg(
                                        array(
                                            'page' => 'ileben-api-new',
                                            'id' => (int) $item['id'],
                                        ),
                                        admin_url('admin.php')
                                    );
                                    ?>
                                    <tr>
                                        <td><?php echo (int) $item['id']; ?></td>
                                        <td><?php echo esc_html($item['nombre']); ?></td>
                                        <td><?php echo esc_html((string) ($item['tipo_producto'] ?? '-')); ?></td>
                                        <td>$ <?php echo number_format((float) ($item['precio_base'] ?? 0), 2, '.', ','); ?></td>
                                        <td>$ <?php echo number_format((float) ($item['precio_lista'] ?? $item['precio'] ?? 0), 2, '.', ','); ?></td>
                                        <td>$ <?php echo number_format((float) ($item['precio_final'] ?? $item['precio'] ?? 0), 2, '.', ','); ?></td>
                                        <td><?php echo (int) $item['banos']; ?></td>
                                        <td><?php echo (int) $item['dormitorios']; ?></td>
                                        <td><span class="badge text-bg-secondary"><?php echo esc_html($this->repository->get_states()[$item['estado']] ?? $item['estado']); ?></span></td>
                                        <td>
                                            <a class="btn btn-sm btn-outline-primary me-2" href="<?php echo esc_url($edit_url); ?>">Editar</a>
                                            <form style="display:inline-block" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Deseas eliminar esta planta?');">
                                                <input type="hidden" name="action" value="ileben_api_delete_plant" />
                                                <input type="hidden" name="id" value="<?php echo (int) $item['id']; ?>" />
                                                <?php wp_nonce_field('ileben_api_delete_' . (int) $item['id']); ?>
                                                <button class="btn btn-sm btn-outline-danger" type="submit">Eliminar</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <?php $this->render_pagination($result['page'], $result['pages']); ?>
        </div>
    <?php
        echo ob_get_clean();
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
            'precio_base' => 0,
            'precio_lista' => 0,
            'precio_final' => 0,
            'banos' => 0,
            'dormitorios' => 0,
            'metros_cuadrados' => 0,
            'tipologia' => '',
            'planta_label' => '',
            'orientacion' => '',
            'superficie_interior' => 0,
            'terraza_m2' => 0,
            'superficie_total' => 0,
            'foto_portada' => '',
            'foto_interior' => '',
            'brochure' => '',
            'cotizacion_url' => '',
            'estado' => 'disponible',
        );

        $plant = wp_parse_args((array) $item, $defaults);
        $image_portada_url = (string) ($plant['foto_portada'] ?? '');
        $image_interior_url = (string) ($plant['foto_interior'] ?? '');

        if ($image_portada_url !== '') {
            $image_portada_url = esc_url_raw($image_portada_url);
            if ($image_portada_url === '') {
                $image_portada_url = '';
            }
        }

        if ($image_interior_url !== '') {
            $image_interior_url = esc_url_raw($image_interior_url);
            if ($image_interior_url === '') {
                $image_interior_url = '';
            }
        }

        $default_logo_url = ILEBEN_API_URL . 'assets/img/logo.png';

        $settings = $this->api_client->get_settings();
        $default_cotiza_url = (string) ($settings['cotiza_url'] ?? '');

        $brochure_url = (string) ($plant['brochure'] ?? '');
        $cotizacion_url = (string) ($plant['cotizacion_url'] ?? '');
        // Si está vacía en la planta, usar la URL por defecto de configuración
        if (empty($cotizacion_url) && !empty($default_cotiza_url)) {
            $cotizacion_url = $default_cotiza_url . '&id=' . $plant['external_id'];
        }

        ob_start();
    ?>
        <div class="wrap ileben-admin">
            <h1 class="mb-3"><?php echo $id ? 'Editar Planta' : 'Nueva Planta'; ?></h1>
            <?php $this->render_flash(); ?>

            <div class="card">
                <div class="card-body">
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="ileben_api_save_plant" />
                        <input type="hidden" name="id" value="<?php echo (int) $plant['id']; ?>" />
                        <?php wp_nonce_field('ileben_api_save'); ?>

                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">External ID</label>
                                <input class="form-control" type="text" name="external_id" value="<?php echo esc_attr($plant['external_id']); ?>" />
                            </div>
                            <div class="col-md-8">
                                <label class="form-label">Nombre *</label>
                                <input class="form-control" type="text" name="nombre" required value="<?php echo esc_attr($plant['nombre']); ?>" />
                            </div>
                            <div class="col-12">
                                <label class="form-label">Descripcion</label>
                                <textarea class="form-control" name="descripcion" rows="4"><?php echo esc_textarea($plant['descripcion']); ?></textarea>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Precio Base</label>
                                <input class="form-control" step="0.01" min="0" type="number" name="precio_base" value="<?php echo esc_attr((string) ($plant['precio_base'] ?? 0)); ?>" />
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Precio Lista</label>
                                <input class="form-control" step="0.01" min="0" type="number" name="precio_lista" value="<?php echo esc_attr((string) ($plant['precio_lista'] ?? $plant['precio'] ?? 0)); ?>" />
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Precio Final</label>
                                <input class="form-control" step="0.01" min="0" type="number" name="precio_final" value="<?php echo esc_attr((string) ($plant['precio_final'] ?? $plant['precio'] ?? 0)); ?>" />
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Banos</label>
                                <input class="form-control" min="0" type="number" name="banos" value="<?php echo esc_attr((string) $plant['banos']); ?>" />
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Dormitorios</label>
                                <input class="form-control" min="0" type="number" name="dormitorios" value="<?php echo esc_attr((string) $plant['dormitorios']); ?>" />
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Metros cuadrados</label>
                                <input class="form-control" step="0.01" min="0" type="number" name="metros_cuadrados" value="<?php echo esc_attr((string) $plant['metros_cuadrados']); ?>" />
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Tipologia</label>
                                <input class="form-control" type="text" name="tipologia" value="<?php echo esc_attr((string) $plant['tipologia']); ?>" placeholder="1 dormitorio + 1 bano" />
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Planta (label)</label>
                                <input class="form-control" type="text" name="planta_label" value="<?php echo esc_attr((string) $plant['planta_label']); ?>" placeholder="A: 501 al 701" />
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Orientacion</label>
                                <input class="form-control" type="text" name="orientacion" value="<?php echo esc_attr((string) $plant['orientacion']); ?>" placeholder="Poniente" />
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Superficie interior (m2)</label>
                                <input class="form-control" step="0.01" min="0" type="number" name="superficie_interior" value="<?php echo esc_attr((string) $plant['superficie_interior']); ?>" />
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Terraza (m2)</label>
                                <input class="form-control" step="0.01" min="0" type="number" name="terraza_m2" value="<?php echo esc_attr((string) $plant['terraza_m2']); ?>" />
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Superficie total (m2)</label>
                                <input class="form-control" step="0.01" min="0" type="number" name="superficie_total" value="<?php echo esc_attr((string) $plant['superficie_total']); ?>" />
                            </div>
                            <div class="col-md-4">
                                <label class="form-label d-block">Estado</label>
                                <?php $is_disponible = (($plant['estado'] ?? 'disponible') === 'disponible'); ?>
                                <input type="hidden" name="estado" id="ileben_estado_value" value="<?php echo $is_disponible ? 'disponible' : 'no_disponible'; ?>" />
                                <div class="ileben-estado-toggle">
                                    <button type="button" class="ileben-estado-btn <?php echo $is_disponible ? 'active' : ''; ?>" id="ileben_estado_btn">
                                        <svg class="ileben-estado-icon-check" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 640" style="display: <?php echo $is_disponible ? 'block' : 'none'; ?>">
                                            <path d="M320 576C178.6 576 64 461.4 64 320C64 178.6 178.6 64 320 64C461.4 64 576 178.6 576 320C576 461.4 461.4 576 320 576zM438 209.7C427.3 201.9 412.3 204.3 404.5 215L285.1 379.2L233 327.1C223.6 317.7 208.4 317.7 199.1 327.1C189.8 336.5 189.7 351.7 199.1 361L271.1 433C276.1 438 282.9 440.5 289.9 440C296.9 439.5 303.3 435.9 307.4 430.2L443.3 243.2C451.1 232.5 448.7 217.5 438 209.7z" />
                                        </svg>
                                        <svg class="ileben-estado-icon-uncheck" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 640" style="display: <?php echo !$is_disponible ? 'block' : 'none'; ?>">
                                            <path d="M64 320C64 178.6 178.6 64 320 64C461.4 64 576 178.6 576 320C576 461.4 461.4 576 320 576C178.6 576 64 461.4 64 320z" />
                                        </svg>
                                    </button>
                                    <span class="ileben-estado-label" id="ileben_estado_label"><?php echo $is_disponible ? 'Disponible' : 'No disponible'; ?></span>
                                </div>
                                <small class="text-muted d-block mt-2">Click en el ícono para cambiar estado.</small>
                                <script type="text/javascript">
                                    (function() {
                                        const btn = document.getElementById('ileben_estado_btn');
                                        const estadoValue = document.getElementById('ileben_estado_value');
                                        const estadoLabel = document.getElementById('ileben_estado_label');
                                        const iconCheck = document.querySelector('.ileben-estado-icon-check');
                                        const iconUncheck = document.querySelector('.ileben-estado-icon-uncheck');

                                        if (!btn || !estadoValue || !estadoLabel || !iconCheck || !iconUncheck) {
                                            return;
                                        }

                                        function updateEstado(disponible) {
                                            estadoValue.value = disponible ? 'disponible' : 'no_disponible';
                                            estadoLabel.textContent = disponible ? 'Disponible' : 'No disponible';

                                            if (disponible) {
                                                btn.classList.add('active');
                                                iconCheck.style.display = 'block';
                                                iconUncheck.style.display = 'none';
                                            } else {
                                                btn.classList.remove('active');
                                                iconCheck.style.display = 'none';
                                                iconUncheck.style.display = 'block';
                                            }
                                        }

                                        btn.addEventListener('click', function(e) {
                                            e.preventDefault();
                                            const isActive = btn.classList.contains('active');
                                            updateEstado(!isActive);
                                        });
                                    })();
                                </script>
                            </div>

                            <div class="col-6">
                                <label class="form-label">Imagen Portada</label>
                                <input type="hidden" name="foto_portada" id="ileben_image_url_portada" value="<?php echo esc_attr($image_portada_url); ?>" />
                                <div class="d-flex gap-2 mb-2">
                                    <button type="button" class="btn btn-outline-primary" id="ileben_select_image_portada">Seleccionar/Subir portada</button>
                                    <button type="button" class="btn btn-outline-secondary" id="ileben_remove_image_portada">Quitar portada</button>
                                </div>
                                <div id="ileben_image_preview_wrapper_portada">
                                    <img
                                        id="ileben_image_preview_portada"
                                        src="<?php echo esc_url($image_portada_url !== '' ? $image_portada_url : $default_logo_url); ?>"
                                        data-default-src="<?php echo esc_url($default_logo_url); ?>"
                                        alt="Preview portada"
                                        style="max-width: 240px; height: auto; border-radius: 8px;" />
                                </div>
                                <p class="text-muted mb-0">Imagen principal de la planta. Si está vacía, se muestra el logo por defecto.</p>
                            </div>

                            <div class="col-6">
                                <label class="form-label">Imagen Interior</label>
                                <input type="hidden" name="foto_interior" id="ileben_image_url_interior" value="<?php echo esc_attr($image_interior_url); ?>" />
                                <div class="d-flex gap-2 mb-2">
                                    <button type="button" class="btn btn-outline-primary" id="ileben_select_image_interior">Seleccionar/Subir interior</button>
                                    <button type="button" class="btn btn-outline-secondary" id="ileben_remove_image_interior">Quitar interior</button>
                                </div>
                                <div id="ileben_image_preview_wrapper_interior">
                                    <img
                                        id="ileben_image_preview_interior"
                                        src="<?php echo esc_url($image_interior_url !== '' ? $image_interior_url : $default_logo_url); ?>"
                                        data-default-src="<?php echo esc_url($default_logo_url); ?>"
                                        alt="Preview interior"
                                        style="max-width: 240px; height: auto; border-radius: 8px;" />
                                </div>
                                <p class="text-muted mb-0">Imagen complementaria de interior.</p>
                            </div>

                            <div class="col-12">
                                <label class="form-label">Brochure (archivo opcional)</label>
                                <input type="hidden" name="brochure" id="ileben_brochure_url" value="<?php echo esc_attr($brochure_url); ?>" />
                                <div class="d-flex gap-2 mb-2">
                                    <button type="button" class="btn btn-outline-primary" id="ileben_select_brochure">Seleccionar/Subir brochure</button>
                                    <button type="button" class="btn btn-outline-secondary" id="ileben_remove_brochure">Quitar brochure</button>
                                </div>
                                <div id="ileben_brochure_preview_wrapper">
                                    <?php if ($brochure_url !== ''): ?>
                                        <a id="ileben_brochure_preview" href="<?php echo esc_url($brochure_url); ?>" target="_blank" rel="noopener">Ver brochure actual</a>
                                    <?php else: ?>
                                        <a id="ileben_brochure_preview" href="#" target="_blank" rel="noopener" style="display:none;">Ver brochure actual</a>
                                    <?php endif; ?>
                                </div>
                                <p class="text-muted mb-0">Puedes subir PDF u otro archivo descargable.</p>
                            </div>

                            <div class="col-12">
                                <label class="form-label">URL de cotizacion (opcional)</label>
                                <input class="form-control" type="url" name="cotizacion_url" value="<?php echo esc_attr($cotizacion_url); ?>" placeholder="https://..." />
                                <p class="text-muted mb-0">Si queda vacio, se usara la URL Cotizar por defecto configurada en el plugin.</p>
                            </div>

                            <div class="col-12"><button type="submit" class="btn btn-primary">Guardar</button></div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php
        echo ob_get_clean();
    }

    public function render_import_page()
    {
        $this->guard_permission();

        ob_start();
    ?>
        <div class="wrap ileben-admin">
            <h1 class="mb-3">Importar CSV</h1>
            <?php $this->render_flash(); ?>

            <div class="card">
                <div class="card-body">
                    <?php
                    $sample_url = wp_nonce_url(
                        admin_url('admin-post.php?action=ileben_api_download_csv_sample'),
                        'ileben_api_download_csv_sample'
                    );
                    ?>
                    <p class="text-muted">Columnas esperadas: external_id, nombre, descripcion, precio_base, precio_lista, precio_final, banos, dormitorios, metros_cuadrados, tipologia, planta_label, orientacion, superficie_interior, terraza_m2, superficie_total, foto_portada, foto_interior, brochure, cotizacion_url, estado.</p>
                    <p><a class="btn btn-outline-secondary" href="<?php echo esc_url($sample_url); ?>">Descargar CSV de ejemplo</a></p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="ileben_api_import_csv" />
                        <?php wp_nonce_field('ileben_api_import_csv'); ?>
                        <div class="mb-3"><input class="form-control" type="file" name="csv_file" accept=".csv,text/csv" required /></div>
                        <button type="submit" class="btn btn-primary">Importar</button>
                    </form>
                </div>
            </div>
        </div>
    <?php
        echo ob_get_clean();
    }

    public function render_sync_page()
    {
        $this->guard_permission();
        $settings = $this->api_client->get_settings();
        $proyecto_id_empty = empty($settings['proyecto_id']);
        $has_saved_token = ! empty($settings['api_token']);

        ob_start();
    ?>
        <div class="wrap ileben-admin">
            <h1 class="mb-3">Sincronizacion con API</h1>
            <?php $this->render_flash(); ?>

            <div class="card mb-4">
                <div class="card-body">
                    <h2 class="h5 mb-3">Configuracion del plugin</h2>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="ileben_api_save_settings" />
                        <?php wp_nonce_field('ileben_api_save_settings'); ?>

                        <div class="row g-3">
                            <div class="col-md-8">
                                <label class="form-label">Endpoint API (URL Base) <sup>*</sup></label>
                                <input class="form-control" type="url" name="api_endpoint" required value="<?php echo esc_attr((string) ($settings['api_endpoint'] ?? '')); ?>" placeholder="https://api.tuservicio.com/api/v1" id="ileben_api_endpoint" />
                                <small class="text-muted d-block mt-2">Solo la URL base. Los endpoints /plantas y /proyectos se agregan automáticamente.</small>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label">Proyecto</label>
                                <select class="form-select" name="proyecto_id" id="ileben_proyecto_select" <?php disabled($has_saved_token, false, true); ?>>
                                    <?php if (! $has_saved_token): ?>
                                        <option value="">-- Guarda la configuración con token para cargar proyectos --</option>
                                    <?php else: ?>
                                        <option value="">-- Cargando proyectos --</option>
                                    <?php endif; ?>
                                </select>
                                <small class="text-muted d-block mt-2">
                                    <a href="#" class="link-secondary <?php echo ! $has_saved_token ? 'disabled' : ''; ?>" id="ileben_reload_proyectos" aria-disabled="<?php echo $has_saved_token ? 'false' : 'true'; ?>">Recargar proyectos</a>
                                </small>
                            </div>

                            <div class="col-md-8">
                                <label class="form-label">Bearer Token<sup>*</sup></label>
                                <input class="form-control" type="password" name="api_token" required value="<?php echo esc_attr((string) ($settings['api_token'] ?? '')); ?>" autocomplete="new-password" placeholder="eyJhbGciOi..." />
                            </div>

                            <div class="col-md-4">
                                <label class="form-label">Timeout API (5-120s)<sup>*</sup></label>
                                <input class="form-control" type="number" min="5" max="120" name="timeout" required value="<?php echo esc_attr((string) ($settings['timeout'] ?? 15)); ?>" />
                            </div>

                            <div class="col-md-8">
                                <label class="form-label">URL Cotizar por defecto</label>
                                <input class="form-control" type="url" name="cotiza_url" value="<?php echo esc_attr((string) ($settings['cotiza_url'] ?? '')); ?>" placeholder="https://..." />
                            </div>

                            <div class="col-md-4">
                                <label class="form-label">Precio a mostrar</label>
                                <select class="form-select" name="price_display_mode">
                                    <option value="base" <?php selected((string) ($settings['price_display_mode'] ?? 'base'), 'base'); ?>>Precio base</option>
                                    <option value="lista" <?php selected((string) ($settings['price_display_mode'] ?? 'base'), 'lista'); ?>>Precio lista</option>
                                    <option value="final" <?php selected((string) ($settings['price_display_mode'] ?? 'base'), 'final'); ?>>Precio final</option>
                                </select>
                                <small class="text-muted d-block mt-1">Define el precio que se muestra en el shortcode. Base: fallback a lista y final. Lista: fallback a final y base. Final: fallback a base.</small>
                            </div>

                            <div class="col-md-4 d-flex align-items-end">
                                <div class="mb-2">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="ileben_cron_enabled" name="cron_enabled" value="1" <?php checked((int) ($settings['cron_enabled'] ?? 0), 1, true); ?> />
                                        <label class="form-check-label" for="ileben_cron_enabled">Activar sincronizacion automatica (CRON)</label>
                                    </div>
                                    <div class="mt-2">
                                        <label class="form-label" for="ileben_cron_interval_hours">Intervalo CRON (horas)</label>
                                        <input class="form-control" type="number" min="1" max="24" id="ileben_cron_interval_hours" name="cron_interval_hours" value="<?php echo esc_attr((string) ($settings['cron_interval_hours'] ?? 1)); ?>" />
                                        <small class="text-muted d-block mt-1">Define cada cuantas horas se ejecuta la sincronizacion automatica.</small>
                                    </div>
                                    <div class="form-check mt-2">
                                        <input class="form-check-input" type="checkbox" id="ileben_show_cover_image" name="show_cover_image" value="1" <?php checked((int) ($settings['show_cover_image'] ?? 1), 1, true); ?> />
                                        <label class="form-check-label" for="ileben_show_cover_image">Mostrar foto de portada</label>
                                    </div>
                                    <small class="text-muted d-block mt-1">Si se desactiva, se usara la imagen interior como imagen principal.</small>
                                </div>
                            </div>

                            <div class="col-12">
                                <p class="text-muted mb-2">Si defines Bearer Token, el plugin enviara el header Authorization: Bearer TOKEN.</p>
                                <button class="btn btn-outline-primary" type="submit">Guardar configuracion</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card">
                <div class="card-body">
                    <?php if ($proyecto_id_empty): ?>
                        <div class="alert alert-warning mb-3" role="alert">
                            <strong>⚠️ Advertencia:</strong> El campo "Proyecto ID" está vacío. Se importarán <strong>TODAS</strong> las plantas de la API.
                        </div>
                    <?php endif; ?>

                    <p class="text-muted">Ejecuta una sincronizacion manual de plantas desde la API configurada.</p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="ileben_sync_form">
                        <input type="hidden" name="action" value="ileben_api_sync_api" />
                        <input type="hidden" name="proyecto_id" id="ileben_sync_proyecto_id" value="<?php echo esc_attr((string) ($settings['proyecto_id'] ?? '')); ?>" />
                        <?php wp_nonce_field('ileben_api_sync_api'); ?>
                        <button class="btn btn-primary" type="submit" id="ileben_sync_btn">Sincronizar ahora</button>
                    </form>

                    <script type="text/javascript">
                        (function() {
                            const endpointInput = document.getElementById('ileben_api_endpoint');
                            const proyectoSelect = document.getElementById('ileben_proyecto_select');
                            const reloadBtn = document.getElementById('ileben_reload_proyectos');
                            const syncForm = document.getElementById('ileben_sync_form');
                            const syncBtn = document.getElementById('ileben_sync_btn');
                            const syncProyectoInput = document.getElementById('ileben_sync_proyecto_id');
                            const hasSavedToken = <?php echo $has_saved_token ? 'true' : 'false'; ?>;

                            function selectedProyectoId() {
                                return proyectoSelect ? String(proyectoSelect.value || '').trim() : '';
                            }

                            function requiresConfirm() {
                                return selectedProyectoId() === '';
                            }

                            function loadProyectos() {
                                if (!hasSavedToken) {
                                    proyectoSelect.innerHTML = '<option value="">-- Guarda la configuración con token para cargar proyectos --</option>';
                                    proyectoSelect.disabled = true;
                                    return;
                                }

                                const endpoint = endpointInput.value.trim();
                                if (!endpoint) {
                                    proyectoSelect.innerHTML = '<option value="">-- Configura el endpoint API primero --</option>';
                                    proyectoSelect.disabled = true;
                                    return;
                                }

                                proyectoSelect.innerHTML = '<option value="">-- Cargando proyectos --</option>';
                                proyectoSelect.disabled = true;

                                fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {
                                        method: 'POST',
                                        headers: {
                                            'Content-Type': 'application/x-www-form-urlencoded',
                                        },
                                        body: new URLSearchParams({
                                            action: 'ileben_api_get_proyectos',
                                            nonce: '<?php echo wp_create_nonce('ileben_api_get_proyectos'); ?>',
                                            endpoint: endpoint,
                                        }).toString()
                                    })
                                    .then(response => response.text())
                                    .then(text => {
                                        const cleanedText = text.replace(/^\uFEFF/, '').trim();
                                        let data;
                                        try {
                                            data = JSON.parse(cleanedText);
                                        } catch (e) {
                                            console.error('AJAX JSON parse error:', e, 'Raw response:', text);
                                            proyectoSelect.innerHTML = '<option value="">Error al cargar proyectos</option>';
                                            proyectoSelect.disabled = false;
                                            return;
                                        }
                                        if (data.success && Array.isArray(data.data)) {
                                            let options = '<option value="">-- Selecciona un proyecto --</option>';
                                            data.data.forEach(proyecto => {
                                                const id = proyecto.id || '';
                                                const nombre = proyecto.nombre || 'Sin nombre';
                                                const selected = String(id) === '<?php echo esc_attr((string) ($settings['proyecto_id'] ?? '')); ?>' ? 'selected' : '';
                                                options += '<option value="' + id + '" ' + selected + '>' + nombre + '</option>';
                                            });
                                            proyectoSelect.innerHTML = options;
                                        } else {
                                            console.error('AJAX error response:', data);
                                            proyectoSelect.innerHTML = '<option value="">Error: ' + (data.data || 'respuesta inválida') + '</option>';
                                        }
                                        proyectoSelect.disabled = false;
                                    })
                                    .catch(error => {
                                        console.error('Error:', error);
                                        proyectoSelect.innerHTML = '<option value="">Error al cargar proyectos</option>';
                                        proyectoSelect.disabled = false;
                                    });
                            }

                            // Cargar proyectos solo con token guardado y endpoint guardado.
                            if (hasSavedToken && endpointInput.value.trim()) {
                                setTimeout(loadProyectos, 100);
                            }

                            // Botón recargar
                            reloadBtn.addEventListener('click', function(e) {
                                e.preventDefault();

                                if (!hasSavedToken) {
                                    return;
                                }

                                loadProyectos();
                            });

                            syncForm.addEventListener('submit', function(e) {
                                if (syncBtn.disabled) {
                                    e.preventDefault();
                                    return;
                                }

                                if (syncProyectoInput) {
                                    const selected = selectedProyectoId();
                                    if (selected !== '') {
                                        syncProyectoInput.value = selected;
                                    }
                                }

                                if (requiresConfirm()) {
                                    const confirmed = confirm('⚠️ ADVERTENCIA: Proyecto ID está vacío.\n\nSe importarán TODAS las plantas de la API.\n\n¿Deseas continuar?');
                                    if (!confirmed) {
                                        e.preventDefault();
                                        return;
                                    }
                                }

                                syncBtn.disabled = true;
                                syncBtn.dataset.originalText = syncBtn.textContent;
                                syncBtn.textContent = 'Sincronizando...';
                            });
                        })();
                    </script>
                </div>
            </div>

            <div class="card mt-4">
                <div class="card-body">
                    <h2 class="h5 mb-3">Configuración del sitio (API)</h2>
                    <?php
                    $site_config = $this->api_client->get_site_config();
                    if (! empty($site_config)) :
                    ?>
                        <div class="row g-2 mb-3">
                            <?php
                            $fields = array(
                                'site_name'          => 'Nombre del sitio',
                                'site_description'   => 'Descripción',
                                'brand_color'        => 'Color principal',
                                'webawesome_theme'   => 'Tema',
                                'webawesome_palette' => 'Paleta',
                                'icon_family'        => 'Familia de íconos',
                                'font_family_body'   => 'Fuente cuerpo',
                                'font_family_heading' => 'Fuente títulos',
                                'maintenance_mode'   => 'Modo mantenimiento',
                            );
                            foreach ($fields as $key => $label) :
                                if (! array_key_exists($key, $site_config)) continue;
                                $val = $site_config[$key];
                            ?>
                                <div class="col-md-4">
                                    <small class="text-muted d-block"><?php echo esc_html($label); ?></small>
                                    <?php if ($key === 'brand_color' && ! empty($val)) : ?>
                                        <span class="d-inline-flex align-items-center gap-2">
                                            <span style="display:inline-block;width:18px;height:18px;border-radius:3px;background:<?php echo esc_attr((string) $val); ?>;border:1px solid #ccc;"></span>
                                            <strong><?php echo esc_html((string) $val); ?></strong>
                                        </span>
                                    <?php elseif ($key === 'maintenance_mode') : ?>
                                        <strong><?php echo $val ? 'Activado' : 'Desactivado'; ?></strong>
                                    <?php elseif ($val !== null && $val !== '') : ?>
                                        <strong><?php echo esc_html((string) $val); ?></strong>
                                    <?php else : ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php
                        $contact = $site_config['contact'] ?? array();
                        $social  = $site_config['social'] ?? array();
                        $contact_items = array_filter((array) $contact);
                        $social_items  = array_filter((array) $social);
                        if (! empty($contact_items) || ! empty($social_items)) :
                        ?>
                            <div class="row g-2 mb-3">
                                <?php foreach ($contact_items as $ck => $cv) : ?>
                                    <div class="col-md-4">
                                        <small class="text-muted d-block"><?php echo esc_html(ucfirst($ck)); ?></small>
                                        <strong><?php echo esc_html((string) $cv); ?></strong>
                                    </div>
                                <?php endforeach; ?>
                                <?php foreach ($social_items as $sk => $sv) : ?>
                                    <div class="col-md-4">
                                        <small class="text-muted d-block"><?php echo esc_html(ucfirst($sk)); ?></small>
                                        <strong><?php echo esc_html((string) $sv); ?></strong>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <?php
                        $logo_fields = array(
                            'logo' => 'Logo',
                            'logo_dark' => 'Logo oscuro',
                            'logo_sale' => 'Logo sale',
                            'favicon' => 'Favicon',
                        );
                        $logo_items = array();
                        foreach ($logo_fields as $key => $label) {
                            $logo_url = esc_url((string) ($site_config[$key] ?? ''));
                            if ($logo_url === '') {
                                continue;
                            }

                            $logo_items[$key] = array(
                                'label' => $label,
                                'url' => $logo_url,
                            );
                        }
                        if (! empty($logo_items)) :
                        ?>
                            <div class="row g-3 mb-3">
                                <?php foreach ($logo_items as $logo_item) : ?>
                                    <div class="col-md-3 col-sm-6">
                                        <small class="text-muted d-block mb-2"><?php echo esc_html($logo_item['label']); ?></small>
                                        <a href="<?php echo esc_url($logo_item['url']); ?>" target="_blank" rel="noopener noreferrer" class="d-block border rounded p-2 bg-white text-center">
                                            <img src="<?php echo esc_url($logo_item['url']); ?>" alt="<?php echo esc_attr($logo_item['label']); ?>" style="max-width:100%;max-height:72px;object-fit:contain;" />
                                        </a>
                                        <small class="text-muted d-block mt-2 text-break"><?php echo esc_html($logo_item['url']); ?></small>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="ileben-site-config-toggle mb-3">
                            <input type="hidden" name="action" value="ileben_api_toggle_favicon" />
                            <?php wp_nonce_field('ileben_api_toggle_favicon'); ?>
                            <div class="ileben-site-config-toggle__row">
                                <div>
                                    <label class="ileben-site-config-toggle__label" for="ileben_use_api_favicon_site_config">Usar favicon de la API</label>
                                    <small class="text-muted d-block mt-1">Activa o desactiva la inyeccion del favicon recibido desde la API.</small>
                                </div>
                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input" type="checkbox" role="switch" id="ileben_use_api_favicon_site_config" name="use_api_favicon" value="1" <?php checked((int) ($settings['use_api_favicon'] ?? 1), 1, true); ?> onchange="this.form.submit()" />
                                    <span class="ileben-site-config-toggle__state"><?php echo ! empty($settings['use_api_favicon']) ? 'Activo' : 'Desactivado'; ?></span>
                                </div>
                            </div>
                        </form>
                        <p class="text-muted small mb-0">Última sincronización incluida en el proceso de sync.</p>
                    <?php else : ?>
                        <p class="text-muted mb-3">No hay configuración guardada aún. Usa el botón para sincronizar desde la API.</p>
                    <?php endif; ?>

                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="ileben_api_sync_site_config" />
                        <?php wp_nonce_field('ileben_api_sync_site_config'); ?>
                        <button class="btn btn-outline-secondary" type="submit">Sincronizar configuración del sitio</button>
                    </form>
                </div>
            </div>
        </div>
    <?php

        echo ob_get_clean();
    }

    public function render_contact_sync_page()
    {
        $this->guard_permission();

        $page = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;
        $filters = array(
            'status' => sanitize_text_field((string) ($_GET['status'] ?? '')),
            'channel' => sanitize_text_field((string) ($_GET['channel'] ?? '')),
            'email' => sanitize_text_field((string) ($_GET['email'] ?? '')),
            'date_from' => sanitize_text_field((string) ($_GET['date_from'] ?? '')),
            'date_to' => sanitize_text_field((string) ($_GET['date_to'] ?? '')),
        );

        $states = $this->repository->get_contact_sync_states();
        $result = $this->repository->query_contact_sync_logs($filters, $page, 20);
        $stats = $this->repository->get_contact_sync_stats();

        ob_start();
    ?>
        <div class="wrap ileben-admin" id="ilebenContactSyncWrap" data-status="<?php echo esc_attr($filters['status']); ?>" data-channel="<?php echo esc_attr($filters['channel']); ?>" data-email="<?php echo esc_attr($filters['email']); ?>" data-date-from="<?php echo esc_attr($filters['date_from']); ?>" data-date-to="<?php echo esc_attr($filters['date_to']); ?>" data-total="<?php echo (int) $result['total']; ?>" data-page-items="<?php echo count($result['items']); ?>">
            <h1 class="mb-3">Sync Contactos API</h1>
            <?php $this->render_flash(); ?>

            <div class="card mb-4">
                <div class="card-body">
                    <h2 class="h5 mb-3">Uso en Contact Form 7</h2>
                    <p class="text-muted mb-2">Inserta este tag dentro del formulario CF7 para enviar el canal obligatorio al API:</p>
                    <pre class="ileben-code-sample">[ileben_channel "sale"]</pre>
                    <p class="text-muted mb-0">Tambien puedes usar un hidden estandar con name="channel". El canal debe existir y estar activo en el backend.</p>
                </div>
            </div>

            <div class="row g-3 mb-4">
                <div class="col-md-3">
                    <div class="card">
                        <div class="card-body"><small class="text-muted d-block">Total envios</small><strong><?php echo (int) ($stats['total'] ?? 0); ?></strong></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card">
                        <div class="card-body"><small class="text-muted d-block">Enviados</small><strong><?php echo (int) ($stats['sent'] ?? 0); ?></strong></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card">
                        <div class="card-body"><small class="text-muted d-block">Fallidos hoy</small><strong><?php echo (int) ($stats['failed_today'] ?? 0); ?></strong></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card">
                        <div class="card-body"><small class="text-muted d-block">Tasa de error</small><strong><?php echo esc_html(number_format((float) ($stats['error_rate'] ?? 0), 2)); ?>%</strong></div>
                    </div>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-body">
                    <form class="row g-3" method="get">
                        <input type="hidden" name="page" value="ileben-api-contact-sync" />
                        <div class="col-md-2">
                            <label class="form-label">Estado</label>
                            <select class="form-select" name="status">
                                <option value="">Todos</option>
                                <?php foreach ($states as $state_key => $state_label): ?>
                                    <option value="<?php echo esc_attr($state_key); ?>" <?php echo selected($filters['status'], $state_key, false); ?>><?php echo esc_html($state_label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Canal</label>
                            <input class="form-control" type="text" name="channel" value="<?php echo esc_attr($filters['channel']); ?>" />
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Email</label>
                            <input class="form-control" type="text" name="email" value="<?php echo esc_attr($filters['email']); ?>" />
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Desde</label>
                            <input class="form-control" type="date" name="date_from" value="<?php echo esc_attr($filters['date_from']); ?>" />
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Hasta</label>
                            <input class="form-control" type="date" name="date_to" value="<?php echo esc_attr($filters['date_to']); ?>" />
                        </div>
                        <div class="col-md-1 d-flex align-items-end">
                            <button class="btn btn-primary w-100" type="submit">Filtrar</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Barra de Acciones Masivas -->
            <div id="ilebenBulkToolbar" class="card mb-3 d-none border-primary bg-light shadow-sm">
                <div class="card-body py-2 px-3 d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge bg-primary fs-6 px-3 py-2" id="bulkSelectedCount">0 seleccionados</span>
                        <button type="button" class="btn btn-sm btn-link text-decoration-none text-muted" id="btnDeselectAll">
                            <i class="fa fa-times me-1"></i>Deseleccionar
                        </button>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <button type="button" class="btn btn-sm btn-outline-primary" id="btnOpenBulkChannelModal">
                            <i class="fa fa-tag me-1"></i> Cambiar Canal
                        </button>
                        <button type="button" class="btn btn-sm btn-success" id="btnStartBulkResync">
                            <i class="fa fa-sync me-1"></i> Resincronizar Seleccionados
                        </button>
                    </div>
                </div>
                <div id="bulkSelectAllFilteredBanner" class="card-footer py-2 px-3 bg-primary-subtle text-primary small d-none text-center">
                    <span id="bulkBannerText">Has seleccionado los <strong id="bulkPageCount"><?php echo count($result['items']); ?></strong> contactos de esta página.</span>
                    <button type="button" class="btn btn-link btn-sm p-0 ms-1 fw-bold text-decoration-underline text-primary" id="btnSelectAllFiltered">
                        Seleccionar los <span id="bulkTotalFilteredCount"><?php echo (int) $result['total']; ?></span> contactos que coinciden con los filtros actuales
                    </button>
                    <button type="button" class="btn btn-link btn-sm p-0 ms-1 fw-bold text-decoration-underline text-secondary d-none" id="btnClearAllFiltered">
                        Limpiar selección global
                    </button>
                </div>
            </div>

            <!-- Tabla contactos -->
            <div class="card">
                <div class="table-responsive">
                    <table class="table table-striped table-hover mb-0" id="contact-table">
                        <thead>
                            <tr>
                                <th style="width: 38px;" class="text-center">
                                    <input class="form-check-input" type="checkbox" id="selectAllContacts" title="Seleccionar todos en esta página" />
                                </th>
                                <th>ID</th>
                                <th>Fecha</th>
                                <th>Formulario</th>
                                <th>Canal</th>
                                <th>Contacto</th>
                                <th>Estado</th>
                                <th>HTTP</th>
                                <th>Intentos</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($result['items'])): ?>
                                <tr>
                                    <td colspan="10" class="text-center py-4">No hay envios registrados.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($result['items'] as $item): ?>
                                    <?php
                                    $status_key = (string) ($item['status'] ?? 'failed');
                                    $status_label = $states[$status_key] ?? $status_key;
                                    $badge_class = $status_key === 'sent' ? 'text-bg-success' : ($status_key === 'rate_limited' ? 'text-bg-warning' : 'text-bg-danger');
                                    ?>
                                    <tr id="contact-row-<?php echo (int) ($item['id'] ?? 0); ?>">
                                        <td class="text-center">
                                            <input class="form-check-input ileben-contact-check" type="checkbox" value="<?php echo (int) ($item['id'] ?? 0); ?>" data-channel="<?php echo esc_attr((string) ($item['channel'] ?? '')); ?>" />
                                        </td>
                                        <td><?php echo (int) ($item['id'] ?? 0); ?></td>
                                        <td><?php echo esc_html((string) ($item['created_at'] ?? '')); ?></td>
                                        <td>
                                            <div>#<?php echo (int) ($item['form_id'] ?? 0); ?></div>
                                            <small class="text-muted"><?php echo esc_html((string) ($item['form_title'] ?? '')); ?></small>
                                        </td>
                                        <td class="contact-channel-cell"><?php echo esc_html((string) ($item['channel'] ?? '')); ?></td>
                                        <td>
                                            <div><?php echo esc_html((string) ($item['contact_name'] ?? '')); ?></div>
                                            <small class="text-muted"><?php echo esc_html((string) ($item['contact_email'] ?? '')); ?></small>
                                        </td>
                                        <td><span class="badge <?php echo esc_attr($badge_class); ?>"><?php echo esc_html($status_label); ?></span></td>
                                        <td><?php echo (int) ($item['response_code'] ?? 0); ?></td>
                                        <td><?php echo (int) ($item['retries'] ?? 0); ?></td>
                                        <td>
                                            <?php if ($status_key !== 'sent'): ?>
                                                <button
                                                    class="btn btn-sm btn-outline-primary ileben-edit-contact-btn"
                                                    data-contact-id="<?php echo (int) ($item['id'] ?? 0); ?>"
                                                    data-contact-name="<?php echo esc_attr((string) ($item['contact_name'] ?? '')); ?>"
                                                    data-contact-email="<?php echo esc_attr((string) ($item['contact_email'] ?? '')); ?>"
                                                    data-contact-channel="<?php echo esc_attr((string) ($item['channel'] ?? '')); ?>"
                                                    data-payload='<?php echo esc_attr((string) ($item['payload_json'] ?? '{}')); ?>'
                                                    type="button">
                                                    Editar y reintentar
                                                </button>
                                            <?php else: ?>
                                                <span class="text-muted small">-</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php if (! empty($item['error_message'])): ?>
                                        <?php
                                        // Intenta parsear el JSON de errores
                                        $error_data = @json_decode($item['error_message'], true);
                                        $error_html = '';

                                        if (is_array($error_data) && isset($error_data['errors']) && is_array($error_data['errors'])) {
                                            // Errores estructurados por campo
                                            $error_html .= '<div class="alert alert-danger alert-sm mb-2" style="margin-bottom: 0.5rem;">';
                                            $error_html .= '<strong>Errores de validacion:</strong><ul class="mt-2 mb-0">';
                                            foreach ($error_data['errors'] as $field => $messages) {
                                                if (is_array($messages)) {
                                                    foreach ($messages as $msg) {
                                                        $error_html .= '<li><code>' . esc_html($field) . '</code>: ' . esc_html($msg) . '</li>';
                                                    }
                                                } else {
                                                    $error_html .= '<li><code>' . esc_html($field) . '</code>: ' . esc_html($messages) . '</li>';
                                                }
                                            }
                                            $error_html .= '</ul></div>';
                                            if (!empty($error_data['message'])) {
                                                $error_html .= '<p class="text-muted mb-0"><small>' . esc_html($error_data['message']) . '</small></p>';
                                            }
                                        } else {
                                            // Texto simple
                                            $error_html .= '<p class="text-muted mb-0"><small>' . esc_html($item['error_message']) . '</small></p>';
                                        }
                                        ?>
                                        <tr class="bg-light">
                                            <td colspan="10" class="small py-3">
                                                <strong>Detalle:</strong>
                                                <div class="mt-2">
                                                    <?php echo $error_html; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <?php $this->render_pagination($result['page'], $result['pages']); ?>
        </div>

        <!-- Modal para editar y reintentar contacto individual -->
        <div class="modal fade" id="ilebenContactEditModal" tabindex="-1">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Editar y reintentar contacto</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <form id="ilebenContactEditForm" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="ileben_api_retry_contact_sync" />
                        <input type="hidden" name="id" id="editContactId" value="" />
                        <?php wp_nonce_field('ileben_api_retry_contact_sync'); ?>

                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Canal de contacto</label>
                                <input type="text" class="form-control" name="channel" id="editContactChannel" placeholder="ej: sale, rent, info" required />
                                <div class="form-text">Identificador del canal requerido por el API.</div>
                            </div>
                            <hr class="my-3" />
                            <h6 class="fw-bold mb-3">Campos del contacto</h6>
                            <div id="editContactFields"></div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                            <button type="submit" class="btn btn-primary">Reintentar</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Modal para cambio masivo de canal -->
        <div class="modal fade" id="ilebenBulkChannelModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="fa fa-tag me-2 text-primary"></i>Cambiar Canal de Contacto</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <form id="ilebenBulkChannelForm">
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label fw-bold" for="bulkNewChannel">Nuevo Canal de Contacto</label>
                                <input type="text" class="form-control" id="bulkNewChannel" placeholder="ej: sale, rent, info" required />
                                <div class="form-text">Este canal se asignará a todos los contactos seleccionados.</div>
                            </div>

                            <div class="mb-3 p-3 bg-light rounded border">
                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input" type="checkbox" id="bulkResyncAfterUpdate" checked />
                                    <label class="form-check-label fw-bold" for="bulkResyncAfterUpdate">
                                        Resincronizar con la API inmediatamente
                                    </label>
                                </div>
                                <div class="small text-muted mt-1">
                                    Si está activo, tras guardar el nuevo canal se iniciará automáticamente el reenvío de estos contactos a la API Leben.
                                </div>
                            </div>

                            <div id="bulkTargetScopeNotice" class="alert alert-info py-2 px-3 small mb-0">
                                Se actualizarán <strong id="bulkModalCount">0</strong> contacto(s) seleccionados.
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                            <button type="submit" class="btn btn-primary" id="btnConfirmBulkChannel">Guardar cambios</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Modal de Progreso de Sincronización Masiva -->
        <div class="modal fade" id="ilebenBulkSyncProgressModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="fa fa-sync fa-spin me-2 text-primary" id="syncSpinnerIcon"></i>Resincronización Masiva</h5>
                        <button type="button" class="btn-close d-none" id="btnCloseProgressModal" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="fw-bold small" id="syncProgressStatusText">Iniciando proceso...</span>
                                <span class="fw-bold small text-primary" id="syncProgressPercent">0%</span>
                            </div>
                            <div class="progress" style="height: 22px;">
                                <div id="syncProgressBar" class="progress-bar progress-bar-striped progress-bar-animated bg-primary" role="progressbar" style="width: 0%;"></div>
                            </div>
                        </div>

                        <div class="row g-2 text-center mb-3">
                            <div class="col-4">
                                <div class="p-2 border rounded bg-light">
                                    <small class="text-muted d-block">Enviados OK</small>
                                    <strong class="text-success fs-5" id="syncCountSuccess">0</strong>
                                </div>
                            </div>
                            <div class="col-4">
                                <div class="p-2 border rounded bg-light">
                                    <small class="text-muted d-block">Validación</small>
                                    <strong class="text-warning fs-5" id="syncCountValidation">0</strong>
                                </div>
                            </div>
                            <div class="col-4">
                                <div class="p-2 border rounded bg-light">
                                    <small class="text-muted d-block">Fallidos</small>
                                    <strong class="text-danger fs-5" id="syncCountFailed">0</strong>
                                </div>
                            </div>
                        </div>

                        <div class="card bg-light">
                            <div class="card-header py-1 px-2 d-flex justify-content-between align-items-center bg-white border-bottom">
                                <small class="fw-bold text-muted">Registro de actividad</small>
                                <small class="text-muted" id="syncQueueStatus">0 / 0 procesados</small>
                            </div>
                            <div class="card-body p-2 bg-dark text-light rounded-bottom" id="syncLogContainer" style="max-height: 220px; overflow-y: auto; font-family: SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-size: 0.8rem; line-height: 1.4;">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" id="btnCancelSync">Detener</button>
                        <button type="button" class="btn btn-primary d-none" id="btnFinishReload" onclick="window.location.reload();">Finalizar y Actualizar</button>
                    </div>
                </div>
            </div>
        </div>


        <script>
            // Diagnóstico inmediato
            (function() {
                console.log('[ileben-api] Diagnostic: página de contactos cargada');
                console.log('[ileben-api] Diagnostic: buscando botones...');
                var buttons = document.querySelectorAll('.ileben-edit-contact-btn');
                console.log('[ileben-api] Diagnostic: ' + buttons.length + ' botones encontrados');

                // Verificar si la función existe
                var checkFunction = function() {
                    if (typeof ilebenEditContactModal === 'function') {
                        console.log('[ileben-api] Diagnostic: ilebenEditContactModal cargada ✓');
                        return true;
                    } else {
                        console.log('[ileben-api] Diagnostic: ilebenEditContactModal NO cargada');
                        return false;
                    }
                };

                if (!checkFunction()) {
                    console.log('[ileben-api] Diagnostic: esperando admin.js...');
                    var attempt = 0;
                    var interval = setInterval(function() {
                        attempt++;
                        if (checkFunction()) {
                            clearInterval(interval);
                        } else if (attempt > 50) { // 5 segundos
                            clearInterval(interval);
                            console.error('[ileben-api] Diagnostic: admin.js no se cargó');
                        }
                    }, 100);
                }
            })();
        </script>
    <?php
        echo ob_get_clean();
    }

    public function handle_save_plant()
    {
        $this->guard_permission();
        check_admin_referer('ileben_api_save');

        $payload = array(
            'id' => (int) ($_POST['id'] ?? 0),
            'external_id' => sanitize_text_field($_POST['external_id'] ?? ''),
            'nombre' => sanitize_text_field($_POST['nombre'] ?? ''),
            'descripcion' => sanitize_textarea_field($_POST['descripcion'] ?? ''),
            'precio_base' => (float) ($_POST['precio_base'] ?? 0),
            'precio_lista' => (float) ($_POST['precio_lista'] ?? 0),
            'precio_final' => (float) ($_POST['precio_final'] ?? 0),
            'banos' => (int) ($_POST['banos'] ?? 0),
            'dormitorios' => (int) ($_POST['dormitorios'] ?? 0),
            'metros_cuadrados' => (float) ($_POST['metros_cuadrados'] ?? 0),
            'tipologia' => sanitize_text_field($_POST['tipologia'] ?? ''),
            'planta_label' => sanitize_text_field($_POST['planta_label'] ?? ''),
            'orientacion' => sanitize_text_field($_POST['orientacion'] ?? ''),
            'superficie_interior' => (float) ($_POST['superficie_interior'] ?? 0),
            'terraza_m2' => (float) ($_POST['terraza_m2'] ?? 0),
            'superficie_total' => (float) ($_POST['superficie_total'] ?? 0),
            'foto_portada' => esc_url_raw($_POST['foto_portada'] ?? ''),
            'foto_interior' => esc_url_raw($_POST['foto_interior'] ?? ''),
            'brochure' => esc_url_raw($_POST['brochure'] ?? ''),
            'cotizacion_url' => esc_url_raw($_POST['cotizacion_url'] ?? ''),
            'estado' => sanitize_text_field($_POST['estado'] ?? ''),
        );

        if (empty($payload['nombre'])) {
            $this->redirect_with_flash('ileben-api-new', 'error', 'El nombre es obligatorio.');
        }

        $result_id = $this->repository->save($payload);
        if (! $result_id) {
            $this->redirect_with_flash('ileben-api-new', 'error', 'No se pudo guardar la planta.');
        }

        $this->redirect_with_flash('ileben-api', 'success', 'Planta guardada correctamente.');
    }

    public function handle_delete_plant()
    {
        $this->guard_permission();

        $id = (int) ($_POST['id'] ?? 0);
        check_admin_referer('ileben_api_delete_' . $id);

        if (! $id) {
            $this->redirect_with_flash('ileben-api', 'error', 'ID invalido.');
        }

        $deleted = $this->repository->delete($id);
        if ($deleted === false) {
            $this->redirect_with_flash('ileben-api', 'error', 'No se pudo eliminar la planta.');
        }

        $this->redirect_with_flash('ileben-api', 'success', 'Planta eliminada correctamente.');
    }

    public function handle_delete_all()
    {
        $this->guard_permission();
        check_admin_referer('ileben_api_delete_all');

        $deleted = $this->repository->delete_all();
        if ($deleted === false) {
            $this->redirect_with_flash('ileben-api', 'error', 'No se pudieron eliminar las plantas.');
        }

        $this->redirect_with_flash('ileben-api', 'success', 'Se eliminaron todas las plantas correctamente.');
    }

    public function handle_import_csv()
    {
        $this->guard_permission();
        check_admin_referer('ileben_api_import_csv');

        if (empty($_FILES['csv_file']) || ! is_array($_FILES['csv_file'])) {
            $this->redirect_with_flash('ileben-api-import', 'error', 'No se recibio el archivo CSV.');
        }

        $file = $_FILES['csv_file'];
        if ((int) ($file['error'] ?? 1) !== 0) {
            $this->redirect_with_flash('ileben-api-import', 'error', 'Error al subir el archivo.');
        }

        $tmp_name = $file['tmp_name'] ?? '';
        if (! $tmp_name || ! is_uploaded_file($tmp_name)) {
            $this->redirect_with_flash('ileben-api-import', 'error', 'Archivo no valido.');
        }

        $csv = fopen($tmp_name, 'r');
        if (! $csv) {
            $this->redirect_with_flash('ileben-api-import', 'error', 'No se pudo abrir el archivo CSV.');
        }

        $headers = fgetcsv($csv);
        if (! $headers) {
            fclose($csv);
            $this->redirect_with_flash('ileben-api-import', 'error', 'CSV vacio o sin cabeceras.');
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

            if (empty($item['foto_portada']) && ! empty($item['foto'])) {
                $item['foto_portada'] = trim((string) $item['foto']);
            }

            if (empty($item['foto_portada']) && ! empty($item['fotos'])) {
                $item['foto_portada'] = trim((string) $item['fotos']);
            }

            if (empty($item['brochure']) && ! empty($item['brochure_url'])) {
                $item['brochure'] = trim((string) $item['brochure_url']);
            }

            if (empty($item['precio_final']) && ! empty($item['precio_lista'])) {
                $item['precio_final'] = trim((string) $item['precio_lista']);
            }

            if (empty($item['precio_lista']) && ! empty($item['precio'])) {
                $item['precio_lista'] = trim((string) $item['precio']);
            }

            if (empty($item['precio_final']) && ! empty($item['precio'])) {
                $item['precio_final'] = trim((string) $item['precio']);
            }

            if (empty($item['precio_base']) && ! empty($item['precio'])) {
                $item['precio_base'] = trim((string) $item['precio']);
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

        $this->redirect_with_flash('ileben-api-import', 'success', $message);
    }

    public function handle_download_csv_sample()
    {
        $this->guard_permission();
        check_admin_referer('ileben_api_download_csv_sample');

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="ileben-api-ejemplo.csv"');

        $out = fopen('php://output', 'w');
        if (! $out) {
            wp_die('No se pudo generar el CSV de ejemplo.');
        }

        fputcsv($out, array('external_id', 'nombre', 'descripcion', 'precio_base', 'precio_lista', 'precio_final', 'banos', 'dormitorios', 'metros_cuadrados', 'tipologia', 'planta_label', 'orientacion', 'superficie_interior', 'terraza_m2', 'superficie_total', 'foto_portada', 'foto_interior', 'brochure', 'cotizacion_url', 'estado'));
        fputcsv($out, array('APT-001', 'Departamento Norte', 'Planta con vista al jardin', '118000', '119500', '120000', '1', '1', '44.78', '1 dormitorio + 1 bano', 'A: 501 al 701', 'Poniente', '41.00', '3.78', '44.78', 'https://example.com/portada.jpg', 'https://example.com/interior.jpg', 'https://example.com/brochure.pdf', 'https://example.com/cotiza', 'disponible'));

        fclose($out);
        exit;
    }

    public function handle_sync_site_config()
    {
        $this->guard_permission();
        check_admin_referer('ileben_api_sync_site_config');

        $config = $this->api_client->fetch_site_config();

        if (is_wp_error($config)) {
            $this->redirect_with_flash('ileben-api-sync', 'error', 'Error al obtener configuracion del sitio: ' . $config->get_error_message());
        }

        $this->api_client->save_site_config($config);
        $this->redirect_with_flash('ileben-api-sync', 'success', 'Configuracion del sitio sincronizada correctamente.');
    }

    public function handle_sync_api()
    {
        $this->guard_permission();
        check_admin_referer('ileben_api_sync_api');

        // Respect currently selected proyecto_id from sync page.
        $posted_proyecto_id = sanitize_text_field(wp_unslash($_POST['proyecto_id'] ?? ''));
        if ($posted_proyecto_id !== '') {
            $settings = $this->api_client->get_settings();
            $settings['proyecto_id'] = $posted_proyecto_id;
            $this->api_client->save_settings($settings);
        }

        $this->log_sync('=== SINCRONIZACION INICIADA ===');

        $report = $this->run_sync();

        $this->log_sync(sprintf('Reporte final: insertados=%d, actualizados=%d, errores=%d', $report['inserted'], $report['updated'], $report['errors']));

        if (isset($report['error'])) {
            $this->log_sync('ERROR: ' . $report['error']);
            $this->redirect_with_flash('ileben-api-sync', 'error', $report['error']);
        }

        $message = sprintf(
            'Sincronizacion completada. Insertados: %d, Actualizados: %d, Errores: %d.',
            (int) $report['inserted'],
            (int) $report['updated'],
            (int) $report['errors']
        );

        $this->redirect_with_flash('ileben-api-sync', 'success', $message);
    }

    public function process_contact_retry($id, $override_channel = null, $override_fields = null)
    {
        $id = (int) $id;
        if (! $id) {
            return array('success' => false, 'status' => 'error', 'status_code' => 0, 'message' => 'ID de envio invalido.');
        }

        $log_item = $this->repository->find_contact_sync_log($id);
        if (! is_array($log_item) || empty($log_item)) {
            return array('success' => false, 'status' => 'error', 'status_code' => 0, 'message' => 'No se encontro el envio a reintentar.');
        }

        $payload = json_decode((string) ($log_item['payload_json'] ?? '{}'), true);
        if (! is_array($payload)) {
            $payload = array();
        }

        $channel = sanitize_text_field((string) ($payload['channel'] ?? $log_item['channel'] ?? ''));
        if ($override_channel !== null && trim((string) $override_channel) !== '') {
            $channel = sanitize_text_field((string) $override_channel);
        }

        $fields = isset($payload['fields']) && is_array($payload['fields']) ? $payload['fields'] : array();
        if (is_array($override_fields) && ! empty($override_fields)) {
            foreach ($override_fields as $fk => $fv) {
                $clean_key = sanitize_key((string) $fk);
                if ($clean_key !== '') {
                    $fields[$clean_key] = sanitize_text_field((string) $fv);
                }
            }
        }

        $turnstile_token = sanitize_text_field((string) ($payload['turnstile_token'] ?? ''));

        if ($channel === '' || empty($fields)) {
            error_log('[ileben-api] Retry ' . $id . ': Payload insuficiente. Channel: ' . $channel . ', Fields: ' . wp_json_encode($fields));
            return array('success' => false, 'status' => 'error', 'status_code' => 0, 'message' => 'El payload guardado no tiene datos suficientes para reintentar.');
        }

        error_log('[ileben-api] Retry ' . $id . ': Reenviando con ' . count($fields) . ' campos. Channel: ' . $channel);
        $this->repository->increment_contact_sync_retry($id);
        $result = $this->api_client->submit_contact_submission($channel, $fields, $turnstile_token);

        $status = 'failed';
        if (! empty($result['success'])) {
            $status = 'sent';
        } elseif ((int) ($result['status_code'] ?? 0) === 422) {
            $status = 'validation_error';
        } elseif ((int) ($result['status_code'] ?? 0) === 429) {
            $status = 'rate_limited';
        }

        $error_message = $this->build_error_message_from_result($result);

        // Guardar payload_json y channel actualizado para persistir cambios
        $payload['channel'] = $channel;
        $payload['fields'] = $fields;

        $this->repository->save_contact_sync_log(array(
            'id' => $id,
            'channel' => $channel,
            'payload_json' => wp_json_encode($payload),
            'status' => $status,
            'response_code' => (int) ($result['status_code'] ?? 0),
            'response_body' => (string) ($result['raw_body'] ?? ''),
            'error_message' => $error_message,
            'remote_submission_id' => (int) (($result['data']['id'] ?? 0)),
        ));

        error_log('[ileben-api] Retry ' . $id . ': Status=' . $status . ', Code=' . (int) ($result['status_code'] ?? 0));

        return array(
            'success' => $status === 'sent',
            'status' => $status,
            'status_code' => (int) ($result['status_code'] ?? 0),
            'message' => $status === 'sent' ? 'Reintento enviado correctamente.' : sanitize_text_field((string) ($result['message'] ?? 'Error desconocido.')),
            'error_message' => $error_message,
        );
    }

    public function handle_retry_contact_sync()
    {
        $this->guard_permission();

        $id = (int) ($_POST['id'] ?? 0);
        check_admin_referer('ileben_api_retry_contact_sync');

        if (! $id) {
            $this->redirect_with_flash('ileben-api-contact-sync', 'error', 'ID de envio invalido.');
        }

        $override_channel = isset($_POST['channel']) ? sanitize_text_field(wp_unslash($_POST['channel'])) : null;

        // Procesar campos editados del modal (field_* POST vars)
        $override_fields = array();
        foreach ($_POST as $post_key => $post_value) {
            if (strpos($post_key, 'field_') === 0) {
                $field_name = substr($post_key, 6); // Quitar prefijo "field_"
                $field_name = sanitize_key($field_name);
                if ($field_name !== '') {
                    $override_fields[$field_name] = sanitize_text_field((string) $post_value);
                }
            }
        }

        $res = $this->process_contact_retry($id, $override_channel, $override_fields);

        if (! empty($res['success'])) {
            $this->redirect_with_flash('ileben-api-contact-sync', 'success', 'Reintento enviado correctamente.');
        }

        $this->redirect_with_flash('ileben-api-contact-sync', 'error', 'El reintento no pudo completarse: ' . ($res['message'] ?? 'Error desconocido.'));
    }

    public function ajax_bulk_update_contact_channel()
    {
        check_ajax_referer('ileben_api_bulk_update_channel', 'nonce');
        $this->guard_permission();

        $channel = isset($_POST['channel']) ? sanitize_text_field(wp_unslash($_POST['channel'])) : '';
        if ($channel === '') {
            wp_send_json_error(array('message' => 'El canal no puede estar vacío.'), 400);
        }

        $apply_all = ! empty($_POST['apply_all']);
        $ids = array();

        if ($apply_all) {
            $filters = array(
                'status' => sanitize_text_field((string) ($_POST['status'] ?? '')),
                'channel' => sanitize_text_field((string) ($_POST['current_channel'] ?? '')),
                'email' => sanitize_text_field((string) ($_POST['email'] ?? '')),
                'date_from' => sanitize_text_field((string) ($_POST['date_from'] ?? '')),
                'date_to' => sanitize_text_field((string) ($_POST['date_to'] ?? '')),
            );
            $ids = $this->repository->get_contact_ids_by_filters($filters);
        } else {
            $raw_ids = isset($_POST['ids']) ? (array) $_POST['ids'] : array();
            $ids = array_filter(array_map('intval', $raw_ids), function ($id) {
                return $id > 0;
            });
        }

        if (empty($ids)) {
            wp_send_json_error(array('message' => 'No se seleccionó ningún contacto válido.'), 400);
        }

        $updated = $this->repository->bulk_update_contact_channel($ids, $channel);

        wp_send_json_success(array(
            'message' => sprintf('Se actualizó el canal a "%s" en %d contacto(s).', $channel, $updated),
            'updated_count' => $updated,
            'ids' => array_values($ids),
            'channel' => $channel,
        ));
    }

    public function ajax_batch_resync_contacts()
    {
        check_ajax_referer('ileben_api_batch_resync', 'nonce');
        $this->guard_permission();

        $raw_ids = isset($_POST['ids']) ? (array) $_POST['ids'] : array();
        $ids = array_filter(array_map('intval', $raw_ids), function ($id) {
            return $id > 0;
        });

        if (empty($ids)) {
            wp_send_json_error(array('message' => 'No se enviaron IDs válidos.'), 400);
        }

        $results = array();
        foreach ($ids as $id) {
            $res = $this->process_contact_retry($id);
            $results[] = array(
                'id' => $id,
                'success' => ! empty($res['success']),
                'status' => $res['status'] ?? 'failed',
                'status_code' => $res['status_code'] ?? 0,
                'message' => $res['message'] ?? '',
                'error_message' => $res['error_message'] ?? '',
            );
        }

        wp_send_json_success(array(
            'results' => $results,
        ));
    }

    public function ajax_get_filtered_contact_ids()
    {
        check_ajax_referer('ileben_api_get_contact_ids', 'nonce');
        $this->guard_permission();

        $filters = array(
            'status' => sanitize_text_field((string) ($_POST['status'] ?? '')),
            'channel' => sanitize_text_field((string) ($_POST['channel'] ?? '')),
            'email' => sanitize_text_field((string) ($_POST['email'] ?? '')),
            'date_from' => sanitize_text_field((string) ($_POST['date_from'] ?? '')),
            'date_to' => sanitize_text_field((string) ($_POST['date_to'] ?? '')),
        );

        $ids = $this->repository->get_contact_ids_by_filters($filters);

        wp_send_json_success(array(
            'ids' => array_values($ids),
            'total' => count($ids),
        ));
    }


    private function build_error_message_from_result($result)
    {
        $base = sanitize_text_field((string) ($result['message'] ?? ''));
        $errors = isset($result['errors']) && is_array($result['errors']) ? $result['errors'] : array();

        if (empty($errors)) {
            return $base;
        }

        $field_errors = array();
        foreach ($errors as $field => $messages) {
            $field = sanitize_text_field((string) $field);
            if ($field === '') {
                continue;
            }

            $clean_messages = array();
            if (is_array($messages)) {
                foreach ($messages as $msg) {
                    $msg = sanitize_text_field((string) $msg);
                    if ($msg !== '') {
                        $clean_messages[] = $msg;
                    }
                }
            } else {
                $clean_messages[] = sanitize_text_field((string) $messages);
            }

            if (! empty($clean_messages)) {
                $field_errors[$field] = $clean_messages;
            }
        }

        return wp_json_encode(array('message' => $base, 'errors' => $field_errors));
    }

    public function handle_save_settings()
    {
        $this->guard_permission();
        check_admin_referer('ileben_api_save_settings');

        $payload = array(
            'api_endpoint' => esc_url_raw(wp_unslash($_POST['api_endpoint'] ?? '')),
            'api_token' => sanitize_text_field(wp_unslash($_POST['api_token'] ?? '')),
            'proyecto_id' => sanitize_text_field(wp_unslash($_POST['proyecto_id'] ?? '')),
            'cotiza_url' => esc_url_raw(wp_unslash($_POST['cotiza_url'] ?? '')),
            'price_display_mode' => sanitize_key(wp_unslash($_POST['price_display_mode'] ?? 'base')),
            'timeout' => (int) ($_POST['timeout'] ?? 15),
            'cron_enabled' => ! empty($_POST['cron_enabled']) ? 1 : 0,
            'cron_interval_hours' => (int) ($_POST['cron_interval_hours'] ?? 1),
            'show_cover_image' => ! empty($_POST['show_cover_image']) ? 1 : 0,
            'use_api_favicon' => ! empty($_POST['use_api_favicon']) ? 1 : 0,
        );

        if (empty($payload['api_endpoint'])) {
            $this->redirect_with_flash('ileben-api-sync', 'error', 'Debes ingresar el Endpoint API.');
        }

        $this->api_client->save_settings($payload);
        $this->api_client->schedule_cron();

        $this->redirect_with_flash('ileben-api-sync', 'success', 'Configuracion guardada correctamente.');
    }

    public function handle_toggle_favicon()
    {
        $this->guard_permission();
        check_admin_referer('ileben_api_toggle_favicon');

        $settings = $this->api_client->get_settings();
        $settings['use_api_favicon'] = ! empty($_POST['use_api_favicon']) ? 1 : 0;

        $this->api_client->save_settings($settings);

        $this->redirect_with_flash(
            'ileben-api-sync',
            'success',
            ! empty($settings['use_api_favicon'])
                ? 'Favicon de la API activado.'
                : 'Favicon de la API desactivado.'
        );
    }



    public function run_sync()
    {
        $settings = $this->api_client->get_settings();
        $selected_proyecto_id = sanitize_text_field((string) ($settings['proyecto_id'] ?? ''));
        $is_project_scoped_sync = $selected_proyecto_id !== '';

        $this->log_sync('Proyecto ID efectivo para sync: ' . ($selected_proyecto_id !== '' ? $selected_proyecto_id : '[VACIO]'));

        $this->log_sync('Sincronizando configuracion del sitio...');
        $site_config = $this->api_client->fetch_site_config();
        if (! is_wp_error($site_config) && is_array($site_config)) {
            $this->api_client->save_site_config($site_config);
            $this->log_sync('Configuracion del sitio guardada correctamente.');
        } else {
            $err = is_wp_error($site_config) ? $site_config->get_error_message() : 'Respuesta no valida';
            $this->log_sync('AVISO: No se pudo obtener configuracion del sitio: ' . $err);
        }

        $this->log_sync('Obteniendo plantas desde API...');
        $items = $this->api_client->fetch_plants();

        if (is_wp_error($items)) {
            $error_msg = $items->get_error_message();
            $this->log_sync('ERROR WP: ' . $error_msg);
            return array(
                'error' => $error_msg,
                'inserted' => 0,
                'updated' => 0,
                'errors' => 0,
            );
        }

        if (! is_array($items)) {
            $this->log_sync('ERROR: Respuesta no es un array. Tipo: ' . gettype($items));
            return array(
                'error' => 'Respuesta de API invalida.',
                'inserted' => 0,
                'updated' => 0,
                'errors' => 0,
            );
        }

        $this->log_sync('Total de plantas obtenidas: ' . count($items));

        $inserted = 0;
        $updated = 0;
        $errors = 0;
        $synced_external_ids = array();

        foreach ($items as $idx => $item) {
            $payload = $this->api_client->map_api_item($item);

            if (empty($payload['external_id']) || empty($payload['nombre'])) {
                $this->log_sync('Item ' . $idx . ': IGNORADO (external_id o nombre vacio)');
                $errors++;
                continue;
            }

            $this->log_sync('Item ' . $idx . ': external_id=' . $payload['external_id'] . ', nombre=' . $payload['nombre']);

            $existing_id = $this->repository->find_id_by_external_id($payload['external_id']);
            $result = $this->repository->upsert_by_external_id($payload);

            if (! $result) {
                $this->log_sync('Item ' . $idx . ': ERROR al guardar en BD');
                $errors++;
                continue;
            }

            if ($existing_id) {
                $this->log_sync('Item ' . $idx . ': ACTUALIZADO (ID BD: ' . $existing_id . ')');
                $updated++;
            } else {
                $this->log_sync('Item ' . $idx . ': INSERTADO');
                $inserted++;
            }

            $synced_external_ids[] = (string) $payload['external_id'];
        }

        if ($is_project_scoped_sync) {
            $deleted = 0;
            if (empty($synced_external_ids)) {
                $deleted = (int) $this->repository->delete_all();
            } else {
                $deleted = (int) $this->repository->delete_not_in_external_ids($synced_external_ids);
            }
            $this->log_sync('Depuracion por proyecto aplicada. Eliminadas fuera del proyecto: ' . $deleted);
        }

        $this->log_sync('Resumen: insertados=' . $inserted . ', actualizados=' . $updated . ', errores=' . $errors);

        return array(
            'inserted' => $inserted,
            'updated' => $updated,
            'errors' => $errors,
        );
    }

    private function log_sync($message)
    {
        if (!WP_DEBUG_LOG) {
            return;
        }

        $log_dir = ILEBEN_API_PATH . 'logs';
        if (! file_exists($log_dir)) {
            @mkdir($log_dir, 0755, true);
        }
        /** @disregard gmdate */
        $log_file = $log_dir . DIRECTORY_SEPARATOR . 'sync-' . gmdate('Y-m-d') . '.log';
        /** @disregard gmdate */
        $timestamp = gmdate('Y-m-d H:i:s');
        $line = "[{$timestamp}] {$message}\n";

        @file_put_contents($log_file, $line, FILE_APPEND | LOCK_EX);
    }

    private function render_pagination($current_page, $total_pages)
    {
        if ($total_pages <= 1) {
            return;
        }

        ob_start();
    ?>
        <nav class="mt-4">
            <ul class="pagination">
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <?php
                    $url = add_query_arg('paged', $i);
                    $active = $i === (int) $current_page ? ' active' : '';
                    ?>
                    <li class="page-item<?php echo esc_attr($active); ?>">
                        <a class="page-link" href="<?php echo esc_url($url); ?>"><?php echo (int) $i; ?></a>
                    </li>
                <?php endfor; ?>
            </ul>
        </nav>
    <?php
        echo ob_get_clean();
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

        ob_start();
    ?>
        <div class="alert <?php echo esc_attr($classes); ?>" role="alert"><?php echo esc_html($message); ?></div>
<?php
        echo ob_get_clean();
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
        if (! current_user_can(ILEBEN_API_CAPABILITY)) {
            wp_die('No tienes permisos para acceder a esta seccion.');
        }
    }
}
