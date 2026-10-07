(function (Drupal, once) {

  'use strict';

  Drupal.behaviors.cttIntakeClipboard = {
    attach: function (context) {
      once('ctt-intake-copy', '[data-ctt-copy]', context).forEach(function (button) {
        button.addEventListener('click', function () {
          var target = document.querySelector(button.getAttribute('data-ctt-copy'));
          if (!target) {
            return;
          }
          target.select();
          document.execCommand('copy');
          if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(target.value);
          }
          var original = button.textContent;
          button.textContent = Drupal.t('Copied');
          window.setTimeout(function () {
            button.textContent = original;
          }, 1500);
        });
      });
    }
  };

})(Drupal, once);