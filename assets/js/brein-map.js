(function () {
  function init() {
    if (!window.maplibregl || !window.breinMapWidget) return;

    var el = document.getElementById('brein-map');
    if (!el) return;

    var styleUrl = breinMapWidget.styleUrl || 'https://basemaps.cartocdn.com/gl/positron-gl-style/style.json';
    var center = breinMapWidget.center || [0, 20];
    var zoom = typeof breinMapWidget.zoom === 'number' ? breinMapWidget.zoom : 1.5;
    var lowPowerKey = 'brein-map-low-power';
    var lowPower = false;
    try {
      lowPower = localStorage.getItem(lowPowerKey) === '1';
    } catch (e) {}

    function initMap(style) {
      var map = new maplibregl.Map({
        container: 'brein-map',
        style: style,
        center: center,
        zoom: zoom,
        renderWorldCopies: false,
        zoom: 1.5

      });

      map.addControl(new maplibregl.NavigationControl({ showCompass: false, showZoom: true }), 'top-right');
      if (typeof maplibregl.FullscreenControl === 'function') {
        map.addControl(new maplibregl.FullscreenControl({}), 'top-right');
      }

      function AutoRotateControl() {
        this._map = null;
        this._container = null;
        this._btn = null;
        this._raf = null;
        this._enabled = false;
        this._speed = 0.05;
      }

      AutoRotateControl.prototype.onAdd = function (map) {
        this._map = map;
        var container = document.createElement('div');
        container.className = 'maplibregl-ctrl maplibregl-ctrl-group';

        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'maplibregl-ctrl-icon brein-rotate-btn';
        button.setAttribute('aria-label', 'Toggle auto rotate');
        button.title = 'Auto rotate';
        button.innerHTML = '<svg width="14px" height="14px" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><g id="SVGRepo_bgCarrier" stroke-width="0"></g><g id="SVGRepo_tracerCarrier" stroke-linecap="round" stroke-linejoin="round"></g><g id="SVGRepo_iconCarrier"> <path d="M11.5 20.5C6.80558 20.5 3 16.6944 3 12C3 7.30558 6.80558 3.5 11.5 3.5C16.1944 3.5 20 7.30558 20 12C20 13.5433 19.5887 14.9905 18.8698 16.238M22.5 15L18.8698 16.238M17.1747 12.3832L18.5289 16.3542L18.8698 16.238" stroke="#ffffff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></path> </g></svg>';

        var self = this;
        button.addEventListener('click', function (e) {
          e.preventDefault();
          e.stopPropagation();
          self.toggle();
        });

        container.appendChild(button);
        this._container = container;
        this._btn = button;

        map.on('dragstart', function () { self.stop(); });
        map.on('zoomstart', function () { self.stop(); });

        return container;
      };

      AutoRotateControl.prototype.onRemove = function () {
        if (this._container && this._container.parentNode) {
          this._container.parentNode.removeChild(this._container);
        }
        this.stop();
        this._map = null;
      };

      AutoRotateControl.prototype.toggle = function () {
        if (this._enabled) {
          this.stop();
        } else {
          this.start();
        }
      };

      AutoRotateControl.prototype.start = function () {
        if (!this._map) return;
        this._enabled = true;
        if (this._btn) {
          this._btn.classList.add('is-active');
        }
        this._tick();
      };

      AutoRotateControl.prototype.stop = function () {
        this._enabled = false;
        if (this._btn) {
          this._btn.classList.remove('is-active');
        }
        if (this._raf) {
          cancelAnimationFrame(this._raf);
          this._raf = null;
        }
      };

      AutoRotateControl.prototype._tick = function () {
        var self = this;
        if (!self._enabled || !self._map) return;
        var bearing = self._map.getBearing();
        self._map.setBearing(bearing + self._speed);
        self._raf = requestAnimationFrame(function () { self._tick(); });
      };

      var autoRotateControl = null;
      if (!lowPower) {
        autoRotateControl = new AutoRotateControl();
        map.addControl(autoRotateControl, 'top-right');
      }

      function LowPowerControl() {
        this._map = null;
        this._container = null;
        this._btn = null;
      }

      LowPowerControl.prototype.onAdd = function (mapRef) {
        this._map = mapRef;
        var container = document.createElement('div');
        container.className = 'maplibregl-ctrl maplibregl-ctrl-group';

        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'maplibregl-ctrl-icon brein-lowpower-btn';
        button.setAttribute('aria-label', 'Toggle low power mode');
        button.title = 'Low power mode';
        button.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M3 12h10.5l-3.2 3.2a1 1 0 1 0 1.4 1.4l5-5a1 1 0 0 0 0-1.4l-5-5a1 1 0 0 0-1.4 1.4L13.5 10H3a1 1 0 1 0 0 2z" fill="#ffffff"/></svg>';

        var self = this;
        button.addEventListener('click', function (e) {
          e.preventDefault();
          e.stopPropagation();
          self.toggle();
        });

        container.appendChild(button);
        this._container = container;
        this._btn = button;
        this._sync();

        return container;
      };

      LowPowerControl.prototype.onRemove = function () {
        if (this._container && this._container.parentNode) {
          this._container.parentNode.removeChild(this._container);
        }
        this._map = null;
      };

      LowPowerControl.prototype.toggle = function () {
        lowPower = !lowPower;
        try {
          localStorage.setItem(lowPowerKey, lowPower ? '1' : '0');
        } catch (e) {}
        this._sync();

        if (lowPower) {
          if (autoRotateControl) {
            autoRotateControl.stop();
          }
          if (typeof this._map.setProjection === 'function') {
            this._map.setProjection({ type: 'mercator' });
          }
          if (typeof this._map.setFog === 'function') {
            this._map.setFog(null);
          }
        } else {
          if (typeof this._map.setProjection === 'function') {
            this._map.setProjection({ type: 'globe' });
          }
          if (typeof this._map.setFog === 'function') {
            this._map.setFog({
              'color': 'rgb(90, 150, 230)',
              'high-color': 'rgb(200, 225, 255)',
              'space-color': 'rgb(10, 22, 48)',
              'horizon-blend': 1.2,
              'star-intensity': 0.35
            });
          }
        }
      };

      LowPowerControl.prototype._sync = function () {
        if (!this._btn) return;
        if (lowPower) {
          this._btn.classList.add('is-active');
        } else {
          this._btn.classList.remove('is-active');
        }
      };

      map.addControl(new LowPowerControl(), 'top-right');
      var visitorMarkers = [];
      var infoCard = null;

      function formatDuration(seconds) {
        if (!seconds || seconds < 0) return '0 min';
        var mins = Math.floor(seconds / 60);
        var secs = Math.floor(seconds % 60);
        if (mins <= 0) return secs + ' sec';
        if (secs <= 0) return mins + ' min';
        return mins + ' min ' + secs + ' sec';
      }

      function parseDateTime(value) {
        if (!value) return null;
        var iso = String(value).replace(' ', 'T');
        var d = new Date(iso);
        if (isNaN(d.getTime())) return null;
        return d;
      }

      function detectOS(ua) {
        var s = (ua || '').toLowerCase();
        if (s.indexOf('windows nt') !== -1) return 'Windows';
        if (s.indexOf('mac os x') !== -1) return 'Mac OS';
        if (s.indexOf('android') !== -1) return 'Android';
        if (s.indexOf('iphone') !== -1 || s.indexOf('ipad') !== -1 || s.indexOf('ios') !== -1) return 'iOS';
        if (s.indexOf('linux') !== -1) return 'Linux';
        return 'Unknown';
      }

      function detectBrowser(ua) {
        var s = (ua || '').toLowerCase();
        if (s.indexOf('edg/') !== -1) return 'Edge';
        if (s.indexOf('opr/') !== -1 || s.indexOf('opera') !== -1) return 'Opera';
        if (s.indexOf('chrome/') !== -1) return 'Chrome';
        if (s.indexOf('safari/') !== -1) return 'Safari';
        if (s.indexOf('firefox/') !== -1) return 'Firefox';
        return 'Unknown';
      }

      function ensureInfoCard() {
        if (infoCard) return infoCard;
        infoCard = document.createElement('div');
        infoCard.className = 'brein-map-info';
        infoCard.innerHTML =
          '<div class="brein-map-info__header">' +
            '<div class="brein-map-info__user">' +
              '<img class="brein-map-info__avatar" alt="Visitor Avatar" />' +
              '<div class="brein-map-info__name">Visitor</div>' +
            '</div>' +
            '<button type="button" class="brein-map-info__close" aria-label="Close">×</button>' +
          '</div>' +
          '<div class="brein-map-info__meta"></div>' +
          '<div class="brein-map-info__list"></div>';

        var container = document.getElementById('brein-map');
        if (container) {
          container.appendChild(infoCard);
        }

        infoCard.querySelector('.brein-map-info__close').addEventListener('click', function () {
          infoCard.setAttribute('data-open', 'false');
        });

        return infoCard;
      }

      function renderInfoCard(visitor) {
        var card = ensureInfoCard();
        var avatar = card.querySelector('.brein-map-info__avatar');
        avatar.src = visitor.avatar_url || '';

        var country = visitor.country || 'Unknown';
        var countryCode = visitor.country_code ? String(visitor.country_code).toUpperCase() : '';
        var countryLabel = countryCode ? (country + ' (' + countryCode + ')') : country;
        var device = visitor.device || 'Unknown';
        var os = detectOS(visitor.user_agent);
        var osFirstWord = os.trim().split(/\s+/)[0].toLowerCase();

        var browser = detectBrowser(visitor.user_agent);
        var browserimg = browser.toLowerCase();

        var first = parseDateTime(visitor.first_seen);
        var last = parseDateTime(visitor.last_seen);
        var sessionSeconds = 0;
        if (first && last) {
          sessionSeconds = Math.max(0, Math.floor((last.getTime() - first.getTime()) / 1000));
        }

        var metaHtml =
          '<span>' + countryLabel + '</span>' +
          '<img class="browser_logo" src="https://cdn.jsdelivr.net/npm/operating-system-logos@1.0.0/src/32x32/' + osFirstWord + '.png">' +
          '<span>' + os + '</span>' +
          '<span>' + device + '</span>' +
          '<img class="browser_logo" src="https://cdnjs.cloudflare.com/ajax/libs/browser-logos/74.1.0/' + browserimg + '/' + browserimg + '_64x64.png">' +
          '<span>' + browser + '</span>';

        var refererLabel = visitor.referer_label || 'Direct/None';
        var listHtml =
          '<div class="brein-map-info__row"><span>Referer</span><span>' + refererLabel + '</span></div>' +
          '<div class="brein-map-info__row"><span>Session time</span><span>' + formatDuration(sessionSeconds) + '</span></div>' +
          '<div class="brein-map-info__row"><span>Total visits</span><span>' + (visitor.visit_count || 0) + '</span></div>';

        card.querySelector('.brein-map-info__meta').innerHTML = metaHtml;
        card.querySelector('.brein-map-info__list').innerHTML = listHtml;
        card.setAttribute('data-open', 'true');
      }

      map.on('style.load', function () {
        if (typeof map.setProjection === 'function') {
          map.setProjection({ type: lowPower ? 'mercator' : 'globe' });
        }
        if (typeof map.setFog === 'function') {
          map.setFog(lowPower ? null : {
            'color': 'rgb(90, 150, 230)',
            'high-color': 'rgb(200, 225, 255)',
            'space-color': 'rgb(10, 22, 48)',
            'horizon-blend': 1.2,
            'star-intensity': 0.35
          });
        }
      });

      function clearVisitorMarkers() {
        visitorMarkers.forEach(function (m) { m.remove(); });
        visitorMarkers = [];
      }

      function addVisitorMarkers(visitors) {
        clearVisitorMarkers();
        visitors.forEach(function (v) {
          if (typeof v.lat !== 'number' || typeof v.lon !== 'number') return;
          var img = document.createElement('img');
          img.className = 'brein-visitor-marker';
          img.src = v.avatar_url;
          img.alt = 'Visitor Avatar';
          img.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            renderInfoCard(v);
          });
          var m = new maplibregl.Marker({ element: img, anchor: 'center' })
            .setLngLat([v.lon, v.lat])
            .addTo(map);
          visitorMarkers.push(m);
        });
      }

      function fetchVisitors() {
        if (!breinMapWidget.ajaxUrl || !breinMapWidget.nonce) return;
        var url = breinMapWidget.ajaxUrl + '?action=brein_map_visitors&nonce=' + encodeURIComponent(breinMapWidget.nonce);
        fetch(url)
          .then(function (res) { return res.json(); })
          .then(function (data) {
            if (data && data.success && Array.isArray(data.data)) {
              addVisitorMarkers(data.data);
            }
          })
          .catch(function () {});
      }

      fetchVisitors();
      if (breinMapWidget.pollMs) {
        var pollMs = breinMapWidget.pollMs;
        if (lowPower) {
          pollMs = Math.max(pollMs, 60000);
        }
        setInterval(fetchVisitors, pollMs);
      }
    }

    initMap(styleUrl);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
