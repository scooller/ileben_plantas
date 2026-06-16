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
        return formatNumber(num) + ' m<sup>2*</sup>';
    }

    function normalizeSecureUrl(url) {
        if (!url) {
            return '';
        }

        try {
            var parsed = new URL(String(url), window.location.origin);
            var host = (parsed.hostname || '').toLowerCase();
            var isLocalHost = host === 'localhost' || host === '127.0.0.1' || host === '::1' || host.endsWith('.local');
            if (window.location.protocol === 'https:' && parsed.protocol === 'http:' && !isLocalHost) {
                parsed.protocol = 'https:';
            }
            return parsed.toString();
        } catch (e) {
            return String(url);
        }
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
        var mainGrid = wrapper.querySelector('.ileben-main-grid');
        var detailsCol = wrapper.querySelector('.ileben-details-col');
        var nextControl = wrapper.querySelector('.carousel-control-next');
        // var indicators = document.getElementById(wrapper.id + '-indicators');
        var tipologiaSelect = wrapper.querySelector('[data-filter="tipologia"]');
        var tipoProductoSelect = wrapper.querySelector('[data-filter="tipo_producto"]');
        var plantaSelect = wrapper.querySelector('[data-filter="planta_label"]');
        var pisoSelect = wrapper.querySelector('[data-filter="piso"]');
        var mostrarTodasCheckbox = wrapper.querySelector('[data-filter="mostrar_todas"]');
        var filterBtn = wrapper.querySelector('[data-action="filter"]');
        var resetBtn = wrapper.querySelector('[data-action="reset"]');
        var shownPlantsNode = wrapper.querySelector('.show_plantas');
        var ajaxUrl = wrapper.getAttribute('data-ajax-url') || '';
        var ajaxNonce = wrapper.getAttribute('data-ajax-nonce') || '';
        var ajaxPerPage = Number(wrapper.getAttribute('data-ajax-per-page') || 5000);
        var defaultOrderBy = wrapper.getAttribute('data-orderby') || '';
        var detailName = wrapper.querySelector('[data-field="name"], [data-field="nombre"]');
        var detailDesc = wrapper.querySelector('[data-field="descripcion"]');
        var lightbox = wrapper.querySelector('.ileben-lightbox');
        var lightboxImage = wrapper.querySelector('[data-lightbox-image]');
        var lightboxClose = wrapper.querySelector('[data-lightbox-close]');
        var fields = {
            planta_label: wrapper.querySelector('[data-field="planta_label"], [data-field="product_code"]'),
            superficie_util: wrapper.querySelector('[data-field="superficie_util"]'),
            dorm_bano: wrapper.querySelector('[data-field="dorm_bano"]'),
            terraza_m2: wrapper.querySelector('[data-field="terraza_m2"]'),
            orientacion: wrapper.querySelector('[data-field="orientacion"]'),
            superficie_total: wrapper.querySelector('[data-field="superficie_total"]'),
            precio_base: wrapper.querySelector('[data-field="precio_base"]'),
            estado: wrapper.querySelector('[data-field="estado"]')
            // precio_lista: wrapper.querySelector('[data-field="precio_lista"]')
        };
        var brochureBtn = wrapper.querySelector('[data-field="brochure_btn"]');
        var cotizarBtn = wrapper.querySelector('[data-field="cotizar_btn"]');
        var visibleItems = allItems.slice();
        var currentFilters = { tipologia: [], tipo_producto: [], planta_label: [], piso: [] };
        var ajaxPage = 1;
        var ajaxHasMore = false;
        var ajaxLoadingMore = false;
        var filteringSafetyTimer = null;
        var FILTERING_TIMEOUT_MS = 4500;

        function initSelect2(select) {
            if (!select || !window.jQuery || !window.jQuery.fn || typeof window.jQuery.fn.select2 !== 'function') {
                return;
            }

            var $select = window.jQuery(select);
            if ($select.hasClass('select2-hidden-accessible')) {
                return;
            }

            var placeholder = '';
            if (select.options && select.options.length) {
                placeholder = String(select.options[0].text || '').trim();
            }

            $select.select2({
                width: '100%',
                placeholder: placeholder || 'Seleccionar',
                closeOnSelect: !select.multiple,
                allowClear: true,
                dropdownAutoWidth: true
            });
        }

        function clearFilteringSafetyTimer() {
            if (!filteringSafetyTimer) {
                return;
            }

            window.clearTimeout(filteringSafetyTimer);
            filteringSafetyTimer = null;
        }

        function scheduleFilteringSafetyTimer() {
            clearFilteringSafetyTimer();
            filteringSafetyTimer = window.setTimeout(function () {
                setFilteringState(false);
            }, FILTERING_TIMEOUT_MS);
        }

        function updateShownPlants(count) {
            if (!shownPlantsNode) {
                return;
            }

            shownPlantsNode.textContent = String(Math.max(0, Number(count) || 0));
        }

        function openLightbox(imageUrl, altText) {
            if (!lightbox || !lightboxImage || !imageUrl) {
                return;
            }

            lightboxImage.setAttribute('src', normalizeSecureUrl(imageUrl));
            lightboxImage.setAttribute('alt', altText || 'Imagen interior');
            lightbox.classList.add('is-open');
        }

        function closeLightbox() {
            if (!lightbox || !lightboxImage) {
                return;
            }

            lightbox.classList.remove('is-open');
            lightboxImage.setAttribute('src', '');
        }

        function setFilteringState(isFiltering) {
            if (isFiltering) {
                wrapper.classList.add('is-filtering');
                if (mainGrid) {
                    mainGrid.classList.add('transition-opacity');
                }
                scheduleFilteringSafetyTimer();
                return;
            }

            clearFilteringSafetyTimer();
            wrapper.classList.remove('is-filtering');
            if (mainGrid) {
                mainGrid.classList.remove('transition-opacity');
            }
        }

        function getCarouselController() {
            if (!carouselElement) {
                return null;
            }

            if (window.bootstrap && window.bootstrap.Carousel) {
                if (typeof window.bootstrap.Carousel.getOrCreateInstance === 'function') {
                    return window.bootstrap.Carousel.getOrCreateInstance(carouselElement, { interval: false, ride: false });
                }

                if (typeof window.bootstrap.Carousel.getInstance === 'function') {
                    var bootstrapInstance = window.bootstrap.Carousel.getInstance(carouselElement);
                    if (bootstrapInstance) {
                        return bootstrapInstance;
                    }
                }

                try {
                    return new window.bootstrap.Carousel(carouselElement, { interval: false, ride: false });
                } catch (e) {
                    // Fallback to jQuery bootstrap plugin when present.
                }
            }

            if (window.jQuery && typeof window.jQuery.fn.carousel === 'function') {
                var $carousel = window.jQuery(carouselElement);
                $carousel.carousel({ interval: false });
                return {
                    to: function (index) {
                        $carousel.carousel(Number(index) || 0);
                    }
                };
            }

            return null;
        }

        function fetchFilteredItems(filters, page) {
            if (!ajaxUrl || !ajaxNonce) {
                return Promise.resolve(null);
            }

            var body = new URLSearchParams();
            body.append('action', 'ileben_api_filter_plantas');
            body.append('nonce', ajaxNonce);
            body.append('orderby', defaultOrderBy || '');
            body.append('estado', mostrarTodasCheckbox && mostrarTodasCheckbox.checked ? '' : 'disponible');
            body.append('per_page', String(ajaxPerPage || 100));
            body.append('page', String(Math.max(1, Number(page) || 1)));

            (filters.tipologia || []).forEach(function (value) {
                body.append('tipologia[]', value);
            });
            (filters.tipo_producto || []).forEach(function (value) {
                body.append('tipo_producto[]', value);
            });
            (filters.planta_label || []).forEach(function (value) {
                body.append('planta_label[]', value);
            });
            (filters.piso || []).forEach(function (value) {
                body.append('piso[]', value);
            });

            return window.fetch(ajaxUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                },
                body: body.toString(),
                credentials: 'same-origin'
            })
                .then(function (response) {
                    return response.json();
                })
                .then(function (json) {
                    if (!json || json.success !== true || !json.data || !Array.isArray(json.data.items)) {
                        return null;
                    }
                    return {
                        items: json.data.items,
                        total: Number(json.data.total || 0),
                        page: Number(json.data.page || 1),
                        pages: Number(json.data.pages || 1),
                        hasMore: Boolean(json.data.has_more)
                    };
                })
                .catch(function () {
                    return null;
                });
        }

        function updateDetails(item) {
            if (detailName) {
                detailName.textContent = item.name || item.nombre || item.tipologia || 'Tipologia';
            }
            if (detailDesc) {
                detailDesc.textContent = item.descripcion || '';
            }
            if (fields.planta_label) fields.planta_label.textContent = item.product_code || item.planta_label || '-';
            if (fields.superficie_util) fields.superficie_util.innerHTML = formatArea(item.superficie_util);
            if (fields.dorm_bano) fields.dorm_bano.textContent = item.dorm_bano || '-';
            if (fields.terraza_m2) fields.terraza_m2.innerHTML = formatArea(item.terraza_m2);
            if (fields.orientacion) fields.orientacion.textContent = item.orientacion || '-';
            if (fields.superficie_total) fields.superficie_total.innerHTML = formatArea(item.superficie_total);

            var precioBase = Number(item.precio_base || 0);
            // var precioLista = Number(item.precio_lista || item.precio || 0);
            if (fields.precio_base) fields.precio_base.textContent = 'UF ' + precioBase.toLocaleString('es-CL', { maximumFractionDigits: 0 });
            // fields.precio_lista.textContent = 'Lista: UF ' + precioLista.toLocaleString('es-CL');

            if (cotizarBtn) {
                if (item.cotizacion_url) {
                    //si estado es disponible, mostrar botón cotizar, si no mostrar botón de contacto genérico desabilitado
                    cotizarBtn.removeAttribute('disabled');
                    cotizarBtn.classList.remove('disabled');
                    if (item.estado && String(item.estado).toLowerCase() === 'disponible') {
                        cotizarBtn.style.display = '';
                        cotizarBtn.setAttribute('href', normalizeSecureUrl(item.cotizacion_url));
                    } else {
                        cotizarBtn.setAttribute('disabled', 'disabled');
                        cotizarBtn.setAttribute('href', '');
                        cotizarBtn.classList.add('disabled');
                    }
                } else {
                    cotizarBtn.style.display = 'none';
                    cotizarBtn.setAttribute('href', '');
                }
            }

            if (brochureBtn && item.brochure) {
                brochureBtn.style.display = '';
                brochureBtn.setAttribute('href', normalizeSecureUrl(item.brochure));
            } else if (brochureBtn) {
                brochureBtn.style.display = 'none';
                brochureBtn.setAttribute('href', '#');
            }
        }

        function renderCarousel(items) {
            inner.innerHTML = '';
            // indicators.innerHTML = '';
            updateShownPlants(items.length);

            if (!items.length) {
                inner.innerHTML = '<div class="carousel-item active"><div class="ileben-empty">No hay plantas para esos filtros.</div></div>';
                updateDetails({});
                return;
            }

            items.forEach(function (item, index) {
                var slide = document.createElement('div');
                slide.className = 'carousel-item text-center' + (index === 0 ? ' active' : '');
                slide.setAttribute('data-item-id', String(item.id));

                var imageSrc = normalizeSecureUrl(item.imagen || item.imagen_fallback || '');
                var interiorImageSrc = normalizeSecureUrl(item.imagen_interior || imageSrc || '');
                var fallbackSrc = normalizeSecureUrl(item.imagen_fallback || '');
                var safeAlt = item.nombre || 'Planta';

                var imageHtml = imageSrc
                    ? '<button type="button" class="ileben-lightbox-trigger w-100 h-100" data-bs-toggle="tooltip" data-bs-title="Ver imagen interior" data-interior-src="' + interiorImageSrc + '" aria-label="Ver imagen interior"><img src="' + imageSrc + '" class="object-fit-cover mx-auto w-75" alt="' + safeAlt + '" data-fallback-src="' + fallbackSrc + '"></button>'
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

                var trigger = slide.querySelector('.ileben-lightbox-trigger');
                if (trigger) {
                    trigger.addEventListener('click', function () {
                        openLightbox(trigger.getAttribute('data-interior-src') || '', safeAlt);
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
                // indicators.appendChild(indicator);
            });

            updateDetails(items[0]);
        }

        function applyFilters(showLoader, useAjax) {
            var useLoader = showLoader !== false;
            var shouldUseAjax = useAjax === true;
            var tipologia = getSelectValues(tipologiaSelect);
            var tipoProducto = getSelectValues(tipoProductoSelect);
            var planta = getSelectValues(plantaSelect);
            var piso = getSelectValues(pisoSelect);
            currentFilters = {
                tipologia: tipologia,
                tipo_producto: tipoProducto,
                planta_label: planta,
                piso: piso
            };

            var runFiltering = function () {
                //add class to ileben-main-grid to reduce opacity and add loader
                
                visibleItems = allItems.filter(function (item) {
                    var tipologiaOk = !tipologia.length || tipologia.indexOf(item.tipologia) !== -1;
                    var tipoProductoOk = !tipoProducto.length || tipoProducto.indexOf(item.tipo_producto) !== -1;
                    var plantaCode = item.product_code || item.planta_label;
                    var plantaOk = !planta.length || planta.indexOf(plantaCode) !== -1;
                    var pisoOk = !piso.length || piso.indexOf(String(item.piso || '')) !== -1;
                    return tipologiaOk && tipoProductoOk && plantaOk && pisoOk;
                });

                renderCarousel(visibleItems);

                getCarouselController();

                setFilteringState(false);
            };

            var runAjaxFiltering = function () {
                fetchFilteredItems(currentFilters, 1).then(function (response) {
                    if (response && Array.isArray(response.items)) {
                        allItems = response.items;
                        visibleItems = response.items;
                        ajaxPage = response.page || 1;
                        ajaxHasMore = Boolean(response.hasMore);
                        renderCarousel(visibleItems);

                        getCarouselController();
                        return;
                    }

                    runFiltering();
                }).catch(function () {
                    runFiltering();
                }).finally(function () {
                    setFilteringState(false);
                });
            };

            if (!useLoader) {
                runFiltering();
                return;
            }

            setFilteringState(true);
            window.setTimeout(function () {
                if (shouldUseAjax) {
                    runAjaxFiltering();
                    return;
                }
                runFiltering();
            }, 140);
        }

        function loadNextPageIfNeeded() {
            if (ajaxLoadingMore || !ajaxHasMore) {
                return;
            }

            var slides = inner.querySelectorAll('.carousel-item');
            var active = inner.querySelector('.carousel-item.active');
            if (!active || !slides.length) {
                return;
            }

            var activeIndex = Array.prototype.indexOf.call(slides, active);
            if (activeIndex !== slides.length - 1) {
                return;
            }

            ajaxLoadingMore = true;
            setFilteringState(true);

            fetchFilteredItems(currentFilters, ajaxPage + 1).then(function (response) {
                if (!response || !Array.isArray(response.items) || !response.items.length) {
                    ajaxHasMore = false;
                    return;
                }

                var startIndex = allItems.length;
                allItems = allItems.concat(response.items);
                visibleItems = allItems;
                ajaxPage = response.page || (ajaxPage + 1);
                ajaxHasMore = Boolean(response.hasMore);

                renderCarousel(visibleItems);

                var carouselController = getCarouselController();
                if (carouselController && typeof carouselController.to === 'function') {
                    carouselController.to(startIndex);
                }
            }).finally(function () {
                ajaxLoadingMore = false;
                setFilteringState(false);
            });
        }

        function onFilterChange(event) {
            if (event && event.target === plantaSelect && plantaSelect && hasSelectValues(plantaSelect)) {
                if (tipologiaSelect) {
                    clearSelect(tipologiaSelect);
                }
                if (tipoProductoSelect) {
                    clearSelect(tipoProductoSelect);
                }
                if (pisoSelect) {
                    clearSelect(pisoSelect);
                }
            }
        }

        function getSelectValues(select) {
            if (!select) {
                return [];
            }

            if (select.multiple) {
                return Array.prototype.map.call(select.selectedOptions || [], function (option) {
                    return String(option.value || '').trim();
                }).filter(function (value) {
                    return value !== '';
                });
            }

            var value = String(select.value || '').trim();
            return value === '' ? [] : [value];
        }

        function hasSelectValues(select) {
            return getSelectValues(select).length > 0;
        }

        function clearSelect(select) {
            if (!select) {
                return;
            }

            if (select.multiple) {
                Array.prototype.forEach.call(select.options, function (option) {
                    option.selected = false;
                });
            } else {
                select.value = '';
            }

            if (window.jQuery && window.jQuery.fn && typeof window.jQuery.fn.select2 === 'function') {
                window.jQuery(select).trigger('change.select2');
            }
        }

        if (tipologiaSelect) {
            tipologiaSelect.addEventListener('change', onFilterChange);
            initSelect2(tipologiaSelect);
        }
        if (tipoProductoSelect) {
            tipoProductoSelect.addEventListener('change', onFilterChange);
            initSelect2(tipoProductoSelect);
        }
        if (plantaSelect) {
            plantaSelect.addEventListener('change', onFilterChange);
            initSelect2(plantaSelect);
        }
        if (pisoSelect) {
            pisoSelect.addEventListener('change', onFilterChange);
            initSelect2(pisoSelect);
        }

        if (mostrarTodasCheckbox) {
            mostrarTodasCheckbox.addEventListener('change', function () {
                applyFilters(true, true);
            });
        }

        if (filterBtn) {
            filterBtn.addEventListener('click', function (event) {
                event.preventDefault();
                applyFilters(true, true);
            });
        }

        if (resetBtn) {
            resetBtn.addEventListener('click', function (event) {
                event.preventDefault();
                clearSelect(tipologiaSelect);
                clearSelect(tipoProductoSelect);
                clearSelect(plantaSelect);
                clearSelect(pisoSelect);
                if (mostrarTodasCheckbox) {
                    mostrarTodasCheckbox.checked = false;
                }
                applyFilters(true, true);
            });
        }

        carouselElement.addEventListener('slide.bs.carousel', function () {
            if (detailsCol) {
                detailsCol.classList.add('transition-opacity');
            }
        });

        carouselElement.addEventListener('slid.bs.carousel', function () {
            if (detailsCol) {
                detailsCol.classList.remove('transition-opacity');
            }

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

            loadNextPageIfNeeded();
        });

        if (lightboxClose) {
            lightboxClose.addEventListener('click', closeLightbox);
        }
        if (lightbox) {
            lightbox.addEventListener('click', function (event) {
                if (event.target === lightbox) {
                    closeLightbox();
                }
            });
        }
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                closeLightbox();
            }
        });

        applyFilters(false, false);
    }

    document.addEventListener('DOMContentLoaded', function () {
        var showcases = document.querySelectorAll('.ileben-showcase');
        showcases.forEach(initShowcase);

        // Tooltip bootstrap 5 / bootstrap 4 compatibility.
        if (window.bootstrap && window.bootstrap.Tooltip) {
            var tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
            Array.prototype.forEach.call(tooltipTriggerList, function (tooltipTriggerEl) {
                new window.bootstrap.Tooltip(tooltipTriggerEl);
            });
            return;
        }

        if (window.jQuery && window.jQuery.fn && typeof window.jQuery.fn.tooltip === 'function') {
            window.jQuery('[data-bs-toggle="tooltip"], [data-toggle="tooltip"]').tooltip();
        }
    });
})();
