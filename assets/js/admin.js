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

        var fields = payload.fields || {};
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
        
        var modal = new bootstrap.Modal(modalElement);
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

