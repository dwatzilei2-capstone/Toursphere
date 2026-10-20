# Shared Vehicle Photos

`vehicle_photos.vehicle_id` is the single visual identity associated with a vehicle. Directory/profile payloads and reservation assignment options use `includes/vehicle_photo.php` to resolve that record. Photos never participate in compliance, ranking, availability, designation, or dispatch validation.

## Deployment

Deploy the migration, PHP/JS/CSS files, and the placeholder. `database/migrations/2026_10_05_vehicle_photos.sql` creates the shared storage table and is already applied on existing installs; the former sample-seeding runner was removed with the illustrations. Vehicles without an uploaded photo show `assets/images/vehicle-placeholder.svg`.

## Built-in passenger illustrations removed

The Logistics 2 cargo change removed every generated passenger illustration, its `manifest.json` catalog, the `vehicle-samples` directory (about 25 MB), `scripts/migrate-vehicle-photos.php`, and the `vehicle_photo_sample_*` helpers. Vehicles that referenced an illustration keep their uploaded actual photo and otherwise fall back to the clean placeholder. `vehicle_photos.sample_filename` and `sample_label` remain only so retired pointers can be inspected; the application no longer reads or writes them.

## Uploads and display

Authorized `vehicles.manage` users can upload an optional photo in Add Vehicle or replace it from Vehicle Details. The latter redirects back to the same profile with a success/error notice. Subsequent directory and assignment requests automatically resolve the uploaded photo from the same record. Assignment requests bypass response caching; actual image responses revalidate and replacement filenames change the image URL.

Uploads require CSRF validation, successful HTTP upload provenance, matching extension and MIME/raster type, a nonempty file up to 5 MB, and bounded dimensions (12,000 per side / 20 million pixels). JPEG, PNG, and WebP are accepted. Random filenames and private storage prevent executable uploads from being exposed. Originals are served only through the authenticated image endpoint with an explicit content type, nosniff, and sandbox policy. The existing private-folder `.htaccess` must remain deployed. Preserve `storage/private/vehicle-photos/` in persistent hosting storage and backups; actual photos are excluded from Git.

Replacement locks the vehicle record, updates one photo record, retains its sample, and audits the change. Failed database writes roll back and remove the newly stored image. Old actual files are removed only after successful commit. No compliance-document files are used as photos.

Resolution is uploaded image → placeholder, with filesystem existence checks on the server and image-error fallback in the browser. Placeholder imagery carries a visible “No photo” label and accessible wording. Thumbnail dimensions stay fixed and images use `object-fit: contain`.

## Verification

```powershell
php tests/vehicle_photo_test.php
php tests/assignment_http_sessions.php
node tests/vehicle_photo_browser_test.js
node tests/reservation_assignment_browser_test.js
php tests/assignment_http_sessions.php --clean
```

The photo browser test uses one explicitly named temporary vehicle and cleans its record, audit events, and test uploads. It verifies PNG/JPEG/WebP uploads, authorization/CSRF/content/size rejection, shared sources, replacement cleanup, placeholder labels, selection, and responsive layouts. It does not upload photos to current fleet vehicles or confirm/dispatch reservations.
