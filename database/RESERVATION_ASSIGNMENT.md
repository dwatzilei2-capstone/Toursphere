# Reservation vehicle and driver assignment

The existing reservation and dispatch module is retained. No schema changes or historical allowance deletion are required.

## Audited relationships and rules

- `vehicles.assigned_driver_id` is the authoritative designated vehicle-driver pairing. `reservations.assigned_vehicle_id / assigned_driver_id`, `trips.vehicle_id / driver_id`, and shared `scheduled_departures` hold operational assignments.
- Driver Class 2/3 capability comes from the existing Fleet Vehicle Assignment license rule. Class 3 covers Class 2 vehicles; buses/coaches and vehicles above 30 seats require Class 3. Both fleet pairing and reservation assignment use the same helper.
- Existing vehicle document evaluation supplies registration, insurance, and LTFRB restrictions, including verification and expiry. It is evaluated for current operation and target departure.
- Maintenance orders, lifecycle status, driver status, license expiry, reservation intervals, active trips, and the existing dispatch buffer determine eligibility. Shared departures exclude their own cohort from duplicate schedule checks and use aggregate reserved demand.
- `dispatch.manage` remains required on the options endpoint and final action. Pending approval and legacy Pending reservations cannot enter assignment. Assignment form mutations also require a session CSRF token.
- Dispatch remains a separate action. Existing saved-resource locking, shared departure synchronization, notifications, trip monitoring, navigation authorization, and status transitions are preserved. Completion does not remove designated pairings.

## Revised workflow

The modal loads a fresh server-calculated snapshot. Eligible designated pairings rank first, followed by vehicles with compatible unassigned drivers, then controlled reassignment candidates. Within each group, smaller adequate capacity ranks first, with vehicle ID as a stable tie-breaker. Archived vehicles stay out of the active selector but their existing designations are considered when determining whether reassignment is required.

The recommendation remains visible above alternatives and disabled vehicles. Selecting a vehicle resolves its designated driver read-only. Only a vehicle without a driver offers compatible available candidates. Reassignment requires a separate confirmation dialog naming the previous vehicle. Changing departure clears manual driver/reassignment selection and refreshes the snapshot. Shared departure times remain controlled by the reservation schedule workflow.

Final confirmation rechecks authoritative data under a transaction, reservation/vehicle/driver row locks, and the shared `fleet-designated-assignment` advisory lock. Fleet pair/unpair actions use the same lock and cannot break outstanding trips. Reassignment clears the previous designation before saving the new pairing and reservation/trip resources. Any failure rolls everything back. One existing audit-log entry records selection, actual recommendation, inherited/new designation, previous pairing, operator role, and departure.

Fuel Allowance / Budget and the independent driver dropdown are removed. Historical fare and driver allowance columns remain intact; no financial default is generated.

## Validation

- `php tests/reservation_assignment_test.php`: 34 isolated database assertions, including capabilities, restrictions, ranking, schedule refresh, pairing, rollback, and two-connection locking.
- `php tests/reservation_assignment_action_test.php`: 12 actual action integration cases using temporary tables, including consistent reservation/trip/designation persistence, separate dispatch, rejected mismatches, concurrent-resource conflict, and failure after designation update.
- `node tests/reservation_assignment_browser_test.js`: 81 browser assertions including the real options endpoint, authorized roles, no independent dropdown, controlled reassignment, departure refresh, large lists, long text, and 12 viewport sizes (320px to 1920px, portrait and landscape). Create temporary test sessions with `php tests/assignment_http_sessions.php` first and remove them afterward using `--clean`.
- Existing dispatch-modal, driver-dispatch, assignment-picker, and responsive-form suites: 65 checks.
- PHP and JavaScript syntax checks pass. Desktop and phone screenshots were visually inspected.

Database fixtures are connection-local temporary tables. Browser fixture responses are intercepted only in the test context. No live reservations, trips, driver designations, or allowance history are modified by these tests.
