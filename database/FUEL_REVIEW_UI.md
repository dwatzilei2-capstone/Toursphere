# Fuel Transaction Review — approved workspace

The existing Review entry now opens the complete review interface in one click, in the same dialog shell. Desktop uses two columns: recorded refueling/vehicle/submission/verification information on the left, protected uploaded receipt and inline review decisions on the right. Mobile stacks the sections with custom disclosure icons. The trip link returns to the existing Fuel Details view so funding/authorization controls remain accessible. No additional modal or verification workflow was added.

The receipt viewer reads the existing authenticated, ownership- and hash-checked receipt endpoint. It displays the recorded image or PDF; no Petron/sample document is copied from the reference. Missing files, access failures and loading errors have explicit states. Fullscreen expands the same receipt panel, with a non-blocking fallback when browser policy denies fullscreen; no nested modal is opened. Existing receipt viewing elsewhere is unchanged.

Initial decisions are unselected. Verify reveals optional notes and Confirm Verification; Return/Reject reveal their required reasons and corresponding confirmation actions. Dispatcher retains Confirm/Return only. The existing CSRF, revision, role checks, receipt validation, audit and verified-expense calculations remain authoritative. Existing POST redirects/toasts and duplicate-submit protection remain in use. No submission timestamp is invented when the record does not contain one.

## Modified files

- `modules/fuel-management/fuel-transactions.php`: Review link targets the eligible transaction; table layout/data unchanged.
- `includes/fuel-unified-details.php`: guarded review-view routing inside the existing dialog.
- `includes/fuel-review-ui.php`: immediate inline decision section and existing Details-to-Review entry.
- New `includes/fuel-review-workspace.php`: approved review layout, using loaded records.
- New `js/fuel-review-receipt.js`: protected inline image/PDF viewer, loading/unavailable feedback and fullscreen.
- `js/fuel-operations.js`: workspace cancellation/close integration and fullscreen keyboard handling.
- `includes/fuel-presentation.php`: Confirmed uses the shared green semantic badge.
- `css/fuel-management.css`: review-only layout/responsiveness.
- `tests/fuel_operations_pages_test.php`, `tests/fuel_operations_http_fixture.php`: role/entry/receipt UI coverage with isolated PostgreSQL fixtures.

## Validation

- Admin/Dispatcher/Driver/Manager page checks: immediate workspace, exact pending transaction, no extra expansion click, unchanged permissions and grouped-record totals. Forged review parameters do not expose controls to Driver/Manager.
- 23 existing real HTTP checks passed: actual receipt upload/streaming, Dispatcher confirmation, Admin verify/return/reject, CSRF, ownership, receipt integrity and verified costs. Temporary tables only.
- Browser tests: protected receipt loaded; missing receipt state; unselected initial decisions; all three decisions; optional/required notes; inline validation; Cancel; responsive single/two-column layout. Fullscreen was denied by the automation/browser policy, and the fallback was checked. Manual fullscreen permission behavior remains browser-dependent.
- Representative CSS widths approximately 320, 375, 430, 768, 1024, 1440, 1920: no workspace overflow, outer shell scrolling or viewport overflow of the dialog. Existing user browser zoom was accounted for.
- PHP/JavaScript syntax and targeted whitespace checks passed.

Browser tests used token-protected temporary fixtures because the live browser login had expired. No public transaction, user, vehicle or funding records were modified by this redesign. No migrations, commit, push or deployment.
