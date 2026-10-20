BEGIN;
INSERT INTO permissions(code,name,module) VALUES ('drivers.manage','Register drivers and verify licenses','Driver & Trip Performance') ON CONFLICT(code) DO NOTHING;
INSERT INTO role_permissions(role_id,permission_id) SELECT r.id,p.id FROM roles r CROSS JOIN permissions p WHERE r.code IN ('fleet_admin','fleet_manager') AND p.code='drivers.manage' ON CONFLICT DO NOTHING;
ALTER TABLE drivers
 ADD COLUMN IF NOT EXISTS license_type varchar(30),
 ADD COLUMN IF NOT EXISTS authorized_dl_codes jsonb NOT NULL DEFAULT '[]'::jsonb,
 ADD COLUMN IF NOT EXISTS license_verification_status varchar(20) NOT NULL DEFAULT 'Unverified',
 ADD COLUMN IF NOT EXISTS license_document_filename varchar(100),
 ADD COLUMN IF NOT EXISTS license_document_mime varchar(50),
 ADD COLUMN IF NOT EXISTS license_extracted_data jsonb NOT NULL DEFAULT '{}'::jsonb,
 ADD COLUMN IF NOT EXISTS license_verification_notes text,
 ADD COLUMN IF NOT EXISTS license_verified_by integer REFERENCES users(id),
 ADD COLUMN IF NOT EXISTS license_verified_at timestamptz;
ALTER TABLE vehicles ADD COLUMN IF NOT EXISTS gross_vehicle_weight_kg numeric(10,2), ADD COLUMN IF NOT EXISTS requires_trailer_license boolean NOT NULL DEFAULT false;
ALTER TABLE drivers ADD CONSTRAINT drivers_dl_codes_valid CHECK (jsonb_typeof(authorized_dl_codes)='array' AND authorized_dl_codes <@ '["A","A1","B","B1","B2","BE","C","CE","D"]'::jsonb);
ALTER TABLE drivers ADD CONSTRAINT drivers_license_verification_valid CHECK (license_verification_status IN ('Unverified','Verified'));
ALTER TABLE drivers ADD CONSTRAINT drivers_license_type_valid CHECK (license_type IS NULL OR license_type IN ('Professional','Non-Professional'));
ALTER TABLE drivers ADD CONSTRAINT drivers_verified_evidence CHECK (license_verification_status<>'Verified' OR (license_type='Professional' AND license_no IS NOT NULL AND btrim(license_no)<>'' AND license_expiration IS NOT NULL AND license_document_filename IS NOT NULL AND btrim(license_document_filename)<>'' AND license_verified_by IS NOT NULL AND license_verified_at IS NOT NULL AND license_verification_notes IS NOT NULL AND length(btrim(license_verification_notes))>=10));
ALTER TABLE vehicles ADD CONSTRAINT vehicles_gvw_positive CHECK (gross_vehicle_weight_kg IS NULL OR gross_vehicle_weight_kg>0);
-- Existing Class 2/3 text is retained for reference, never converted into modern DL codes.
UPDATE drivers SET status='Inactive' WHERE status IN ('Active','Assigned','On Trip') AND license_verification_status='Unverified';
CREATE OR REPLACE FUNCTION toursphere_driver_license_status_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.status IN ('Assigned','On Trip') AND NOT COALESCE(NEW.license_type='Professional' AND NEW.license_verification_status='Verified' AND NEW.authorized_dl_codes ? 'C' AND NEW.license_expiration >= CURRENT_DATE AND NEW.license_document_filename IS NOT NULL AND NEW.license_verified_by IS NOT NULL AND NEW.license_verified_at IS NOT NULL,false) THEN NEW.status:='Inactive'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER driver_license_status_guard BEFORE INSERT OR UPDATE ON drivers FOR EACH ROW EXECUTE FUNCTION toursphere_driver_license_status_guard();
COMMIT;
