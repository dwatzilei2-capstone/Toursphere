# Automatic Trip Funding estimate — 17 October 2026

The correction applies to the shared Trip Funding flow for existing and future trips; there is no production-code condition for TRP-024. Opening Trip Funding as Admin/Dispatcher automatically obtains missing driving distance using the existing configured Google distance calculator. Request submission also prepares missing distance before validation. Existing saved distances are reused, and pending/approved funding snapshots and active navigation are not recalculated.

The calculation is distance ÷ expected vehicle efficiency × configured fuel price. An existing vehicle value marked `(est.)` is accepted as an explicitly labelled planning assumption, without changing vehicle specifications or claiming measured consumption. Verified historical efficiency retains precedence. Unknown efficiency or price remains unavailable; unsupported toll/parking expenses remain excluded. Automatic distance is cached in the existing reservation planning-distance field, which supports two decimals; no schema migration or Driver route selection is required.

| Test | Expected | Observed | Result |
|---|---|---|---|
| Existing funding behavior | Roles, formula, approval timing, snapshots and dispatch gate preserved | 35 existing checks passed in temporary tables | PASS |
| Independent automatic calculation | No Driver Apply; stops included; safe failure; precise costs | 14 new checks passed, including a separate newly assigned future trip | PASS |
| Route and funding preservation | Applied geometry/lock unchanged; pending/approved funding retained; active navigation untouched | Dedicated new checks passed | PASS |
| Cargo workflow regression | Cargo validation, independent driver availability and funding restrictions retained | 41 checks passed in temporary tables | PASS |
| Actual local funding form | Real Google distance, efficiency, liters and cost displayed automatically | TRP-024 displayed 8.84 km, 9 km/L, 0.982222 L, ₱95.95/L and ₱94.24; 24 browser checks passed, including mobile layout | PASS |
| No workflow shortcut | Loading estimate neither applies route nor submits funding/dispatch | Driver route unchanged; funding remained Not Requested; no POST requests from the browser test | PASS |
| Deployed environment | Same behavior on deployed host | No deployed safe test endpoint provided | NOT TESTED |

The live test was limited to the user's marked practice request RES-2026-034, Manila City Hall → Makati City Hall, with no stops. The browser test fails closed before the external lookup if those public test locations or the test-only handling instruction differ. It saved only the system-generated planning distance through normal funding loading. Temporary test sessions were removed. No Finance approval, dispatch, route application, license modification, commit, push, or deployment was performed for this correction.

The optional manual efficiency/source panel and separate fuel-allocation input were removed at the user's request. The request form now retains Funding Method and Requested Funding Amount. The amount remains automatically prefilled from the estimate and editable; backend fuel allocation defaults to calculated fuel cost. No request is approved by that prefill.
