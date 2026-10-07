(function (Drupal, $) {
  Drupal.behaviors.clipboardBehavior = {
    attach: function (context) {
      new ClipboardJS('.use-clipboard-js');

      $('.use-clipboard-js', context).on('click', function (e) {
        e.preventDefault();
        $(this).closest('.text-content').find('.clipboard-feedback').fadeIn().delay(1000).fadeOut();
      });
    }
  };
})(Drupal, jQuery);