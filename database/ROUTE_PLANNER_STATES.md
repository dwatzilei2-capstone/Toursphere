# RouteThink saved route lifecycle

Run `C:\xampp\php\php.exe scripts/migrate-route-planner-state.php` when deploying these changes. The migration is idempotent and creates no sample routes or operational trip records.

The existing planner now stores generated alternatives, per-mode evaluations, selected geometry, the applied snapshot, and navigation progress in `route_planner_states`. Trip states are shared by authorized Admin, Dispatcher and assigned Driver accounts. Free planning is scoped to the current account. Outbound and return legs have separate scopes. Existing permissions continue to apply; accounts with view access cannot apply or start navigation.

Generation evaluates the same provider candidates independently for fastest ETA, shortest distance, minimum estimated fuel, and balanced normalized time/fuel/distance/cost. Different objectives may legitimately select the same road. Each selection has a generation/mode/candidate identity. The map, results, applied state and navigation use that candidate's geometry and metrics. Fuel prices and efficiency still come from existing configuration and vehicle records. Existing revenue calculations are unchanged.

Apply persists the complete snapshot. Start Navigation validates the dispatched assignment and consumes that saved snapshot rather than trusting posted preview metrics. Pre-trip route preparation likewise uses the applied distance. Reopening restores saved alternatives and navigation without requesting another route. Rerouting retains the applied objective and updates its saved geometry; original prediction/history values are preserved for accountability. Optimistic revisions prevent stale pages overwriting another account's changes.

Input/assignment changes invalidate previous planning data. Completed/cancelled trips do not restore an active planner state. Historical route history lacking complete geometry cannot be reconstructed from labels or estimates: generate and apply a complete route when valid recovery is required. No geometry or expenses are fabricated.

Validation:

- `php tests/route_planner_state_test.php`: per-mode objectives and snapshot integrity; creates an ignored test fixture under `tmp/`.
- `node tests/route_planner_lifecycle_test.js`: all four modes across Admin, Dispatcher and Driver, including restore, Apply, navigation and mode-preserving reroutes, plus read-only access.
- `php tests/route_planner_action_test.php`: unchanged endpoint execution against connection-local temporary database tables; tests authorization, stale revisions, navigation canonicalization, active-session resume and return legs.
- `php tests/assignment_http_sessions.php`, then `node tests/route_planner_browser_test.js`, then `php tests/assignment_http_sessions.php --clean`: browser clicks and module switching for all 12 mode/role combinations. Route-provider/state responses are intercepted to avoid changing real trips or requesting paid directions. This does not replace an outdoor live-GPS acceptance test.
