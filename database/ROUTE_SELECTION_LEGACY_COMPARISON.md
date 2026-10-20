# Route selection comparison — 2026-10-17

Source: `C:/Users/LAPTOP-3DS/Downloads/Toursphere-main (1).zip`.
Compared only `js/map-route.js`, `includes/routethink_engine.php`, and `actions/route_ai.php`.
Archive code was extracted under `tmp/legacy-route-analysis`, not installed into the application.

## Findings

- Old and current browser/server selectors use the same four objective weights.
- Both explicitly choose minimum duration for Fastest, minimum distance for Shortest, and minimum estimated fuel for Fuel Efficient. Balanced uses normalized weighted scores. Neither forces distinct roads for distinct modes.
- `updatePrePickupPathForMode` is identical in the archive and current source.
- `applyEvaluationResult` renders the chosen alternative identically; the current version additionally exposes the fuel-cost result field.
- Legacy passenger load was replaced with cargo/payload load in current calculations. Historical passenger model predictions are not treated as validated cargo predictions. These differences must not be reverted just to produce different roads.
- Current selection also blocks changes after Apply, preserving the user's one-time Apply requirement.

## Reproduction

`php tmp/legacy-route-analysis/read-candidates.php` reads only distance/time/road summaries from saved TRP-024 outbound, approach, and return alternatives. It exports no coordinates and modifies no trip data.

`node tmp/legacy-route-analysis/compare.js`: 16 comparisons pass. All 12 real segment/mode combinations choose candidate 0 in both versions. Four controlled combinations choose 0/1/2/2 for Fastest/Shortest/Fuel Efficient/Balanced in both versions.

`php tmp/legacy-route-analysis/compare-server.php`: 12 real segment/mode comparisons pass. Both archived and current server engines select candidate 0. Database access is enclosed in a read-only transaction and rolled back. This compares code using current settings/model storage; it does not recreate historical database/model state.

No production application code, driver eligibility, trip records, UI, or Apply restrictions were changed during this analysis. Results establish parity for these saved alternatives; they do not guarantee that every historical Google response or historical trained model had identical results.
