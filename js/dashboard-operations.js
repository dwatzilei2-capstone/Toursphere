(() => {
  'use strict';
  const root = document.getElementById('dashboard-operations');
  if (!root) return;
  const $ = selector => root.querySelector(selector);
  const today = root.dataset.today;
  const parse = value => new Date(`${value}T12:00:00`);
  const key = date => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
  const addDays = (date, count) => { const next = new Date(date); next.setDate(next.getDate() + count); return next; };
  const make = (tag, text, className) => { const el = document.createElement(tag); if (text !== undefined) el.textContent = text; if (className) el.className = className; return el; };
  let anchor = parse(today), calendarView = 'month', trendView = 'month', chart;
  let calendarRequest = 0, trendRequest = 0;
  async function request(params, options = {}) {
    if (params) params = {...params, source: root.dataset.source};
    const response = await fetch(`${root.dataset.endpoint}${params ? `?${new URLSearchParams(params)}` : ''}`, {credentials: 'same-origin', ...options});
    const data = await response.json();
    if (!response.ok) throw new Error(data.error || 'Unable to load data.');
    return data;
  }
  function select(group, value) {
    root.querySelectorAll(`[data-${group}-view]`).forEach(button => button.setAttribute('aria-pressed', String(button.dataset[`${group}View`] === value)));
  }
  function details(event) {
    const fields = {
      'Trip': event.trip_id || 'Not assigned', 'Reservation': event.reservation_id,
      'Cargo reference': event.external_request_id || event.reservation_id,
      'Type': event.request_kind, 'Cargo': event.cargo_type || '—',
      'Route': `${event.origin} → ${event.destination}`, 'Pickup': event.departure,
      'Delivery due': event.return_date ? `${event.return_date} ${event.return_time || ''}` : 'Not recorded',
      'Vehicle': event.plate_number || 'Not assigned', 'Driver': event.driver_name || 'Not assigned', 'Status': event.status
    };
    $('#do-event-details').replaceChildren(...Object.entries(fields).flatMap(([label, value]) => [make('dt', label), make('dd', value)]));
    $('#do-event-dialog').showModal();
  }
  $('#do-event-close').addEventListener('click', () => $('#do-event-dialog').close());
  async function calendar() {
    const id = ++calendarRequest;
    const grid = $('#do-calendar-grid');
    grid.replaceChildren();
    grid.classList.toggle('do-day', calendarView === 'day');
    $('#do-calendar-status').textContent = 'Loading schedule…';
    $('#do-calendar-date').textContent = anchor.toLocaleDateString(undefined, {month: 'long', year: 'numeric', ...(calendarView !== 'month' ? {day: 'numeric'} : {})});
    select('calendar', calendarView);
    try {
      const data = await request({kind: 'calendar', date: key(anchor), view: calendarView});
      if (id !== calendarRequest) return;
      let start = calendarView === 'month' ? new Date(anchor.getFullYear(), anchor.getMonth(), 1, 12) : new Date(anchor);
      if (calendarView !== 'day') start = addDays(start, -start.getDay());
      const days = calendarView === 'month' ? Math.ceil((new Date(anchor.getFullYear(), anchor.getMonth(), 1).getDay() + new Date(anchor.getFullYear(), anchor.getMonth() + 1, 0).getDate()) / 7) * 7 : calendarView === 'week' ? 7 : 1;
      if (calendarView !== 'day') ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].forEach(name => grid.append(make('div', name, 'do-weekday')));
      const byDate = new Map();
      data.events.forEach(event => {
        const date = event.departure.slice(0, 10);
        if (!byDate.has(date)) byDate.set(date, []);
        byDate.get(date).push(event);
      });
      for (let index = 0; index < days; index++) {
        const date = addDays(start, index), dateKey = key(date);
        const cell = make('div', undefined, 'do-cell');
        cell.classList.toggle('do-outside', date.getMonth() !== anchor.getMonth());
        cell.classList.toggle('do-current', dateKey === today);
        const label = make('time', calendarView === 'day' ? date.toLocaleDateString(undefined, {weekday: 'long', month: 'long', day: 'numeric'}) : String(date.getDate()));
        label.dateTime = dateKey;
        cell.append(label);
        const events = byDate.get(dateKey) || [];
        events.slice(0, calendarView === 'month' ? 3 : events.length).forEach(event => {
          const title = `${event.trip_id || event.reservation_id} · ${event.status}`;
          const button = make('button', title, 'do-event');
          button.type = 'button'; button.dataset.status = event.status;
          button.title = `${title} · ${event.departure.slice(11, 16)} · ${event.origin} → ${event.destination}`;
          button.setAttribute('aria-label', button.title);
          button.addEventListener('click', () => details(event));
          cell.append(button);
        });
        if (calendarView === 'month' && events.length > 3) {
          const more = make('button', `+${events.length - 3} more`, 'do-more');
          more.type = 'button';
          more.addEventListener('click', () => { anchor = date; calendarView = 'day'; calendar(); });
          cell.append(more);
        }
        grid.append(cell);
      }
      $('#do-calendar-status').textContent = data.truncated ? 'Showing the first 2,000 events. Choose Day or Week to narrow the schedule.' : data.events.length ? '' : 'No scheduled trips or deliveries in this period.';
    } catch (error) {
      if (id !== calendarRequest) return;
      $('#do-calendar-status').textContent = error.message + ' ';
      const retry = make('button', 'Retry'); retry.type = 'button'; retry.addEventListener('click', calendar); $('#do-calendar-status').append(retry);
    }
  }
  root.querySelectorAll('[data-calendar-view]').forEach(button => button.addEventListener('click', () => { calendarView = button.dataset.calendarView; calendar(); }));
  root.querySelectorAll('[data-calendar-nav]').forEach(button => button.addEventListener('click', () => {
    const offset = Number(button.dataset.calendarNav);
    if (calendarView === 'month') anchor = new Date(anchor.getFullYear(), anchor.getMonth() + offset, 1, 12);
    else anchor = addDays(anchor, offset * (calendarView === 'week' ? 7 : 1));
    calendar();
  }));
  $('#do-today').addEventListener('click', () => { anchor = parse(today); calendar(); });
  $('#do-show-calendar').addEventListener('change', async event => {
    const toggle = event.target, visible = toggle.checked;
    toggle.disabled = true;
    const panel = $('#do-calendar');
    if (!visible && !matchMedia('(prefers-reduced-motion: reduce)').matches) {
      const animation = panel.animate([{height: `${panel.offsetHeight}px`, opacity: 1}, {height: '0px', paddingTop: '0px', paddingBottom: '0px', marginBottom: '0px', opacity: 0}], {duration: 160});
      panel.style.overflow = 'hidden';
      await animation.finished;
      panel.style.overflow = '';
    }
    panel.hidden = !visible;
    if (visible) { calendar(); if (!matchMedia('(prefers-reduced-motion: reduce)').matches) panel.animate([{opacity: 0}, {opacity: 1}], {duration: 180}); }
    $('#do-preference-status').textContent = 'Saving…';
    try {
      await request(null, {method: 'POST', body: new URLSearchParams({csrf: root.dataset.csrf, visible: visible ? '1' : '0'})});
      $('#do-preference-status').textContent = '';
    } catch (error) {
      toggle.checked = !visible; panel.hidden = visible;
      $('#do-preference-status').textContent = `Preference not saved. ${error.message}`;
    } finally { toggle.disabled = false; }
  });
  async function trend() {
    const id = ++trendRequest;
    const input = $('#do-trend-date');
    if (!input.reportValidity() || !input.value) return;
    const date = trendView === 'year' ? `${input.value}-01-01` : trendView === 'month' ? `${input.value}-01` : input.value;
    select('trend', trendView);
    $('#do-trend-status').textContent = 'Loading delivery trends…';
    $('#do-trend-table').replaceChildren();
    if (chart) { chart.destroy(); chart = null; }
    try {
      const data = await request({kind: 'trend', date, view: trendView});
      if (id !== trendRequest) return;
      const metrics = ['completed', 'on_time', 'scheduled', 'cancelled'];
      const labels = ['Completed Deliveries', 'On-Time Deliveries', 'Scheduled Deliveries', 'Cancelled Deliveries'];
      const colors = ['#27AE60', '#2F80ED', '#F2994A', '#EB5757'];
      const total = data.buckets.reduce((sum, bucket) => sum + metrics.reduce((n, metric) => n + bucket[metric], 0), 0);
      $('#do-trend-status').textContent = (data.sample_count ? `${data.sample_count.toLocaleString()} fictional delivery examples in this period. ` : '') + (total ? '' : 'No delivery activity recorded for this period. ') + (data.unknown ? `${data.unknown} completed deliveries have no measurable punctuality evidence.` : '');
      data.buckets.forEach(bucket => {
        const row = make('tr'); row.append(make('th', bucket.label), ...metrics.map(metric => make('td', String(bucket[metric])))); $('#do-trend-table').append(row);
      });
      if (typeof Chart === 'undefined') throw new Error('Chart could not load. Delivery counts are available under View chart data.');
      chart = new Chart($('#do-trend-chart'), {
        type: 'line',
        data: {labels: data.buckets.map(bucket => bucket.label), datasets: metrics.map((metric, index) => ({
          label: labels[index], data: data.buckets.map(bucket => bucket[metric]), borderColor: colors[index], backgroundColor: colors[index],
          borderWidth: 2, pointRadius: data.buckets.length > 24 ? 2 : 3, pointHoverRadius: 5, tension: .2, cubicInterpolationMode: 'monotone', fill: false,
          borderDash: index === 3 ? [5, 3] : []
        }))},
        options: {responsive: true, maintainAspectRatio: false, animation: {duration: matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 250},
          interaction: {mode: 'index', intersect: false}, plugins: {legend: {position: 'top', align: 'end', labels: {usePointStyle: true, boxWidth: 7, font: {size: 11}}}},
          scales: {y: {beginAtZero: true, suggestedMax: 5, ticks: {precision: 0}}, x: {ticks: {maxTicksLimit: 16, maxRotation: 0}, grid: {display: false}}}}
      });
    } catch (error) { if (id === trendRequest) $('#do-trend-status').textContent = error.message; }
  }
  root.querySelectorAll('[data-trend-view]').forEach(button => button.addEventListener('click', () => {
    const input = $('#do-trend-date'), previous = input.value || today;
    trendView = button.dataset.trendView;
    input.type = trendView === 'year' ? 'number' : trendView === 'month' ? 'month' : 'date';
    input.min = trendView === 'year' ? '1900' : trendView === 'month' ? '1900-01' : '1900-01-01';
    input.max = trendView === 'year' ? '2100' : trendView === 'month' ? '2100-12' : '2100-12-31';
    const fullDate = previous.length === 4 ? `${previous}-01-01` : previous.length === 7 ? `${previous}-01` : previous;
    input.value = trendView === 'year' ? fullDate.slice(0, 4) : trendView === 'month' ? fullDate.slice(0, 7) : fullDate;
    trend();
  }));
  $('#do-trend-date').addEventListener('change', trend);
  $('#do-trend-retry').addEventListener('click', trend);
  if ($('#do-show-calendar').checked) calendar();
  trend();
})();
