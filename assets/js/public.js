(function () {
    'use strict';

    function formatNumber(value) {
        var num = Number(value || 0);
        return num.toLocaleString('es-CL', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function formatArea(value) {
        var num = Number(value || 0);
        if (!num) {
            return '-';
        }
        return formatNumber(num) + ' m2 aprox';
    }

    function initShowcase(wrapper) {
        var dataNode = document.getElementById(wrapper.id + '-data');
        if (!dataNode) {
            return;
        }

        var allItems = JSON.parse(dataNode.textContent || '[]');
        if (!allItems.length) {
            return;
        }

        var carouselId = wrapper.id + '-carousel';
        var carouselElement = document.getElementById(carouselId);
        var inner = carouselElement.querySelector('.carousel-inner');
        var indicators = document.getElementById(wrapper.id + '-indicators');
        var tipologiaSelect = wrapper.querySelector('[data-filter="tipologia"]');
        var plantaSelect = wrapper.querySelector('[data-filter="planta_label"]');
        var detailName = wrapper.querySelector('[data-field="nombre"]');
        var detailDesc = wrapper.querySelector('[data-field="descripcion"]');
        var fields = {
            planta_label: wrapper.querySelector('[data-field="planta_label"]'),
            superficie_interior: wrapper.querySelector('[data-field="superficie_interior"]'),
            dorm_bano: wrapper.querySelector('[data-field="dorm_bano"]'),
            terraza_m2: wrapper.querySelector('[data-field="terraza_m2"]'),
            orientacion: wrapper.querySelector('[data-field="orientacion"]'),
            superficie_total: wrapper.querySelector('[data-field="superficie_total"]'),
            precio: wrapper.querySelector('[data-field="precio"]')
        };
        var brochureBtn = wrapper.querySelector('[data-field="brochure_btn"]');
        var cotizarBtn = wrapper.querySelector('[data-field="cotizar_btn"]');
        var visibleItems = allItems.slice();

        function updateDetails(item) {
            detailName.textContent = item.tipologia || item.nombre || 'Tipologia';
            detailDesc.textContent = item.descripcion || '';
            fields.planta_label.textContent = item.planta_label || '-';
            fields.superficie_interior.textContent = formatArea(item.superficie_interior);
            fields.dorm_bano.textContent = item.dorm_bano || '-';
            fields.terraza_m2.textContent = formatArea(item.terraza_m2);
            fields.orientacion.textContent = item.orientacion || '-';
            fields.superficie_total.textContent = formatArea(item.superficie_total);
            fields.precio.textContent = 'UF ' + Number(item.precio || 0).toLocaleString('es-CL');

            if (cotizarBtn) {
                if (item.cotizacion_url) {
                    cotizarBtn.style.display = 'inline-flex';
                    cotizarBtn.setAttribute('href', item.cotizacion_url);
                } else {
                    cotizarBtn.style.display = 'none';
                    cotizarBtn.setAttribute('href', '#');
                }
            }

            if (item.brochure) {
                brochureBtn.style.display = 'inline-flex';
                brochureBtn.setAttribute('href', item.brochure);
            } else {
                brochureBtn.style.display = 'none';
                brochureBtn.setAttribute('href', '#');
            }
        }

        function renderCarousel(items) {
            inner.innerHTML = '';
            indicators.innerHTML = '';

            if (!items.length) {
                inner.innerHTML = '<div class="carousel-item active"><div class="ileben-empty">No hay plantas para esos filtros.</div></div>';
                updateDetails({});
                return;
            }

            items.forEach(function (item, index) {
                var slide = document.createElement('div');
                slide.className = 'carousel-item' + (index === 0 ? ' active' : '');
                slide.setAttribute('data-item-id', String(item.id));

                var imageSrc = item.imagen || item.imagen_fallback || '';
                var fallbackSrc = item.imagen_fallback || '';
                var safeAlt = item.nombre || 'Planta';

                var imageHtml = imageSrc
                    ? '<img src="' + imageSrc + '" class="d-block w-100 ileben-plan-image" alt="' + safeAlt + '" data-fallback-src="' + fallbackSrc + '">'
                    : '<div class="ileben-empty">Sin imagen disponible</div>';

                slide.innerHTML = imageHtml;
                inner.appendChild(slide);

                var slideImage = slide.querySelector('img');
                if (slideImage) {
                    slideImage.addEventListener('error', function onImageError() {
                        var fallback = slideImage.getAttribute('data-fallback-src') || '';
                        if (fallback && slideImage.getAttribute('src') !== fallback) {
                            slideImage.setAttribute('src', fallback);
                            return;
                        }

                        slideImage.removeEventListener('error', onImageError);
                        slideImage.outerHTML = '<div class="ileben-empty">Sin imagen disponible</div>';
                    });
                }

                var indicator = document.createElement('button');
                indicator.type = 'button';
                indicator.setAttribute('data-bs-target', '#' + carouselId);
                indicator.setAttribute('data-bs-slide-to', String(index));
                indicator.setAttribute('aria-label', 'Slide ' + (index + 1));
                if (index === 0) {
                    indicator.classList.add('active');
                    indicator.setAttribute('aria-current', 'true');
                }
                indicators.appendChild(indicator);
            });

            updateDetails(items[0]);
        }

        function applyFilters() {
            var tipologia = tipologiaSelect ? tipologiaSelect.value : '';
            var planta = plantaSelect ? plantaSelect.value : '';

            visibleItems = allItems.filter(function (item) {
                var tipologiaOk = !tipologia || item.tipologia === tipologia;
                var plantaOk = !planta || item.planta_label === planta;
                return tipologiaOk && plantaOk;
            });

            renderCarousel(visibleItems);

            if (window.bootstrap && carouselElement) {
                window.bootstrap.Carousel.getOrCreateInstance(carouselElement, { interval: false, ride: false });
            }
        }

        if (tipologiaSelect) {
            tipologiaSelect.addEventListener('change', applyFilters);
        }
        if (plantaSelect) {
            plantaSelect.addEventListener('change', applyFilters);
        }

        carouselElement.addEventListener('slid.bs.carousel', function () {
            var active = inner.querySelector('.carousel-item.active');
            if (!active) {
                return;
            }

            var itemId = Number(active.getAttribute('data-item-id') || 0);
            var current = visibleItems.find(function (item) {
                return Number(item.id) === itemId;
            });

            if (current) {
                updateDetails(current);
            }
        });

        applyFilters();
    }

    document.addEventListener('DOMContentLoaded', function () {
        var showcases = document.querySelectorAll('.ileben-showcase');
        showcases.forEach(initShowcase);
    });
})();
