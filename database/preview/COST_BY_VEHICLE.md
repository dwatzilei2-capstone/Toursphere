# TCAO — Cost by Vehicle

Scope: `modules/cost-analysis/cost-by-vehicle.php`, `includes/vehicle_costs.php`, its own CSS/JS, tests and `database/preview/cost-by-vehicle.json`. Cost Overview, Cost Trends, operational modules and shared financial functions are unchanged.

## Sources and isolation

The source selector chooses exactly one of:
- **TEST DATA PREVIEW:** a read-only JSON fixture with 90 synthetic completed delivery-cost records across 2025–2026 and five clearly labeled cargo vehicles. No importer or database writes exist. Records cover differing delivery volumes, improving/stable/worsening fuel/cost efficiency, allowance surpluses/shortfalls, partial funding coverage, a newly acquired vehicle and a vehicle without delivery history. Default preview dates use the latest eligible fixture month. Future-dated entries are excluded.
- **Live records:** current cargo vehicles (`service_category= Cargo` or recorded cargo payload) and completed delivery relationships. Revenue uses the existing confirmed completed-reservation charge field; it is not recalculated from passenger counts. Each reservation is recognized on its first completed delivery to prevent duplicate revenue. Verified fuel must match both delivery and vehicle. Tolls come from the completed delivery. Latest confirmed Finance allowance is used once per delivery; its associated estimate is retained. For an unfunded delivery, its latest estimate remains available.

Current live schema limitations are shown on the page: maintenance orders store estimated_cost, not verified actual invoices; no supported source of other verified expenses currently exists. These categories are Not recorded in live mode and are excluded from actual operating cost. Preview maintenance and other amounts are synthetic verified invoices, not operational maintenance estimates. Preview and live use the same cent-based aggregation and financial formulas.

Recorded actual distance is accepted only with valid beginning/ending odometer evidence. Planned route distance is not silently substituted. Actual fuel consumption requires verified route-history consumption linked to a matching verified fuel transaction. Purchased liters alone never produce km/L. Missing evidence produces Not available / Insufficient Data.

## Financial rules

All financial fixture and aggregate amounts use integer centavos:
- Actual operating cost = recorded verified fuel + actual maintenance + tolls + other verified expenses. Missing categories are not invented.
- Delivery profit = recognized revenue − actual recorded cost.
- Margin = profit ÷ revenue × 100; unavailable when revenue is zero.
- Average cost = actual recorded cost ÷ completed deliveries.
- Cost/km and fuel cost/km require reliable distance for every matching delivery.
- km/L requires reliable distance and verified actual consumption for every matching delivery.
- Remaining/shortfall = approved allowance − actual recorded cost. It is shown only when every matching delivery has confirmed funding and recorded cost. Mixed funded/unfunded sets show their coverage and Comparison unavailable rather than manufacturing a zero budget. Positive unused allowance does not establish that funds were returned.
- Delivery IDs are deduplicated before aggregation.
- Missing/zero baselines never generate fabricated percentage changes.

## History and comparisons

Top filters constrain the aggregate cost and funding tables. Selected-vehicle analytics has an explicit view period ending at the selected To date, with monthly/yearly grouping. Newly acquired vehicles start at their available acquisition history. Each chart tooltip shows its exact date coverage. Partial-year vs full-year comparisons, or unequal partial-month coverage, suppress growth/efficiency statuses with Insufficient Historical Data. Total-cost and maintenance statuses use cost per completed delivery; per-km and km/L metrics use their respective ratios. Distance changes are activity changes, not efficiency claims. Changes within 5% are Stable; favorable normalized changes of at least 5% are Improving, unfavorable ones Needs Attention.

## Validation

- `php tests/cost_by_vehicle_test.php`
- `php tests/cost_by_vehicle_session.php` creates a temporary session for an existing administrator, with no operational inserts.
- `node tests/cost_by_vehicle_browser_test.js` checks the four sections, chart axes, independent financial totals, live/preview separation, monthly/yearly/new/missing/future cases, filtering and 320–1920px responsiveness. Operational table row counts are checked before and after browsing.
- `php tests/cost_by_vehicle_session.php --clean` removes the temporary session.

Browser checks use the existing workspace Playwright runtime and Microsoft Edge. Screenshots are saved under `storage/cost-by-vehicle-*.png`.
