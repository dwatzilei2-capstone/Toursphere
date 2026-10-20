# Evidence-based cargo Trip Performance

## Records and modal interface (2026-10-13)

The main page now shows Trip Performance Records (Trip ID, Driver, Delivery Status, Evaluation Status, contextual action) instead of the large evaluator and cargo selector. Existing analytics are retained. A contextual link selects an existing trip and opens the same evidence/evaluation renderer inside TourSphere's responsive modal; no new endpoint or workflow is introduced. Post-action redirects reopen that same modal. Closing by button, backdrop or Escape restores the records view; keyboard focus remains inside the open dialog.

Only Dispatcher sees Evaluate for a completed cargo trip whose reservation is Completed, trip stage is Verified Completed, verification event exists, driver/vehicle are assigned and actual arrival is recorded. The backend enforces the same conditions. Submitted evaluations offer Manager/Admin Review Evaluation, Dispatcher Edit Evaluation, or Driver View Evaluation. Finalized evaluations use View Evaluation, preserving assigned-driver disputes. Noneligible records offer evidence review/view, never initial Evaluate. Drivers see only their own records. No trips means a clear empty state and no evaluation modal.

Weights are configured exclusively through existing `settings.php?tab=trip-performance-evaluation`, restricted to Admin/Manager; the old Trip Performance weights save operation is rejected. The existing PostgreSQL setting, exact-100 validation, audit and historical weight snapshots are reused. No database changes or sample records are needed for this UI adjustment.

Files for this adjustment: `includes/trip_performance.php` (eligibility/actions), `includes/trip_performance_view.php` (table/modal), `modules/driver-trip-performance/trip-performance.php` (scoped script), `js/trip-performance.js` (dialog lifecycle/focus), `css/driver-trip-monitoring.css` (Trip Performance modal sizing/scroll only), `tests/trip_performance_records_test.php` (four-role records/modal checks), and this report. Existing System Settings integration remains unchanged.

Validation: four records-table role variants pass 27 checks each; four modal role variants pass 31 checks each, using isolated temporary tables. Existing evidence/evaluation/dispute tests (41), Manager/Dispatcher render variants (39 each), simultaneous final approval, HTTP upload/security checks (10) and Admin/Manager settings persistence/unauthorized-save checks pass. Live empty state verified with no selector, modal or sample records; the 390px viewport has no page overflow. No schema migration, reset, commit, push or deployment.

Applied migration: `migrations/2026_10_12_trip_performance.sql`. The runner `scripts/migrate-trip-performance.php` makes a complete custom-format PostgreSQL backup and verifies its contents with pg_restore --list before the transactional migration. It refuses to repeat an applied migration. No trips, reservations, users, driver profiles or historical ratings are updated or backfilled.

## Storage

- `trip_performance_evaluations`: unique trip_id, driver reference, weight snapshot, per-criterion scores/evidence IDs/explanations, timing snapshot, delay responsibility, Dispatcher submission, one Manager/Admin finalization and revision for stale-write protection.
- `trip_performance_evidence`: trip-linked system references and immutable verified snapshots, or private POD/supporting files with MIME, size, SHA-256, provider and Dispatcher verification metadata. A changed source can be linked as a new evidence version. Verification never invents missing records.
- `trip_performance_disputes`: assigned driver's reason and supporting evidence IDs, one open dispute per evaluation, Manager/Admin resolution. Returned for Review reopens the same evaluation; earlier approvals and scores stay in the append-only audit trail.
- Existing `system_settings`: trip_performance.weights (25/25/20/15/10/5), configurable by Manager/Admin, integer percentages totaling 100. Existing evaluations retain saved weights.
- Existing `audit_logs`: evidence additions/verification, settings changes, old/new evaluations, finalization, disputes and resolutions. Existing append-only audit protections remain enabled.

All new trip, driver and user relationships use foreign keys with RESTRICT; existing records are preserved. No separate delivery, GPS, fuel, reservation or approval module is created.

## Evidence and scoring

The Dispatcher selects actual records or accepts private PDF/JPG/PNG uploads (5 MB), reads them, and verifies them with notes. Uploads are initially unverified, including driver uploads. System source snapshots are checked again at evaluation time; altered or missing source/file evidence cannot support a score.

Each criterion takes a justified manual score from 0 to 100 and verified evidence appropriate to that criterion. Overall = sum(score × saved weight / 100). Every nonzero-weight criterion must be supported before an overall score exists or approval is allowed. Unsupported criteria and incomplete overall scores display **Not Evaluated**; weights are not redistributed and absence of incidents is not treated as perfect safety.

- On-Time Delivery: reservation return_date/time (shared cargo delivery commitment) compared with actual arrival. A dated `trip_timeline` Arrived event takes priority, because the existing completion action overwrites trips.actual_arrival at handover. Otherwise the trip arrival field is labeled explicitly as requiring delivery relevance verification. Route estimated_arrival is displayed only as a prediction, never a promised schedule. Missing schedules are not fabricated.
- Late delivery: unknown responsibility must remain Not Evaluated. Driver or external responsibility requires verified supporting documentation and delay notes. External delays cannot lower the driver's punctuality score.
- Delivery Completion: both an existing Verified Completed reservation event/current verified trip state and verified POD are required.
- Fuel Efficiency: trip distance plus verified actual consumption from existing route_history, or trip-linked fuel records with a verified supporting consumption document. A fuel purchase alone is insufficient.
- Documentation: verified POD or a relevant supporting document.
- Safety / Driver Conduct: verified relevant supporting documents; existing trip-specific requester ratings can also support conduct. No incidents or qualifications are fabricated.

The existing GPS arrival action validates positions but does not retain observed coordinates. The page explicitly says so; planned route coordinates are not actual GPS evidence. Actual GPS exports may be attached and reviewed as supporting documents. No new tracker is introduced.

## Roles and integrity

Dispatcher verifies evidence and submits initial/updated evaluations for completed cargo trips. Manager and Admin have identical rights to review, adjust with reasons, configure weights, resolve disputes and finalize. Neither can bypass Dispatcher submission or missing evidence. One final approval is sufficient; finalized scores cannot be edited outside the audited dispute/review process. An open dispute blocks final approval.

The assigned driver can view their own cargo trip evaluations, attach unverified evidence and dispute a submitted/finalized evaluation. Driver access is scoped on the server; fleet analytics and other drivers' evaluations are withheld. A link to this same Trip Performance page is added to the Driver sidebar. Driver Performance is unchanged.

POST actions require CSRF. Trip/evaluation locks and revision checks prevent concurrent updates and duplicate final approvals. Downloads recheck role/ownership and SHA-256 and force attachment delivery; originals are under the existing denied private storage directory. Failed uploads/transactions remove only the new file.

## Verification

`php tests/trip_performance_test.php`: scoring, evidence, roles, approvals, disputes and actual page rendering against connection-local temporary tables.

`php tests/trip_performance_test.php csrf`: real action handler rejects invalid CSRF without mutation.

`php tests/trip_performance_http_test.php`: real multipart uploads, MIME/extension/role/CSRF checks through a token-gated loopback-only test server; temporary database tables and file cleanup.

`php tests/trip_performance_concurrency_test.php`: two independent Manager/Admin database sessions race final approval in a randomly named isolated test schema; one succeeds, one is rejected. The isolated schema is removed afterward and public operational records/sequences are untouched.

## Modified / added files

- `modules/driver-trip-performance/trip-performance.php`: evaluation panel, role-scoped access, preserved legacy analytics labeled as departure metrics.
- `includes/sidebar.php`: driver link to the same Trip Performance page; existing requester-label changes were preserved.
- `includes/trip_performance.php`: evidence, scoring, role, approval, audit and dispute services.
- `includes/trip_performance_view.php`: cargo trip selection, matrix, evidence, review and dispute forms using existing styling.
- `actions/trip-performance.php`: transactional CSRF-protected mutations and private uploads.
- `actions/trip-performance-evidence.php`: authorized evidence downloads.
- `database/migrations/2026_10_12_trip_performance.sql`: three additive tables, constraints/indexes and one default weight setting.
- `scripts/migrate-trip-performance.php`: verified full backup and migration runner.
- `tests/trip_performance_test.php`, `tests/trip_performance_http_fixture.php`, `tests/trip_performance_http_test.php`, `tests/trip_performance_concurrency_test.php`: isolated workflow, upload/security and concurrent approval checks.
- `database/TRIP_PERFORMANCE.md`: this implementation and database report.

Backup created before migration: `storage/private/trip-performance-migration-20261012-201601/before.dump` (verified custom PostgreSQL archive).

Validation results: 41 workflow/driver-render checks; Manager and Dispatcher render variants pass; CSRF action check passes; 10 actual HTTP upload/security checks; simultaneous Manager/Admin approval passes. Existing Dispatch Board (22) and Trip Schedule (29) checks pass. PHP syntax and scoped whitespace checks pass. Live desktop/mobile empty states were inspected; no page-level horizontal overflow. Public evaluation/evidence/dispute tables remain empty after testing; operational counts remain 0 trips, 0 reservations, 18 drivers, 6 users and 1 vehicle. Temporary evidence files and test schemas were removed. No database reset, Driver Performance changes, commit, push or deployment.
