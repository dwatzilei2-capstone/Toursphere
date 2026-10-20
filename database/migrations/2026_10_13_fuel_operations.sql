BEGIN;
ALTER TABLE fuel_transactions ALTER COLUMN liters TYPE numeric(12,3);
ALTER TABLE fuel_transactions ALTER COLUMN price_per_liter TYPE numeric(12,4);
ALTER TABLE trip_funding_requests ADD COLUMN requested_fuel_amount numeric(14,2);
ALTER TABLE trip_funding_requests ADD COLUMN approved_fuel_amount numeric(14,2);
ALTER TABLE trip_funding_requests ADD CONSTRAINT funding_fuel_bounds CHECK
 (requested_fuel_amount IS NULL OR (requested_fuel_amount>=0 AND requested_fuel_amount<=requested_amount));
ALTER TABLE trip_funding_requests ADD CONSTRAINT funding_approved_fuel_bounds CHECK
 (approved_fuel_amount IS NULL OR (approved_fuel_amount>=0 AND approved_fuel_amount<=approved_amount));
-- Historical funding was fuel-only; retain its recorded allocation without approving anything new.
UPDATE trip_funding_requests SET requested_fuel_amount=requested_amount,
 approved_fuel_amount=CASE WHEN approval_status='APPROVED' THEN approved_amount END;
CREATE TABLE fuel_authorizations (
 id bigserial PRIMARY KEY, trip_id varchar(50) NOT NULL REFERENCES trips(id),
 vehicle_id varchar(50) NOT NULL REFERENCES vehicles(id), driver_id varchar(50) NOT NULL REFERENCES drivers(id),
 funding_request_id bigint REFERENCES trip_funding_requests(id),
 estimated_liters numeric(12,3), maximum_amount numeric(14,2),
 assessment text NOT NULL, instructions text NOT NULL,
 status varchar(30) NOT NULL CHECK(status IN ('Authorized','Not Required','Assessment Needed','Submitted','Fulfilled','Cancelled')),
 authorized_by integer NOT NULL REFERENCES users(id), created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 CHECK(status NOT IN ('Authorized','Submitted','Fulfilled') OR (estimated_liters>0 AND maximum_amount>0 AND funding_request_id IS NOT NULL))
);
CREATE UNIQUE INDEX fuel_authorization_open ON fuel_authorizations(trip_id) WHERE status IN ('Authorized','Submitted');
CREATE TABLE fuel_shortage_reports (
 id bigserial PRIMARY KEY, trip_id varchar(50) NOT NULL REFERENCES trips(id), driver_id varchar(50) NOT NULL REFERENCES drivers(id),
 reported_by integer NOT NULL REFERENCES users(id), description text NOT NULL,
 status varchar(20) NOT NULL DEFAULT 'Open' CHECK(status IN ('Open','Resolved')),
 resolution text, resolved_by integer REFERENCES users(id), created_at timestamptz NOT NULL DEFAULT clock_timestamp(), resolved_at timestamptz,
 CHECK(status<>'Resolved' OR (resolution IS NOT NULL AND resolved_by IS NOT NULL AND resolved_at IS NOT NULL))
);
CREATE UNIQUE INDEX fuel_shortage_open ON fuel_shortage_reports(trip_id) WHERE status='Open';
ALTER TABLE fuel_transactions ADD COLUMN authorization_id bigint REFERENCES fuel_authorizations(id);
ALTER TABLE fuel_transactions ADD COLUMN verification_status varchar(30) NOT NULL DEFAULT 'Legacy Unverified'
 CHECK(verification_status IN ('Legacy Unverified','Submitted','Pending Verification','Verified','Returned','Rejected'));
ALTER TABLE fuel_transactions ADD COLUMN submitted_by integer REFERENCES users(id);
ALTER TABLE fuel_transactions ADD COLUMN confirmed_by integer REFERENCES users(id);
ALTER TABLE fuel_transactions ADD COLUMN confirmed_at timestamptz;
ALTER TABLE fuel_transactions ADD COLUMN verified_by integer REFERENCES users(id);
ALTER TABLE fuel_transactions ADD COLUMN verified_at timestamptz;
ALTER TABLE fuel_transactions ADD COLUMN review_reason text;
ALTER TABLE fuel_transactions ADD COLUMN receipt_sha256 varchar(64);
ALTER TABLE fuel_transactions ADD COLUMN revision integer NOT NULL DEFAULT 1;
ALTER TABLE fuel_transactions ADD CONSTRAINT fuel_verification_complete CHECK
 (verification_status<>'Verified' OR (verified_by IS NOT NULL AND verified_at IS NOT NULL AND confirmed_by IS NOT NULL AND confirmed_at IS NOT NULL AND receipt_filename IS NOT NULL));
CREATE UNIQUE INDEX fuel_authorization_transaction ON fuel_transactions(authorization_id) WHERE authorization_id IS NOT NULL;
CREATE UNIQUE INDEX fuel_receipt_unique ON fuel_transactions(receipt_sha256) WHERE receipt_sha256 IS NOT NULL AND verification_status<>'Rejected';
CREATE UNIQUE INDEX fuel_receipt_station_unique ON fuel_transactions(lower(station),lower(receipt_no)) WHERE authorization_id IS NOT NULL AND verification_status<>'Rejected';
CREATE INDEX fuel_transactions_verification ON fuel_transactions(verification_status,trip_id);
DO $$ DECLARE tbl text; BEGIN
 FOREACH tbl IN ARRAY ARRAY['fuel_authorizations','fuel_shortage_reports'] LOOP
 EXECUTE format('CREATE TRIGGER toursphere_audit_change AFTER INSERT OR UPDATE OR DELETE ON %I FOR EACH ROW EXECUTE FUNCTION toursphere_audit_change()',tbl);
 END LOOP;
END $$;
COMMIT;
