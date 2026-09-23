BEGIN;

ALTER TABLE reservations ADD COLUMN IF NOT EXISTS cancellation_type VARCHAR(30);
ALTER TABLE reservations ADD COLUMN IF NOT EXISTS cancellation_reason VARCHAR(120);
ALTER TABLE reservations ADD COLUMN IF NOT EXISTS cancellation_notes TEXT;
ALTER TABLE reservations ADD COLUMN IF NOT EXISTS cancelled_by INTEGER;
ALTER TABLE reservations ADD COLUMN IF NOT EXISTS cancelled_at TIMESTAMP;
ALTER TABLE reservations ADD COLUMN IF NOT EXISTS cancelled_vehicle_id VARCHAR(20);
ALTER TABLE reservations ADD COLUMN IF NOT EXISTS cancelled_driver_id VARCHAR(20);

DO $$ BEGIN
  ALTER TABLE reservations ADD CONSTRAINT fk_res_cancelled_by
    FOREIGN KEY (cancelled_by) REFERENCES users(id) ON DELETE SET NULL;
EXCEPTION WHEN duplicate_object THEN NULL; END $$;

DO $$ BEGIN
  ALTER TABLE reservations ADD CONSTRAINT fk_res_cancelled_vehicle
    FOREIGN KEY (cancelled_vehicle_id) REFERENCES vehicles(id) ON DELETE SET NULL;
EXCEPTION WHEN duplicate_object THEN NULL; END $$;

DO $$ BEGIN
  ALTER TABLE reservations ADD CONSTRAINT fk_res_cancelled_driver
    FOREIGN KEY (cancelled_driver_id) REFERENCES drivers(id) ON DELETE SET NULL;
EXCEPTION WHEN duplicate_object THEN NULL; END $$;

COMMIT;
