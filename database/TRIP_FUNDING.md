# Trip Funding

The migration `2026_10_05_trip_funding.sql` adds funding requests, frozen estimates, fuel price history, configurable funding methods, and fuel transaction snapshots. Apply with `C:\xampp\php\php.exe scripts/migrate-trip-funding.php` (already applied to this installation). No sample reservations, fuel prices, estimates, or funding requests are inserted.

## Preparation and request

Approve the reservation and save its vehicle/driver assignment using the existing workflow. Use the existing AI Route Planner to generate and save route distance for funding. An existing recorded trip/reservation distance can also be used. The vehicle must have a recorded fuel type and valid fuel efficiency. Admin configures the current price for that fuel type in Settings → Fuel Prices; no initial rate is supplied.

Admin or Dispatcher opens Trip Funding, selects an enabled funding method, enters the requested amount, and sends the request. The server stores the estimate and a pending request, waits until the database five-second deadline, then records the automatic mock response. The interface labels this **Mock Finance — Test Mode**. There is no Finance dashboard or Fleet approval button. Manager can view but cannot send or revise requests. Drivers see only the method and confirmation status for their assigned trip.

The backend rejects dispatch without matching confirmed funding and retains existing vehicle, driver, document, and schedule checks. Changes to a funded preparation require revision and a new request before dispatch. Historical estimates survive later price updates and trip progress. Duplicate requests reuse the active request; a unique index and row locks prevent duplicate active records.

## Estimates and actual costs

Estimated liters = saved distance / recorded vehicle km/L. Estimated fuel cost = estimated liters × configured price for the actual fuel type. Toll and parking are explicitly excluded when no reliable estimate exists. The requested/approved funding is a limit, not an expense.

Fuel logs use the vehicle’s configured price as an editable default. The actual pump price and computed total are saved together with the default-price snapshot and funding reference. Editing a transaction price never updates the global default. Driver vehicle/trip/type are derived on the server. Missing actual expenses display **Not Recorded**. Refueled liters and verified trip fuel consumption remain separate.

## Configuration and recovery

Enabled funding methods are stored in `trip_funding_methods`. Price changes append to `fuel_price_history`; historical estimate and transaction snapshots retain their prior price. Configure `funding.finance_mode` through the existing `system_settings` / `save_fleet_setting` mechanism. `mock` enables automatic approval; a different mode disables mock responses pending a real Finance integration. Do not enable a real mode without a response connector.

If the initiating PHP worker is interrupted after committing a request, run `C:\xampp\php\php.exe scripts/process-trip-funding.php` to process eligible due mock requests. Requests are never approved before their deadline; actual response time is stored truthfully. Configure the recovery command with the deployment's scheduler if needed. Logs, notifications, and requests retain the MOCK_FINANCE source and requester identity; the mock system response has no fabricated human approver.

## Validation

`tests/trip_funding_test.php` checks role rules, actual formulas, missing configuration, duplicates, pending dispatch rejection, database approval timing, readiness, revisions, and snapshots. `tests/trip_funding_fuel_action_test.php` exercises the real fuel action with isolated temporary tables. `tests/trip_funding_http_test.php` verifies endpoint permissions and existing page integration; `--browser` creates temporary login sessions for `tests/trip_funding_browser_test.js`, and `--clean` removes them. Browser response interception is test-only; the production interface reads database records. Tests never seed operational business records.
