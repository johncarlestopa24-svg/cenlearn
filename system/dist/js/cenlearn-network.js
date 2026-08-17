/**
 * CenLearn Universal Network & Loading Engine
 * ============================================
 * - Universal Floating Transparent Loader Screen (CenLearn, Logo, Loading...)
 * - Automatic Real-Time No Network / Offline Indicator
 * - Multi-WiFi Switch & Smart AJAX Retry with Backoff
 * - Universal Exposure: window.showCenLoader(), window.hideCenLoader(), window.showCenOffline()
 */

(function (window, $) {
  'use strict';

  var CenNetwork = {
    isOnline: navigator.onLine !== undefined ? navigator.onLine : true,
    retryCount: 0,
    maxRetries: 3,
    autoHideTimeout: null,
    logoPath: 'dist/img/bcc_logo.jpg',

    init: function () {
      this.resolveAssetPath();
      this.createUniversalOverlay();
      this.bindNetworkEvents();
      this.setupAjaxRetry();
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

      // Fallback based on pathname depth
      var path = window.location.pathname.replace(/\\/g, '/');
      if (path.indexOf('/system/student/') !== -1 ||
          path.indexOf('/system/teacher/') !== -1 ||
          path.indexOf('/system/superadmin/') !== -1 ||
          path.indexOf('/system/admin/') !== -1 ||
          path.indexOf('/system/shared/') !== -1) {
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

      // Bind retry click
      $(document).on('click', '#cl-overlay-retry-btn', function () {
        $(this).prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Checking...');
        setTimeout(function () {
          if (navigator.onLine) {
            CenNetwork.showOnline('Connection Restored!');
          } else {
            $('#cl-overlay-retry-btn').prop('disabled', false).html('<i class="fa fa-refresh"></i> Retry Connection');
          }
        }, 800);
      });
    },

    // ── Show / Hide Public Methods ───────────────────────────────────────────
    showLoader: function (message) {
      this.createUniversalOverlay();
      var $overlay = $('#cenlearn-universal-overlay');
      var $ring = $('#cl-overlay-ring');
      var $label = $('#cl-overlay-label');
      var $dots = $('#cl-overlay-dots');
      var $retry = $('#cl-overlay-retry-btn');

      clearTimeout(this.autoHideTimeout);

      $ring.removeClass('offline success');
      $label.text(message || 'Loading');
      $dots.show();
      $retry.hide();

      $overlay.css('display', 'flex');
      setTimeout(function () {
        $overlay.addClass('active');
      }, 10);
    },

    hideLoader: function () {
      var $overlay = $('#cenlearn-universal-overlay');
      $overlay.removeClass('active');
      setTimeout(function () {
        if (!$overlay.hasClass('active')) {
          $overlay.hide();
        }
      }, 250);
    },

    showOffline: function (message) {
      this.createUniversalOverlay();
      var $overlay = $('#cenlearn-universal-overlay');
      var $ring = $('#cl-overlay-ring');
      var $label = $('#cl-overlay-label');
      var $dots = $('#cl-overlay-dots');
      var $retry = $('#cl-overlay-retry-btn');

      clearTimeout(this.autoHideTimeout);

      $ring.removeClass('success').addClass('offline');
      $label.text(message || 'No Internet Connection');
      $dots.hide();
      $retry.show().prop('disabled', false).html('<i class="fa fa-refresh"></i> Retry Connection');

      $overlay.css('display', 'flex');
      setTimeout(function () {
        $overlay.addClass('active');
      }, 10);
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
      }, 1500);
    },

    // ── Online / Offline Events ──────────────────────────────────────────────
    bindNetworkEvents: function () {
      var self = this;

      window.addEventListener('online', function () {
        self.isOnline = true;
        self.showOnline('Connection Restored!');
        $(document).trigger('cenlearn:online');
      });

      window.addEventListener('offline', function () {
        self.isOnline = false;
        self.showOffline('No Internet Connection');
        $(document).trigger('cenlearn:offline');
      });
    },

    // ── Smart AJAX Auto-Retry for WiFi Handshakes ────────────────────────────
    setupAjaxRetry: function () {
      if (!$) return;
      var self = this;

      $.ajaxPrefilter(function (options, originalOptions, jqXHR) {
        if (options.noRetry) return;

        var originalError = options.error;
        var retryCount = 0;

        options.error = function (xhr, status, error) {
          if ((xhr.status === 0 || status === 'timeout') && retryCount < self.maxRetries) {
            retryCount++;
            var delay = Math.pow(2, retryCount) * 400; // 800ms, 1600ms, 3200ms

            self.showLoader('Reconnecting to network...', true);

            setTimeout(function () {
              $.ajax($.extend({}, originalOptions, {
                noRetry: retryCount >= self.maxRetries
              }));
            }, delay);
            return;
          }

          if (typeof originalError === 'function') {
            originalError.apply(this, arguments);
          }
        };
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
  window.showCenLoader  = function (msg, backdrop) { CenNetwork.showLoader(msg, backdrop); };
  window.hideCenLoader  = function () { CenNetwork.hideLoader(); };
  window.showCenOffline = function (msg) { CenNetwork.showOffline(msg); };
  window.showCenOnline  = function (msg) { CenNetwork.showOnline(msg); };

  $(document).ready(function () {
    CenNetwork.init();
  });

})(window, window.jQuery);
