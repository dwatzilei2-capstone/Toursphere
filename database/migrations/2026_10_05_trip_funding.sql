BEGIN;
CREATE TABLE IF NOT EXISTS fuel_price_history (
 id bigserial PRIMARY KEY, fuel_type varchar(50) NOT NULL, price_per_liter numeric(12,4) NOT NULL CHECK(price_per_liter>0),
 effective_date date NOT NULL, source varchar(200) NOT NULL, updated_by integer NOT NULL REFERENCES users(id), updated_at timestamp NOT NULL DEFAULT clock_timestamp()
);
CREATE INDEX IF NOT EXISTS fuel_price_current ON fuel_price_history(fuel_type,effective_date DESC,id DESC);
CREATE TABLE IF NOT EXISTS trip_funding_methods (
 code varchar(40) PRIMARY KEY, name varchar(100) NOT NULL, enabled boolean NOT NULL DEFAULT true
);
INSERT INTO trip_funding_methods(code,name) VALUES ('company_card','Company Card / Fuel Card'),('cash_advance','Cash Advance'),('company_account','Company Account'),('other','Other Authorized Funding Method') ON CONFLICT DO NOTHING;
CREATE TABLE IF NOT EXISTS trip_cost_estimates (
 id bigserial PRIMARY KEY, trip_id varchar(50) NOT NULL REFERENCES trips(id), reservation_id varchar(50) NOT NULL REFERENCES reservations(id),
 vehicle_id varchar(50) NOT NULL REFERENCES vehicles(id), driver_id varchar(50) NOT NULL REFERENCES drivers(id),
 distance_km numeric(12,4) NOT NULL CHECK(distance_km>0), distance_source text NOT NULL, expected_km_per_liter numeric(12,4) NOT NULL CHECK(expected_km_per_liter>0),
 fuel_type varchar(50) NOT NULL, fuel_price_id bigint NOT NULL REFERENCES fuel_price_history(id), reference_price numeric(12,4) NOT NULL CHECK(reference_price>0),
 estimated_liters numeric(12,4) NOT NULL CHECK(estimated_liters>0), estimated_fuel_cost numeric(14,2) NOT NULL, estimated_total_cost numeric(14,2) NOT NULL,
 inputs jsonb NOT NULL, created_by integer NOT NULL REFERENCES users(id), created_at timestamp NOT NULL DEFAULT clock_timestamp()
);
CREATE TABLE IF NOT EXISTS trip_funding_requests (
 id bigserial PRIMARY KEY, trip_id varchar(50) NOT NULL REFERENCES trips(id), reservation_id varchar(50) NOT NULL REFERENCES reservations(id),
 vehicle_id varchar(50) NOT NULL REFERENCES vehicles(id), driver_id varchar(50) NOT NULL REFERENCES drivers(id), estimate_id bigint NOT NULL REFERENCES trip_cost_estimates(id),
 funding_method varchar(40) NOT NULL REFERENCES trip_funding_methods(code), method_name varchar(100) NOT NULL,
 requested_amount numeric(14,2) NOT NULL CHECK(requested_amount>0), approved_amount numeric(14,2), requested_by integer NOT NULL REFERENCES users(id),
 requested_at timestamp NOT NULL DEFAULT clock_timestamp(), approval_due_at timestamp NOT NULL DEFAULT (clock_timestamp()+interval '5 seconds'),
 finance_source varchar(40) NOT NULL DEFAULT 'MOCK_FINANCE', finance_funding_id varchar(100), safe_reference varchar(100), response_reason text,
 status varchar(40) NOT NULL DEFAULT 'Pending Finance Approval' CHECK(status IN ('Pending Finance Approval','Funding Confirmed','Superseded','Cancelled','Rejected')),
 approval_status varchar(20), approved_at timestamp, response_at timestamp,
 CHECK(approved_at IS NULL OR approved_at>=approval_due_at)
);
CREATE UNIQUE INDEX IF NOT EXISTS funding_active_trip ON trip_funding_requests(trip_id) WHERE status IN ('Pending Finance Approval','Funding Confirmed');
ALTER TABLE fuel_transactions ADD COLUMN IF NOT EXISTS system_default_price numeric(12,4);
ALTER TABLE fuel_transactions ADD COLUMN IF NOT EXISTS system_fuel_price_id bigint REFERENCES fuel_price_history(id);
ALTER TABLE fuel_transactions ADD COLUMN IF NOT EXISTS funding_request_id bigint REFERENCES trip_funding_requests(id);
ALTER TABLE fuel_transactions ADD COLUMN IF NOT EXISTS funding_method varchar(100);
ALTER TABLE fuel_transactions ADD COLUMN IF NOT EXISTS funding_safe_reference varchar(100);
DO $$ DECLARE tbl text; BEGIN
 FOREACH tbl IN ARRAY ARRAY['fuel_price_history','trip_funding_methods','trip_cost_estimates','trip_funding_requests'] LOOP
  EXECUTE format('DROP TRIGGER IF EXISTS toursphere_audit_change ON %I',tbl);
  EXECUTE format('CREATE TRIGGER toursphere_audit_change AFTER INSERT OR UPDATE OR DELETE ON %I FOR EACH ROW EXECUTE FUNCTION toursphere_audit_change()',tbl);
 END LOOP;
END $$;
COMMIT;
