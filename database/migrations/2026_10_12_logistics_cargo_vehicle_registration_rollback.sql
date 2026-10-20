-- Manual rollback for 2026_10_12_logistics_cargo_vehicle_registration.sql.
-- Run only if the cargo registration change must be reverted. It removes the added catalog
-- rows and columns; it never deletes a vehicle, a document, an actual photo, or any history.
-- Cargo vehicles registered after the migration must be handled manually before this runs.

BEGIN;

DELETE FROM vehicle_variants
 WHERE payload_capacity_kg IS NOT NULL OR cargo_volume_m3 IS NOT NULL;

DELETE FROM vehicle_models m
 USING vehicle_types t
 WHERE m.vehicle_type_id = t.id AND t.service_category = 'Cargo';

DELETE FROM vehicle_types WHERE service_category = 'Cargo';

ALTER TABLE vehicle_variants
  DROP CONSTRAINT IF EXISTS vehicle_variants_payload_capacity_check,
  DROP CONSTRAINT IF EXISTS vehicle_variants_cargo_volume_check,
  DROP COLUMN IF EXISTS payload_capacity_kg,
  DROP COLUMN IF EXISTS cargo_volume_m3;

ALTER TABLE vehicle_types
  DROP CONSTRAINT IF EXISTS vehicle_types_service_category_check,
  DROP COLUMN IF EXISTS service_category;

ALTER TABLE vehicles
  DROP CONSTRAINT IF EXISTS vehicles_service_category_check,
  DROP CONSTRAINT IF EXISTS vehicles_payload_capacity_check,
  DROP CONSTRAINT IF EXISTS vehicles_cargo_volume_check,
  DROP CONSTRAINT IF EXISTS vehicles_cargo_dimension_check,
  DROP COLUMN IF EXISTS service_category,
  DROP COLUMN IF EXISTS payload_capacity_kg,
  DROP COLUMN IF EXISTS cargo_volume_m3,
  DROP COLUMN IF EXISTS cargo_length_m,
  DROP COLUMN IF EXISTS cargo_width_m,
  DROP COLUMN IF EXISTS cargo_height_m;

-- Passenger seat counts were never cleared by the migration, so capacity can be restored.
ALTER TABLE vehicles ALTER COLUMN capacity SET NOT NULL;

DROP TABLE IF EXISTS fuel_types;

COMMIT;
