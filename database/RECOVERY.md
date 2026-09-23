# Password recovery

Email recovery uses `users.recovery_email` for recovery and keeps the existing
`users.email` login address unchanged. No accounts are created.
The login link opens `forgot-password.php`; POSTs go to `actions/recovery.php`.
The login CSS was extracted unchanged into `css/auth.css` for both pages.

## Deployment

1. Run `composer install --no-dev` (PHP 8.2+, PDO PostgreSQL, OpenSSL, mbstring).
2. Run `php scripts/migrate-recovery.php`. This adds two tables without changing
   existing account tables. Do not import the destructive full database seed.
3. Set these variables in the existing protected `.env` or server environment:
   `SMTP_HOST`, `SMTP_PORT`, `SMTP_USERNAME`, `SMTP_PASSWORD`,
   `SMTP_ENCRYPTION` (`tls` for STARTTLS, or `ssl` for implicit TLS),
   `SMTP_FROM_EMAIL`. Recovery mail always uses the current company name as its
   sender display name, even if `SMTP_FROM_NAME` is configured differently.
   Use the system mailbox credentials. For Gmail use that mailbox's App Password.
   Never ask a recovering user for mailbox credentials. Keep `.env` private.
4. Run the delivery worker under a service supervisor:
   `php scripts/recovery-worker.php`. It polls every two seconds.
   Alternatively schedule `php scripts/recovery-worker.php --once` every minute
   using Windows Task Scheduler or cron. On this XAMPP installation use
   `C:\xampp\php\php.exe` with the absolute script path
   `C:\xampp\htdocs\fleet\scripts\recovery-worker.php --once`.
   Configure startup/restart and prevent overlapping scheduled runs.
5. Serve production over HTTPS, set `APP_DEBUG=false`, and confirm PHP sessions
   use a private writable session directory. When terminating TLS at a proxy,
   configure the web server's HTTPS flag; do not trust arbitrary forwarding headers.
6. Verify receipt with a designated test account through the complete browser
   flow. Inspect worker logs for a generic delivery failure if no email arrives.

The worker is required: SMTP is deliberately absent from HTTP requests to avoid
account enumeration through SMTP response time. Queue records store no plaintext
OTP. The worker generates a cryptographically random six-digit code in memory,
sends through PHPMailer, then saves only its password hash. Expiration starts
after SMTP accepts the message. Pending requests expire after five minutes;
failed deliveries require a new request and never appear as confirmed delivery.
Worker logs deliberately omit recipients, codes, credentials and SMTP transcripts.
There is a small SMTP/database failure window: if SMTP accepts the message but
the database commit fails, that email's code is unusable; request a new one.

## Security and behavior

- Identical generic request result for unknown, inactive, ambiguous and throttled
  addresses; SMTP delivery is out of band. Ambiguous case-insensitive email
  matches fail closed. The current database has no such duplicates.
- Request cap: 20 per IP/hour, 5 per email/hour, 60 seconds between sends.
- Verify cap: 30 per IP/15 minutes and 5 attempts per delivered code.
- Successful verification immediately destroys the OTP hash and creates a random
  server-session reset grant, hashed in PostgreSQL, valid for ten minutes.
- Password update and grant consumption are atomic with row locks. Reissue
  invalidates prior codes and grants. Password reset also invalidates other open
  requests for the account. Account email/status are rechecked before update.
- All recovery POSTs require CSRF. Session IDs rotate at request, verification,
  and completion. Cookies are HttpOnly, SameSite=Lax and Secure over HTTPS.
- Recovery responses are no-store; grants/codes never appear in URLs or browser
  responses. Passwords use `password_hash` / `password_verify`.
- No preexisting password policy was found: new reset passwords require 12
  characters with a 72-byte maximum to avoid bcrypt truncation. No composition
  rules. Existing login password rules remain compatible.
- Requests and limit counters older than one day are cleaned by the worker.
- No existing logged-in sessions are automatically revoked; this application
  currently has no central session registry. Reset grants are revoked.

## Phone extension

Phone recovery is visibly marked not configured. The POST handler fails closed;
`sendSmsOtp()` throws without sending anything. The recovery table supports
`channel='phone'` and `destination`, but email verification/reset only accepts
email rows. Before enabling phone recovery, define verified, unique account
phone ownership (the existing `users` table has no phone field; driver phone
numbers alone do not cover all roles), add a provider adapter and its configuration,
and add the phone issue/verify routes with the same controls. No SMS SDK is installed.

## Checks

`php scripts/test-recovery.php` runs isolated PostgreSQL integration checks in a
temporary schema and removes it afterward. It never sends email or modifies real
accounts. SMTP delivery must additionally be checked with configured credentials.

References: [PHPMailer](https://github.com/PHPMailer/PHPMailer),
[OWASP recovery guidance](https://cheatsheetseries.owasp.org/cheatsheets/Forgot_Password_Cheat_Sheet.html).
