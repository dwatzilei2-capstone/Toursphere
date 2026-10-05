# Archive Management

Open `http://localhost/fleet/archive.php` after signing in. Archive is the final sidebar item, immediately after Reports.

The additive migration is `migrations/2026_10_05_archive_management.sql`. Apply it with `php scripts/migrate-archive.php` on each deployment after the existing vehicle-document/RBAC migrations. It is safe to rerun. The development database has already been migrated. No records are archived by the migration.

## Operational workflows

- **Admin / Dispatcher:** Dispatch Board → Closed Trips → Archive → Confirm Archive. Completed, Cancelled, and Incomplete trips remain on the board until this explicit action.
- **Admin only:** Vehicle Directory → Retire → review recorded history → select a reason → Continue → Confirm Retirement. Other requires an explanation. Active trips and assigned reservations prevent retirement.
- **Manager:** view all four categories, search, filter, sort, and view details.
- **Dispatcher:** trip categories only.
- **Staff:** the current database has no Staff role. No role is created. If an existing `staff` role is introduced through the system's normal administration, both `archive.view` and `dispatch.view` must be granted for trip-only, read-only access. No access is granted implicitly.
- **Driver / Customer:** centralized Archive access is denied, including direct URLs and action endpoints. Existing personal trip/reservation history remains available.

Archive flags stay on the original trips and vehicles. IDs, driver/vehicle links, reservations, costs, fuel transactions, maintenance and route history remain intact. Retirement reasons and recommendation snapshots are stored with the vehicle. Decisions are recorded in the existing `audit_logs` table and trip archiving also adds a timeline event. Archived trip statuses and retired vehicle status are protected against later operational status changes.

Recommendations require at least three in-repair or completed maintenance orders explicitly documenting breakdown, mechanical failure, engine failure, or transmission failure. Routine service and estimated costs do not establish permanent damage or economic irreparability. Recommendations never select a reason or change status.

## Verification

- `php tests/archive_test.php`: database behavior, authorization, search/filter/sort/pagination and preservation, using connection-local temporary tables.
- `php tests/archive_http_test.php`: real HTTP authorization and integration, using temporary authenticated test sessions; does not archive or retire real records.
- Browser suite: first run `php tests/archive_test.php --render` and `php tests/archive_http_test.php --browser`, then `node tests/archive_responsive_test.js`. Set `PLAYWRIGHT_MODULE` to the installed Playwright module path on other machines. Tests intercept write requests and populated test pages; source fixtures never enter operational tables. Run `php tests/archive_http_test.php --clean` afterward to destroy test sessions and rendered HTML.

The browser suite covers 320–2560px widths, portrait and landscape, empty/populated lists, details, filter dialogs, mobile navigation and final confirmations.
