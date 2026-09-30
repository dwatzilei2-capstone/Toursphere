ALTER TABLE reservations
    ADD COLUMN IF NOT EXISTS required_vehicle_type_id INTEGER REFERENCES vehicle_types(id) ON DELETE RESTRICT;

ALTER TABLE reservations
    ADD COLUMN IF NOT EXISTS required_capacity INTEGER CHECK (required_capacity > 0);

CREATE INDEX IF NOT EXISTS idx_reservations_required_vehicle_type
    ON reservations(required_vehicle_type_id);

COMMENT ON COLUMN reservations.required_vehicle_type_id IS
    'Server-determined vehicle category requirement; this is not a physical vehicle assignment.';

COMMENT ON COLUMN reservations.required_capacity IS
    'Capacity snapshot selected by the server when the reservation was submitted.';
