(function () {
  if (typeof window.breinClickTracker === 'undefined') {
    return;
  }

  var config = window.breinClickTracker;
  if (!config.ajaxUrl || !config.nonce) {
    return;
  }

  function getTextLabel(element) {
    if (!element) {
      return '';
    }

    var label = element.getAttribute('aria-label') || element.textContent || '';
    return label.replace(/\s+/g, ' ').trim().slice(0, 120);
  }

  function getClassNames(element) {
    if (!element || !element.classList) {
      return [];
    }

    return Array.prototype.slice.call(element.classList)
      .map(function (className) {
        return (className || '').trim();
      })
      .filter(Boolean);
  }

  function buildSelector(element, classNames) {
    var tagName = element && element.tagName ? element.tagName.toLowerCase() : 'element';
    var elementId = getElementId(element);
    if (elementId) {
      return tagName + '#' + elementId;
    }

    if (!classNames.length) {
      return tagName;
    }

    return tagName + '.' + classNames.join('.');
  }

  function getElementId(element) {
    if (!element || !element.id) {
      return '';
    }

    return String(element.id).trim();
  }

  document.addEventListener(
    'click',
    function (event) {
      var target = event.target && event.target.closest ? event.target.closest('a, button') : null;
      if (!target) {
        return;
      }

      var classNames = getClassNames(target);
      var elementId = getElementId(target);
      if (!classNames.length && !elementId) {
        return;
      }

      var data = new window.FormData();
      data.append('action', 'brein_track_recording_event');
      data.append('nonce', config.nonce);
      data.append('event_type', 'click');
      data.append('path', window.location.pathname || '/');
      data.append('page_title', document.title || '');
      data.append('referrer', document.referrer || 'direct');
      data.append('element_label', getTextLabel(target));
      data.append('element_href', target.href || '');
      data.append('selector', buildSelector(target, classNames));
      data.append('element_id', elementId);
      data.append('timestamp_ms', String(Date.now()));
      data.append('viewport_width', String(window.innerWidth || 0));
      data.append('viewport_height', String(window.innerHeight || 0));
      data.append('x', String(event.clientX || 0));
      data.append('y', String(event.clientY || 0));
      data.append('class_names', JSON.stringify(classNames));

      if (typeof window.fetch === 'function') {
        window.fetch(config.ajaxUrl, {
          method: 'POST',
          credentials: 'same-origin',
          body: data,
          keepalive: true
        }).catch(function () {});
      }
    },
    true
  );
})();
