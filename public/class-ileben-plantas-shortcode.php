<?php

if (! defined('ABSPATH')) {
    exit;
}

class Ileben_Plantas_Shortcode
{
    private $repository;
    private static $instance_counter = 0;

    public function __construct($repository)
    {
        $this->repository = $repository;
    }

    public function register()
    {
        add_shortcode('ileben_plantas', array($this, 'render'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));
    }

    public function enqueue_assets()
    {
        if (! is_singular()) {
            return;
        }

        global $post;
        if (! $post || ! has_shortcode((string) $post->post_content, 'ileben_plantas')) {
            return;
        }

        wp_enqueue_style(
            'ileben-plantas-bootstrap',
            'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css',
            array(),
            '5.3.3'
        );

        wp_enqueue_style(
            'ileben-plantas-public',
            ILEBEN_PLANTAS_URL . 'assets/css/public.css',
            array('ileben-plantas-bootstrap'),
            ILEBEN_PLANTAS_VERSION
        );

        wp_enqueue_script(
            'ileben-plantas-bootstrap',
            'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js',
            array(),
            '5.3.3',
            true
        );

        wp_enqueue_script(
            'ileben-plantas-public',
            ILEBEN_PLANTAS_URL . 'assets/js/public.js',
            array('ileben-plantas-bootstrap'),
            ILEBEN_PLANTAS_VERSION,
            true
        );
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
            'planta_label' => sanitize_text_field($_GET['ip_planta'] ?? ''),
            'orderby' => sanitize_text_field($_GET['ip_orderby'] ?? $atts['orderby']),
        );

        $per_page = max(1, (int) $atts['por_pagina']);
        $result = $this->repository->query($filters, 1, $per_page);

        $items = is_array($result['items']) ? $result['items'] : array();
        $tipologias = array();
        $plantas = array();

        foreach ($items as $item) {
            $tipologia = trim((string) ($item['tipologia'] ?? ''));
            $planta = trim((string) ($item['planta_label'] ?? ''));

            if ($tipologia !== '') {
                $tipologias[$tipologia] = $tipologia;
            }
            if ($planta !== '') {
                $plantas[$planta] = $planta;
            }
        }

        $default_cotiza_url = $this->get_default_cotiza_url();
        $items_payload = array();
        foreach ($items as $item) {
            $items_payload[] = $this->map_showcase_item($item, $default_cotiza_url);
        }
        $json_payload = wp_json_encode(array_values($items_payload));

        ob_start();
        ?>
        <section class="ileben-showcase" id="<?php echo esc_attr($instance); ?>">
            <?php if (empty($items_payload)) : ?>
                <div class="alert alert-light border">No se encontraron plantas disponibles.</div>
            <?php else : ?>
                <?php if ($atts['mostrar_filtros'] === '1') : ?>
                    <div class="ileben-top-filters">
                        <div class="ileben-filter-title">Selecciona una tipologia</div>
                        <select class="ileben-filter-select" data-filter="tipologia">
                            <option value="">Todas</option>
                            <?php foreach ($tipologias as $tipologia) : ?>
                                <option value="<?php echo esc_attr($tipologia); ?>"><?php echo esc_html($tipologia); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select class="ileben-filter-select" data-filter="planta_label">
                            <option value="">Todas las plantas</option>
                            <?php foreach ($plantas as $planta) : ?>
                                <option value="<?php echo esc_attr($planta); ?>"><?php echo esc_html($planta); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <div class="ileben-main-grid">
                    <div class="ileben-carousel-col">
                        <div id="<?php echo esc_attr($instance); ?>-carousel" class="carousel slide" data-bs-ride="false">
                            <div class="carousel-inner"></div>
                            <button class="carousel-control-prev" type="button" data-bs-target="#<?php echo esc_attr($instance); ?>-carousel" data-bs-slide="prev"><span class="carousel-control-prev-icon" aria-hidden="true"></span><span class="visually-hidden">Anterior</span></button>
                            <button class="carousel-control-next" type="button" data-bs-target="#<?php echo esc_attr($instance); ?>-carousel" data-bs-slide="next"><span class="carousel-control-next-icon" aria-hidden="true"></span><span class="visually-hidden">Siguiente</span></button>
                        </div>
                        <div class="carousel-indicators position-static mt-3" id="<?php echo esc_attr($instance); ?>-indicators"></div>
                    </div>

                    <div class="ileben-details-col">
                        <h3 class="ileben-detail-name" data-field="nombre"></h3>
                        <p class="ileben-detail-desc" data-field="descripcion"></p>
                        <div class="ileben-details-grid">
                            <div><span class="ileben-k">Planta</span><span class="ileben-v" data-field="planta_label"></span></div>
                            <div><span class="ileben-k">Superficie interior</span><span class="ileben-v" data-field="superficie_interior"></span></div>
                            <div><span class="ileben-k">Dorm + Bano</span><span class="ileben-v" data-field="dorm_bano"></span></div>
                            <div><span class="ileben-k">Terraza</span><span class="ileben-v" data-field="terraza_m2"></span></div>
                            <div><span class="ileben-k">Orientacion</span><span class="ileben-v" data-field="orientacion"></span></div>
                            <div><span class="ileben-k">Superficie total</span><span class="ileben-v" data-field="superficie_total"></span></div>
                        </div>
                        <div class="ileben-price-wrap">
                            <span class="ileben-k">Precio desde</span>
                            <span class="ileben-price" data-field="precio"></span>
                        </div>
                        <div class="ileben-actions">
                            <a class="btn btn-primary" data-field="cotizar_btn" href="#" target="_blank" rel="noopener">Cotizar</a>
                            <a class="btn btn-outline-teal" data-field="brochure_btn" href="#" target="_blank" rel="noopener" download>Descargar brochure</a>
                        </div>
                    </div>
                </div>
                <script type="application/json" id="<?php echo esc_attr($instance); ?>-data"><?php echo $json_payload; ?></script>
            <?php endif; ?>
        </section>
        <?php
        return ob_get_clean();
    }

    private function map_showcase_item($item, $default_cotiza_url = '')
    {
        $photos = json_decode((string) ($item['fotos'] ?? '[]'), true);
        $fallback_image = ILEBEN_PLANTAS_URL . 'assets/img/logo.png';
        $image = $fallback_image;
        if (is_array($photos) && ! empty($photos)) {
            $candidate = esc_url_raw((string) $photos[0]);
            if ($candidate !== '' && wp_http_validate_url($candidate)) {
                $image = $candidate;
            }
        }

        $dormitorios = (int) ($item['dormitorios'] ?? 0);
        $banos = (int) ($item['banos'] ?? 0);
        $cotizacion_url = esc_url_raw((string) ($item['cotizacion_url'] ?? ''));
        if ($cotizacion_url === '' && $default_cotiza_url !== '') {
            $cotizacion_url = esc_url_raw((string) $default_cotiza_url);
        }

        return array(
            'id' => (int) ($item['id'] ?? 0),
            'nombre' => (string) ($item['nombre'] ?? ''),
            'descripcion' => (string) ($item['descripcion'] ?? ''),
            'tipologia' => (string) ($item['tipologia'] ?? ''),
            'planta_label' => (string) ($item['planta_label'] ?? ''),
            'orientacion' => (string) ($item['orientacion'] ?? ''),
            'superficie_interior' => (float) ($item['superficie_interior'] ?? 0),
            'terraza_m2' => (float) ($item['terraza_m2'] ?? 0),
            'superficie_total' => (float) ($item['superficie_total'] ?? 0),
            'precio' => (float) ($item['precio'] ?? 0),
            'dorm_bano' => trim($dormitorios . ' dorm + ' . $banos . ' bano'),
            'imagen' => esc_url_raw($image),
            'imagen_fallback' => esc_url_raw($fallback_image),
            'brochure' => esc_url_raw((string) ($item['brochure'] ?? '')),
            'cotizacion_url' => $cotizacion_url,
        );
    }

    private function get_default_cotiza_url()
    {
        if (! class_exists('Ileben_Plantas_Api_Client')) {
            return '';
        }

        $api_client = new Ileben_Plantas_Api_Client();
        $settings = $api_client->get_settings();
        return esc_url_raw((string) ($settings['cotiza_url'] ?? ''));
    }
}
