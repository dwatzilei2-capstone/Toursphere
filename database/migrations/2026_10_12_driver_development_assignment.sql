BEGIN;
ALTER TABLE drivers ADD COLUMN IF NOT EXISTS license_development_assignment_enabled boolean NOT NULL DEFAULT false,
 ADD COLUMN IF NOT EXISTS license_development_verified_at timestamptz;
CREATE OR REPLACE FUNCTION toursphere_driver_license_status_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.status IN ('Assigned','On Trip') AND NOT (
   COALESCE(NEW.license_type='Professional' AND NEW.license_verification_status='Verified' AND NEW.authorized_dl_codes ? 'C' AND NEW.license_expiration >= CURRENT_DATE AND NEW.license_document_filename IS NOT NULL AND NEW.license_verified_by IS NOT NULL AND NEW.license_verified_at IS NOT NULL,false)
   OR COALESCE(current_setting('toursphere.app_env',true) IN ('development','testing') AND NEW.license_development_verified AND NEW.license_development_assignment_enabled AND NEW.license_development_verified_at IS NOT NULL AND NEW.license_expiration >= CURRENT_DATE,false)
 ) THEN NEW.status:='Inactive'; END IF;
 RETURN NEW;
END $$;
COMMIT;
