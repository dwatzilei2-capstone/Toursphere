BEGIN;

CREATE TABLE IF NOT EXISTS vehicle_types (
  id SERIAL PRIMARY KEY,
  name VARCHAR(80) NOT NULL UNIQUE,
  status VARCHAR(20) NOT NULL DEFAULT 'Active' CHECK (status IN ('Active', 'Inactive'))
);

CREATE TABLE IF NOT EXISTS vehicle_brands (
  id SERIAL PRIMARY KEY,
  name VARCHAR(80) NOT NULL UNIQUE,
  status VARCHAR(20) NOT NULL DEFAULT 'Active' CHECK (status IN ('Active', 'Inactive'))
);

CREATE TABLE IF NOT EXISTS vehicle_models (
  id SERIAL PRIMARY KEY,
  vehicle_type_id INTEGER NOT NULL REFERENCES vehicle_types(id),
  brand_id INTEGER NOT NULL REFERENCES vehicle_brands(id),
  model_name VARCHAR(100) NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'Active' CHECK (status IN ('Active', 'Inactive')),
  UNIQUE (vehicle_type_id, brand_id, model_name)
);

CREATE INDEX IF NOT EXISTS idx_vehicle_models_type_brand ON vehicle_models(vehicle_type_id, brand_id);

CREATE TABLE IF NOT EXISTS vehicle_variants (
  id SERIAL PRIMARY KEY,
  model_id INTEGER NOT NULL REFERENCES vehicle_models(id),
  variant_name VARCHAR(120) NOT NULL,
  model_year INTEGER,
  passenger_capacity INTEGER CHECK (passenger_capacity > 0),
  fuel_tank_capacity INTEGER CHECK (fuel_tank_capacity > 0),
  fuel_type VARCHAR(30),
  status VARCHAR(20) NOT NULL DEFAULT 'Active' CHECK (status IN ('Active', 'Inactive')),
  UNIQUE (model_id, variant_name, model_year)
);

CREATE INDEX IF NOT EXISTS idx_vehicle_variants_model ON vehicle_variants(model_id);

ALTER TABLE vehicles ADD COLUMN IF NOT EXISTS variant_id INTEGER REFERENCES vehicle_variants(id) ON DELETE SET NULL;
ALTER TABLE vehicles ADD COLUMN IF NOT EXISTS created_by INTEGER REFERENCES users(id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS vehicle_documents (
  id VARCHAR(40) PRIMARY KEY,
  vehicle_id VARCHAR(20) NOT NULL REFERENCES vehicles(id) ON DELETE CASCADE,
  document_type VARCHAR(30) NOT NULL DEFAULT 'OR/CR',
  stored_filename VARCHAR(255) NOT NULL UNIQUE,
  original_filename VARCHAR(255) NOT NULL,
  mime_type VARCHAR(100) NOT NULL,
  file_size INTEGER NOT NULL CHECK (file_size > 0),
  extracted_plate_number VARCHAR(20),
  extraction_confidence NUMERIC(5,4),
  uploaded_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
  uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_vehicle_documents_vehicle ON vehicle_documents(vehicle_id);

CREATE TABLE IF NOT EXISTS audit_logs (
  id BIGSERIAL PRIMARY KEY,
  action VARCHAR(80) NOT NULL,
  user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
  entity_type VARCHAR(50),
  entity_id VARCHAR(50),
  details TEXT,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_audit_logs_action_created ON audit_logs(action, created_at DESC);

INSERT INTO vehicle_types (name) VALUES
  ('Van'), ('MPV'), ('SUV'), ('Minibus'), ('Bus / Coach')
ON CONFLICT (name) DO NOTHING;

INSERT INTO vehicle_brands (name) VALUES
  ('Toyota'), ('Hyundai'), ('Isuzu'), ('Mitsubishi'), ('Hino'), ('Ford'), ('Nissan'), ('Kia'), ('Volvo'), ('Scania'), ('Yutong'), ('King Long')
ON CONFLICT (name) DO NOTHING;

INSERT INTO vehicle_models (vehicle_type_id, brand_id, model_name)
SELECT vt.id, vb.id, seed.model_name
FROM (VALUES
  ('Van', 'Toyota', 'Hiace'),
  ('Van', 'Hyundai', 'Staria'),
  ('MPV', 'Kia', 'Carnival'),
  ('MPV', 'Toyota', 'Innova'),
  ('SUV', 'Ford', 'Everest'),
  ('SUV', 'Nissan', 'Terra'),
  ('SUV', 'Mitsubishi', 'Montero Sport'),
  ('Minibus', 'Toyota', 'Coaster'),
  ('Minibus', 'Hyundai', 'County'),
  ('Minibus', 'Isuzu', 'N-Series Minibus'),
  ('Bus / Coach', 'Hyundai', 'Universe'),
  ('Bus / Coach', 'Hino', 'RM2P'),
  ('Bus / Coach', 'Volvo', 'B8R'),
  ('Bus / Coach', 'Scania', 'Touring'),
  ('Bus / Coach', 'Yutong', 'ZK6122H9'),
  ('Bus / Coach', 'King Long', 'XMQ6127')
) AS seed(type_name, brand_name, model_name)
JOIN vehicle_types vt ON vt.name = seed.type_name
JOIN vehicle_brands vb ON vb.name = seed.brand_name
ON CONFLICT (vehicle_type_id, brand_id, model_name) DO NOTHING;

INSERT INTO vehicle_variants (model_id, variant_name, model_year, passenger_capacity, fuel_tank_capacity, fuel_type)
SELECT vm.id, seed.variant_name, seed.model_year, seed.capacity, seed.tank, seed.fuel
FROM (VALUES
  ('Hiace', 'Grandia Tourer', 2024, 14, 70, 'Diesel'),
  ('Staria', 'Premium 9-seater', 2024, 9, 75, 'Diesel'),
  ('Carnival', 'Grand Carnival', 2023, 11, 72, 'Diesel'),
  ('Everest', 'Titanium 4x4', 2024, 7, 80, 'Diesel'),
  ('Terra', 'VL 4x4', 2024, 7, 78, 'Diesel'),
  ('Coaster', 'Deluxe', 2023, 29, 95, 'Diesel'),
  ('County', 'Deluxe', 2023, 29, 95, 'Diesel'),
  ('RM2P', 'Grand Coach', 2023, 47, 260, 'Diesel'),
  ('B8R', 'Coach 50', 2023, 50, 280, 'Diesel'),
  ('Touring', 'HD 49', 2023, 49, 300, 'Diesel'),
  ('ZK6122H9', 'Coach', 2023, 49, 300, 'Diesel'),
  ('XMQ6127', 'Coach', 2023, 48, 290, 'Diesel')
) AS seed(model_name, variant_name, model_year, capacity, tank, fuel)
JOIN vehicle_models vm ON vm.model_name = seed.model_name
ON CONFLICT (model_id, variant_name, model_year) DO NOTHING;

COMMIT;
