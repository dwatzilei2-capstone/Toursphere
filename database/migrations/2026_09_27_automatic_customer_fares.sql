ALTER TABLE reservations ADD COLUMN IF NOT EXISTS base_fare_used DECIMAL(12,2);
ALTER TABLE reservations ADD COLUMN IF NOT EXISTS rate_per_km_used DECIMAL(12,2);
ALTER TABLE reservations ADD COLUMN IF NOT EXISTS route_distance_km DECIMAL(10,2);
ALTER TABLE reservations ADD COLUMN IF NOT EXISTS distance_charge DECIMAL(12,2);
ALTER TABLE reservations ADD COLUMN IF NOT EXISTS fare_per_person DECIMAL(12,2);
ALTER TABLE reservations ADD COLUMN IF NOT EXISTS total_booking_fare DECIMAL(12,2);
ALTER TABLE reservations ADD COLUMN IF NOT EXISTS fare_status VARCHAR(20);
ALTER TABLE reservations ADD COLUMN IF NOT EXISTS fare_calculated_at TIMESTAMP;

COMMENT ON COLUMN reservations.base_fare_used IS 'Immutable base fare snapshot used when the customer submitted the reservation.';
COMMENT ON COLUMN reservations.rate_per_km_used IS 'Immutable per-kilometer rate snapshot used when the customer submitted the reservation.';
COMMENT ON COLUMN reservations.route_distance_km IS 'Authoritative road-route distance obtained by the server.';
COMMENT ON COLUMN reservations.total_booking_fare IS 'Customer booking revenue estimate; not an internal trip operating cost.';

