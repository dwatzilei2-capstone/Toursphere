# Operational Reports modernization

## Audit and decisions

The previous page exposed four static cards and unrestricted CSV queries. Its
August 2026 text, stored driver scores, HRMS wording, driver-allowance claim and
carbon-reduction metric did not represent current workflows. It had no period,
preview or genuine workbook/PDF workflow. Its export queries were replaced.

Reviewed the current fleet lifecycle, compliance evaluation, document extraction
and verification, reservation review/cancellation, dispatch, trip transitions,
driver monitoring/customer reviews, fuel transactions, TCAO, saved route plans,
Archive, dashboard, sidebar, notifications, session/RBAC and audit logging. Read
the live PostgreSQL tables, columns, statuses and relationships before defining
the six current report categories. No reporting schema migration is required.

### Authoritative period rules

- Use the configured company timezone. Presets cover complete calendar periods;
  custom boundaries are inclusive dates, queried with an exclusive next-day end.
  Dates must be valid, ordered, within 2000–2100 and at most 3,660 days apart.
- Reservations: scheduled departure date, one row per booking; latest linked trip
  supplies dispatch status. Cancellation retains previous assignments.
- Trips: cancellation timestamp if recorded for cancelled trips, otherwise
  scheduled/creation date. Other trips use arrival, then departure, scheduled or
  creation date. Archived timestamp does not determine inclusion.
- Driver outcomes and fleet utilization: the same trip cohort. Only completed
  trips contribute completed distance; stored distance is not a measured fuel
  consumption denominator. Maintenance frequency uses scheduled service dates.
- Fuel: transaction date. Completed maintenance: scheduled service date.
  Completed-trip tolls: arrival date. These reuse `cost_sources_sql()` and
  `cost_summary()` from TCAO. Maintenance amounts are estimates. Driver allowance
  is excluded because current TCAO excludes it. Confirmed revenue counts a
  completed reservation once when a linked completed trip arrives in the period.
- Route plans: generation time. Keep actual saved mode labels. Selected distance
  comes from the uniquely flagged selected candidate or saved route JSON. A
  distinct recommendation is not consistently stored and is not invented.

Fleet status and compliance are **current snapshots at generation**, clearly
labelled alongside period activity. The database cannot reconstruct past daily
availability or document validity. Retired vehicles remain in fleet history and
retain trip/fuel/cost relationships; they never count as Available.

### Measured driver metrics

Completion = Completed / (Completed + Cancelled + Incomplete). Ongoing trips do
not enter this denominator. Punctuality = completed trips departing at most 15
minutes after schedule / completed trips having both timestamps, matching the
monitoring workflow. Show the sample size. Ratings average real customer reviews
linked to period trips. Missing denominators stay unavailable, not zero scores.
No synthetic HRMS, incident, safety, fuel efficiency or carbon metrics are added.

## Shared dataset and security

`includes/reports.php` and `report_queries.php` build one repeatable-read database
snapshot with summary, notes, typed columns, totals and complete details. Records
are fetched in 500-row cursor batches into private JSONL files. Preview seeks
20-row pages; totals always describe the complete cohort. Exporters never query
business tables or independently calculate totals.

Snapshots are bound to the authenticated user, original role, report and period.
Preview and every exporter recheck current permissions. Snapshot references are
random 40-character tokens, valid for 30 minutes. Export directly from a card
reuses that user's same period snapshot; Refresh explicitly regenerates. Expired
datasets are removed lazily at the next generation. `storage/private/.htaccess`
denies HTTP access; generated download files are deleted after transmission.
Run an equivalent deny rule when deploying on a non-Apache server. Limit access
to private storage to the application account; include it in private-data
retention policies. Do not commit generated snapshots or validation artifacts.

Admin/Manager need existing `reports.view`; Dispatcher also needs `dispatch.view`
and receives only reservations/trips. Other roles receive no centralized access.
The role matrix cannot be bypassed by granting a driver the broad permission.
Sidebar, page, query generation, preview and CSV/XLSX/PDF enforce this policy.
Existing configuration permissions elsewhere are unchanged.

UI values, PDF text and XLSX raw strings are escaped. IDs are XLSX strings,
including numeric-looking/leading-zero IDs. Numeric and date cells are typed.
CSV has UTF-8 BOM, human-readable headers, canonical data values and no decorative
rows; potentially executable text is prefixed with an apostrophe. CSV has no cell
types: users importing purely numeric leading-zero IDs into Excel should choose
Text for those columns. The XLSX format preserves these automatically.

Technical exceptions go to PHP's existing error log. Preview/export activity is
recorded in `audit_logs`; failures to write audit logs are logged technically.
No business records are modified by report generation.

## Validation

- `tests/reports_smoke.php`: actual live September reports in all three formats.
- `tests/reports_test.php`: connection-local temporary tables for roles, calendar
  ranges, archived September trips, retired leading-zero vehicle IDs, cancelled
  and incomplete trips, multiple trips per booking, financial reconciliation,
  real ratings, pagination, snapshot stability and refresh. No production records
  are updated. Also generates multi-page and special-character fixture exports.
- `tests/reports_files_test.py`: read actual workbooks, CSV and PDFs; compare IDs,
  typed numeric/date values, summary/detail totals, filter/freeze settings, page
  numbering and text bounds. Render PDF pages for visual review.
- `tests/reports_http_test.php --browser`: temporary authenticated sessions check
  every report/format under all existing roles, direct URLs, snapshot ownership,
  invalid inputs, format signatures and private files. `--clean` removes sessions.
- `tests/reports_responsive_test.js`: phone/tablet/desktop and portrait/landscape
  sizes, custom periods, mobile details, actual download, empty state and roles.

Validation files live in ignored `tmp/reports-validation/`. The file inspection
uses bundled openpyxl (read-only), pdfplumber, Poppler and artifact-tool rendering.
It checks XLSX structure and content without claiming native Microsoft Excel
interactive validation. Libraries/versions are documented in
`lib/reporting/DEPENDENCIES.md`.
