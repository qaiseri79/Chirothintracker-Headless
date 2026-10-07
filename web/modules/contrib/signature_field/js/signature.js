(function ($, Drupal, once) {
  Drupal.behaviors.signature_field = {
    attach(context) {
      // Loop over signature instances.
      $(once('signature_field', '.signature', context)).each(function () {
        const $signature = $(this);
        const $signature_canvas = $('.signature-canvas', $signature);
        const $signature_thumb = $('.signature-thumb', $signature);
        const $signature_data = $('.signature-data', $signature);

        // Initialize signature pad.
        const signaturePad = new SignaturePad($signature_canvas[0]);

        // Set min line width.
        signaturePad.minWidth = 2.5;
        if ($signature_canvas.data('min-line-width')) {
          signaturePad.minWidth = $signature_canvas.data('min-line-width');
        }

        // Set max line width.
        signaturePad.maxWidth = 5;
        if ($signature_canvas.data('max-line-width')) {
          signaturePad.maxWidth = $signature_canvas.data('max-line-width');
        }

        // Set background color.
        signaturePad.backgroundColor = 'rgb(250, 250, 250)';
        if ($signature_canvas.data('bg-color')) {
          signaturePad.backgroundColor = $signature_canvas.data('bg-color');
        }

        // Set pen color.
        signaturePad.penColor = 'rgb(66, 133, 244)';
        if ($signature_canvas.data('pen-color')) {
          signaturePad.penColor = $signature_canvas.data('pen-color');
        }

        // endStroke event.
        signaturePad.addEventListener(
          'endStroke',
          () => {
            $signature_data.val(signaturePad.toDataURL());
            $signature_thumb.attr({ src: signaturePad.toDataURL() });
          },
          { once: false },
        );

        // Clean signature click event.
        const $clear_signature_btn = $('.clear-signature-button', $signature);
        $clear_signature_btn.click(function () {
          signaturePad.clear();
          $signature_thumb.attr({ src: '' });
          $signature_data.val('');
        });

        // Set signature image.
        const $signature_image = $('.signature-image', $signature);
        if ($signature_image.attr('src')) {
          $signature_canvas.hide();
          $signature_thumb.attr({ src: $signature_image.attr('src') });
          $clear_signature_btn.addClass('change-canvas');
        }

        // Change canvas click event.
        const $change_canvas = $('.change-canvas', $signature);
        if ($change_canvas.length) {
          $change_canvas.click(function () {
            $clear_signature_btn.removeClass('change-canvas');
            $signature_image.hide().attr({ src: '' });
            $signature_thumb.attr({ src: '' });
            $signature_canvas.show();
          });
        }
      });
    },
    detach(context) {},
  };
})(jQuery, Drupal, once);
