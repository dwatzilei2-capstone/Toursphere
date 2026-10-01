CREATE TABLE IF NOT EXISTS ai_learning_samples (
    log_id VARCHAR(30) NOT NULL,
    target VARCHAR(20) NOT NULL,
    context_key VARCHAR(80) NOT NULL,
    ratio DOUBLE PRECISION NOT NULL CHECK (ratio > 0),
    fingerprint VARCHAR(64) NOT NULL,
    learned_at TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),
    PRIMARY KEY (log_id, target)
);
CREATE TABLE IF NOT EXISTS ai_learning_stats (
    target VARCHAR(20) NOT NULL,
    context_key VARCHAR(80) NOT NULL,
    samples INTEGER NOT NULL DEFAULT 0 CHECK (samples >= 0),
    ratio_sum DOUBLE PRECISION NOT NULL DEFAULT 0,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),
    PRIMARY KEY (target, context_key)
);
