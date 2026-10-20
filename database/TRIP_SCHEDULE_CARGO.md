# Cargo Trip Schedule

No schema migration or data cleanup is required. The calendar reads existing reservations and the latest linked trip, including approved requests without an assigned trip. Completed cargo deliveries retain Delivered / Verified Completed stages from Dispatch Board. Existing weekly navigation and legacy schedule rendering remain.

For cargo, reservations.departure_date/time represent scheduled pickup; reservations.return_date/time represent the estimated delivery end of the resource booking window. An absent estimate displays Not set; it is never fabricated from actual arrival. The estimate is required when rescheduling. Trips.scheduled_departure is updated in place; no trip or scheduled_departures record is created. Reservations and Dispatch Board read these same fields automatically.

Only Admin/Dispatcher with dispatch.manage can submit a CSRF-protected reschedule. The backend requires an approved cargo request awaiting dispatch, a future pickup, a later delivery estimate and a nonempty reason. It uses the same allocation lock, buffer/conflict rules, vehicle eligibility and driver qualification checks as existing dispatch assignment. Dispatched, started and closed trips cannot be rescheduled here. Updates are transactional; reservation_events records the old/new window, actor and reason, and existing trip history records the change. Existing funding fingerprints include departure and return fields, so a changed window requires funding review before dispatch.

Validation: `php tests/trip_schedule_test.php` uses connection-local temporary tables, leaving operational data and sequences unchanged. Existing dispatch-board and logistics assignment tests cover the reused workflow.
