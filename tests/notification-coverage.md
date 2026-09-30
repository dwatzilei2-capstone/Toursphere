Notification system coverage

The 2026_09_30_notifications.sql migration has been applied to the local database.
Apply it after the other schema migrations on another installation.

Database events (within the same transaction as the action):
- Reservations: new bookings, approval/rejection, cancellation without a trip, schedule/location/fare changes. Operations and the customer receive their own rows.
- Trips: assignment/direct dispatch/reassignment (including the previous driver), departure, return, completion, cancellation and route/schedule changes. Operations, assigned driver and reservation customer receive rows.
- Vehicles: registration, driver assignment/unassignment and maintenance changes. Operations and current/previous driver receive rows.
- Maintenance: work orders, driver issue reports and status/schedule/priority changes. Operations and the vehicle's assigned driver receive rows.
- Fuel: recorded transactions notify operations and the linked driver.
- Ratings: submitted customer ratings notify operations and the rated driver.
- Schedules: operating schedule changes notify operations.
- Compliance: uploaded/reviewed vehicle documents notify operations and the assigned driver.
- Revenue: stored route revenue changes notify operations. Reservation fare changes also notify the customer.

Read-only reports, exports, route previews/AI comparisons, availability checks and location/progress polling do not create notifications. Profile/avatar edits and login challenges use their existing account UI/email behavior.

Each row belongs to one active user. No shared unread state. Legacy broadcast rows were copied into independent operations inboxes and removed. Historic missed events are not backfilled as new notifications.
The client polls every five seconds while visible, checks again on focus and queues an eight-second rounded bell preview. Dismissing a preview does not mark it read. Each tab retains its event cursor across navigation. Opening a message marks only that recipient's row read. Read mutations require a session CSRF token.
Database timestamps are displayed in the configured company timezone. Record links select the customer reservation or trip and anchor operational records in their existing lists.

Verification: php tests/notifications_test.php uses real database transactions and rolls back test events. PHP syntax and JavaScript syntax checks passed. The browser reached the login page; authenticated visual verification requires an existing signed-in session.
