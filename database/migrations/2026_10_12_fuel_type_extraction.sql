-- Fuel Type extraction and manual selection: add the remaining supported options.
-- Additive and non-destructive: no column or constraint changes are required because
-- vehicles.fuel_type and vehicle_variants.fuel_type already exist, and existing fuel
-- values (Diesel on all current vehicles) keep their exact stored spelling.
-- Idempotent: safe to re-run.
--
-- Manual rollback (only if this must be reverted):
--   DELETE FROM fuel_types WHERE name IN ('Ethanol / Flex Fuel', 'Other');

BEGIN;

INSERT INTO fuel_types (name, sort_order) VALUES
  ('Ethanol / Flex Fuel', 65),
  ('Other', 90)
ON CONFLICT (name) DO NOTHING;

COMMENT ON TABLE fuel_types IS
  'Configurable fuel options for vehicle registration, document extraction normalization, and fuel pricing.';

COMMIT;
