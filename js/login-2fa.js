(() => {
  'use strict';

  const code = document.getElementById('login-otp');
  code?.addEventListener('input', () => {
    code.value = code.value.replace(/\D/g, '').slice(0, 6);
  });

  const resend = document.getElementById('resend-code');
  const resendCountdown = document.getElementById('resend-countdown');
  let cooldown = Number(resend?.dataset.cooldown || 0);

  const updateResend = () => {
    if (!resend || !resendCountdown) return;
    resend.disabled = cooldown > 0;
    resendCountdown.textContent = cooldown > 0 ? `Available in ${cooldown}s` : '';
    if (cooldown > 0) cooldown -= 1;
  };
  updateResend();
  if (cooldown > 0) window.setInterval(updateResend, 1000);

  const expiration = document.getElementById('otp-expiration');
  let remaining = Number(expiration?.dataset.seconds || 0);
  const updateExpiration = () => {
    if (!expiration) return;
    const minutes = Math.floor(Math.max(0, remaining) / 60);
    const seconds = Math.max(0, remaining) % 60;
    expiration.textContent = remaining > 0
      ? `Code expires in ${minutes}:${String(seconds).padStart(2, '0')}`
      : 'Code expired — request a new code';
    if (remaining > 0) remaining -= 1;
  };
  updateExpiration();
  if (remaining > 0) window.setInterval(updateExpiration, 1000);
})();
