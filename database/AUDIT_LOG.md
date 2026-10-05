# Audit Log

Apply `php scripts/migrate-audit.php` when deploying. The migration adds identity snapshots, old/new JSON values, transactional change triggers and append-only enforcement to the existing audit table. It creates no sample events and does not backfill invented historical roles.

`audit-log.php` requires the exact `fleet_admin` role before querying or rendering records. Navigation uses the same rule. Search and pagination use database records and parameterized search. There are no edit/delete endpoints. Database triggers reject UPDATE, DELETE and TRUNCATE on the audit table (a database owner can deliberately disable triggers for exceptional administration).

Bootstrap sets the authenticated actor on the request's database connection. Operational triggers capture all roles and commit or roll back alongside the action. Existing explicit audit inserts also receive identity snapshots. Additional row events are intentional: they preserve old/new values for each affected record. External jobs should call `audit_set_actor` for an authenticated account or leave the actor unset for System activity. Historical rows retain their original details and show an unknown role when none was recorded.

Accounts, reservations, assignment changes, trip status/start, vehicles, archive/restore, maintenance, fuel, ratings, documents, costs, schedules, role assignments and settings are captured. Credential hashes are excluded; password-only updates record a credential-change event. Login success/failure, failed two-factor verification and logout have explicit security events; no passwords or verification codes are logged.

Run `php tests/audit_log_test.php` for transactional checks. All fixture mutations and generated events are rolled back.
