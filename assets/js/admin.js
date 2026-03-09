(function ($) {
    'use strict';

    $(function () {
        var selectButton = $('#ileben_select_image');
        var removeButton = $('#ileben_remove_image');
        var imageInput = $('#ileben_image_url');
        var preview = $('#ileben_image_preview');
        var brochureSelectButton = $('#ileben_select_brochure');
        var brochureRemoveButton = $('#ileben_remove_brochure');
        var brochureInput = $('#ileben_brochure_url');
        var brochurePreview = $('#ileben_brochure_preview');
        var imageFrame = null;
        var brochureFrame = null;

        if (!selectButton.length || !imageInput.length || !preview.length) {
            return;
        }

        selectButton.on('click', function (event) {
            event.preventDefault();

            if (imageFrame) {
                imageFrame.open();
                return;
            }

            imageFrame = wp.media({
                title: 'Seleccionar imagen de la planta',
                button: {
                    text: 'Usar esta imagen'
                },
                multiple: false,
                library: {
                    type: 'image'
                }
            });

            imageFrame.on('select', function () {
                var attachment = imageFrame.state().get('selection').first().toJSON();
                imageInput.val(attachment.url || '');

                if (attachment.url) {
                    preview.attr('src', attachment.url).show();
                }
            });

            imageFrame.open();
        });

        removeButton.on('click', function (event) {
            event.preventDefault();
            imageInput.val('');
            preview.attr('src', '').hide();
        });

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
