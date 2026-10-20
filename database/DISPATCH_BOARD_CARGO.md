# Cargo Dispatch Board

The Dispatch Board reuses existing cargo reservations, assignment validation, funding preparation and dispatch actions. No schema migration or data reset is required.

* **Assignment / Preparation**: assigned requests that do not currently pass all funding/readiness checks.
* **Ready for Dispatch**: derived from the existing `funding_readiness` checks, including approval, saved vehicle/driver pairing, payload, cargo volume, availability, maintenance, document validity, driver qualifications, schedule conflicts and confirmed trip funding. The dispatch action rechecks requirements when saving.
* **Dispatched**: the existing dispatch action saves this state without recording departure or starting navigation. A separate Start In Transit action uses the existing trip-status handler.
* **In Transit**: departure is recorded only after an explicit trip start. The board's Mark Delivered action uses the existing completion handler, validates delivery notes, final odometer and vehicle condition, and preserves trip history, operational counters and resource release.
* **Delivered**: an existing cargo `Completed` reservation/trip that has not been explicitly verified. This display distinction preserves compatibility with existing reports, driver portal and archive status rules.
* **Verified Completed**: Admin/Dispatcher explicitly verifies a delivered, non-archived trip with a recorded arrival. The existing `trips.current_step` stores `Verified Completed`, and `reservation_events` stores the actor, time and notes. A trip timeline event retains the verification in history. Duplicate submissions do not add duplicate events. No delivery is automatically verified.

Cancelled, rejected, incomplete and legacy passenger history remains visible using existing statuses. Delivered cargo is kept out of the board's closed/archive lane until verification. Existing cancellation and recall actions remain available from Reservations List.

Tests: `tests/dispatch_board_test.php` (stages/verification), with `transit`, `deliver` and `invalid-transit` arguments for actual trip-status action checks. The existing logistics assignment/dispatch tests verify the shared cargo assignment and funding flow. All test records use connection-local temporary PostgreSQL tables.
