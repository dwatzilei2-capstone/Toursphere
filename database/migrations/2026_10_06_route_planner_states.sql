BEGIN;
CREATE TABLE IF NOT EXISTS route_planner_states (
 scope_key varchar(100) PRIMARY KEY,
 trip_id varchar(50) REFERENCES trips(id) ON DELETE CASCADE,
 user_id integer NOT NULL REFERENCES users(id),
 phase varchar(10) NOT NULL CHECK(phase IN ('outbound','return')),
 lifecycle varchar(12) NOT NULL CHECK(lifecycle IN ('GENERATED','APPLIED','NAVIGATING')),
 revision integer NOT NULL DEFAULT 1,
 state_data jsonb NOT NULL,
 updated_at timestamp NOT NULL DEFAULT clock_timestamp()
);
COMMIT;
