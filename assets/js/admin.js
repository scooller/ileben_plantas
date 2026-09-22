// ============================================================================
// CONTACTO MODAL - Se ejecuta apenas se carga el script
// ============================================================================
console.log('[ileben-api] Iniciando script admin.js');

// Función global para abrir modal de contacto
function ilebenEditContactModal(button) {
    console.log('[ileben-api] ilebenEditContactModal called');
    
    try {
        var contactId = button.getAttribute('data-contact-id');
        var contactEmail = button.getAttribute('data-contact-email');
        var contactName = button.getAttribute('data-contact-name');
        var payloadJson = button.getAttribute('data-payload');

        console.log('[ileben-api] contactId:', contactId, 'email:', contactEmail);

        if (!contactId) {
            alert('Error: No se encontro el ID del contacto.');
            return;
        }

        var payload = {};
        try {
            payload = JSON.parse(payloadJson || '{}');
        } catch (e) {
            console.error('[ileben-api] Error parseando JSON:', e);
        }

        console.log('[ileben-api] payload:', payload);

        var contactChannel = button.getAttribute('data-contact-channel') || payload.channel || '';
        var channelInput = document.getElementById('editContactChannel');
        if (channelInput) {
            channelInput.value = contactChannel;
        }

        var fields = payload.fields || {};
        if (fields.hasOwnProperty('channel')) {
            delete fields.channel;
        }

        var fieldsHtml = '';

        // Crear un campo para cada propiedad en fields
        Object.keys(fields).forEach(function(fieldName) {
            var fieldValue = fields[fieldName] || '';
            var fieldLabel = fieldName.charAt(0).toUpperCase() + fieldName.slice(1);
            fieldsHtml += '<div class="mb-3">' +
                '<label class="form-label">' + escapeHtml(fieldLabel) + '</label>' +
                '<input type="text" class="form-control" name="field_' + escapeHtml(fieldName) + '" value="' + escapeHtml(fieldValue) + '" />' +
                '</div>';
        });

        document.getElementById('editContactId').value = contactId;
        document.getElementById('editContactFields').innerHTML = fieldsHtml;

        console.log('[ileben-api] Abriendo modal');

        // Abrir modal usando Bootstrap
        var modalElement = document.getElementById('ilebenContactEditModal');
        if (!modalElement) {
            console.error('[ileben-api] Modal element not found!');
            alert('Error: Modal element not found');
            return;
        }
        
        var modal = bootstrap.Modal.getOrCreateInstance(modalElement);
        modal.show();
        console.log('[ileben-api] Modal abierto');
    } catch (error) {
        console.error('[ileben-api] Error:', error);
        alert('Error al abrir el formulario de edición: ' + error.message);
    }
}

function escapeHtml(unsafe) {
    if (!unsafe) return '';
    return unsafe
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

// Vincular botones de contacto apenas DOM esté listo
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', attachContactListeners);
} else {
    attachContactListeners();
}

function attachContactListeners() {
    console.log('[ileben-api] Vinculando listeners de contacto');
    var editButtons = document.querySelectorAll('.ileben-edit-contact-btn');
    console.log('[ileben-api] Botones encontrados:', editButtons.length);
    
    editButtons.forEach(function(button) {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            console.log('[ileben-api] Click en botón de contacto');
            ilebenEditContactModal(this);
        });
    });
}

// ============================================================================
// BULK ACTIONS & RESYNC - Sync Contactos
// ============================================================================
(function() {
    'use strict';

    var selectedIds = [];
    var isAllFilteredSelected = false;
    var syncCancelled = false;

    function getAdminVars() {
        return window.ileben_admin_vars || {
            ajax_url: (typeof ajaxurl !== 'undefined') ? ajaxurl : '/wp-admin/admin-ajax.php',
            nonce_bulk_channel: '',
            nonce_batch_resync: '',
            nonce_get_ids: ''
        };
    }

    function initBulkActions() {
        var wrap = document.getElementById('ilebenContactSyncWrap');
        if (!wrap) return;

        var selectAllCheckbox = document.getElementById('selectAllContacts');
        var rowCheckboxes = document.querySelectorAll('.ileben-contact-check');
        var bulkToolbar = document.getElementById('ilebenBulkToolbar');
        var bulkSelectedCount = document.getElementById('bulkSelectedCount');
        var btnDeselectAll = document.getElementById('btnDeselectAll');
        var banner = document.getElementById('bulkSelectAllFilteredBanner');
        var bannerText = document.getElementById('bulkBannerText');
        var btnSelectAllFiltered = document.getElementById('btnSelectAllFiltered');
        var btnClearAllFiltered = document.getElementById('btnClearAllFiltered');

        var totalFiltered = parseInt(wrap.getAttribute('data-total') || '0', 10);
        var pageItemsCount = rowCheckboxes.length;

        function updateSelectionState() {
            var checkedCheckboxes = document.querySelectorAll('.ileben-contact-check:checked');
            selectedIds = [];
            checkedCheckboxes.forEach(function(cb) {
                var id = parseInt(cb.value, 10);
                if (id) selectedIds.push(id);
            });

            var count = isAllFilteredSelected ? totalFiltered : selectedIds.length;

            if (count > 0) {
                bulkToolbar.classList.remove('d-none');
                bulkSelectedCount.textContent = count + ' seleccionado' + (count > 1 ? 's' : '');

                // Comprobar si todos los de la página están marcados y hay más registros filtrados
                if (selectedIds.length === pageItemsCount && totalFiltered > pageItemsCount) {
                    banner.classList.remove('d-none');
                    if (isAllFilteredSelected) {
                        bannerText.innerHTML = 'Todos los <strong>' + totalFiltered + '</strong> contactos coincidentes están seleccionados.';
                        btnSelectAllFiltered.classList.add('d-none');
                        btnClearAllFiltered.classList.remove('d-none');
                    } else {
                        bannerText.innerHTML = 'Has seleccionado los <strong>' + pageItemsCount + '</strong> contactos de esta página.';
                        btnSelectAllFiltered.classList.remove('d-none');
                        btnClearAllFiltered.classList.add('d-none');
                    }
                } else {
                    banner.classList.add('d-none');
                    isAllFilteredSelected = false;
                }
            } else {
                bulkToolbar.classList.add('d-none');
                banner.classList.add('d-none');
                isAllFilteredSelected = false;
            }

            // Actualizar checkbox maestro
            if (selectAllCheckbox) {
                if (pageItemsCount > 0 && selectedIds.length === pageItemsCount) {
                    selectAllCheckbox.checked = true;
                    selectAllCheckbox.indeterminate = false;
                } else if (selectedIds.length > 0) {
                    selectAllCheckbox.checked = false;
                    selectAllCheckbox.indeterminate = true;
                } else {
                    selectAllCheckbox.checked = false;
                    selectAllCheckbox.indeterminate = false;
                }
            }
        }

        // Evento checkbox maestro
        if (selectAllCheckbox) {
            selectAllCheckbox.addEventListener('change', function() {
                var isChecked = this.checked;
                isAllFilteredSelected = false;
                rowCheckboxes.forEach(function(cb) {
                    cb.checked = isChecked;
                });
                updateSelectionState();
            });
        }

        // Eventos checkboxes de cada fila
        rowCheckboxes.forEach(function(cb) {
            cb.addEventListener('change', function() {
                isAllFilteredSelected = false;
                updateSelectionState();
            });
        });

        // Botón deseleccionar todo
        if (btnDeselectAll) {
            btnDeselectAll.addEventListener('click', function(e) {
                e.preventDefault();
                rowCheckboxes.forEach(function(cb) {
                    cb.checked = false;
                });
                isAllFilteredSelected = false;
                updateSelectionState();
            });
        }

        // Botón seleccionar todos los filtrados
        if (btnSelectAllFiltered) {
            btnSelectAllFiltered.addEventListener('click', function(e) {
                e.preventDefault();
                isAllFilteredSelected = true;
                updateSelectionState();
            });
        }

        // Botón limpiar selección global
        if (btnClearAllFiltered) {
            btnClearAllFiltered.addEventListener('click', function(e) {
                e.preventDefault();
                isAllFilteredSelected = false;
                updateSelectionState();
            });
        }

        // Modal Cambio de Canal Masivo
        var btnOpenBulkChannelModal = document.getElementById('btnOpenBulkChannelModal');
        var bulkChannelModalEl = document.getElementById('ilebenBulkChannelModal');
        var bulkChannelForm = document.getElementById('ilebenBulkChannelForm');
        var bulkNewChannelInput = document.getElementById('bulkNewChannel');
        var bulkModalCount = document.getElementById('bulkModalCount');
        var bulkResyncCheckbox = document.getElementById('bulkResyncAfterUpdate');
        var btnConfirmBulkChannel = document.getElementById('btnConfirmBulkChannel');

        if (btnOpenBulkChannelModal && bulkChannelModalEl) {
            btnOpenBulkChannelModal.addEventListener('click', function() {
                var count = isAllFilteredSelected ? totalFiltered : selectedIds.length;
                if (count === 0) {
                    alert('Debes seleccionar al menos un contacto.');
                    return;
                }
                bulkModalCount.textContent = count;
                bulkNewChannelInput.value = '';
                var modal = bootstrap.Modal.getOrCreateInstance(bulkChannelModalEl);
                modal.show();
            });
        }

        if (bulkChannelForm) {
            bulkChannelForm.addEventListener('submit', function(e) {
                e.preventDefault();
                var newChannel = (bulkNewChannelInput.value || '').trim();
                if (!newChannel) {
                    alert('Por favor ingresa un canal válido.');
                    bulkNewChannelInput.focus();
                    return;
                }

                var count = isAllFilteredSelected ? totalFiltered : selectedIds.length;
                if (!confirm('¿Estás seguro de cambiar el canal a "' + newChannel + '" para ' + count + ' contacto(s)?')) {
                    return;
                }

                btnConfirmBulkChannel.disabled = true;
                btnConfirmBulkChannel.textContent = 'Guardando...';

                var postData = new URLSearchParams();
                postData.append('action', 'ileben_api_bulk_update_contact_channel');
                postData.append('nonce', getAdminVars().nonce_bulk_channel);
                postData.append('channel', newChannel);

                if (isAllFilteredSelected) {
                    postData.append('apply_all', '1');
                    postData.append('status', wrap.getAttribute('data-status') || '');
                    postData.append('current_channel', wrap.getAttribute('data-channel') || '');
                    postData.append('email', wrap.getAttribute('data-email') || '');
                    postData.append('date_from', wrap.getAttribute('data-date-from') || '');
                    postData.append('date_to', wrap.getAttribute('data-date-to') || '');
                } else {
                    selectedIds.forEach(function(id) {
                        postData.append('ids[]', id);
                    });
                }

                fetch(getAdminVars().ajax_url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: postData.toString()
                })
                .then(function(res) { return res.json(); })
                .then(function(res) {
                    btnConfirmBulkChannel.disabled = false;
                    btnConfirmBulkChannel.textContent = 'Guardar cambios';

                    if (!res.success) {
                        alert('Error: ' + ((res.data && res.data.message) || 'No se pudo actualizar'));
                        return;
                    }

                    var modal = bootstrap.Modal.getInstance(bulkChannelModalEl);
                    if (modal) modal.hide();

                    var updatedIds = (res.data && res.data.ids) || selectedIds;

                    // Si se marcó resincronizar inmediatamente
                    if (bulkResyncCheckbox && bulkResyncCheckbox.checked) {
                        launchBatchResync(updatedIds);
                    } else {
                        alert(res.data.message || 'Canal actualizado exitosamente.');
                        window.location.reload();
                    }
                })
                .catch(function(err) {
                    btnConfirmBulkChannel.disabled = false;
                    btnConfirmBulkChannel.textContent = 'Guardar cambios';
                    console.error('[ileben-api] Error actualizando canal masivo:', err);
                    alert('Error de conexión al actualizar canal.');
                });
            });
        }

        // Resincronización Masiva Directa
        var btnStartBulkResync = document.getElementById('btnStartBulkResync');
        if (btnStartBulkResync) {
            btnStartBulkResync.addEventListener('click', function() {
                var count = isAllFilteredSelected ? totalFiltered : selectedIds.length;
                if (count === 0) {
                    alert('Debes seleccionar al menos un contacto.');
                    return;
                }

                if (!confirm('¿Deseas resincronizar ' + count + ' contacto(s) seleccionados con la API?')) {
                    return;
                }

                if (isAllFilteredSelected) {
                    btnStartBulkResync.disabled = true;
                    btnStartBulkResync.innerHTML = '<i class="fa fa-spinner fa-spin me-1"></i> Preparando...';

                    var postData = new URLSearchParams();
                    postData.append('action', 'ileben_api_get_filtered_contact_ids');
                    postData.append('nonce', getAdminVars().nonce_get_ids);
                    postData.append('status', wrap.getAttribute('data-status') || '');
                    postData.append('channel', wrap.getAttribute('data-channel') || '');
                    postData.append('email', wrap.getAttribute('data-email') || '');
                    postData.append('date_from', wrap.getAttribute('data-date-from') || '');
                    postData.append('date_to', wrap.getAttribute('data-date-to') || '');

                    fetch(getAdminVars().ajax_url, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: postData.toString()
                    })
                    .then(function(res) { return res.json(); })
                    .then(function(res) {
                        btnStartBulkResync.disabled = false;
                        btnStartBulkResync.innerHTML = '<i class="fa fa-sync me-1"></i> Resincronizar Seleccionados';
                        if (!res.success || !res.data || !res.data.ids) {
                            alert('No se pudieron obtener los contactos filtrados.');
                            return;
                        }
                        launchBatchResync(res.data.ids);
                    })
                    .catch(function(err) {
                        btnStartBulkResync.disabled = false;
                        btnStartBulkResync.innerHTML = '<i class="fa fa-sync me-1"></i> Resincronizar Seleccionados';
                        console.error('[ileben-api] Error obteniendo IDs:', err);
                        alert('Error al preparar la sincronización.');
                    });
                } else {
                    launchBatchResync(selectedIds);
                }
            });
        }
    }

    // Orquestador de Resincronización por Lotes
    function launchBatchResync(allIds) {
        if (!allIds || allIds.length === 0) {
            alert('No hay contactos para sincronizar.');
            return;
        }

        syncCancelled = false;

        var modalEl = document.getElementById('ilebenBulkSyncProgressModal');
        if (!modalEl) return;

        var progressModal = bootstrap.Modal.getOrCreateInstance(modalEl);
        progressModal.show();

        var progressBar = document.getElementById('syncProgressBar');
        var progressPercent = document.getElementById('syncProgressPercent');
        var progressStatusText = document.getElementById('syncProgressStatusText');
        var syncCountSuccess = document.getElementById('syncCountSuccess');
        var syncCountValidation = document.getElementById('syncCountValidation');
        var syncCountFailed = document.getElementById('syncCountFailed');
        var syncQueueStatus = document.getElementById('syncQueueStatus');
        var syncLogContainer = document.getElementById('syncLogContainer');
        var btnCancelSync = document.getElementById('btnCancelSync');
        var btnFinishReload = document.getElementById('btnFinishReload');
        var btnCloseProgressModal = document.getElementById('btnCloseProgressModal');
        var spinnerIcon = document.getElementById('syncSpinnerIcon');

        // Reset UI
        progressBar.style.width = '0%';
        progressBar.classList.add('progress-bar-animated', 'progress-bar-striped');
        progressPercent.textContent = '0%';
        progressStatusText.textContent = 'Iniciando resincronización...';
        syncCountSuccess.textContent = '0';
        syncCountValidation.textContent = '0';
        syncCountFailed.textContent = '0';
        syncQueueStatus.textContent = '0 / ' + allIds.length + ' procesados';
        syncLogContainer.innerHTML = '<div>[' + new Date().toLocaleTimeString() + '] Iniciando cola de ' + allIds.length + ' envíos...</div>';
        btnCancelSync.classList.remove('d-none');
        btnCancelSync.disabled = false;
        btnFinishReload.classList.add('d-none');
        btnCloseProgressModal.classList.add('d-none');
        if (spinnerIcon) spinnerIcon.classList.add('fa-spin');

        var total = allIds.length;
        var processed = 0;
        var countOk = 0;
        var countVal = 0;
        var countErr = 0;

        btnCancelSync.onclick = function() {
            if (confirm('¿Deseas detener el proceso de resincronización? Los contactos ya procesados mantendrán su nuevo estado.')) {
                syncCancelled = true;
                btnCancelSync.disabled = true;
                btnCancelSync.textContent = 'Deteniendo...';
            }
        };

        // Bloques de 3 elementos para llamadas AJAX
        var batchSize = 3;
        var batches = [];
        for (var i = 0; i < allIds.length; i += batchSize) {
            batches.push(allIds.slice(i, i + batchSize));
        }

        function appendLog(msg, colorClass) {
            var line = document.createElement('div');
            line.className = colorClass || '';
            line.innerHTML = '<span class="text-secondary">[' + new Date().toLocaleTimeString() + ']</span> ' + msg;
            syncLogContainer.appendChild(line);
            syncLogContainer.scrollTop = syncLogContainer.scrollHeight;
        }

        function processBatch(batchIndex) {
            if (syncCancelled) {
                appendLog('⚠️ Proceso detenido por el usuario.', 'text-warning fw-bold');
                finishSync();
                return;
            }

            if (batchIndex >= batches.length) {
                appendLog('✓ Sincronización completada.', 'text-success fw-bold');
                finishSync();
                return;
            }

            var currentBatch = batches[batchIndex];
            progressStatusText.textContent = 'Procesando contactos ID: ' + currentBatch.join(', ') + '...';

            var postData = new URLSearchParams();
            postData.append('action', 'ileben_api_batch_resync_contacts');
            postData.append('nonce', getAdminVars().nonce_batch_resync);
            currentBatch.forEach(function(id) {
                postData.append('ids[]', id);
            });

            fetch(getAdminVars().ajax_url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: postData.toString()
            })
            .then(function(res) { return res.json(); })
            .then(function(res) {
                if (res.success && res.data && res.data.results) {
                    res.data.results.forEach(function(item) {
                        processed++;
                        if (item.success) {
                            countOk++;
                            appendLog('ID #' + item.id + ': Enviado correctamente (HTTP ' + item.status_code + ')', 'text-success');
                        } else if (item.status === 'validation_error') {
                            countVal++;
                            appendLog('ID #' + item.id + ': Error de validación (' + (item.error_message || item.message) + ')', 'text-warning');
                        } else {
                            countErr++;
                            appendLog('ID #' + item.id + ': Falló (' + (item.message || item.status) + ')', 'text-danger');
                        }
                    });
                } else {
                    currentBatch.forEach(function(id) {
                        processed++;
                        countErr++;
                        appendLog('ID #' + id + ': Error en respuesta del servidor', 'text-danger');
                    });
                }

                syncCountSuccess.textContent = countOk;
                syncCountValidation.textContent = countVal;
                syncCountFailed.textContent = countErr;

                var percent = Math.min(100, Math.round((processed / total) * 100));
                progressBar.style.width = percent + '%';
                progressPercent.textContent = percent + '%';
                syncQueueStatus.textContent = processed + ' / ' + total + ' procesados';

                setTimeout(function() {
                    processBatch(batchIndex + 1);
                }, 150);
            })
            .catch(function(err) {
                console.error('[ileben-api] Error procesando lote:', err);
                currentBatch.forEach(function(id) {
                    processed++;
                    countErr++;
                    appendLog('ID #' + id + ': Error de conexión al reintentar', 'text-danger');
                });

                syncCountFailed.textContent = countErr;
                var percent = Math.min(100, Math.round((processed / total) * 100));
                progressBar.style.width = percent + '%';
                progressPercent.textContent = percent + '%';
                syncQueueStatus.textContent = processed + ' / ' + total + ' procesados';

                setTimeout(function() {
                    processBatch(batchIndex + 1);
                }, 150);
            });
        }

        function finishSync() {
            progressBar.classList.remove('progress-bar-animated');
            if (spinnerIcon) spinnerIcon.classList.remove('fa-spin');
            progressStatusText.textContent = syncCancelled ? 'Proceso detenido.' : '¡Proceso finalizado!';
            btnCancelSync.classList.add('d-none');
            btnFinishReload.classList.remove('d-none');
            btnCloseProgressModal.classList.remove('d-none');
        }

        processBatch(0);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initBulkActions);
    } else {
        initBulkActions();
    }
})();


// ============================================================================
// IMAGE PICKER - jQuery para seleccionar imágenes en editor de plantas
// ============================================================================
(function ($) {
    'use strict';

    $(function () {
        console.log('[ileben-api] jQuery ready');
        
        var portadaSelectButton = $('#ileben_select_image_portada');
        var portadaRemoveButton = $('#ileben_remove_image_portada');
        var portadaInput = $('#ileben_image_url_portada');
        var portadaPreview = $('#ileben_image_preview_portada');

        var interiorSelectButton = $('#ileben_select_image_interior');
        var interiorRemoveButton = $('#ileben_remove_image_interior');
        var interiorInput = $('#ileben_image_url_interior');
        var interiorPreview = $('#ileben_image_preview_interior');

        var brochureSelectButton = $('#ileben_select_brochure');
        var brochureRemoveButton = $('#ileben_remove_brochure');
        var brochureInput = $('#ileben_brochure_url');
        var brochurePreview = $('#ileben_brochure_preview');
        var brochureFrame = null;

        function bindImagePicker(selectButton, removeButton, imageInput, preview, titleText, buttonText) {
            if (!selectButton.length || !removeButton.length || !imageInput.length || !preview.length) {
                return;
            }

            var mediaFrame = null;

            selectButton.on('click', function (event) {
                event.preventDefault();

                if (mediaFrame) {
                    mediaFrame.open();
                } else {
                    mediaFrame = wp.media({
                        title: titleText,
                        button: {
                            text: buttonText
                        },
                        multiple: false,
                        library: {
                            type: 'image'
                        }
                    });
                }

                mediaFrame.off('select');
                mediaFrame.on('select', function () {
                    var attachment = mediaFrame.state().get('selection').first().toJSON();
                    imageInput.val(attachment.url || '');

                    if (attachment.url) {
                        preview.attr('src', attachment.url).show();
                    }
                });

                mediaFrame.open();
            });

            removeButton.on('click', function (event) {
                event.preventDefault();
                imageInput.val('');
                var defaultSrc = preview.attr('data-default-src') || '';
                if (defaultSrc) {
                    preview.attr('src', defaultSrc).show();
                } else {
                    preview.attr('src', '').hide();
                }
            });
        }

        bindImagePicker(
            portadaSelectButton,
            portadaRemoveButton,
            portadaInput,
            portadaPreview,
            'Seleccionar imagen de portada',
            'Usar esta portada'
        );

        bindImagePicker(
            interiorSelectButton,
            interiorRemoveButton,
            interiorInput,
            interiorPreview,
            'Seleccionar imagen de interior',
            'Usar esta imagen'
        );

        // Brochure picker (solo en página de editar planta)
        if (brochureSelectButton.length && brochureInput.length && brochurePreview.length) {
            brochureSelectButton.on('click', function (event) {
                event.preventDefault();

                if (brochureFrame) {
                    brochureFrame.open();
                    return;
                }

                brochureFrame = wp.media({
                    title: 'Seleccionar brochure',
                    button: {
                        text: 'Usar este archivo'
                    },
                    multiple: false
                });

                brochureFrame.on('select', function () {
                    var attachment = brochureFrame.state().get('selection').first().toJSON();
                    brochureInput.val(attachment.url || '');

                    if (attachment.url) {
                        brochurePreview.attr('href', attachment.url).show();
                    }
                });

                brochureFrame.open();
            });

            brochureRemoveButton.on('click', function (event) {
                event.preventDefault();
                brochureInput.val('');
                brochurePreview.attr('href', '#').hide();
            });
        }
    });
})(jQuery);

