BEGIN;
ALTER TABLE audit_logs ADD COLUMN IF NOT EXISTS actor_name text;
ALTER TABLE audit_logs ADD COLUMN IF NOT EXISTS actor_email text;
ALTER TABLE audit_logs ADD COLUMN IF NOT EXISTS actor_role text;
ALTER TABLE audit_logs ADD COLUMN IF NOT EXISTS old_values jsonb;
ALTER TABLE audit_logs ADD COLUMN IF NOT EXISTS new_values jsonb;
CREATE INDEX IF NOT EXISTS idx_audit_created_id ON audit_logs(created_at DESC,id DESC);

CREATE OR REPLACE FUNCTION toursphere_audit_actor() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.user_id IS NULL THEN
        NEW.user_id := NULLIF(current_setting('toursphere.actor_id', true),'')::integer;
    END IF;
    SELECT u.name,u.email,r.name INTO NEW.actor_name,NEW.actor_email,NEW.actor_role
      FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=NEW.user_id;
    RETURN NEW;
END $$;
DROP TRIGGER IF EXISTS audit_actor_snapshot ON audit_logs;
CREATE TRIGGER audit_actor_snapshot BEFORE INSERT ON audit_logs FOR EACH ROW EXECUTE FUNCTION toursphere_audit_actor();

-- Historical identity is only recoverable from the current account; do not invent past roles.
CREATE OR REPLACE FUNCTION toursphere_audit_change() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE before_row jsonb; after_row jsonb; record_id text; event_name text;
BEGIN
    IF TG_OP <> 'INSERT' THEN before_row := to_jsonb(OLD); END IF;
    IF TG_OP <> 'DELETE' THEN after_row := to_jsonb(NEW); END IF;
    -- Credentials and verification secrets must never enter the audit trail.
    before_row := before_row - ARRAY['password_hash','token_hash','code_hash','otp_hash','secret','token'];
    after_row := after_row - ARRAY['password_hash','token_hash','code_hash','otp_hash','secret','token'];
    IF TG_OP='UPDATE' AND before_row=after_row THEN
        IF TG_TABLE_NAME='users' AND to_jsonb(OLD)->>'password_hash' IS DISTINCT FROM to_jsonb(NEW)->>'password_hash' THEN
            INSERT INTO audit_logs(action,entity_type,entity_id,details) VALUES('ACCOUNT_CREDENTIAL_CHANGED','users',after_row->>'id','Credential changed; secret values are excluded.');
        END IF;
        RETURN NEW;
    END IF;
    record_id := COALESCE(after_row->>'id',before_row->>'id');
    event_name := upper(TG_TABLE_NAME || '_' || TG_OP);
    IF TG_OP='UPDATE' AND before_row->>'status' IS DISTINCT FROM after_row->>'status' THEN
        event_name := upper(TG_TABLE_NAME || '_STATUS_CHANGED');
    END IF;
    IF TG_OP='UPDATE' AND before_row->>'is_archived' IS DISTINCT FROM after_row->>'is_archived' THEN
        event_name := upper(TG_TABLE_NAME || CASE WHEN after_row->>'is_archived'='true' THEN '_ARCHIVED' ELSE '_RESTORED' END);
    END IF;
    INSERT INTO audit_logs(action,entity_type,entity_id,details,old_values,new_values)
      VALUES(event_name,TG_TABLE_NAME,record_id,jsonb_build_object('operation',TG_OP,'module',TG_TABLE_NAME)::text,before_row,after_row);
    RETURN COALESCE(NEW,OLD);
END $$;
DO $$ DECLARE table_name text; BEGIN
    FOREACH table_name IN ARRAY ARRAY['reservations','trips','vehicles','drivers','driver_ratings','maintenance_orders','fuel_transactions','vehicle_documents','vehicle_photos','vehicle_cost_ledger','users','roles','role_permissions','system_settings','operating_schedules','operating_schedule_days','scheduled_departures'] LOOP
        IF to_regclass('public.' || table_name) IS NOT NULL THEN
            EXECUTE format('DROP TRIGGER IF EXISTS toursphere_audit_change ON %I',table_name);
            EXECUTE format('CREATE TRIGGER toursphere_audit_change AFTER INSERT OR UPDATE OR DELETE ON %I FOR EACH ROW EXECUTE FUNCTION toursphere_audit_change()',table_name);
        END IF;
    END LOOP;
END $$;
CREATE OR REPLACE FUNCTION toursphere_audit_readonly() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'Audit records are append-only';
END $$;
DROP TRIGGER IF EXISTS audit_readonly ON audit_logs;
CREATE TRIGGER audit_readonly BEFORE UPDATE OR DELETE OR TRUNCATE ON audit_logs FOR EACH STATEMENT EXECUTE FUNCTION toursphere_audit_readonly();
COMMIT;
