(() => {
  'use strict';

  const code = document.getElementById('login-otp');
  code?.addEventListener('input', () => {
    code.value = code.value.replace(/\D/g, '').slice(0, 6);
  });

  const resend = document.getElementById('resend-code');
  const resendCountdown = document.getElementById('resend-countdown');
  const resendDeadline = Date.now() + Number(resend?.dataset.cooldown || 0) * 1000;

  const updateResend = () => {
    if (!resend || !resendCountdown) return;
    const cooldown = Math.max(0, Math.ceil((resendDeadline - Date.now()) / 1000));
    resend.disabled = cooldown > 0;
    resendCountdown.textContent = cooldown > 0 ? `Available in ${cooldown}s` : '';
  };
  updateResend();
  window.setInterval(updateResend, 1000);

  const expiration = document.getElementById('otp-expiration');
  const expirationDeadline = Date.now() + Number(expiration?.dataset.seconds || 0) * 1000;
  const updateExpiration = () => {
    if (!expiration) return;
    if (expiration.dataset.active !== '1') return;
    const remaining = Math.max(0, Math.ceil((expirationDeadline - Date.now()) / 1000));
    const minutes = Math.floor(Math.max(0, remaining) / 60);
    const seconds = Math.max(0, remaining) % 60;
    expiration.textContent = remaining > 0
      ? `Code expires in ${minutes}:${String(seconds).padStart(2, '0')}`
      : 'Code expired — request a new code';
    if (remaining === 0) {
      const verifyButton = code?.form?.querySelector('button[type="submit"]');
      if (verifyButton) verifyButton.disabled = true;
    }
  };
  updateExpiration();
  window.setInterval(updateExpiration, 1000);
  document.addEventListener('visibilitychange', () => { updateResend(); updateExpiration(); });
})();
