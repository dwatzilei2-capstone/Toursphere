'use strict';
const resend = document.getElementById('resend');
if (resend) {
  const deadline = Date.now() + Number(resend.dataset.cooldown) * 1000;
  const tick = () => {
    const seconds = Math.max(0, Math.ceil((deadline - Date.now()) / 1000));
    resend.disabled = seconds > 0;
    document.getElementById('resend-countdown').textContent = seconds ? `You can resend in ${seconds}s` : '';
  };
  tick();
  setInterval(tick, 1000);
}
document.querySelectorAll('form').forEach(form => {
  form.addEventListener('submit', () => {
    const button = form.querySelector('button[type="submit"]');
    if (button) { button.disabled = true; button.textContent = 'Please wait…'; }
    form.setAttribute('aria-busy', 'true');
  });
});
window.addEventListener('pageshow', event => { if (event.persisted) location.reload(); });
