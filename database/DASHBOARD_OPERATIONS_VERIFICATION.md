# Dashboard calendar and delivery trend verification

Verified on 20 October 2026 against the local database and an isolated local PHP server.

The only edit to the original dashboard is an include immediately after the existing KPI row. Existing dashboard queries, cards, tables, charts, navigation and relative section order are unchanged. New files provide a read-only endpoint, scoped presentation and a per-user setting. No schema migration is needed.

## Functional results

| # | Requested test | Result and evidence |
|---|---|---|
| 1 | Calendar Month / Week / Day | PASS — browser switched between 35-cell October, 7-day week and single-day views. |
| 2 | Date navigation | PASS — previous, next and Today controls exercised. |
| 3 | Event accuracy | PASS — returned event routes matched live reservations; reservation IDs were unique. Live event details opened in the browser. |
| 4 | Read-only calendar | PASS — event dialog contains no editing fields or operational links; new endpoint only writes the authenticated user's visibility setting. |
| 5 | Show Calendar toggle | PASS — both states exercised and preference save responses verified. |
| 6 | Layout reflow | PASS — hiding removes calendar content and moves the graph upward by over 300 pixels. |
| 7 | Restore calendar | PASS — restoring renders the calendar; existing KPI text and active-trips table HTML remain identical. |
| 8 | Preference persistence | PASS — refresh, module navigation and a separate authenticated session retain the preference. NOT TESTED — password/2FA login and logout flow. |
| 9 | Day / Month / Year analytics | PASS — browser verifies 24 hourly, 31 daily and 12 monthly buckets. |
| 10 | Analytics accuracy | PASS — live completed totals match an independent SQL count; on-time is a subset of completed; pure fixtures verify early/late arrival, finalized assessment precedence, missing evidence and unverified completion. Leap-year February returns 29 buckets. |
| 11 | Missing / empty / failed data | PASS — empty period displays zero counts and a clear message; missing punctuality evidence stays unknown; simulated request failure displays an error rather than stale chart data. |
| 12 | Access restrictions | PASS — admin, dispatcher and fleet manager requests succeed; customer and driver requests return 403; unauthenticated requests return 401; invalid preference tokens return 403 for every tested role. |
| 13 | Desktop / mobile | PASS — no document overflow at 320, 390, 768, 1024 and 1440 pixels; screenshots captured and inspected at desktop/mobile sizes. |
| 14 | Existing KPIs | PASS — four cards render and their contents remain unchanged after toggle/navigation. NOT TESTED — physical touch swiping; existing KPI code was not edited. |
| 15 | Active Trips & Dispatches | PASS — original six columns and empty state render unchanged. NOT TESTED — populated-row Track action, because no active trips were present. |
| 16 | Vehicle Availability | PASS — original Chart.js instance renders; original source/calculation unchanged. |
| 17 | Vehicle Operations | PASS — original Chart.js instance renders; original source/calculation unchanged. |
| 18 | Completed Trips | PASS — original Chart.js instance renders; original seven-day query unchanged. |
| 19 | Maintenance Alerts | PASS — live alert section, severity and record render; original markup unchanged. |
| 20 | Navigation / actions | PASS — Export Summary, New Reservation and View Full Board destinations respond successfully; Trip Schedule navigation and return exercised. NOT TESTED — every sidebar destination or operational mutation. |

No remaining test failures. No browser JavaScript errors. Before/after hashes of all reservations, trips, vehicles, drivers and maintenance orders were identical.

## Counting and visibility rules

- Calendar follows the Trip Schedule module's reservation visibility/status rules and uses its latest linked trip. Details include reservation/external cargo reference, vehicle and driver. Calendar requests are period-bounded, with an explicit 2,000-event cap and a notice to narrow the view.
- Analytics uses cargo reservations only, one latest linked trip per reservation. Development tests/simulations are excluded using the existing performance module's rule.
- Scheduled counts approved, non-cancelled pickups on the reservation's pickup date, including subsequently completed deliveries.
- Completed requires the existing verified-completion conditions and uses the recorded completion date.
- On-time uses the finalized warehouse outcome when available; otherwise it reuses `performance_timing()` and the promised delivery time. Missing evidence is never assumed to be on time.
- Cancelled counts cancelled reservations on their recorded cancellation-event date. Missing cancellation dates are excluded.
- Calendar preferences use the existing settings store under `user.<authenticated ID>.dashboard_calendar`. Client-supplied user IDs are not accepted.

## Checks

- `php tests/dashboard_operations_test.php`
- `php tests/dashboard_operations_test.php --sessions`
- Local test server with session storage confined to `tmp/dashboard-sessions`
- `node tests/dashboard_operations_browser_test.js`
- PHP syntax, JavaScript syntax and dashboard diff whitespace checks

Browser tests use temporary authenticated sessions for existing local accounts, without creating operational records. Password/2FA login is outside that harness. No commit, push or deployment was performed.
