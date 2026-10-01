(() => {
  'use strict';
  const config = window.TC_SESSION;
  if (!config) return;
  const root = document.getElementById('session-warning');
  const countdown = document.getElementById('session-warning-countdown');
  const status = document.getElementById('session-warning-status');
  const stay = document.getElementById('session-stay');
  const logout = document.getElementById('session-logout');
  const modal = new bootstrap.Modal(root);
  const originalFetch = window.fetch.bind(window);
  const endpoint = `${window.TC_BASE_URL}/actions/session.php`;
  let deadline = performance.now() + config.remaining * 1000;
  let busy = false, leaving = false, visible = false, lastActivitySent = -Infinity, activityTimer, queuedAction;
  const channel = typeof BroadcastChannel === 'function' ? new BroadcastChannel(`toursphere.session.${config.userId}`) : null;
  const login = expired => {
    if (leaving) return;
    leaving = true;
    channel?.postMessage({type:'check'});
    window.location.replace(`${window.TC_BASE_URL}/login.php${expired ? '?error=' + encodeURIComponent('Your session expired due to inactivity. Please log in again.') : ''}`);
  };
  const setRemaining = seconds => {
    deadline = performance.now() + Math.max(0, seconds) * 1000;
    if (seconds > 60 && visible) { modal.hide(); visible = false; }
  };
  // Existing same-origin AJAX calls also receive authoritative timeout updates/errors.
  window.fetch = async (...args) => {
    const response = await originalFetch(...args);
    if (new URL(response.url || endpoint, location.href).origin === location.origin) {
      const remaining = response.headers.get('X-TourSphere-Session-Remaining');
      if (remaining !== null) setRemaining(Number(remaining));
      if (response.status === 401) login(true);
    }
    return response;
  };
  const request = async action => {
    if (leaving) return;
    if (busy) { if (action !== 'status') queuedAction = action; return; }
    busy = true;
    try {
      const body = new FormData(); body.append('action',action); body.append('csrf',config.csrf);
      const response = await originalFetch(endpoint, action === 'status' ? {cache:'no-store',headers:{Accept:'application/json'}} : {method:'POST',body,headers:{Accept:'application/json'}});
      if (response.status === 401) { login(true); return; }
      const data = await response.json();
      if (!response.ok || !data.ok) throw new Error('Unable to confirm your session. Please try again.');
      if (data.logged_out) { login(false); return; }
      setRemaining(data.remaining);
      status.textContent = '';
      if (action === 'activity') channel?.postMessage({type:'check'});
    } catch (error) { status.textContent = 'Unable to reach the server. Your session has not been extended.'; }
    finally {
      busy = false; stay.disabled = false; logout.disabled = false;
      if (queuedAction && !leaving) { const next = queuedAction; queuedAction = null; request(next); }
    }
  };
  const tick = () => {
    const remaining = Math.max(0,Math.ceil((deadline-performance.now())/1000));
    countdown.textContent = `${String(Math.floor(remaining/60)).padStart(2,'0')}:${String(remaining%60).padStart(2,'0')}`;
    if (remaining <= 60 && !visible && !leaving) { clearTimeout(activityTimer); modal.show(); visible = true; }
    // Only the backend decides whether to invalidate or renew an apparently expired session.
    if (remaining === 0) request('status');
  };
  root.addEventListener('shown.bs.modal', () => { document.querySelectorAll('.modal-backdrop').forEach(el=>el.classList.add('session-idle-backdrop')); stay.focus(); });
  stay.addEventListener('click', () => { stay.disabled = true; request('activity'); });
  logout.addEventListener('click', () => { logout.disabled = true; request('logout'); });
  const activity = event => {
    if (!event.isTrusted || visible || leaving || root.contains(event.target)) return;
    clearTimeout(activityTimer);
    const send = () => {
      if (visible || leaving) return;
      if (busy) { activityTimer = setTimeout(send,500); return; }
      lastActivitySent = performance.now(); request('activity');
    };
    if (performance.now()-lastActivitySent >= 15000 && !busy) send();
    else activityTimer = setTimeout(send,1000);
  };
  ['click','keydown','input','submit'].forEach(type=>document.addEventListener(type,activity,true));
  document.addEventListener('visibilitychange', () => { if (!document.hidden) request('status'); });
  window.addEventListener('focus', () => request('status'));
  channel?.addEventListener('message', () => request('status'));
  window.addEventListener('pageshow', () => request('status'));
  tick(); request('status'); setInterval(tick,1000); setInterval(()=>{ if(!document.hidden) request('status'); },15000);
})();
