(() => {
  'use strict';
  if (!window.Chart) return;
  const font = { family: 'Poppins, system-ui, sans-serif', size: 11 };
  const reduced = window.matchMedia('(prefers-reduced-motion: reduce)');

  Chart.register({
    id: 'tourspherePresentation',
    beforeInit(chart) {
      const options = chart.config.options ||= {};
      const dark = document.documentElement.dataset.bsTheme === 'dark';
      const text = dark ? '#CBD5E1' : '#64748B';
      const grid = dark ? 'rgba(148,163,184,.12)' : 'rgba(148,163,184,.16)';
      options.layout ||= {};
      options.layout.padding ??= { top: 12, right: 8, bottom: 4, left: 4 };
      options.animation = reduced.matches ? false : { ...options.animation, duration: 650, easing: 'easeOutQuart' };
      const type = chart.config.type;
      if (chart.canvas.id === 'chart-weekly-trips') {
        const highest = Math.max(0, ...chart.data.datasets.flatMap(dataset => dataset.data.map(Number)));
        const target = Math.max(5, Math.ceil(highest * 1.35));
        const step = Math.max(1, Math.ceil(target / 5));
        options.scales ||= {};
        options.scales.y = {
          ...options.scales.y, beginAtZero: true, min: 0,
          max: Math.ceil(target / step) * step,
          ticks: { ...options.scales.y?.ticks, precision: 0, stepSize: step }
        };
        chart.data.datasets.forEach(dataset => {
          dataset.maxBarThickness = 32;
          dataset.barPercentage = .65;
          dataset.categoryPercentage = .8;
          dataset.minBarLength = 0;
        });
      }
      if (chart.canvas.id === 'breakdown' && type === 'doughnut') {
        options.cutout = '62%';
        options.plugins ||= {};
        options.plugins.legend = { display: false };
        options.plugins.tooltip = { callbacks: { label: (context) => {
          const total = context.dataset.data.reduce((sum, value) => sum + Number(value), 0);
          const amount = Number(context.parsed);
          const share = total > 0 ? (amount / total * 100).toFixed(1) : '0.0';
          return `${context.label}: ${window.fleetCurrencySymbol || '₱'}${amount.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })} (${share}%)`;
        } } };
        chart.data.datasets.forEach(dataset => { dataset.spacing = 2; });
      }
      if (type === 'line') options.interaction = { ...options.interaction, mode: 'index', intersect: false };
      options.plugins ||= {};
      const legend = options.plugins.legend ||= {};
      if (legend !== false) {
        legend.labels = { ...legend.labels, color: text, font, usePointStyle: true,
          pointStyle: 'circle', boxWidth: 8, boxHeight: 8, padding: 18 };
      }
      const tooltip = options.plugins.tooltip ||= {};
      if (tooltip !== false) {
        Object.assign(tooltip, { backgroundColor: 'rgba(15,23,42,.96)', titleColor: '#FFFFFF',
          bodyColor: '#E2E8F0', titleFont: { ...font, size: 12, weight: '600' },
          bodyFont: { ...font, size: 12 }, padding: 12, cornerRadius: 8,
          boxPadding: 5, caretPadding: 8 });
        tooltip.callbacks ||= {};
        // Existing unit-specific callbacks take precedence.
        if (!tooltip.callbacks.label) tooltip.callbacks.label = (context) => {
          const config = window.TC_CHART_DATA?.[chart.canvas.id];
          const value = typeof context.parsed === 'number' ? context.parsed : context.parsed?.y;
          const label = context.dataset.label || context.label || '';
          const monetary = config?.money || /cost|revenue|expense|fuel|maintenance|toll|allowance/i.test(context.dataset.label || '') && chart.canvas.closest('main')?.querySelector('h1')?.textContent.includes('Cost');
          const formatted = monetary
            ? (window.fleetCurrencySymbol || '₱') + Number(value).toLocaleString(undefined, { maximumFractionDigits: 2 })
            : Number(value).toLocaleString(undefined, { maximumFractionDigits: 2 }) + (config?.max === 100 ? '%' : '');
          return `${label}${label ? ': ' : ''}${formatted}`;
        };
      }
      if (!['pie', 'doughnut'].includes(type)) {
        options.scales ||= {};
        for (const axis of ['x', 'y']) {
          const scale = options.scales[axis] ||= {};
          scale.border = { ...scale.border, display: false };
          scale.grid = { ...scale.grid, color: grid, drawTicks: false };
          if (axis === 'x') scale.grid.display = options.indexAxis === 'y';
          if (axis === 'y' && options.indexAxis === 'y') scale.grid.display = false;
          scale.ticks = { ...scale.ticks, color: text, font, padding: 10, maxTicksLimit: axis === 'y' ? 6 : 10 };
          if (axis === 'x') Object.assign(scale.ticks, { maxRotation: 0, autoSkip: true });
        }
      }
      for (const dataset of chart.data.datasets) {
        if (type === 'line') {
          Object.assign(dataset, { borderWidth: 2.5, pointRadius: chart.data.labels.length > 24 ? 0 : 3,
            pointHoverRadius: 6, pointHitRadius: 14, pointBorderWidth: 0,
            pointHoverBorderWidth: 0, pointBorderColor: dataset.borderColor,
            pointBackgroundColor: dataset.borderColor,
            pointHoverBackgroundColor: dataset.borderColor });
          dataset.tension ??= .3;
        } else if (type === 'bar') {
          dataset.borderRadius ??= 5;
          dataset.maxBarThickness ??= 48;
        } else if (['pie', 'doughnut'].includes(type)) {
          dataset.borderColor = dark ? '#1E293B' : '#FFFFFF';
          dataset.borderWidth = 3;
          dataset.hoverOffset = 5;
        }
      }
    }
  });
})();
