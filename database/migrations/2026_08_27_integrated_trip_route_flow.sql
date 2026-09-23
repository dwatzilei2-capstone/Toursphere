 
 

ALTER TABLE route_history ADD COLUMN IF NOT EXISTS reservation_id VARCHAR(30);
ALTER TABLE route_history ADD COLUMN IF NOT EXISTS trip_id VARCHAR(20);
ALTER TABLE route_history ADD COLUMN IF NOT EXISTS origin VARCHAR(200);
ALTER TABLE route_history ADD COLUMN IF NOT EXISTS destination VARCHAR(200);
ALTER TABLE route_history ADD COLUMN IF NOT EXISTS waypoints_json TEXT;
ALTER TABLE route_history ADD COLUMN IF NOT EXISTS route_data_json TEXT;
ALTER TABLE route_history ADD COLUMN IF NOT EXISTS navigation_key VARCHAR(64);
ALTER TABLE route_history ADD COLUMN IF NOT EXISTS actual_fuel_verified SMALLINT NOT NULL DEFAULT 0 CHECK (actual_fuel_verified IN (0,1));
ALTER TABLE route_history ADD COLUMN IF NOT EXISTS actual_fuel_transaction_id VARCHAR(30);
ALTER TABLE route_history ALTER COLUMN variance_pct TYPE DECIMAL(8,2);

ALTER TABLE trips ADD COLUMN IF NOT EXISTS route_history_id VARCHAR(30);
ALTER TABLE trips ADD COLUMN IF NOT EXISTS actual_arrival TIMESTAMP;
ALTER TABLE trips ADD COLUMN IF NOT EXISTS final_odometer INTEGER;
ALTER TABLE trips ADD COLUMN IF NOT EXISTS vehicle_condition VARCHAR(40);
ALTER TABLE trips ADD COLUMN IF NOT EXISTS completion_notes TEXT;
ALTER TABLE fuel_transactions ADD COLUMN IF NOT EXISTS trip_id VARCHAR(20);
ALTER TABLE maintenance_orders ADD COLUMN IF NOT EXISTS source_trip_id VARCHAR(20);
ALTER TABLE notifications ADD COLUMN IF NOT EXISTS user_id INTEGER;

INSERT INTO permissions (code, name, module)
SELECT 'ai.navigate', 'Navigate Assigned Route', 'AI Route Optimization'
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE code = 'ai.navigate');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE p.code = 'ai.navigate' AND r.code IN ('fleet_admin','fleet_manager','dispatcher','driver')
ON CONFLICT DO NOTHING;

DELETE FROM role_permissions
WHERE role_id = (SELECT id FROM roles WHERE code = 'driver')
  AND permission_id = (SELECT id FROM permissions WHERE code = 'ai.manage');

CREATE UNIQUE INDEX IF NOT EXISTS uq_route_history_log_id ON route_history(log_id);

DO $$ BEGIN
  ALTER TABLE route_history
    ADD CONSTRAINT fk_route_history_reservation
    FOREIGN KEY (reservation_id) REFERENCES reservations(id) ON DELETE SET NULL;
EXCEPTION WHEN duplicate_object THEN NULL; END $$;

DO $$ BEGIN
  ALTER TABLE route_history
    ADD CONSTRAINT fk_route_history_trip
    FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE SET NULL;
EXCEPTION WHEN duplicate_object THEN NULL; END $$;

DO $$ BEGIN
  ALTER TABLE trips
    ADD CONSTRAINT fk_trips_route_history
    FOREIGN KEY (route_history_id) REFERENCES route_history(log_id) ON DELETE SET NULL;
EXCEPTION WHEN duplicate_object THEN NULL; END $$;

DO $$ BEGIN
  ALTER TABLE fuel_transactions
    ADD CONSTRAINT fk_fuel_trip
    FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE SET NULL;
EXCEPTION WHEN duplicate_object THEN NULL; END $$;

DO $$ BEGIN
  ALTER TABLE maintenance_orders
    ADD CONSTRAINT fk_maintenance_source_trip
    FOREIGN KEY (source_trip_id) REFERENCES trips(id) ON DELETE SET NULL;
EXCEPTION WHEN duplicate_object THEN NULL; END $$;

DO $$ BEGIN
  ALTER TABLE notifications
    ADD CONSTRAINT fk_notifications_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;
EXCEPTION WHEN duplicate_object THEN NULL; END $$;

CREATE INDEX IF NOT EXISTS idx_route_history_trip ON route_history(trip_id);
CREATE INDEX IF NOT EXISTS idx_route_history_reservation ON route_history(reservation_id);
CREATE UNIQUE INDEX IF NOT EXISTS uq_route_history_trip_navigation
  ON route_history(trip_id, navigation_key)
  WHERE trip_id IS NOT NULL AND navigation_key IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_trips_route_history ON trips(route_history_id);
CREATE INDEX IF NOT EXISTS idx_fuel_trip ON fuel_transactions(trip_id);
CREATE INDEX IF NOT EXISTS idx_notifications_user_read ON notifications(user_id, is_read);

 
 
DO $$ BEGIN
  ALTER TABLE ai_route_candidates_log
    ADD CONSTRAINT fk_candidate_route_history
    FOREIGN KEY (log_id) REFERENCES route_history(log_id) ON DELETE CASCADE;
EXCEPTION WHEN duplicate_object THEN NULL; END $$;
