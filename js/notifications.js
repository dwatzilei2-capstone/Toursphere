(() => {
  const bell = document.getElementById('header-notif-btn');
  const preview = document.getElementById('notification-preview');
  if (!bell || !preview) return;
  const key = `toursphere.notifications.${bell.dataset.userId}`;
  let cursor = Number(bell.dataset.cursor), busy = false, timer, queue = [], showing = false;
  try { const saved = sessionStorage.getItem(key); if (saved !== null) cursor = Number(saved); } catch (_) {}
  const text = (tag, value, className) => { const el = document.createElement(tag); el.textContent = value; el.className = className || ''; return el; };
  const render = items => {
    const list = document.getElementById('notifications-dropdown-list');
    list.replaceChildren();
    if (!items.length) list.append(text('div', 'No notifications', 'px-3 py-4 text-center small text-muted-custom'));
    items.forEach(n => {
      const a = document.createElement('a'); a.href = n.url; a.dataset.notifId = n.id;
      a.className = `notifications-item d-block px-3 py-3 text-decoration-none ${n.is_read ? '' : 'unread'}`;
      a.append(text('div', n.title, 'fw-semibold small'), text('div', n.body, 'text-muted-custom small mt-1'), text('time', n.timestamp, 'text-muted-custom small'));
      a.addEventListener('click', e => App.markNotificationRead(n.id, e)); list.append(a);
    });
    document.querySelectorAll('[data-notif-row]').forEach(row => {
      const n = items.find(n => n.id === Number(row.dataset.notifRow));
      if (n) row.classList.toggle('unread', !n.is_read);
    });
  };
  const dismiss = () => { clearTimeout(timer); preview.hidden = true; showing = false; display(); };
  const positionPreview = () => {
    if (preview.hidden) return;
    const rect = bell.getBoundingClientRect();
    const width = Math.min(260, window.innerWidth - 32);
    const left = Math.max(16, Math.min(rect.right + 12 - width, window.innerWidth - width - 16));
    preview.style.position = 'fixed';
    preview.style.width = `${width}px`;
    preview.style.left = `${left}px`;
    preview.style.right = 'auto';
    preview.style.top = `${rect.bottom + 10}px`;
    preview.style.setProperty('--notification-arrow-left', `${Math.max(10, Math.min(rect.left + rect.width / 2 - left - 4, width - 18))}px`);
  };
  const display = () => {
    if (showing || !queue.length) return;
    const n = queue.shift(); showing = true; preview.replaceChildren();
    const a = document.createElement('a'); a.href = n.url; a.className = 'notification-preview-link';
    const message = [n.title, n.body].filter(Boolean).join(': ').replace(/\s+/g, ' ').trim();
    a.setAttribute('aria-label', message);
    a.title = message;
    const track = text('span', '', 'notification-preview-track');
    track.setAttribute('aria-hidden', 'true');
    track.append(text('span', message, 'notification-preview-message'), text('span', message, 'notification-preview-message'));
    a.append(track);
    a.addEventListener('click', e => App.markNotificationRead(n.id, e));
    const close = text('button', '×', 'notification-preview-close'); close.type = 'button'; close.setAttribute('aria-label', 'Dismiss notification'); close.onclick = dismiss;
    preview.append(a, close); preview.hidden = false; positionPreview();
    track.style.setProperty('--notification-scroll-duration', `${Math.max(12, track.scrollWidth / 2 / 25)}s`);
    timer = setTimeout(dismiss, 10000);
  };
  const renderCenter = items => {
    const list = document.getElementById('notifications-full-list');
    if (!list || !items) return;
    list.replaceChildren();
    const categories = new Set(['All']);
    items.forEach(n => {
      categories.add(n.category || '');
      const row = text('div', '', `p-3 border-bottom notifications-item-row ${n.is_read ? '' : 'unread'}`);
      row.dataset.notifRow = n.id; row.dataset.notifCat = n.category || ''; row.dataset.notifTarget = n.url;
      const a = document.createElement('a'); a.href = n.url; a.className = 'text-decoration-none text-body';
      a.append(text('strong', n.title), text('div', n.body, 'small mt-1'), text('time', n.timestamp, 'small text-muted-custom'));
      a.addEventListener('click', e => App.markNotificationRead(n.id, e)); row.append(a);
      if (!n.is_read) { const read = text('button', 'Mark as read', 'tc-btn tc-btn-light tc-btn-sm ms-2'); read.dataset.markReadBtn = n.id; read.onclick = () => App.markNotificationRead(n.id); row.append(read); }
      list.append(row);
    });
    if (!items.length) list.append(text('div', 'No notifications found.', 'p-4 text-center text-muted-custom'));
    const tabs = document.getElementById('notifications-tabs');
    if (tabs) {
      categories.forEach(cat => { if (!cat || Array.from(tabs.children).some(b => (b.dataset.notifCat || 'All') === cat)) return; const b=text('button',cat,'tc-tab-btn'); b.dataset.notifCat=cat; b.onclick=()=>App.filterNotifications(cat); tabs.append(b); });
    }
    App.filterNotifications(App.notificationsFilter || 'All');
  };
  const poll = async () => {
    if (busy || document.hidden) return; busy = true;
    try {
      const response = await fetch(`${window.TC_BASE_URL}/actions/notifications.php?after=${cursor}${document.getElementById('notifications-full-list') ? '&center=1' : ''}`, {cache:'no-store'});
      const data = await response.json(); if (!response.ok || !data.ok) return;
      App.updateNotifBadge(data.unread); render(data.items); renderCenter(data.all_items);
      queue.push(...data.events.filter(n => !n.is_read)); cursor = data.cursor;
      try { sessionStorage.setItem(key, String(cursor)); } catch (_) {}
      display();
    } catch (_) {} finally { busy = false; }
  };
  bell.addEventListener('show.bs.dropdown', () => { queue = []; clearTimeout(timer); preview.hidden = true; showing = false; });
  document.addEventListener('visibilitychange', poll); window.addEventListener('focus', poll);
  window.addEventListener('resize', positionPreview);
  window.addEventListener('scroll', positionPreview, true);
  poll(); setInterval(poll, 5000);
})();
