<?php

if (! defined('ABSPATH')) {
    exit;
}

class Ileben_Api_Shortcode
{
    private $repository;
    private static $instance_counter = 0;
    private $footer_site_config_rendered = false;

    public function __construct($repository)
    {
        $this->repository = $repository;
    }

    public function register()
    {
        add_shortcode('ileben_plantas', array($this, 'render'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('wp_head', array($this, 'render_head_site_config_assets'), 100);
        add_action('wp_footer', array($this, 'render_footer_site_config_style'), 100);
        add_action('wp_ajax_ileben_api_filter_plantas', array($this, 'handle_filter_plantas_ajax'));
        add_action('wp_ajax_nopriv_ileben_api_filter_plantas', array($this, 'handle_filter_plantas_ajax'));
    }

    public function enqueue_assets()
    {
        // if (! is_singular()) {
        //     return;
        // }

        // global $post;
        // if (! $post || ! has_shortcode((string) $post->post_content, 'ileben_plantas')) {
        //     return;
        // }

        wp_enqueue_style(
            'ileben-api-bootstrap',
            'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css',
            array(),
            '5.3.3'
        );

        wp_enqueue_style(
            'ileben-api-fontawesome',
            'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.1/css/all.min.css',
            array(),
            '7.0.1'
        );

        wp_enqueue_style(
            'ileben-api-select2',
            'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css',
            array(),
            '4.1.0-rc.0'
        );

        $public_css_path = ILEBEN_API_PATH . 'assets/css/public.css';
        $public_js_path = ILEBEN_API_PATH . 'assets/js/public.js';
        $public_css_version = file_exists($public_css_path) ? (string) filemtime($public_css_path) : ILEBEN_API_VERSION;
        $public_js_version = file_exists($public_js_path) ? (string) filemtime($public_js_path) : ILEBEN_API_VERSION;

        wp_enqueue_style(
            'ileben-api-public',
            ILEBEN_API_URL . 'assets/css/public.css',
            array('ileben-api-bootstrap', 'ileben-api-fontawesome', 'ileben-api-select2'),
            $public_css_version
        );

        wp_enqueue_script(
            'ileben-api-select2',
            'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js',
            array('jquery'),
            '4.1.0-rc.0',
            true
        );

        wp_enqueue_script(
            'ileben-api-bootstrap',
            'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js',
            array(),
            '5.3.3',
            true
        );

        wp_enqueue_script(
            'ileben-api-public',
            ILEBEN_API_URL . 'assets/js/public.js',
            array('jquery', 'ileben-api-bootstrap', 'ileben-api-select2'),
            $public_js_version,
            true
        );

        // Mantener la variable legacy en el CSS principal para no romper estilos existentes.
        $css_variables = $this->get_site_config_css_variables();
        if (! empty($css_variables['--leben-brand-color'])) {
            wp_add_inline_style('ileben-api-public', ':root { --leben-brand-color: ' . $css_variables['--leben-brand-color'] . '; }');
        }

        $google_fonts_stylesheet = $this->get_google_fonts_stylesheet();
        if ($google_fonts_stylesheet !== '') {
            wp_enqueue_style('ileben-api-google-fonts', $google_fonts_stylesheet, array(), null);
        }
    }

    public function render_footer_site_config_style()
    {
        if ($this->footer_site_config_rendered || is_admin()) {
            return;
        }

        $css_variables = $this->get_site_config_css_variables();
        if (empty($css_variables)) {
            return;
        }

        $declarations = array();
        foreach (array('--primary', '--font-family', '--font-family-title', '--leben-brand-color') as $property) {
            if (empty($css_variables[$property])) {
                continue;
            }

            $declarations[] = $property . ': ' . $css_variables[$property];
        }

        if (empty($declarations)) {
            return;
        }

        $this->footer_site_config_rendered = true;

        echo '<style id="ileben-api-site-config-vars">:root { ' . esc_html(implode('; ', $declarations)) . '; }</style>';
    }

    public function render_head_site_config_assets()
    {
        if (is_admin()) {
            return;
        }

        if (! $this->should_use_api_favicon()) {
            return;
        }

        $favicon_url = $this->get_site_config_asset_url('favicon');
        if ($favicon_url === '') {
            return;
        }

        echo '<link rel="icon" href="' . esc_url($favicon_url) . '" />';
    }

    private function get_site_config_css_variables()
    {
        if (! class_exists('Ileben_Api_Client')) {
            return array();
        }

        $api_client = new Ileben_Api_Client();
        $site_config = $api_client->get_site_config();

        if (! is_array($site_config)) {
            return array();
        }

        $variables = array();

        $brand_color = sanitize_hex_color((string) ($site_config['brand_color'] ?? ''));
        if (! empty($brand_color)) {
            $variables['--primary'] = $brand_color;
            $variables['--leben-brand-color'] = $brand_color;
        }

        $font_family = $this->sanitize_css_font_family((string) ($site_config['font_family_body'] ?? ''));
        if ($font_family !== '') {
            $variables['--font-family'] = $font_family;
        }

        $font_family_heading = $this->sanitize_css_font_family((string) ($site_config['font_family_heading'] ?? ''));
        if ($font_family_heading !== '') {
            $variables['--font-family-title'] = $font_family_heading;
        }

        foreach (
            array(
                'logo' => '--logo-url',
                'logo_dark' => '--logo-dark-url',
                'logo_sale' => '--logo-sale-url',
                'favicon' => '--favicon-url',
            ) as $config_key => $property
        ) {
            $asset_url = $this->get_site_config_asset_url($config_key, $site_config);
            if ($asset_url !== '') {
                $variables[$property] = 'url("' . $asset_url . '")';
            }
        }

        return $variables;
    }

    private function get_site_config_asset_url($key, $site_config = null)
    {
        if (! is_array($site_config)) {
            if (! class_exists('Ileben_Api_Client')) {
                return '';
            }

            $api_client = new Ileben_Api_Client();
            $site_config = $api_client->get_site_config();
        }

        if (! is_array($site_config)) {
            return '';
        }

        return esc_url_raw((string) ($site_config[$key] ?? ''));
    }

    private function get_google_fonts_stylesheet()
    {
        if (! class_exists('Ileben_Api_Client')) {
            return '';
        }

        $api_client = new Ileben_Api_Client();
        $site_config = $api_client->get_site_config();

        if (! is_array($site_config)) {
            return '';
        }

        $stylesheet = esc_url_raw((string) ($site_config['google_fonts_stylesheet'] ?? ''));
        if ($stylesheet === '') {
            return '';
        }

        $parts = wp_parse_url($stylesheet);
        if (! is_array($parts)) {
            return '';
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if ($scheme !== 'https' || $host !== 'fonts.googleapis.com') {
            return '';
        }

        return $stylesheet;
    }

    private function should_use_api_favicon()
    {
        if (! class_exists('Ileben_Api_Client')) {
            return false;
        }

        $api_client = new Ileben_Api_Client();
        $settings = $api_client->get_settings();

        return ! empty($settings['use_api_favicon']);
    }

    private function sanitize_css_font_family($font_family)
    {
        $font_family = trim(wp_strip_all_tags($font_family));
        if ($font_family === '') {
            return '';
        }

        return (string) preg_replace('/[^A-Za-z0-9,\-\s\"\']+/', '', $font_family);
    }

    public function render($atts)
    {
        self::$instance_counter++;
        $instance = 'ileben-showcase-' . self::$instance_counter;

        $atts = shortcode_atts(
            array(
                'por_pagina' => 100,
                'orderby' => '',
                'mostrar_filtros' => '1'
            ),
            $atts,
            'ileben_plantas'
        );

        $filters = array(
            'estado' => sanitize_text_field($_GET['ip_estado'] ?? ''),
            'tipologia' => sanitize_text_field($_GET['ip_tipologia'] ?? ''),
            'tipo_producto' => sanitize_text_field($_GET['ip_tipo_producto'] ?? ''),
            'planta_label' => sanitize_text_field($_GET['ip_planta'] ?? ''),
            'piso' => sanitize_text_field($_GET['ip_piso'] ?? ''),
            'orderby' => sanitize_text_field($_GET['ip_orderby'] ?? $atts['orderby']),
        );

        $per_page = max(1, (int) $atts['por_pagina']);
        $result = $this->repository->query($filters, 1, $per_page);

        $items = is_array($result['items']) ? $result['items'] : array();
        $tipologias = array();
        $tipos_producto = array();
        $plantas = array();
        $pisos = array();
        $total_plantas = isset($result['total']) ? (int) $result['total'] : count($items);

        // Build filter options from the full set, not only the paginated current items.
        $option_filters = array(
            'estado' => $filters['estado'] ?? '',
            'orderby' => $filters['orderby'] ?? '',
            'tipo_producto' => $filters['tipo_producto'] ?? '',
        );
        $option_count_result = $this->repository->query($option_filters, 1, 1);
        $option_total = isset($option_count_result['total']) ? (int) $option_count_result['total'] : count($items);
        $option_per_page = max(1, min(5000, $option_total));
        $option_result = $this->repository->query($option_filters, 1, $option_per_page);
        $option_items = is_array($option_result['items']) ? $option_result['items'] : $items;

        foreach ($option_items as $item) {
            $tipologia = trim((string) ($item['tipologia'] ?? ''));
            $tipo_producto = trim((string) ($item['tipo_producto'] ?? ''));
            $planta = trim((string) ($item['planta_label'] ?? ''));
            $piso = $this->infer_piso_from_planta_label($planta);

            if ($tipologia !== '') {
                $tipologias[$tipologia] = $tipologia;
            }
            if ($tipo_producto !== '') {
                $tipos_producto[$tipo_producto] = $tipo_producto;
            }
            if ($planta !== '') {
                $plantas[$planta] = $planta;
            }
            if ($piso !== '') {
                $pisos[$piso] = $piso;
            }
        }

        natcasesort($tipologias);
        natcasesort($tipos_producto);
        natcasesort($plantas);
        natcasesort($pisos);

        if (($filters['piso'] ?? '') !== '') {
            $selected_piso = (string) $filters['piso'];
            $items = array_values(array_filter($items, function ($item) use ($selected_piso) {
                $planta = (string) ($item['planta_label'] ?? '');
                return $this->infer_piso_from_planta_label($planta) === $selected_piso;
            }));
        }

        $default_cotiza_url = $this->get_default_cotiza_url();
        $show_cover_image = $this->should_show_cover_image();
        $items_payload = array();
        foreach ($items as $item) {
            $items_payload[] = $this->map_showcase_item($item, $default_cotiza_url, $show_cover_image);
        }
        $json_payload = wp_json_encode(array_values($items_payload));

        ob_start();
?>
        <section class="ileben-showcase" id="<?php echo esc_attr($instance); ?>"
            data-ajax-url="<?php echo esc_url(admin_url('admin-ajax.php')); ?>"
            data-ajax-nonce="<?php echo esc_attr(wp_create_nonce('ileben_api_filter_plantas')); ?>"
            data-ajax-per-page="100"
            data-orderby="<?php echo esc_attr((string) ($filters['orderby'] ?? '')); ?>"
            data-estado="<?php echo esc_attr((string) ($filters['estado'] ?? '')); ?>">
            <div class="ileben-filter-loader" aria-hidden="true">
                <i class="fa-solid fa-spinner fa-spin-pulse"></i>
                <span>Filtrando...</span>
            </div>
            <?php if (empty($items_payload)) : ?>
                <div class="alert alert-light border">No se encontraron plantas disponibles.</div>
            <?php else : ?>
                <?php if ($atts['mostrar_filtros'] === '1') : ?>
                    <div class="ileben-top-filters row mb-4">
                        <div class="ileben-filter-title col-md-5 col-12">Selecciona filtro</div>
                        <div class="filtros d-flex flex-wrap gap-2 col-md-7 col-12">
                            <select class="ileben-filter-select" multiple data-filter="tipologia">
                                <option value="">Todas las tipologias</option>
                                <?php foreach ($tipologias as $tipologia) : ?>
                                    <option value="<?php echo esc_attr($tipologia); ?>"><?php echo esc_html($tipologia); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select class="ileben-filter-select" multiple data-filter="tipo_producto">
                                <option value="">Todos los tipos de producto</option>
                                <?php foreach ($tipos_producto as $tipo_producto) : ?>
                                    <option value="<?php echo esc_attr($tipo_producto); ?>"><?php echo esc_html($tipo_producto); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select class="ileben-filter-select" multiple data-filter="piso">
                                <option value="">Todos los pisos</option>
                                <?php foreach ($pisos as $piso) : ?>
                                    <option value="<?php echo esc_attr($piso); ?>" <?php selected((string) ($filters['piso'] ?? ''), (string) $piso); ?>>Piso <?php echo esc_html($piso); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select class="ileben-filter-select" data-filter="planta_label">
                                <option value="">Todas las plantas</option>
                                <?php foreach ($plantas as $planta) : ?>
                                    <option value="<?php echo esc_attr($planta); ?>"><?php echo esc_html($planta); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="d-flex flex-wrap justify-content-end gap-3 mb-4">
                        <button type="button" class="btn btn-secondary" data-action="filter"><i class="fa-solid fa-filter"></i> Filtrar</button>
                        <button type="button" class="btn btn-secondary" data-action="reset"><i class="fa-solid fa-rotate-left"></i> Borrar filtros</button>
                    </div>
                <?php endif; ?>

                <div class="ileben-main-grid row">
                    <div class="ileben-carousel-col col-6">
                        <div id="<?php echo esc_attr($instance); ?>-carousel" class="carousel slide" data-bs-ride="false">
                            <div class="carousel-inner"></div>
                            <button class="carousel-control-prev" type="button" data-bs-target="#<?php echo esc_attr($instance); ?>-carousel" data-bs-slide="prev"><i class="fa-solid fa-angle-left"></i></button>
                            <button class="carousel-control-next" type="button" data-bs-target="#<?php echo esc_attr($instance); ?>-carousel" data-bs-slide="next"><i class="fa-solid fa-angle-right"></i></button>
                        </div>
                        <!-- <div class="carousel-indicators position-static mt-3" id="<?php echo esc_attr($instance); ?>-indicators"></div> -->
                    </div>

                    <div class="ileben-details-col col-6 d-flex align-items-stretch p-lg">
                        <p class="ileben-detail-desc" data-field="descripcion"></p>
                        <div class="ileben-details-grid row">
                            <div class="col-6"><span class="ileben-k"><i class="fa-regular fa-building"></i> Planta</span>
                                <span class="ileben-v" data-field="nombre"></span>
                            </div>
                            <div class="col-6"><span class="ileben-k"><i class="fa-solid fa-ruler"></i> Superficie útil</span>
                                <span class="ileben-v" data-field="superficie_util"></span>
                            </div>
                            <hr class="ileben-divider">
                            <div class="col-6"><span class="ileben-k"><i class="fa-solid fa-restroom"></i> Dorm + Baño</span>
                                <span class="ileben-v" data-field="dorm_bano"></span>
                            </div>
                            <div class="col-6"><span class="ileben-k"><i class="fa-solid fa-umbrella-beach"></i> Terraza</span>
                                <span class="ileben-v" data-field="terraza_m2"></span>
                            </div>
                            <hr class="ileben-divider">
                            <div class="col-6"><span class="ileben-k"><i class="fa-solid fa-compass"></i> Orientación</span>
                                <span class="ileben-v" data-field="orientacion"></span>
                            </div>
                            <div class="col-6"><span class="ileben-k"><i class="fa-solid fa-ruler-combined"></i> Superficie total</span>
                                <span class="ileben-v" data-field="superficie_total"></span>
                            </div>
                            <hr class="ileben-divider">
                            <div class="col-6"><span class="ileben-k"><i class="fa-solid fa-dollar-sign"></i> Precios</span>
                                <span class="ileben-p" data-field="precio_base"></span>
                            </div>
                            <div class="col-6 text-end"><span class="ileben-k">&nbsp;</span>
                                <div class="btn-group" role="group" aria-label="Acciones">
                                    <a class="btn btn-primary" data-field="cotizar_btn" data-bs-toggle="tooltip" data-bs-title="Ir al Cotizador" href="#" target="_blank" rel="noopener">
                                        <i class="fa-solid fa-arrow-up-right-from-square"></i>
                                        Cotizar
                                    </a>
                                    <a class="btn btn-secondary" data-field="brochure_btn" data-bs-toggle="tooltip" data-bs-title="Descargar brochure" href="#" target="_blank" rel="noopener" download>
                                        <i class="fa-regular fa-file-lines"></i>
                                        Brochure
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <script type="application/json" id="<?php echo esc_attr($instance); ?>-data">
                    <?php echo $json_payload; ?>
                </script>
                <div class="ileben-lightbox" aria-hidden="true">
                    <button type="button" class="ileben-lightbox-close" data-lightbox-close aria-label="Cerrar">x</button>
                    <img src="" alt="Imagen interior" data-lightbox-image>
                </div>
            <?php endif; ?>
            <div class="d-flex justify-content-between">
                <small class="ileben-showcase-credit"><sup>*</sup> Superficie aproximada</small><br>
                <small class="ileben-showcase-credit">Total plantas <?php echo esc_html($total_plantas ?? ''); ?>, mostrando <span class="show_plantas"></span> plantas</small>
            </div>
        </section>
<?php
        return ob_get_clean();
    }

    private function map_showcase_item($item, $default_cotiza_url = '', $show_cover_image = true)
    {
        $fallback_image = ILEBEN_API_URL . 'assets/img/logo.png';
        $cover_image = $this->normalize_frontend_url((string) ($item['foto_portada'] ?? ''));
        $interior_image = $this->normalize_frontend_url((string) ($item['foto_interior'] ?? ''));
        $fallback_image = $this->normalize_frontend_url($fallback_image);

        if ($cover_image === '') {
            $cover_image = $fallback_image;
        }

        if ($interior_image === '') {
            $interior_image = $cover_image !== '' ? $cover_image : $fallback_image;
        }

        $image = $show_cover_image ? $cover_image : $interior_image;

        $dormitorios = (int) ($item['dormitorios'] ?? 0);
        $banos = (int) ($item['banos'] ?? 0);

        $cotizacion_url = esc_url_raw((string) ($item['cotizacion_url'] ?? ''));
        if ($cotizacion_url === '' && $default_cotiza_url !== '') {
            $external_id = (string) ($item['external_id'] ?? '');
            if ($external_id !== '') {
                $cotizacion_url = add_query_arg('id', $external_id, $default_cotiza_url);
            } else {
                $cotizacion_url = $default_cotiza_url;
            }
        }

        $cotizacion_url = $this->normalize_frontend_url($cotizacion_url);
        $brochure = $this->normalize_frontend_url((string) ($item['brochure'] ?? ''));

        return array(
            'id' => (int) ($item['id'] ?? 0),
            'name' => (string) ($item['nombre'] ?? ''),
            'nombre' => (string) ($item['nombre'] ?? ''),
            'descripcion' => (string) ($item['descripcion'] ?? ''),
            'tipologia' => (string) ($item['tipologia'] ?? ''),
            'tipo_producto' => (string) ($item['tipo_producto'] ?? ''),
            'product_code' => (string) ($item['planta_label'] ?? ''),
            'planta_label' => (string) ($item['planta_label'] ?? ''),
            'piso' => $this->infer_piso_from_planta_label((string) ($item['planta_label'] ?? '')),
            'orientacion' => (string) ($item['orientacion'] ?? ''),
            'superficie_util' => (float) ($item['superficie_interior'] ?? 0),
            'terraza_m2' => (float) ($item['terraza_m2'] ?? 0),
            'superficie_total' => (float) ($item['superficie_total'] ?? 0),
            'precio' => (float) ($item['precio'] ?? 0),
            'precio_base' => (float) ($item['precio_base'] ?? 0),
            'precio_lista' => (float) ($item['precio_lista'] ?? $item['precio'] ?? 0),
            'dorm_bano' => trim($dormitorios . ' dorm + ' . $banos . ' baño'),
            'imagen' => esc_url_raw($image),
            'imagen_interior' => esc_url_raw($interior_image),
            'imagen_fallback' => esc_url_raw($fallback_image),
            'brochure' => esc_url_raw($brochure),
            'cotizacion_url' => esc_url_raw($cotizacion_url),
        );
    }

    public function handle_filter_plantas_ajax()
    {
        check_ajax_referer('ileben_api_filter_plantas', 'nonce');

        $tipologias = $this->sanitize_text_array($_POST['tipologia'] ?? array());
        $tipos_producto = $this->sanitize_text_array($_POST['tipo_producto'] ?? array());
        $plantas = $this->sanitize_text_array($_POST['planta_label'] ?? array());
        $pisos = $this->sanitize_text_array($_POST['piso'] ?? array());

        $filters = array(
            'estado' => sanitize_text_field($_POST['estado'] ?? ''),
            'orderby' => sanitize_text_field($_POST['orderby'] ?? ''),
        );

        $page = (int) ($_POST['page'] ?? 1);
        $page = max(1, $page);

        $per_page = (int) ($_POST['per_page'] ?? 100);
        $per_page = max(1, min(5000, $per_page));

        // Fetch full base set (bounded) and apply multi-filters in PHP.
        $full_result = $this->repository->query($filters, 1, 5000);
        $items = is_array($full_result['items']) ? $full_result['items'] : array();

        if (! empty($tipologias)) {
            $items = array_values(array_filter($items, function ($item) use ($tipologias) {
                $tipologia = (string) ($item['tipologia'] ?? '');
                return in_array($tipologia, $tipologias, true);
            }));
        }

        if (! empty($tipos_producto)) {
            $items = array_values(array_filter($items, function ($item) use ($tipos_producto) {
                $tipo_producto = (string) ($item['tipo_producto'] ?? '');
                return in_array($tipo_producto, $tipos_producto, true);
            }));
        }

        if (! empty($plantas)) {
            $items = array_values(array_filter($items, function ($item) use ($plantas) {
                $planta = (string) ($item['planta_label'] ?? '');
                return in_array($planta, $plantas, true);
            }));
        }

        if (! empty($pisos)) {
            $items = array_values(array_filter($items, function ($item) use ($pisos) {
                $planta = (string) ($item['planta_label'] ?? '');
                $piso = $this->infer_piso_from_planta_label($planta);
                return in_array($piso, $pisos, true);
            }));
        }

        $total_filtered = count($items);
        $offset = ($page - 1) * $per_page;
        if ($offset < 0) {
            $offset = 0;
        }

        $paged_items = array_slice($items, $offset, $per_page);

        $default_cotiza_url = $this->get_default_cotiza_url();
        $show_cover_image = $this->should_show_cover_image();
        $items_payload = array();
        foreach ($paged_items as $item) {
            $items_payload[] = $this->map_showcase_item($item, $default_cotiza_url, $show_cover_image);
        }

        $pages = (int) ceil($total_filtered / $per_page);
        if ($pages < 1) {
            $pages = 1;
        }

        wp_send_json_success(array(
            'items' => array_values($items_payload),
            'total' => $total_filtered,
            'page' => $page,
            'pages' => $pages,
            'has_more' => $page < $pages,
        ));
    }

    private function sanitize_text_array($values)
    {
        if (! is_array($values)) {
            if ($values === null || $values === '') {
                return array();
            }

            $values = array($values);
        }

        $clean_values = array();
        foreach ($values as $value) {
            $clean = sanitize_text_field((string) $value);
            if ($clean !== '') {
                $clean_values[] = $clean;
            }
        }

        return array_values(array_unique($clean_values));
    }

    private function get_default_cotiza_url()
    {
        if (! class_exists('Ileben_Api_Client')) {
            return '';
        }

        $api_client = new Ileben_Api_Client();
        $settings = $api_client->get_settings();
        return esc_url_raw((string) ($settings['cotiza_url'] ?? ''));
    }

    private function should_show_cover_image()
    {
        if (! class_exists('Ileben_Api_Client')) {
            return true;
        }

        $api_client = new Ileben_Api_Client();
        $settings = $api_client->get_settings();
        return ! isset($settings['show_cover_image']) || ! empty($settings['show_cover_image']);
    }

    private function normalize_frontend_url($url)
    {
        $url = esc_url_raw((string) $url);
        if ($url === '') {
            return '';
        }

        if ((is_ssl() || (function_exists('wp_is_serving_https') && wp_is_serving_https())) && ! $this->is_local_url($url)) {
            $url = set_url_scheme($url, 'https');
        }

        return $url;
    }

    private function is_local_url($url)
    {
        $parts = wp_parse_url((string) $url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '') {
            return false;
        }

        if ($host === 'localhost' || $host === '127.0.0.1' || $host === '::1') {
            return true;
        }

        return substr($host, -6) === '.local';
    }

    private function infer_piso_from_planta_label($planta_label)
    {
        $planta_label = trim((string) $planta_label);
        if ($planta_label === '') {
            return '';
        }

        if (! preg_match('/(\d{1,4})/', $planta_label, $matches)) {
            return '';
        }

        $numero = (int) $matches[1];
        if ($numero <= 0) {
            return '';
        }

        if ($numero >= 100) {
            return (string) floor($numero / 100);
        }

        return (string) $numero;
    }
}
