CREATE TABLE IF NOT EXISTS vehicle_photos (
    vehicle_id varchar(30) PRIMARY KEY REFERENCES vehicles(id) ON DELETE CASCADE,
    actual_filename varchar(100),
    actual_mime varchar(30),
    actual_size integer,
    actual_width integer,
    actual_height integer,
    uploaded_by varchar(30),
    uploaded_at timestamptz,
    sample_filename varchar(100),
    sample_label text,
    updated_at timestamptz NOT NULL DEFAULT now(),
    CHECK (actual_filename IS NULL OR actual_filename ~ '^[a-f0-9]{48}\.(jpg|png|webp)$'),
    CHECK (sample_filename IS NULL OR sample_filename ~ '^[a-f0-9]{20}\.png$')
);
