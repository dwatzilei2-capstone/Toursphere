BEGIN;
CREATE OR REPLACE FUNCTION toursphere_notify(p_title text,p_body text,p_category text,p_target text,p_customer integer DEFAULT NULL,p_driver text DEFAULT NULL,p_old_driver text DEFAULT NULL) RETURNS void LANGUAGE plpgsql AS $$
DECLARE assignment_body text; assigned_driver_user integer;
BEGIN
 SELECT user_id INTO assigned_driver_user FROM drivers WHERE id=p_driver;
 IF p_category='Trip' AND p_title='Trip Assignment' THEN
   SELECT 'You have a new assigned trip: '||t.id||'. Route: '||t.origin||' to '||t.destination
     ||'. Departure: '||coalesce(to_char(t.scheduled_departure,'Mon DD, YYYY HH24:MI'),'To be confirmed')
     ||'. Vehicle: '||coalesce(v.id||' ('||v.plate_number||')','To be confirmed')
     ||'. Open My Trips to review your assignment.' INTO assignment_body
   FROM trips t LEFT JOIN vehicles v ON v.id=t.vehicle_id WHERE t.id=split_part(p_target,':',2);
 END IF;
 INSERT INTO notifications(title,body,type,category,target,user_id)
 SELECT left(CASE WHEN r.code='driver' AND p_category='Trip' AND p_title='Trip Assignment'
      THEN CASE WHEN u.id=assigned_driver_user THEN 'New Trip Assigned to You' ELSE 'Trip Assignment Removed' END ELSE p_title END,200),
   CASE WHEN r.code='driver' AND p_category='Trip' AND p_title='Trip Assignment'
      THEN CASE WHEN u.id=assigned_driver_user THEN coalesce(assignment_body,'You have a new assigned trip. '||p_body)
           ELSE 'Your previous assignment for '||split_part(p_target,':',2)||' has been removed or reassigned. Check My Trips for your current assignments.' END
      ELSE p_body END,
   'info',p_category,left(CASE WHEN r.code='customer' THEN 'customer-reservations' ELSE p_target END,60),u.id
 FROM users u JOIN roles r ON r.id=u.role_id
 WHERE u.status='Active' AND (r.code IN ('fleet_admin','dispatcher') OR u.id=p_customer OR u.id IN (SELECT user_id FROM drivers WHERE id IN (p_driver,p_old_driver)));
END $$;
COMMIT;
