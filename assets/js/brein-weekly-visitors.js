(function () {
  function initCard(card) {
    var chart = card.querySelector('.brein-weekly-chart');
    var tooltip = card.querySelector('.brein-weekly-tooltip');
    var hoverline = card.querySelector('.brein-weekly-hoverline');
    var dot = card.querySelector('.brein-weekly-dot');
    var linePath = card.querySelector('.brein-weekly-line');
    if (!chart || !tooltip) return;

    var values = [];
    var labels = [];
    var dates = [];
    var seriesLabel = chart.getAttribute('data-series-label') || 'Visitors';
    try {
      values = JSON.parse(chart.getAttribute('data-values') || '[]');
      labels = JSON.parse(chart.getAttribute('data-labels') || '[]');
      dates = JSON.parse(chart.getAttribute('data-dates') || '[]');
    } catch (e) {
      return;
    }

    if (values.length === 0) return;

    function showTooltip(index, x, y) {
      var value = values[index] || 0;
      var label = dates[index] || labels[index] || '';
      tooltip.innerHTML =
        '<div class=\"brein-weekly-tooltip-title\">' +
        (label || 'Dag') +
        '</div>' +
        '<div class=\"brein-weekly-tooltip-row\">' +
        '<span class=\"brein-weekly-tooltip-swatch\"></span>' +
        '<span>' + seriesLabel + '</span>' +
        '<span>' +
        value +
        '</span>' +
        '</div>';

      tooltip.style.left = x + 'px';
      tooltip.style.top = y + 'px';
      tooltip.style.opacity = '1';
      tooltip.style.transform = 'translate(-50%, -110%)';

      if (hoverline) {
        hoverline.style.left = x + 'px';
        hoverline.style.opacity = '1';
      }
      if (dot) {
        dot.style.left = x + 'px';
        dot.style.top = y + 'px';
        dot.style.opacity = '1';
      }
    }

    function hideTooltip() {
      tooltip.style.opacity = '0';
      if (hoverline) hoverline.style.opacity = '0';
      if (dot) dot.style.opacity = '0';
    }

    function findPointAtX(path, targetX) {
      var total = path.getTotalLength();
      var start = 0;
      var end = total;
      var point = path.getPointAtLength(0);

      for (var i = 0; i < 20; i++) {
        var mid = (start + end) / 2;
        point = path.getPointAtLength(mid);
        if (point.x < targetX) {
          start = mid;
        } else {
          end = mid;
        }
      }

      return point;
    }

    chart.addEventListener('mousemove', function (event) {
      var rect = chart.getBoundingClientRect();
      var cardRect = card.getBoundingClientRect();
      var x = Math.max(0, Math.min(event.clientX - rect.left, rect.width));
      var ratio = rect.width > 0 ? x / rect.width : 0;
      var index = Math.round(ratio * (values.length - 1));
      index = Math.max(0, Math.min(values.length - 1, index));
      var localX = rect.left - cardRect.left + x;
      var localY = rect.top - cardRect.top;

      if (linePath && typeof linePath.getTotalLength === 'function') {
        var xView = ratio * 100;
        var point = findPointAtX(linePath, xView);
        var y = (point.y / 40) * rect.height;
        localY = rect.top - cardRect.top + y;
      }

      showTooltip(index, localX, localY);

      if (hoverline) {
        hoverline.style.top = rect.top - cardRect.top + 'px';
        hoverline.style.height = rect.height + 'px';
      }
    });

    chart.addEventListener('mouseleave', hideTooltip);
  }

  function init() {
    var cards = document.querySelectorAll('.brein-weekly-card');
    cards.forEach(initCard);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
