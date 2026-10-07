jQuery(document).ready(function($){

var uid = jQuery("#meeting-uid").text();
var url = "window.open('https://meet.chirothintracker.com/room/"+uid+"', '_blank')";
jQuery(".view-my-meeting-room span.button").attr("onClick", url);


  jQuery("body").tooltip({ selector: '[data-bs-toggle=tooltip]' });
   var popoverTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="popover"]'))
  var popoverList = popoverTriggerList.map(function (popoverTriggerEl) {
    return new bootstrap.Popover(popoverTriggerEl)
  })

    const list = [].slice.call(document.querySelectorAll('[data-bs-toggle="popover"]'))
  list.map((el) => {
    let opts = {
      animation: false,
    }
    if (el.hasAttribute('data-bs-content-id')) {
      opts.content = document.getElementById(el.getAttribute('data-bs-content-id')).innerHTML;
      opts.html = true;
    }
    new bootstrap.Popover(el, opts);
  })

});
Drupal.behaviors.infiniteScrollShowButton= {
  attach: function (context, settings) {
    if (jQuery('.view-patients.view-display-id-page_1').length === 1) {
         // jQuery("body").tooltip({ selector: '[data-bs-toggle=tooltip]' });
           var popoverTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="popover"]'))
          var popoverList = popoverTriggerList.map(function (popoverTriggerEl) {
            return new bootstrap.Popover(popoverTriggerEl)
          })

            const list = [].slice.call(document.querySelectorAll('[data-bs-toggle="popover"]'))
          list.map((el) => {
            let opts = {
              animation: false,
            }
            if (el.hasAttribute('data-bs-content-id')) {
              opts.content = document.getElementById(el.getAttribute('data-bs-content-id')).innerHTML;
              opts.html = true;
            }
            new bootstrap.Popover(el, opts);
          })

    }
  }
};


(function ($, Drupal, drupalSettings) {
  Drupal.behaviors.hideSelectAllOption = { 
    attach: function(context,settings) {
      if(drupalSettings.hasOwnProperty("custom_js")){
         if(drupalSettings.custom_js.hasOwnProperty("user_role")){
             if(drupalSettings.custom_js.user_role == "chiropractor_active_"){
              jQuery(".form-item-checkallfield-published-to").hide();
            }
         }
      }
      //console.log(drupalSettings.custom_js.user_role);    
    }
  };

})(jQuery, Drupal, drupalSettings);
(function ($) {
  Drupal.behaviors.refreshDataBehavior = {
    attach: function (context, settings) {
      // Attach the click event to the "Refresh Data" link.
      $('.trigger-refresh-data', context).once('refreshDataBehavior').on('click', function (e) {
        e.preventDefault(); 
        var uid = $(this).data('uid');
        var progressBar = void 0;
        var batch_id = '';
        function updateCallback(progress, status, pb) {
          $('#refresh-data-progress').html(progress + '%');
          if (progress === '100') {
            pb.stopMonitoring();
            // Call the finishCallback()
            $.ajax({
              url: Drupal.url('batch?id=' + batch_id + '&op=finished'),
              type: 'POST',
              contentType: 'application/json; charset=utf-8',
              dataType: 'json',
              success: function success(val) {
                window.location.reload();
              }
            });
          }
        }

        // Call batch controller by ajax & post method.
        const url = 'update-submission-data/ajax';
        $.ajax({
          url: Drupal.url(url),
          type: 'POST',
          data: {
            'uid': uid,
          },
          dataType: 'json',
          success: function success(value) {
            batch_id = value[0].command;
            // Display batch progress
            progressBar = new Drupal.ProgressBar('refresh-data-progress', updateCallback, 'POST');
            progressBar.setProgress(0, 'Updating Data');
            progressBar.startMonitoring(value[0].data + '&op=do', 10);
          }
        });

      });
    },
  };
})(jQuery);
