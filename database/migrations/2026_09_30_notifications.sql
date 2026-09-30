BEGIN;
INSERT INTO role_permissions(role_id,permission_id) SELECT r.id,p.id FROM roles r CROSS JOIN permissions p WHERE r.code IN ('fleet_admin','dispatcher','driver','customer') AND p.code='notifications.view' ON CONFLICT DO NOTHING;
CREATE INDEX IF NOT EXISTS idx_notifications_recipient ON notifications(user_id, id DESC);
-- Broadcast records become independent inbox entries; never expose them to customers/drivers.
INSERT INTO notifications(title,body,time_label,type,category,target,is_read,created_at,user_id)
SELECT n.title,n.body,n.time_label,n.type,n.category,n.target,n.is_read,n.created_at,u.id
FROM notifications n CROSS JOIN users u JOIN roles r ON r.id=u.role_id
WHERE n.user_id IS NULL AND r.code IN ('fleet_admin','dispatcher') AND u.status='Active';
DELETE FROM notifications WHERE user_id IS NULL;

CREATE OR REPLACE FUNCTION toursphere_notify(p_title text,p_body text,p_category text,p_target text,p_customer integer DEFAULT NULL,p_driver text DEFAULT NULL,p_old_driver text DEFAULT NULL) RETURNS void LANGUAGE plpgsql AS $$
BEGIN
 INSERT INTO notifications(title,body,type,category,target,user_id)
 SELECT left(p_title,200),p_body,'info',p_category,left(CASE WHEN r.code='customer' THEN 'customer-reservations' ELSE p_target END,60),u.id
 FROM users u JOIN roles r ON r.id=u.role_id
 WHERE u.status='Active' AND (r.code IN ('fleet_admin','dispatcher') OR u.id=p_customer OR u.id IN (SELECT user_id FROM drivers WHERE id IN (p_driver,p_old_driver)));
END $$;

CREATE OR REPLACE FUNCTION toursphere_event_notification() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE n jsonb:=to_jsonb(NEW); o jsonb; customer integer; driver text; previous_driver text; target text; category text; title text; message text;
BEGIN
 IF TG_OP='UPDATE' THEN o:=to_jsonb(OLD); ELSE o:='{}'::jsonb; END IF;
 IF TG_TABLE_NAME='reservations' THEN
  IF TG_OP='UPDATE' AND n->>'status'='Cancelled' AND EXISTS(SELECT 1 FROM trips WHERE reservation_id=n->>'id') THEN RETURN NEW; END IF;
  -- Trip state is emitted by trips to avoid duplicate reservation/trip updates.
  IF TG_OP='UPDATE' AND (n->>'status') IN ('In Transit','Returning to Depot','Completed','Dispatched') THEN RETURN NEW; END IF;
  IF TG_OP='UPDATE' AND NOT EXISTS(SELECT 1 FROM unnest(ARRAY['status','departure_date','departure_time','return_date','return_time','origin','destination','total_booking_fare','fare_status']) k WHERE n->k IS DISTINCT FROM o->k) THEN RETURN NEW; END IF;
  customer:=(n->>'customer_id')::integer; driver:=n->>'assigned_driver_id'; previous_driver:=o->>'assigned_driver_id';
  category:='Reservation'; target:='reservations:'||(n->>'id');
  title:=CASE WHEN TG_OP='INSERT' THEN 'New Reservation' ELSE 'Reservation Updated' END;
  message:='Reservation '||(n->>'id')||' ('||(n->>'origin')||' to '||(n->>'destination')||'): '||(n->>'status')||'.'||CASE WHEN n->>'status'='Rejected' THEN ' '||coalesce(n->>'rejection_reason','') WHEN n->>'status'='Cancelled' THEN ' '||coalesce(n->>'cancellation_reason','') ELSE '' END;
 ELSIF TG_TABLE_NAME='trips' THEN
  IF TG_OP='UPDATE' AND NOT EXISTS(SELECT 1 FROM unnest(ARRAY['status','driver_id','vehicle_id','scheduled_departure','origin','destination','route_history_id']) k WHERE n->k IS DISTINCT FROM o->k) THEN RETURN NEW; END IF;
  SELECT customer_id INTO customer FROM reservations WHERE id=n->>'reservation_id';
  driver:=n->>'driver_id'; previous_driver:=o->>'driver_id'; category:='Trip'; target:='trip-details:'||(n->>'id');
  title:=CASE WHEN TG_OP='INSERT' OR n->'driver_id' IS DISTINCT FROM o->'driver_id' THEN 'Trip Assignment' ELSE 'Trip Updated' END;
  message:='Trip '||(n->>'id')||' ('||(n->>'origin')||' to '||(n->>'destination')||'): '||(n->>'status')||CASE WHEN n->>'status'='Cancelled' THEN ' '||coalesce(n->>'completion_notes','') ELSE '' END||'. Departure: '||coalesce(n->>'scheduled_departure','To be confirmed')||'.';
 ELSIF TG_TABLE_NAME='vehicles' THEN
  IF TG_OP='UPDATE' AND NOT EXISTS(SELECT 1 FROM unnest(ARRAY['assigned_driver_id','maintenance_status']) k WHERE n->k IS DISTINCT FROM o->k) THEN RETURN NEW; END IF;
  driver:=n->>'assigned_driver_id'; previous_driver:=o->>'assigned_driver_id'; category:='Fleet'; target:='vehicles:'||(n->>'id'); title:='Vehicle Updated'; message:='Vehicle '||(n->>'id')||': '||coalesce(n->>'maintenance_status',n->>'status','Registered')||'. Driver assignment: '||coalesce(driver,'Unassigned')||'.';
 ELSIF TG_TABLE_NAME='maintenance_orders' THEN
  IF TG_OP='UPDATE' AND NOT EXISTS(SELECT 1 FROM unnest(ARRAY['status','scheduled_date','priority','service_type']) k WHERE n->k IS DISTINCT FROM o->k) THEN RETURN NEW; END IF;
  SELECT assigned_driver_id INTO driver FROM vehicles WHERE id=n->>'vehicle_id'; category:='Maintenance'; target:='maintenance:'||(n->>'id'); title:='Maintenance Update'; message:=(n->>'id')||': '||(n->>'service_type')||' for '||(n->>'vehicle_id')||' — '||(n->>'status')||' ('||(n->>'priority')||').';
 ELSIF TG_TABLE_NAME='fuel_transactions' THEN
  IF TG_OP='UPDATE' THEN RETURN NEW; END IF;
  driver:=n->>'driver_id'; category:='Fuel'; target:='fuel-transactions:'||(n->>'id'); title:='Fuel Recorded'; message:=(n->>'id')||': '||(n->>'liters')||' liters recorded for '||(n->>'vehicle_id')||'.';
 ELSIF TG_TABLE_NAME='driver_ratings' THEN
  IF TG_OP='UPDATE' THEN RETURN NEW; END IF;
  driver:=n->>'driver_id'; category:='Trip'; target:='trip-details:'||(n->>'trip_id'); title:='Driver Rating Received'; message:='Trip '||(n->>'trip_id')||' received a '||(n->>'stars')||'/5 rating.';
 ELSIF TG_TABLE_NAME='operating_schedules' THEN
  IF TG_OP='UPDATE' AND NOT EXISTS(SELECT 1 FROM unnest(ARRAY['name','departure_time','status']) k WHERE n->k IS DISTINCT FROM o->k) THEN RETURN NEW; END IF;
  category:='Schedule'; target:='settings'; title:='Operating Schedule Updated'; message:=coalesce(n->>'name','Schedule')||': '||coalesce(n->>'departure_time','')||' ? '||coalesce(n->>'status','')||'.';
 ELSIF TG_TABLE_NAME='vehicle_documents' THEN
  IF TG_OP='UPDATE' AND NOT EXISTS(SELECT 1 FROM unnest(ARRAY['review_status','extraction_status','expiry_date']) k WHERE n->k IS DISTINCT FROM o->k) THEN RETURN NEW; END IF;
  SELECT assigned_driver_id INTO driver FROM vehicles WHERE id=n->>'vehicle_id';
  category:='Fleet'; target:='vehicles:'||(n->>'vehicle_id'); title:='Vehicle Document Updated'; message:='Vehicle '||(n->>'vehicle_id')||': '||coalesce(n->>'document_type','document')||' recorded or reviewed.';
 ELSIF TG_TABLE_NAME='route_history' THEN
  IF TG_OP='UPDATE' AND NOT EXISTS(SELECT 1 FROM unnest(ARRAY['actual_revenue','revenue_status']) k WHERE n->k IS DISTINCT FROM o->k) THEN RETURN NEW; END IF;
  IF TG_OP='INSERT' THEN RETURN NEW; END IF;
  category:='Revenue'; target:='route-history'; title:='Trip Revenue Updated'; message:='Revenue updated for route '||(n->>'id')||'.';
 ELSE RETURN NEW;
 END IF;
 PERFORM toursphere_notify(title,message,category,target,customer,driver,previous_driver);
 RETURN NEW;
END $$;
DO $$ DECLARE t text; BEGIN
 FOREACH t IN ARRAY ARRAY['reservations','trips','vehicles','maintenance_orders','fuel_transactions','driver_ratings','operating_schedules','vehicle_documents','route_history'] LOOP
  IF to_regclass(t) IS NULL THEN CONTINUE; END IF;
  EXECUTE format('DROP TRIGGER IF EXISTS toursphere_notification ON %I',t);
  EXECUTE format('CREATE TRIGGER toursphere_notification AFTER INSERT OR UPDATE ON %I FOR EACH ROW EXECUTE FUNCTION toursphere_event_notification()',t);
 END LOOP;
END $$;
COMMIT;
