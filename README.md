# Toursphere — Fleet & Transportation Management (PHP + PostgreSQL/Supabase)

This is a plain PHP 8+ application using PDO with PostgreSQL. The schema and
sample data are in `database/fleetcore.sql` and are compatible with PostgreSQL
15+ and the Supabase SQL Editor.

## Sample accounts

All sample accounts use the password `password123`.

| Role | Email |
|---|---|
| Fleet Administrator | `a.ramirez@toursphere.com` |
| Fleet Manager | `manager.fleet@toursphere.com` |
| Dispatcher | `dispatch.ops@toursphere.com` |
| Driver | `d.santos@toursphere.com` |

## Run locally with PostgreSQL

1. Install PostgreSQL 15 or newer and create a database named `fleetcore`.
2. In `C:\xampp\php\php.ini`, enable `extension=pdo_pgsql` and
   `extension=pgsql` by removing the leading semicolon. Restart Apache.
3. Import `database/fleetcore.sql` using pgAdmin Query Tool, or run:
   `psql -U postgres -d fleetcore -f database/fleetcore.sql`
4. Copy `.env.example` to `.env`, set your PostgreSQL password, and keep
   `DB_SSLMODE=prefer` for a local database.
5. Start Apache in XAMPP and open `http://localhost/fleet`.

MySQL does not need to be started for this application.

## Connect to Supabase

1. Create a Supabase project.
2. Open the Supabase SQL Editor, paste all of `database/fleetcore.sql`, and run it.
   Warning: the schema file intentionally replaces existing Toursphere tables
   so that a fresh import is consistent.
3. In Project Settings > Database, copy the connection details into `.env`.
   Use the Session Pooler values when your local network has no IPv6 support.
4. Set `DB_SSLMODE=require`, restart Apache, then open the application.

Use the PostgreSQL database password/connection details, not the Supabase anon
key. Never commit the real `.env` file.

## Main project paths

- `config/database.php` — PostgreSQL PDO connection
- `database/fleetcore.sql` — complete schema and seed data
- `database/migrations/` — optional incremental PostgreSQL migrations
- `actions/` — server-side form and workflow handlers
- `modules/` — application modules
