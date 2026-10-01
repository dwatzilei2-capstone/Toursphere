(() => {
  'use strict';
  const canvas = document.getElementById('breakdown');
  const data = window.TC_COST_BREAKDOWN_DATA;
  if (!canvas || !data || !window.Chart) return;
  const money = value => (window.fleetCurrencySymbol || '₱') + Number(value).toLocaleString(undefined, { maximumFractionDigits: 2 });
  new Chart(canvas, {
    type: 'bar', data,
    options: {
      responsive: true, maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { display: false },
        tooltip: { callbacks: {
          label: context => `${context.dataset.label}: ${money(context.parsed.y)}`,
          footer: items => `Total: ${money(items.reduce((sum, item) => sum + item.parsed.y, 0))}`
        } }
      },
      scales: {
        x: { stacked: true },
        y: { stacked: true, beginAtZero: true, grace: '12%', ticks: { callback: value => money(value) } }
      }
    }
  });
})();
