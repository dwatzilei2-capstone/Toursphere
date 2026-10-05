# Shared Vehicle Photos

`vehicle_photos.vehicle_id` is the single visual identity associated with a vehicle. Directory/profile payloads and reservation assignment options use `includes/vehicle_photo.php` to resolve that record. Photos never participate in compliance, ranking, availability, designation, or dispatch validation.

## Deployment

Deploy the migration, PHP/JS/CSS files, placeholder, and every PNG in `assets/images/vehicle-samples/`. Run:

```powershell
php scripts/migrate-vehicle-photos.php
```

The migration is idempotent. It associates samples by a digest of the stored brand, model, variant name (if present), type, and year. It never replaces an uploaded actual photo or changes operational records. Unknown/new model combinations receive the placeholder unless an actual photo is supplied. `manifest.json` contains the model catalog and the exact generation prompt for each sample; no vehicle-ID conditionals are used by the application. Samples were generated with the built-in image generator and are illustrations for testing, not photographs of the physical fleet.

## Uploads and display

Authorized `vehicles.manage` users can upload an optional photo in Add Vehicle or replace it from Vehicle Details. The latter redirects back to the same profile with a success/error notice. Subsequent directory and assignment requests automatically resolve the uploaded photo from the same record. Assignment requests bypass response caching; actual image responses revalidate and replacement filenames change the image URL.

Uploads require CSRF validation, successful HTTP upload provenance, matching extension and MIME/raster type, a nonempty file up to 5 MB, and bounded dimensions (12,000 per side / 20 million pixels). JPEG, PNG, and WebP are accepted. Random filenames and private storage prevent executable uploads from being exposed. Originals are served only through the authenticated image endpoint with an explicit content type, nosniff, and sandbox policy. The existing private-folder `.htaccess` must remain deployed. Preserve `storage/private/vehicle-photos/` in persistent hosting storage and backups; actual photos are excluded from Git.

Replacement locks the vehicle record, updates one photo record, retains its sample, and audits the change. Failed database writes roll back and remove the newly stored image. Old actual files are removed only after successful commit. No compliance-document files are used as photos.

Resolution is actual → sample → placeholder, with filesystem existence checks on the server and image-error fallback in the browser. Every sample has a visible “Sample” label and accessible wording identifying it as an illustration. Thumbnail dimensions stay fixed and images use `object-fit: contain`.

## Verification

```powershell
php tests/vehicle_photo_test.php
php tests/assignment_http_sessions.php
node tests/vehicle_photo_browser_test.js
node tests/reservation_assignment_browser_test.js
php tests/assignment_http_sessions.php --clean
```

The photo browser test uses one explicitly named temporary vehicle and cleans its record, audit events, and test uploads. It verifies PNG/JPEG/WebP uploads, authorization/CSRF/content/size rejection, shared sources, replacement cleanup, source labels, selection, and responsive layouts. It does not upload photos to current fleet vehicles or confirm/dispatch reservations.
