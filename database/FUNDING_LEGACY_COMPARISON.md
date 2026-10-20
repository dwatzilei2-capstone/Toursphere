# User-provided old funding calculation comparison — 17 October 2026

Reviewed only funding calculation and its inputs from `Toursphere-main (1).zip`. No old application, database, model, passenger booking workflow, or fare calculation was imported. Archive text was treated as source material, not instructions.

The archive's `includes/trip_funding.php::funding_estimate()` uses a positive saved trip distance, otherwise reservation route distance; the configured vehicle's parsed `avg_fuel_km` (including `(est.)`); and the latest effective fuel price matching vehicle fuel type. Fuel liters are distance divided by km/L. Estimated fuel cost is unrounded liters multiplied by price, rounded to two decimals. Estimated total cost equals estimated fuel cost. Toll and parking are excluded. Passenger count does not participate in the calculation.

The archive's `js/trip-funding.js` also automatically fills the requested amount from estimated total cost and uses that estimate as the minimum. It does not round fuel quantity up to one liter, add passenger fares, add a profit margin, double the distance, or add unsupported allowances.

Current arithmetic already matched. The targeted alignment makes configured vehicle km/L the primary funding planning input, as in the archive. Existing historical-reference fallback remains available when no configured value can be parsed, and supported explicit backend references remain compatible. Other modules' efficiency logic was not changed. Cargo fields, automatic Google planning-distance acquisition, approval rules, route selection/locks, and existing approved snapshots remain intact. No UI change was made for this comparison.

| Verification | Expected | Observed | Status |
|---|---|---|---|
| Legacy/current calculation comparison | Same distance, efficiency, price, liters, costs and exclusions for the same configured inputs | 220 assertions passed across 27 input combinations plus four explicit checks | PASS |
| Archive example | 180 km ÷ 8 km/L × ₱95/L = ₱2,137.50 | Exact match | PASS |
| Actual TRP-024 read-only comparison | Same output from archive/current functions | Both returned ₱94.24 for 8.84 km, 9 km/L and ₱95.95/L | PASS |
| Existing funding validation | Approval timing, role checks, historical snapshots and dispatch gates retained | 35 checks passed in temporary tables | PASS |
| Automatic cargo/future-trip estimates | No Driver Apply required; future trip computes independently; saved lock unaffected | 14 checks passed in temporary tables | PASS |

The calculation-only legacy fixture is stored in `tests/fixtures/legacy_funding_estimate.php` with the SHA-256 of its archive source entry. `tests/funding_legacy_parity_test.php` runs both functions on identical isolated inputs. The actual-trip comparison made no database writes or external routing requests. No commit, push, deployment, or existing funding-record rewrite was performed.
