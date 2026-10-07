document.addEventListener("DOMContentLoaded", function () {

  const header = document.querySelector(".header-top");

  // Header sticky class add
  function handleStickyMenu() {
    const sticky = header.offsetTop;
    if (window.pageYOffset > sticky) {
      header.classList.add("sticky");
    } else {
      header.classList.remove("sticky");
    }
  }

  // Event listeners
  window.addEventListener("scroll", handleStickyMenu);

  /************START - Add hasSubcat class on desktop sidebar menu************/
  function applyOnClickSidebarMenu() {
    var width = jQuery(window).width();
    if (width > 270) {
      jQuery(".region-sidebar-menu .navbar-nav li.nav-item").each(function () {
        if (jQuery(this).find("ul").length) {
          jQuery(this).addClass("has-sub-menu");
        }
      });
    }
  }
  applyOnClickSidebarMenu();

  // Open sidebar submenu
  jQuery(".region-sidebar-menu .navbar-nav li.nav-item.has-sub-menu").click(
    function (e) {
      jQuery(this).toggleClass("submenu-open");
      jQuery(this).find("ul").slideToggle();
    }
  );

  jQuery(window).resize(function () {
    applyOnClickSidebarMenu();
  });
  /************END - Add hasSubcat class on desktop sidebar menu************/

  /************START - Open User account dropdown on Desktop************/
  jQuery(".header-top ul.navbar-nav li.nav-item").click(function (e) {
    jQuery(this)
      .closest(".header-top ul.navbar-nav")
      .toggleClass("dropdown-open");
    jQuery(this).find("ul").slideToggle();
  });
  /************END - Open User account dropdown on Desktop************/

  /************START - Dashboard toggle button click on Desktop************/
  jQuery(".header-top .dashboard-toggle").click(function (e) {
    jQuery(".website-layout").toggleClass("hideSidebar");
  });
  /************END - Dashboard toggle button click on Desktop************/


  const progressChart = document.querySelector(".view-progress-chart");
  if (progressChart) {
    // Check if the div has child elements or meaningful content
    const hasChildren = progressChart.children.length > 0;
    const hasContent = progressChart.innerHTML.trim() !== "";

    if (hasChildren || hasContent) {
      progressChart.classList.remove("no-content");
    } else {
      progressChart.classList.add("no-content");
    }
  }


  const patientDetails = document.querySelector(".view-patient-details");

  if (patientDetails) {
    // Check for meaningful content (ignores comments and whitespace)
    const hasMeaningfulContent = patientDetails.children.length > 0 || patientDetails.textContent.trim() !== "";

    if (hasMeaningfulContent) {
      patientDetails.classList.remove("no-content");
    } else {
      patientDetails.classList.add("no-content");
    }
  }

  var uid = jQuery("#meeting-uid").text();
  var url =
    "window.open('https://meet.chirothintracker.com/room/" +
    uid +
    "', '_blank')";
  jQuery(".view-my-meeting-room span.button").attr("onClick", url);
});

// jQuery('ul.review-bill-ul li a').click(function(e){
//      e.preventDefault();
//       var href =  jQuery(this).attr('href');
//       var query = href.split("?")[1];
//       console.log(href.split("?"));
//     jQuery.ajax({
//                 url : "/message-request",
//                 data: {"query":query},
//                 beforeSend : function() {
//                  jQuery(".custom-loader").css("display","block");
//               },
//               type : 'POST',
//               cache : false,
//               success : function(data) {
//                  jQuery(".custom-loader").css("display","none");
//                // console.log(JSON.stringify(data));
//                console.log(data);
//                location.reload();
//               },
//               error : function(xhr, status, error) {
//                 if (xhr.status > 0)
//                   alert('got error: ' + status);
//               }
//             });
// });