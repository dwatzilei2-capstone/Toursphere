(() => {
  'use strict';

  const body = document.body;
  if (!body || !body.classList.contains('cost-analysis-module')) return;

  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  const revenueCanvas = document.getElementById('revenue-cost');
  const revenueData = window.TC_REVENUE_COST_DATA;
  if (revenueCanvas && revenueData && window.Chart) {
    const currency = window.fleetCurrencySymbol || '₱';
    const money = (value) => currency + Number(value).toLocaleString('en-PH', {
      minimumFractionDigits: 2, maximumFractionDigits: 2
    });
    const colors = ['#059669', '#2563eb'];
    revenueData.datasets.forEach((dataset, index) => {
      Object.assign(dataset, {
        borderColor: colors[index], borderWidth: 2.5,
        pointRadius: revenueData.labels.length === 1 ? 4 : 2.5,
        pointHoverRadius: 5, pointHitRadius: 14,
        pointBackgroundColor: colors[index], pointBorderColor: colors[index], pointBorderWidth: 0,
        tension: 0.15, cubicInterpolationMode: 'monotone',
        fill: index === 0,
        backgroundColor: (context) => {
          const area = context.chart.chartArea;
          if (!area || index !== 0) return 'transparent';
          const gradient = context.chart.ctx.createLinearGradient(0, area.top, 0, area.bottom);
          gradient.addColorStop(0, 'rgba(5,150,105,0.12)');
          gradient.addColorStop(1, 'rgba(5,150,105,0)');
          return gradient;
        }
      });
    });
    new Chart(revenueCanvas, {
      type: 'line', data: revenueData,
      options: {
        responsive: true, maintainAspectRatio: false,
        animation: false,
        layout: { padding: { top: 12, right: 12, bottom: 0, left: 0 } },
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: { display: false },
          tooltip: {
            backgroundColor: '#0f172a', titleColor: '#fff', bodyColor: '#e2e8f0',
            padding: 14, cornerRadius: 8, boxPadding: 6, usePointStyle: true,
            titleFont: { size: 12, weight: '600' }, bodyFont: { size: 12 },
            callbacks: { label: (context) => ' ' + context.dataset.label + ': ' + money(context.parsed.y) }
          }
        },
        scales: {
          x: {
            offset: true, border: { display: false }, grid: { display: false },
            ticks: { color: '#64748b', padding: 12, maxRotation: 0, autoSkip: true, maxTicksLimit: 12,
              font: { size: 11 }, callback: function(value) {
                const label = this.getLabelForValue(value);
                const years = new Set(revenueData.labels.map(item => item.split(' ').pop()));
                return years.size === 1 ? label.split(' ')[0] : label;
              }
            }
          },
          y: {
            beginAtZero: true, grace: '12%', border: { display: false },
            grid: { color: '#edf1f5', drawTicks: false },
            ticks: { color: '#94a3b8', padding: 12, maxTicksLimit: 6, font: { size: 11 },
              callback: (value) => currency + new Intl.NumberFormat('en-PH', {
                notation: 'compact', maximumFractionDigits: 1
              }).format(value)
            }
          }
        }
      }
    });
  }

  requestAnimationFrame(() => {
    requestAnimationFrame(() => body.classList.add('cost-analysis-motion-ready'));
  });

  document.querySelectorAll('.tc-table tbody tr').forEach((row) => {
    if (row.querySelector('td[colspan]')) return;
    row.addEventListener('pointerdown', () => row.classList.add('cost-row-pressed'));
    ['pointerup', 'pointercancel', 'pointerleave'].forEach((eventName) => {
      row.addEventListener(eventName, () => row.classList.remove('cost-row-pressed'));
    });
  });
})();
