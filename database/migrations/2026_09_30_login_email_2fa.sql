BEGIN;

CREATE TABLE IF NOT EXISTS login_2fa_challenges (
    id CHAR(64) PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    purpose VARCHAR(30) NOT NULL DEFAULT 'login_2fa' CHECK (purpose = 'login_2fa'),
    destination VARCHAR(150) NOT NULL,
    otp_hash VARCHAR(255) NOT NULL,
    delivery_status VARCHAR(12) NOT NULL DEFAULT 'pending' CHECK (delivery_status IN ('pending', 'sent', 'failed')),
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at TIMESTAMPTZ,
    expires_at TIMESTAMPTZ NOT NULL,
    attempts INTEGER NOT NULL DEFAULT 0 CHECK (attempts BETWEEN 0 AND 5),
    verified_at TIMESTAMPTZ,
    used_at TIMESTAMPTZ
);

CREATE INDEX IF NOT EXISTS login_2fa_user_idx ON login_2fa_challenges(user_id);
CREATE INDEX IF NOT EXISTS login_2fa_expiry_idx ON login_2fa_challenges(expires_at);
CREATE UNIQUE INDEX IF NOT EXISTS login_2fa_one_active_per_user_idx ON login_2fa_challenges(user_id) WHERE used_at IS NULL;

CREATE TABLE IF NOT EXISTS login_2fa_limits (
    bucket CHAR(64) PRIMARY KEY,
    window_started_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    hits INTEGER NOT NULL DEFAULT 0,
    last_requested_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

COMMIT;
