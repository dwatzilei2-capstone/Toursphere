# Fuel Management operations — Logistics 2

Implemented in the existing Fuel Overview and Transactions Log. No sidebar submodule was added or renamed. No tank levels, percentages, sensors, or real-time monitoring were implemented. The old automatic `current_fuel=100` write and purchased-fuel consumption checkbox have been removed from the recording workflow; existing vehicle values/specifications are preserved.

## Current page structure

- Fuel Overview preserves the original summary cards, charts, monitoring and comparisons. No allocation table is added there.
- Transactions Log presents one compact Trip Fuel Records table with Trip/Vehicle, Fuel Allocation, Actual Fuel Cost, separate authorization and transaction statuses, and role-based actions.
- A details modal shows authorizations and each individual refueling transaction, receipt and verification controls. Multiple refills retain their original IDs and amounts; verified transactions alone contribute to actual cost. Standalone historical/depot records remain accessible.
- One paginated presentation loader fetches each record set once and groups it without merging database tables. Driver history is scoped to their own records. Submission options match the current vehicle and Driver assignment.
- Assessment, authorization adjustments/cancellation, shortages, receipt submission/correction, funding and verification reuse existing endpoints. Actions return to Transactions Log.
- The redundant History action is removed. Existing Audit Log retains submissions, confirmations, corrections, returns, rejections and verifications. No records or audit data are deleted.

## Workflow and permissions

1. Reservation Trip Funding includes an explicit fuel allocation within the overall trip budget. Remaining funds can cover other authorized trip expenses. Finance remains the existing clearly labeled mock adapter, not a live integration.
2. Dispatcher records an assessment with reasoning and Driver instructions. Outcomes: Authorized, Not Required, or Assessment Needed. Refueling is not mandatory and no new refueling prerequisite has been added to dispatch.
3. An authorization reserves a maximum amount from the approved allocation, linked to its cargo trip, vehicle, and Driver. Only one open authorization per trip is permitted.
4. Assigned Driver submits actual date/time, compatible fuel type, liters, unit price, receipt total, station, receipt number, odometer, and a receipt. Dispatcher/Admin may submit on behalf of the assigned Driver; neither bypasses subsequent Dispatcher confirmation. Manager and Staff do not gain operational approval duties.
5. Dispatcher reviews the Submitted record and confirms the official transaction as Pending Verification, or returns it for correction.
6. Admin verifies, returns, or rejects Pending Verification. Return/rejection requires a reason. Returned records reuse the original transaction and authorization, with optimistic revisions and audit history.
7. Verification fulfills the authorization, releases the unused reserved amount, and includes the actual expense exactly once in TCAO's canonical fuel source. No additional ledger posting is made. Replays/duplicate approvals are rejected.
8. Driver may report an unexpected shortage on an ongoing/dispatched trip. Dispatcher resolves reports with instructions/reason. Additional money uses Trip Funding's cumulative adjustment, not an independent funding process.

Roles and permission database records were not changed. Driver fuel data and receipt access are limited to their own assigned profile. Staff retains fuel viewing; Manager monitors allocations, verified costs and recommendations. Authorization requires Dispatcher, confirmation requires Dispatcher, final expense verification requires Admin.

## Funding and estimation

- Fuel allocation, purchased liters, and measured consumption are distinct.
- Existing Trip Funding supports adjustments during Assigned/Dispatched/In Transit states. An adjustment replaces the cumulative trip budget; it must cover existing verified fuel expenses plus outstanding authorizations.
- Pending adjustments do not authorize spending unapproved money. Budget operations share advisory locks and active-request uniqueness constraints.
- Estimation uses saved delivery distance and an independently measured historical efficiency reference when available. Historical consumption requires an Admin-verified linked fuel transaction and independently verified measured distance; planned route distance is not measured efficiency.
- A documented, user-supplied efficiency planning reference may be entered in the existing funding form. Its value and supporting source are saved in the existing estimate JSON snapshot. It does not alter vehicle specifications or claim a measured result.
- Generic vehicle type values marked `(est.)` are not accepted as reliable records. Missing route distance, fuel price, or credible efficiency produces an explicit missing-data message; none is fabricated.
- Existing system fuel-price settings and source/effective-date history are retained.

## Storage and migration

Applied `2026_10_13_fuel_operations.sql` after full PostgreSQL custom-format backup and `pg_restore --list` verification:

`storage/private/fuel-operations-backup-20261013-101502/before.dump`

Changes:

- `trip_funding_requests`: requested/approved fuel allocations, bounded by overall requested/approved amounts. Historical fuel-only funding retains its recorded allocation; no new funding is approved by migration.
- `fuel_authorizations`: trip/vehicle/Driver/funding links, assessment/instructions, quantity, monetary cap, lifecycle and actor.
- `fuel_shortage_reports`: owned trip reports, resolution status/reason/actor.
- Existing `fuel_transactions`: authorization, submission/confirmation/verification actors and timestamps, verification status, correction reason/revision, SHA-256 receipt hash. Existing records become Legacy Unverified; they are not deleted or retrospectively approved.
- Fuel quantity precision is widened to 3 decimals, unit price to 4 decimals, preserving historical values and valid receipt arithmetic.
- Unique open authorization, authorization-to-transaction, active receipt hash, and station/receipt constraints prevent duplicates. Foreign keys, verification completeness constraints, and audit triggers preserve relationships.
- Receipt uploads reuse private JPG/PNG/PDF storage, MIME/extension/image/size validation, 5 MB limit, ownership checks, and protected streaming. Receipt hashes are checked before confirmation, verification and download. Old receipt versions remain stored when corrected; audit history retains metadata.

No tables were reset; no existing application records, accounts, roles, settings, vehicles, documents or specifications were deleted. No sample operational data was inserted by this development.

## Monitoring and costs

- Fuel Overview monthly totals/charts and trip comparisons use Admin-verified transactions only.
- TCAO shared sources, recent-trip cost calculations, trip actual-cost calculations and relevant financial reports use the same verified-only rule.
- Historical ledger snapshots are preserved; they are not incremented again on verification. Current TCAO reads verified source transactions, avoiding double posting.
- Trip Performance's new fuel source candidates require verified fuel transactions. Existing saved evaluations/evidence remain preserved.
- Optimization flags are supported by recorded values: receipt duplication, decreasing chronological odometer readings, and unit price more than 20% above its saved reference. These are review flags, not accusations or automatic efficiency conclusions.
- Efficiency displays Not available without verified consumption and independently verified distance. Fuel purchases never become consumed fuel automatically.

## Files

Services/integration:
`includes/fuel_operations.php`, `includes/trip_funding.php`, `includes/vehicle_efficiency.php`, `includes/driver_fuel_context.php`, `includes/cost_analysis.php`, `includes/report_queries.php`, `includes/trip_performance.php`.

Existing pages/components:
`modules/fuel-management/fuel-overview.php`, `modules/fuel-management/fuel-transactions.php`, `modules/cost-analysis/cost-overview.php`, `includes/fuel-operations-ui.php`, `includes/fuel-monitoring-ui.php`, `includes/trip-fuel-comparison-ui.php`, `includes/audit-ui.php` (fuel activity grouping only).

Actions/presentation:
`actions/fuel.php`, `actions/fuel-receipt.php`, `actions/trip-funding.php`, `js/fuel-operations.js`, `js/trip-funding.js`, `css/fuel-management.css`.

Migration:
`database/migrations/2026_10_13_fuel_operations.sql`, `scripts/migrate-fuel-operations.php`.

Tests:
`tests/fuel_operations_test.php`, `tests/fuel_operations_http_fixture.php`, `tests/fuel_operations_http_test.php`, `tests/fuel_operations_pages_test.php`, `tests/fuel_operations_audit_test.php`. Legacy fuel test entry points now run the authorization/receipt-required HTTP suite instead of the obsolete receipt-optional workflow. Trip Performance's isolated fuel fixture now explicitly seeds verified status to match the new candidate rule.

## Validation

- 44 isolated operational checks: role restrictions, source-documented estimation, approved allocation, duplicate authorization/submission, receipt requirement, arithmetic/fuel compatibility, odometers, correction, verification, budget reservation/release, active-trip funding adjustment, snapshot persistence, TCAO single counting, absence of tank writes, and a second-connection budget lock.
- 23 real HTTP checks: multipart PNG/PDF uploads; missing, disguised and oversized receipt rejection; CSRF; forged ownership fields; incompatible fuel; Dispatcher confirmation; Admin verification/return/rejection and required reasons; Driver verification denial; receipt byte streaming, ownership, missing files and tamper protection.
- Existing Trip Funding suite: 35 checks including real five-second mock approval, duplicate requests, funding readiness, revisions and saved snapshots.
- Role page rendering checked for Dispatcher, Admin, Driver, Manager and Staff; no unintended review controls. Driver Overview rendering also checked.
- Regression checks: Dispatch Board 28, Trip Schedule 29, Trip Performance Admin modal 31, Dispatcher evaluation render/workflow 57.
- Browser: Driver authorization selection pre-fills read-only compatible fuel type and requires a receipt; Dispatcher assessment shows the stored route/efficiency estimate; Manager receives monitoring without operational controls. Desktop and 390px mobile layout checked. Current production-style tables scroll inside their containers without horizontal page overflow (page width 385px, viewport 390px).
- PHP syntax and JavaScript checks passed on changed implementation files.
- The old `trip_funding_http_test.php` harness could not run because it attempts to create sessions in restricted `C:/xampp/tmp`. Its session setup is independent of application behavior. New loopback HTTP fixtures use temporary tables and directly test current fuel action endpoints; the existing funding service suite passes.

Current data after testing: the original single development fuel record remains Legacy Unverified; zero new persistent fuel authorizations, shortage reports or funding requests. Its existing Trip Performance evaluation is retained. Actual operation still requires real route/reference/price inputs and approved funding. No live Finance or sensor integration is claimed. No commit, push or deployment was performed.

Unified interface validation: role-based main/modal rendering, multiple distinct refills, verified-only totals, standalone historical records and Driver isolation passed with temporary tables. Existing Audit Log integration, 23 HTTP workflow checks and 35 funding checks passed. Desktop and 390px mobile table/modal rendering were checked without horizontal page overflow. Fuel Overview retains its original content. No schema change or persistent test records were needed for this correction. Presentation components: includes/fuel-unified-data.php, includes/fuel-unified-details.php and includes/fuel-unified-transactions.php.

## Transactions Log visual refinement — 2026-10-14

Scope: existing Transactions Log and Fuel Details only. Fuel Overview, service/action business rules, database schema and stored records are unchanged by this refinement.

- Eight-column unified table: Log/Trip, Vehicle/Driver, Fuel Allocation, Refueling Date, Fuel Type/Quantity, Price/Total, Status, Actions. One trip row keeps individual records intact; for multiple refills, date/quantity/price explicitly show the latest refill. Verified aggregate remains in Details and is the only expense counted in cost analysis.
- Search, authorization/verification status, assigned vehicle, actual refueling date range and oldest/newest trip ordering run before pagination. Filters persist in pagination and Details links. Filtered trips retain all their individual refills in Details. Date filters match actual refueling dates, not planned delivery schedules.
- Details uses compact definition grids and expandable authorization/refill records, not nested transaction tables. It displays recorded vehicle/driver separately from current assignment, authenticated receipt previews/viewing, submitter, confirmation/verification actors and timestamps, and review reasons. No submission timestamp is invented where the existing schema has none.
- Existing role-based assessment, funding, authorization amendments/cancellation, receipt correction and exact-transaction review forms are retained. Keyboard focus is contained in the dialog and restored to its opening link; Escape, close buttons and backdrop close restore page scrolling. Nested receipt and funding dialogs retain their existing behavior.
- Below 768px the same table markup becomes two-column compact records with every field and action available. Tablet tables scroll inside their own container. Modal body scrolls independently with fixed header/footer. Theme, sidebar, navigation and Poppins remain intact.

Changed files: modules/fuel-management/fuel-transactions.php; includes/fuel-unified-data.php; includes/fuel-unified-details.php; includes/fuel-unified-transactions.php; new includes/fuel-presentation.php; css/fuel-management.css; js/fuel-operations.js; tests/fuel_operations_pages_test.php; this document.

Validation: role rendering and permissions, multiple refills, verified-only aggregation, search/status/vehicle/date filtering, filtering before pagination, page bounds and Driver isolation tested using temporary PostgreSQL tables. Audit integration and 23 actual HTTP receipt/submission/review checks passed. Edge visual checks covered 320, 375, 430, 768, 1024, 1440 and 1920px, plus 844x390 landscape; no horizontal page or modal-body overflow. Keyboard Tab/Shift+Tab containment, Escape, focus return, search, status empty state and Clear were checked in-browser. Native browser zoom changes were not supported by the browser-control surface; Firefox/Safari and separate Chrome runs were not available and are not claimed as tested.

Screenshots: tmp/fuel-redesign-table.png, tmp/fuel-redesign-details.png, tmp/fuel-redesign-mobile-table.png, tmp/fuel-redesign-mobile-details.png. No records were reset, inserted into public tables or deleted; no migration, commit, push or deployment.

### Toolbar and status refinement

Search (600ms debounce), Status and Vehicle automatically apply through the existing GET filters. Date range and sorting are grouped in a keyboard-accessible native expandable Filters control; Apply Filters submits advanced changes. Clear Filters appears only when non-default filters are active. Search retains keyboard focus/cursor after refresh and respects composition input. With JavaScript disabled, a submit fallback remains available.

The shared badge renderer now maps verified/approved/completed states to green; pending, unverified and returned-for-correction states to amber; rejected/failed/critical to red; inactive/cancelled/unallocated/unassessed to gray; and active authorization/operations to blue. Text labels and grouped counts remain unchanged. Allocation status uses the same renderer as authorization and transaction status in the table/modal. All five foreground/background pairs meet WCAG AA normal-text contrast.

Modified for this adjustment: modules/fuel-management/fuel-transactions.php, includes/fuel-presentation.php, js/fuel-operations.js, css/fuel-management.css, tests/fuel_operations_pages_test.php and this document. No business logic, database changes or Fuel Overview edits.
