BEGIN;
UPDATE roles SET name='Logistics 1 Requester',description='Submit and track own Logistics 1 cargo requests within TourSphere (temporary mock integration)' WHERE code='customer';
INSERT INTO permissions(code,name,module) VALUES ('logistics.requests.own','Submit and view own cargo requests','Reservations') ON CONFLICT(code) DO NOTHING;
INSERT INTO role_permissions(role_id,permission_id) SELECT r.id,p.id FROM roles r CROSS JOIN permissions p WHERE r.code='customer' AND p.code='logistics.requests.own' ON CONFLICT DO NOTHING;
DELETE FROM role_permissions WHERE role_id=(SELECT id FROM roles WHERE code='customer') AND permission_id IN (SELECT id FROM permissions WHERE code IN ('dispatch.manage','dispatch.view','vehicles.assign','vehicles.manage'));
ALTER TABLE reservations
 ADD COLUMN request_kind varchar(20) NOT NULL DEFAULT 'Passenger',
 ADD COLUMN request_purpose varchar(30),
 ADD COLUMN cargo_type varchar(120),
 ADD COLUMN cargo_weight_kg numeric(12,3),
 ADD COLUMN cargo_volume_m3 numeric(12,3),
 ADD COLUMN cargo_quantity integer,
 ADD COLUMN special_handling text,
 ADD COLUMN source_system varchar(40) NOT NULL DEFAULT 'TourSphere',
 ADD COLUMN integration_mode varchar(20) NOT NULL DEFAULT 'Local',
 ADD COLUMN external_request_id varchar(128),
 ADD COLUMN source_payload_version integer NOT NULL DEFAULT 1,
 ADD COLUMN source_received_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP;
ALTER TABLE reservations ADD CONSTRAINT reservations_request_kind_valid CHECK(request_kind IN ('Passenger','Cargo')),
 ADD CONSTRAINT reservations_integration_mode_valid CHECK(integration_mode IN ('Local','Mock','API')),
 ADD CONSTRAINT reservations_cargo_details_valid CHECK(request_kind<>'Cargo' OR (customer_id IS NOT NULL AND passenger_count=0 AND cargo_type IS NOT NULL AND btrim(cargo_type)<>'' AND cargo_weight_kg IS NOT NULL AND cargo_weight_kg>0 AND cargo_volume_m3 IS NOT NULL AND cargo_volume_m3>0 AND cargo_quantity IS NOT NULL AND cargo_quantity>0 AND request_purpose IS NOT NULL AND request_purpose IN ('Cargo Transport','Vehicle Request') AND departure_schedule_instance_id IS NULL AND return_schedule_instance_id IS NULL));
CREATE UNIQUE INDEX reservations_external_request_unique ON reservations(source_system,integration_mode,external_request_id) WHERE external_request_id IS NOT NULL;
CREATE INDEX reservations_request_source ON reservations(source_system,integration_mode,status);
COMMIT;
