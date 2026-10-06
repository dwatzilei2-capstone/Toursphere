# Fuel receipts

Fuel logs support optional JPG/JPEG, PNG or PDF uploads (maximum 5 MB). Apply `scripts/migrate-fuel-receipts.php` to add receipt metadata columns to the existing `fuel_transactions` table; it has been applied locally. No existing receipt numbers are interpreted as uploaded files, and no demo receipts are generated.

Uploads are validated by extension, detected MIME, real uploaded-file origin, size, image dimensions or PDF signature. Files have random server filenames under `storage/private/fuel-receipts`, excluded from Git and protected by the existing private-directory access rule. Receipt metadata and the fuel transaction commit together; failed saves remove the file. No upload leaves the log saveable and shows Not Uploaded.

`actions/fuel-receipt.php` checks login, fuel-view permission and Driver ownership before returning metadata or streaming the actual file. Previews and downloads use that endpoint. Fuel price snapshots, editable pump prices, ownership derivation and calculations remain unchanged. The optional input is included in both existing fuel-log forms.

Validation: `tests/trip_funding_fuel_action_test.php`; `tests/fuel_receipt_test.php` (temporary-table real uploads and byte streaming); `tests/fuel_receipt_browser_test.js` (isolated UI responses). First run `tests/trip_funding_http_test.php --browser` for temporary sessions and finish with `--clean`. Receipt test endpoints require an ephemeral token kept under the non-public tmp directory and return 404 otherwise. No operational fuel transactions or receipts are fabricated by these tests.
