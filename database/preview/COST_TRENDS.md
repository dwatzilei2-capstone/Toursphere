# TCAO — Cost Trends

Cost Trends uses its own page, read-only backend (`includes/cost_trends.php`), table fragment, CSS/JS, tests, and a four-case supplemental JSON fixture. Other TCAO modules and existing operational data are not changed.

## Isolated preview

The preview reads the existing **90 Cost by Vehicle delivery-cost records** directly through `vc_preview()`. Their amounts and vehicle IDs are unchanged. Thus category and main-chart totals reconcile with Cost by Vehicle for matching source dates. Cost Overview has a separate existing fixture and is not silently mixed into this one.

`cost-trends-cases.json` adds four clearly labeled module-only cases: two completed deliveries awaiting expenses, one in-progress delivery with approved funding, and one cancelled delivery. No additional verified expense amounts are invented. Cancelled entries are excluded everywhere; pending entries appear only in the funding table and never contribute to completed-delivery analytics. All dates are 2025–2026; future dates are excluded. No importer or operational inserts exist.

## Date controls

- **Yearly:** only Year 1 and Year 2 control the dataset; January–December are aligned, with blue for Year 1 and green for Year 2. From/To are disabled. Intervening years are excluded. Cost categories and efficiency display Year 2, clearly labeled. Same-year selection shows one series without a fabricated comparison.
- **Monthly:** From/To control the full selected range, inclusively; year selectors are disabled.
- Missing expenses are null, never fabricated zero lines. Missing comparison years are identified. Percent differences require both cost values and a nonzero baseline. Recorded zero expenses remain valid zero amounts.

## Financial calculations

Money is aggregated as integer centavos and converted to pesos only for charts/display. Live data uses current cargo vehicle/delivery relationships. Verified fuel must match both trip and vehicle. Tolls are recorded delivery amounts. Maintenance has only estimated_cost in the current schema: estimates are excluded from actual expenses. No supported other-expense table is fabricated.

Main and category charts aggregate known verified costs on completed deliveries. Fuel, maintenance, toll and other stacks sum exactly to the matching main-chart month. Live maintenance/other remain unavailable until backed by actual records.

Efficiency requires completed deliveries with verified fuel and recorded tolls and no known pending expense evidence. Completed maintenance orders without a verified actual invoice and fuel receipts awaiting review mark expenses incomplete. Cost per completed delivery divides costs of only eligible deliveries by their count. Cost per kilometer further restricts both costs and denominator to deliveries with valid recorded actual distance. Planned route distance is not substituted for actual odometer evidence. Missing denominators are unavailable, not zero efficiency.

Funding uses the latest confirmed allowance and its associated estimate once per delivery; unfunded deliveries may display their latest estimate. Approved allowance is never actual expenditure or revenue. Remaining/shortfall is calculated only for sufficiently complete completed deliveries with approved funding. Positive unused budget does not prove money was returned. Over Allowance is a negative balance; Under Allowance is a positive balance greater than 5% of allowance; Within Allowance is a nonnegative balance in the final 5%. This is a display classification, not an approval or transaction rule. Incomplete expenses show Pending Expense Recording and unavailable balances; missing approval shows Comparison Unavailable.

## Pagination and presentation

The server slices the filtered, deduplicated dataset, sorting date descending then trip ID descending. Default page size is 10; 20/50 are supported. Main filter submissions reset the page, while table paging and page-size changes fetch only the funding-table fragment. Chart instances and metric selection are preserved. The efficiency visualization remains one pure vertical bar dataset, with no secondary axes or revenue dependencies.

Tooltips use a separate bounded overlay to avoid canvas/card clipping. Responsive layouts were checked at 320–1536px.

## Validation

`php tests/cost_trends_test.php`
`php tests/cost_trends_session.php`
`node tests/cost_trends_browser_test.js`
`php tests/cost_trends_session.php --clean`

Browser validation uses the existing Playwright/Edge runtime and a temporary session for an existing administrator. It checks live/preview isolation, missing/same/future-year states, monthly boundaries, pure-bar metric switching, 10/20/50-row server pagination without chart reload, responsive layouts, and unchanged operational-table row counts.
