<?php
$callerDir = str_replace('\\', '/', realpath(dirname($_SERVER['SCRIPT_FILENAME'] ?? __FILE__)));
$systemDir = str_replace('\\', '/', realpath(__DIR__ . '/..'));
$rel = ltrim(str_replace($systemDir, '', $callerDir), '/');
$depth = ($rel === '') ? 0 : (substr_count($rel, '/') + 1);
$_prefix = str_repeat('../', $depth);
?>
<script src="<?php echo $_prefix; ?>bower_components/jquery/dist/jquery.min.js"></script>
<script src="<?php echo $_prefix; ?>bower_components/bootstrap/dist/js/bootstrap.min.js"></script>
<script src="<?php echo $_prefix; ?>bower_components/jquery-slimscroll/jquery.slimscroll.min.js"></script>
<script src="<?php echo $_prefix; ?>bower_components/fastclick/lib/fastclick.js"></script>
<script src="<?php echo $_prefix; ?>dist/js/adminlte.min.js"></script>
<script src="<?php echo $_prefix; ?>dist/js/cenlearn-network.js?v=2.6"></script>
<script>
// Universal Responsive Sidebar & Overlay Control
(function() {
  function getSidebar() {
    return $('.cl-sidebar, .t-sidebar, .td-sidebar, .s-sidebar, .sd-sidebar, .mc-sidebar, .main-sidebar, #sidebar');
  }

  function getOverlay() {
    var $overlay = $('.cl-sidebar-overlay, #sidebarOverlay');
    if ($overlay.length === 0) {
      $overlay = $('<div class="cl-sidebar-overlay" id="sidebarOverlay"></div>').appendTo('body');
    }
    return $overlay;
  }

  window.openSidebar = function() {
    getSidebar().addClass('open');
    getOverlay().addClass('active').show();
  };

  window.closeSidebar = function() {
    getSidebar().removeClass('open');
    getOverlay().removeClass('active').hide();
  };

  window.toggleSidebar = function() {
    var $s = getSidebar();
    if ($s.hasClass('open')) {
      window.closeSidebar();
    } else {
      window.openSidebar();
    }
  };

  $(document).ready(function() {
    $(document).on('click touchend', '.cl-hamburger, [data-toggle="cl-sidebar"], [data-toggle="offcanvas"], .sidebar-toggle, .hamburger-btn', function(e) {
      e.preventDefault();
      e.stopPropagation();
      window.toggleSidebar();
    });

    $(document).on('click touchend', '.cl-sidebar-overlay, #sidebarOverlay', function(e) {
      e.preventDefault();
      window.closeSidebar();
    });

    // Auto-close sidebar drawer on mobile after tapping link
    $(document).on('click', '.cl-sidebar a, .t-sidebar a, .td-sidebar a, .s-sidebar a, .sd-sidebar a, .mc-sidebar a, .main-sidebar a, #sidebar a', function() {
      if ($(window).width() <= 900) {
        setTimeout(function() {
          window.closeSidebar();
        }, 150);
      }
    });

    // Auto wrap standalone tables for mobile responsiveness
    $('table:not(.table-responsive table):not(.table-scroll table):not(.cl-table-responsive table)').each(function() {
      if (!$(this).parent().hasClass('table-responsive') && !$(this).parent().hasClass('table-scroll') && !$(this).parent().hasClass('cl-table-responsive')) {
        $(this).wrap('<div class="table-responsive"></div>');
      }
    });
  });
})();
</script>
