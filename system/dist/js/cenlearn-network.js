/**
 * CenLearn Universal Network & Loading Engine (v2.7 - Production Resilient)
 * =========================================================================
 * - Non-blocking network notifications
 * - Safe manual loader controls (showCenLoader / hideCenLoader)
 * - Auto-dismiss and escape hatch to prevent permanent screen freezing
 * - Responsive resize handlers
 */

(function (window, $) {
  'use strict';

  var CenNetwork = {
    isOnline: typeof navigator.onLine !== 'undefined' ? navigator.onLine : true,
    autoHideTimeout: null,
    logoPath: 'dist/img/bcc_logo.jpg',

    init: function () {
      this.resolveAssetPath();
      this.createUniversalOverlay();
      this.bindNetworkEvents();
      this.setupResponsiveResize();
    },

    // ── Resolve relative path to dist/img/bcc_logo.jpg ───────────────────────
    resolveAssetPath: function () {
      var scriptTag = document.querySelector('script[src*="cenlearn-network.js"]');
      if (scriptTag) {
        var src = scriptTag.getAttribute('src');
        var idx = src.indexOf('dist/js/cenlearn-network.js');
        if (idx !== -1) {
          var prefix = src.substring(0, idx);
          this.logoPath = prefix + 'dist/img/bcc_logo.jpg';
          return;
        }
      }

      // Fallback based on pathname depth (works both locally /system/student/ and online /student/)
      var path = window.location.pathname.replace(/\\/g, '/');
      if (path.indexOf('/student/') !== -1 ||
          path.indexOf('/teacher/') !== -1 ||
          path.indexOf('/superadmin/') !== -1 ||
          path.indexOf('/admin/') !== -1 ||
          path.indexOf('/shared/') !== -1) {
        this.logoPath = '../dist/img/bcc_logo.jpg';
      } else {
        this.logoPath = 'dist/img/bcc_logo.jpg';
      }
    },

    // ── Create or ensure the Universal Screen Overlay ────────────────────────
    createUniversalOverlay: function () {
      if ($('#cenlearn-universal-overlay').length > 0) return;

      var overlayHtml = [
        '<div id="cenlearn-universal-overlay" class="cl-fs-loader">',
        '  <button type="button" id="cl-overlay-close-btn" class="cl-loader-close-btn" title="Dismiss">&times;</button>',
        '  <div class="cl-loader-brand">',
        '    <span class="brand-cen">Cen</span><span class="brand-learn">Learn</span>',
        '  </div>',
        '  <div class="cl-loader-spinner-wrap">',
        '    <div id="cl-overlay-ring" class="cl-loader-spinner-ring"></div>',
        '    <img id="cl-overlay-logo" src="' + this.logoPath + '" alt="Bago City College" class="cl-loader-logo">',
        '  </div>',
        '  <div id="cl-overlay-text" class="cl-loader-text">',
        '    <span id="cl-overlay-label">Loading</span><span id="cl-overlay-dots" class="loading-dots"></span>',
        '  </div>',
        '  <button id="cl-overlay-retry-btn" class="cl-loader-retry-btn" style="display:none;" type="button">',
        '    <i class="fa fa-refresh"></i> Retry Connection',
        '  </button>',
        '</div>'
      ].join('\n');

      $('body').append(overlayHtml);

      var self = this;
      $(document).on('click', '#cl-overlay-close-btn', function () {
        self.hideLoader();
      });

      // Bind retry click
      $(document).on('click', '#cl-overlay-retry-btn', function () {
        var $btn = $(this);
        $btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Checking...');
        setTimeout(function () {
          if (navigator.onLine) {
            self.showOnline('Connection Restored!');
          } else {
            $btn.prop('disabled', false).html('<i class="fa fa-refresh"></i> Retry Connection');
          }
        }, 600);
      });
    },

    // ── Show / Hide Public Methods ───────────────────────────────────────────
    showLoader: function (message, maxDuration) {
      this.createUniversalOverlay();
      var self = this;
      var $overlay = $('#cenlearn-universal-overlay');
      var $ring = $('#cl-overlay-ring');
      var $label = $('#cl-overlay-label');
      var $dots = $('#cl-overlay-dots');
      var $retry = $('#cl-overlay-retry-btn');
      var $close = $('#cl-overlay-close-btn');

      clearTimeout(this.autoHideTimeout);

      $ring.removeClass('offline success');
      $label.text(message || 'Loading');
      $dots.show();
      $retry.hide();
      $close.show();

      $overlay.css('display', 'flex');
      setTimeout(function () {
        $overlay.addClass('active');
      }, 10);

      // Safety timeout: auto-hide after duration (default 8s) if never explicitly hidden
      var limit = maxDuration || 8000;
      this.autoHideTimeout = setTimeout(function () {
        self.hideLoader();
      }, limit);
    },

    hideLoader: function () {
      var $overlay = $('#cenlearn-universal-overlay');
      if (!$overlay.length) return;
      clearTimeout(this.autoHideTimeout);
      $overlay.removeClass('active');
      setTimeout(function () {
        if (!$overlay.hasClass('active')) {
          $overlay.hide();
        }
      }, 200);
    },

    showOffline: function (message) {
      this.createUniversalOverlay();
      var self = this;
      var $overlay = $('#cenlearn-universal-overlay');
      var $ring = $('#cl-overlay-ring');
      var $label = $('#cl-overlay-label');
      var $dots = $('#cl-overlay-dots');
      var $retry = $('#cl-overlay-retry-btn');
      var $close = $('#cl-overlay-close-btn');

      clearTimeout(this.autoHideTimeout);

      $ring.removeClass('success').addClass('offline');
      $label.text(message || 'No Internet Connection');
      $dots.hide();
      $retry.show().prop('disabled', false).html('<i class="fa fa-refresh"></i> Retry Connection');
      $close.show();

      $overlay.css('display', 'flex');
      setTimeout(function () {
        $overlay.addClass('active');
      }, 10);

      // Auto-dismiss offline warning after 6 seconds so user is never locked out
      this.autoHideTimeout = setTimeout(function () {
        self.hideLoader();
      }, 6000);
    },

    showOnline: function (message) {
      this.createUniversalOverlay();
      var self = this;
      var $overlay = $('#cenlearn-universal-overlay');
      var $ring = $('#cl-overlay-ring');
      var $label = $('#cl-overlay-label');
      var $dots = $('#cl-overlay-dots');
      var $retry = $('#cl-overlay-retry-btn');

      clearTimeout(this.autoHideTimeout);

      $ring.removeClass('offline').addClass('success');
      $label.text(message || 'Connection Restored!');
      $dots.hide();
      $retry.hide();

      $overlay.css('display', 'flex');
      setTimeout(function () {
        $overlay.addClass('active');
      }, 10);

      this.autoHideTimeout = setTimeout(function () {
        self.hideLoader();
      }, 1200);
    },

    // ── Online Events ────────────────────────────────────────────────────────
    bindNetworkEvents: function () {
      var self = this;

      window.addEventListener('online', function () {
        self.isOnline = true;
        self.showOnline('Connection Restored!');
        $(document).trigger('cenlearn:online');
      });
    },

    // ── Responsive Layout & Resize Optimizer ─────────────────────────────────
    setupResponsiveResize: function () {
      var resizeTimer;

      function onWindowResize() {
        var w = $(window).width();

        if (w > 900) {
          $('.cl-sidebar-overlay, #sidebarOverlay').removeClass('active').hide();
          $('.cl-sidebar, .t-sidebar, .td-sidebar, .s-sidebar, .sd-sidebar, .mc-sidebar, .main-sidebar, #sidebar').removeClass('open');
        }

        $(document).trigger('cenlearn:resize', { width: w, isMobile: w <= 900 });
      }

      $(window).on('resize orientationchange', function () {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(onWindowResize, 100);
      });

      $(document).ready(function () {
        onWindowResize();
      });
    }
  };

  // ── Global Helper Bindings ─────────────────────────────────────────────────
  window.CenNetwork = CenNetwork;
  window.showCenLoader  = function (msg, maxDuration) { CenNetwork.showLoader(msg, maxDuration); };
  window.hideCenLoader  = function () { CenNetwork.hideLoader(); };
  window.showCenOffline = function (msg) { CenNetwork.showOffline(msg); };
  window.showCenOnline  = function (msg) { CenNetwork.showOnline(msg); };

  $(document).ready(function () {
    CenNetwork.init();
  });

})(window, window.jQuery);
