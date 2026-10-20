# Logistics 1 cargo requests

The existing `customer` role ID/code and user accounts remain unchanged for authentication compatibility. The displayed role name is **Logistics 1 Requester**. Its new `logistics.requests.own` permission allows local submission; it has no vehicle assignment or dispatch permissions. Existing requester actions and trip detail access remain restricted by `reservations.customer_id`.

Cargo submissions reuse `reservations`, `reservation_events`, `trips`, and the existing assignment, funding, notification, cancellation, and rating flows. Passenger records remain readable. Cargo rows store `request_kind=Cargo`, `passenger_count=0`, cargo type, total weight (kg), total volume (m³), quantity, special handling, purpose, pickup/delivery locations, and requested date/time. They do not use passenger operating schedules or passenger fare calculations. Approval does not reserve a specific truck or driver. Admin/Dispatcher must review special handling, then confirm a suitable vehicle and driver; payload, volume, licenses, availability, maintenance, documents, resource conflicts, and funding are checked before dispatch.

Local submission always writes `source_system=Logistics 1`, `integration_mode=Mock`. Ownership, status, and source are server-controlled. No integration endpoint or live Logistics 1 connection is enabled.

## Future API adapter

Add an authenticated adapter that maps the incoming Logistics 1 identity to an existing requester account and calls the shared cargo validation/review workflow inside a transaction. Preserve the external request ID and payload version. `external_request_id` has a unique index scoped by source system and integration mode to support idempotency; `source_payload_version` defaults to 1, and `source_received_at` records receipt time. A future trusted adapter may write mode `API` after authenticating and validating its source; the local form cannot set that mode. Map pickup/delivery to `origin`/`destination`, requested schedule to `departure_date`/`departure_time`, and retain `customer_id` as the owning requester. Do not auto-approve requests, select physical vehicles, infer licenses, or bypass funding.

Changing cargo inputs invalidates the associated pre-dispatch funding snapshot. Existing passenger funding fingerprints are unchanged.

## Validation

`tests/logistics_requests_test.php` checks validation, ownership/source forgery, requester permissions, approvals/rejections, and cargo capacity eligibility in connection-local temporary tables. The `assign` and `dispatch` arguments execute the actual dispatch action against isolated tables, including confirmed mock funding for dispatch. No operational test requests are retained.

Migration runner: `scripts/migrate-logistics-requests.php`. A custom-format PostgreSQL backup is created and checked with pg_restore before migration. Existing customer credentials are unchanged.
