-- vehicles.assigned_driver_id is the permanent/default assignment.
-- reservations/trips retain the vehicle and driver actually used for each trip.
CREATE UNIQUE INDEX IF NOT EXISTS uq_active_trip_driver
  ON trips(driver_id)
  WHERE driver_id IS NOT NULL AND status IN ('In Transit','Returning to Depot');

CREATE UNIQUE INDEX IF NOT EXISTS uq_active_trip_vehicle
  ON trips(vehicle_id)
  WHERE vehicle_id IS NOT NULL AND status IN ('In Transit','Returning to Depot');
