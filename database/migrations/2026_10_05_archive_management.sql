BEGIN;
ALTER TABLE trips ADD COLUMN IF NOT EXISTS is_archived BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE trips ADD COLUMN IF NOT EXISTS archived_at TIMESTAMP;
ALTER TABLE trips ADD COLUMN IF NOT EXISTS archived_by INTEGER REFERENCES users(id) ON DELETE SET NULL;
ALTER TABLE vehicles ADD COLUMN IF NOT EXISTS is_archived BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE vehicles ADD COLUMN IF NOT EXISTS archived_at TIMESTAMP;
ALTER TABLE vehicles ADD COLUMN IF NOT EXISTS archived_by INTEGER REFERENCES users(id) ON DELETE SET NULL;
ALTER TABLE vehicles ADD COLUMN IF NOT EXISTS retirement_reason TEXT;
ALTER TABLE vehicles ADD COLUMN IF NOT EXISTS retirement_notes TEXT;
ALTER TABLE vehicles ADD COLUMN IF NOT EXISTS retired_at TIMESTAMP;
ALTER TABLE vehicles ADD COLUMN IF NOT EXISTS retired_by INTEGER REFERENCES users(id) ON DELETE SET NULL;
ALTER TABLE vehicles ADD COLUMN IF NOT EXISTS retirement_recommendation TEXT;
ALTER TABLE vehicles ADD COLUMN IF NOT EXISTS retirement_basis TEXT;
CREATE INDEX IF NOT EXISTS idx_archive_trips ON trips(status, archived_at DESC, id) WHERE is_archived;
CREATE INDEX IF NOT EXISTS idx_archive_vehicles ON vehicles(retired_at DESC, id) WHERE is_archived;
CREATE INDEX IF NOT EXISTS idx_audit_archive_entity ON audit_logs(entity_type,entity_id,created_at DESC);
INSERT INTO permissions(code,name,module) VALUES
 ('archive.view','View permitted Archive records','Archive'),
 ('archive.trips','Manually archive closed trips','Archive'),
 ('archive.retire','Retire vehicles','Archive') ON CONFLICT(code) DO NOTHING;
INSERT INTO role_permissions(role_id,permission_id)
 SELECT r.id,p.id FROM roles r CROSS JOIN permissions p
 WHERE (r.code='fleet_admin' AND p.code IN ('archive.view','archive.trips','archive.retire'))
 OR (r.code='fleet_manager' AND p.code='archive.view')
 OR (r.code='dispatcher' AND p.code IN ('archive.view','archive.trips'))
 ON CONFLICT DO NOTHING;
-- Protect retirement against older maintenance/status refresh paths as well.
CREATE OR REPLACE FUNCTION archive_vehicle_lifecycle_guard() RETURNS trigger AS $$
BEGIN
 IF OLD.status='Retired' AND (NEW.status<>'Retired' OR NOT NEW.is_archived) THEN
  RAISE EXCEPTION 'A retired vehicle cannot return to fleet operations';
 END IF;
 RETURN NEW;
END; $$ LANGUAGE plpgsql;
DROP TRIGGER IF EXISTS archive_vehicle_lifecycle ON vehicles;
CREATE TRIGGER archive_vehicle_lifecycle BEFORE UPDATE ON vehicles
 FOR EACH ROW EXECUTE FUNCTION archive_vehicle_lifecycle_guard();
CREATE OR REPLACE FUNCTION archive_trip_lifecycle_guard() RETURNS trigger AS $$
BEGIN
 IF NEW.is_archived AND NEW.status NOT IN ('Completed','Cancelled','Incomplete') THEN
  RAISE EXCEPTION 'Only closed trips can be archived';
 END IF;
 IF TG_OP='UPDATE' AND OLD.is_archived AND (NOT NEW.is_archived OR NEW.status<>OLD.status) THEN
  RAISE EXCEPTION 'Archived trip status is preserved';
 END IF;
 RETURN NEW;
END; $$ LANGUAGE plpgsql;
DROP TRIGGER IF EXISTS archive_trip_lifecycle ON trips;
CREATE TRIGGER archive_trip_lifecycle BEFORE INSERT OR UPDATE ON trips
 FOR EACH ROW EXECUTE FUNCTION archive_trip_lifecycle_guard();
COMMIT;
