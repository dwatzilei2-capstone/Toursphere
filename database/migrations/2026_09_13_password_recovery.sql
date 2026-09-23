BEGIN;
ALTER TABLE users ADD COLUMN IF NOT EXISTS recovery_email VARCHAR(150);
ALTER TABLE users ADD COLUMN IF NOT EXISTS recovery_phone VARCHAR(40);
CREATE INDEX IF NOT EXISTS users_recovery_email_idx ON users (lower(recovery_email));
CREATE TABLE IF NOT EXISTS password_recovery (
    id CHAR(64) PRIMARY KEY,
    user_id INTEGER REFERENCES users(id) ON DELETE CASCADE,
    channel VARCHAR(10) NOT NULL CHECK (channel IN ('email', 'phone')),
    destination VARCHAR(150) NOT NULL,
    purpose VARCHAR(30) NOT NULL DEFAULT 'password_reset' CHECK (purpose = 'password_reset'),
    otp_hash VARCHAR(255) NOT NULL,
    delivery_status VARCHAR(12) NOT NULL DEFAULT 'pending' CHECK (delivery_status IN ('pending', 'sent', 'failed', 'skipped')),
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMPTZ NOT NULL,
    attempts INTEGER NOT NULL DEFAULT 0 CHECK (attempts BETWEEN 0 AND 5),
    verified BOOLEAN NOT NULL DEFAULT FALSE,
    used_at TIMESTAMPTZ,
    reset_token_hash CHAR(64),
    reset_expires_at TIMESTAMPTZ
);
CREATE INDEX IF NOT EXISTS password_recovery_user_idx ON password_recovery(user_id);
CREATE INDEX IF NOT EXISTS password_recovery_expiry_idx ON password_recovery(expires_at);
CREATE TABLE IF NOT EXISTS password_recovery_limits (
    bucket CHAR(64) PRIMARY KEY,
    window_started_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    hits INTEGER NOT NULL DEFAULT 0,
    last_requested_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);
COMMIT;
