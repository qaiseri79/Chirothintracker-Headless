(function ($) {
  "use strict";
  /**
   * Enable the colorbox inline functionality.
   */
  Drupal.behaviors.colorboxInline = {
    attach: function (context, drupalSettings) {
      $(once('colorbox-inline-processed', '[data-colorbox-inline]', context)).each(function () {
        var $link = $(this),
          data = $link.data(),
          inlineTarget = data.colorboxInline;

        if (typeof inlineTarget !== 'string' || inlineTarget.indexOf('<') !== -1) {
          return;
        }

        var settings = $.extend({}, drupalSettings.colorbox, {
          href: false,
          inline: true
        }, {
          className: data.class,
          href: inlineTarget,
          width: data.width,
          height: data.height,
          rel: data.rel,
          open: false
        });

        if (!$(document).find(inlineTarget).filter(':visible')[0]) {
          settings.onCleanup = function() {
            $(document).find(inlineTarget).hide();
          }
        }

        $link.colorbox(settings);
        $link.click(function () {
         $(document).find(inlineTarget).show();
         $(this).colorbox();
        });
      });
    }
  };
})(jQuery);
