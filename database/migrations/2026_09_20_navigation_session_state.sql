BEGIN;

ALTER TABLE trips
  ADD COLUMN IF NOT EXISTS navigation_active SMALLINT NOT NULL DEFAULT 0;

ALTER TABLE trips
  DROP CONSTRAINT IF EXISTS chk_trips_navigation_active;

ALTER TABLE trips
  ADD CONSTRAINT chk_trips_navigation_active CHECK (navigation_active IN (0,1));

COMMIT;
