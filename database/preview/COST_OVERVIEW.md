# Cost Overview — Verified costs and approved trip allowances

Scope is Cost Overview only. Cost by Vehicle, Cost Trends, Logistics 1, Finance, assignments and operational records are unchanged.

## Isolated preview

The original 96 synthetic cargo delivery records across 2025–2026 are retained. Existing delivery IDs, dates, vehicles and expense amounts are preserved. Three already-funded 2026 deliveries have lower synthetic approved limits to demonstrate genuine recorded-cost shortfalls; unfunded records remain unfunded. No fake approval is generated for a missing allowance. Missing Other expense records remain unavailable instead of invented zero transactions. Future-dated rows are excluded from every period.

Preview is read directly from `cost-overview.json` and never inserted into operational tables. Every request chooses either this fixture or live cargo records, never both.

## Reporting scope

All eligible cargo vehicles are included. Records are attributed to completed delivery actual-arrival dates, or scheduled operational dates for ongoing deliveries, using the same date cohort for allowances and recorded costs. Cancelled deliveries, future dates, unmatched vehicle/fuel relationships and unallocated fleet overhead are excluded. Average cost counts only Completed deliveries and their delivery-attributable verified costs; ongoing-delivery expenses are not part of its numerator.

Existing date-picker/AJAX and Reset behavior are retained. Reset changes dates while keeping the selected source. A single-calendar-year range compares matching previous-year dates. Different selected years compare only the two endpoint years; intervening years are excluded. Exact coverage is shown, including future-date cutoff and missing-year notices.

## Financial rules

Amounts are summed in integer centavos:
- Verified operating cost = known verified fuel + actual maintenance + toll + other eligible recorded expenses. Missing costs stay unavailable.
- Approved allowances = one latest genuinely confirmed funding request per eligible delivery; pending, rejected and unfunded records are excluded. The confirmed request's linked estimate is retained, falling back to the latest estimate only for unfunded deliveries.
- Per-delivery remaining/shortfall = approved allowance − actual recorded cost, only with a confirmed allowance and sufficiently complete expense evidence. Fleet remaining is the sum of these valid per-delivery balances, **not** all allowances minus all fleet spending. Unfunded and incomplete records are omitted from this net balance, but stay visible in detail.
- Remaining is not company profit or proof of returned money. Individual negative balances remain red in the funding table even when fleet net remaining is positive.
- Average cost = completed-delivery attributable verified costs ÷ completed deliveries. Unrecorded expenses never produce a fabricated zero average when no verified costs are available.
- Estimate variance = actual − estimate; allowance balance = approved − actual. Each has independently validated required records.
- Monthly allowance utilization = comparable actual costs ÷ comparable approved amounts × 100. Both sides use exactly the same funded, complete delivery subset. No percentage is generated for zero/missing approved funding. Higher utilization is not automatically better efficiency.

## Live evidence limitations

Live sources are current cargo trips/vehicles, matching verified fuel transactions and recorded tolls, existing estimates and confirmed Finance funding requests. No booking revenue, passenger count or profitability dependency remains.

Current maintenance orders contain estimates, not verified actual invoices, and there is no supported other-expense source. Those categories stay Not recorded; estimates never become actual costs. Completed maintenance orders awaiting an actual invoice, unverified fuel receipts, unrecorded tolls, and ongoing deliveries prevent a complete allowance/estimate comparison. Reliable starting/ending odometer evidence may support cost/km recommendations; planned distance is not substituted.

## Presentation

Four fleet KPIs: verified costs, approved allowances, net remaining/shortfall, average cost per completed delivery. The existing stacked cost/year comparison remains, with four supported expense categories. The allowance-versus-actual chart has four derived indicators: highest allowance, highest cost, lowest cost per completed delivery, highest allowance utilization. External bounded tooltips avoid clipping.

Insights use verified costs, comparable history, completed counts and recorded distance where available. Missing evidence shows Insufficient Historical Data; no revenue, margin or profitability recommendations are generated. Recent costs has expense-only columns; both detail tables retain View All.

## Validation

`php tests/cost_overview_test.php`
`php tests/cost_overview_session.php`
`node tests/cost_overview_browser_test.js`
`node tests/cost_overview_date_filter_browser_test.js`
`php tests/cost_overview_session.php --clean`

Checks cover independent fleet sums, multiple vehicles, pending/rejected/unfunded approvals, incomplete expenses, positive/negative trip balances, duplicate IDs, monthly chart reconciliation, endpoint years, no-data/future states, actual/preview separation, date-picker AJAX, View All and desktop/tablet/mobile widths.
