(function ($) {
    'use strict';

    $(function () {
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

        if (!brochureSelectButton.length || !brochureInput.length || !brochurePreview.length) {
            return;
        }

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
    });
})(jQuery);
