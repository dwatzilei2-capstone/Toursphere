BEGIN;
ALTER TABLE fuel_transactions ADD COLUMN IF NOT EXISTS receipt_filename varchar(80);
ALTER TABLE fuel_transactions ADD COLUMN IF NOT EXISTS receipt_original_name varchar(255);
ALTER TABLE fuel_transactions ADD COLUMN IF NOT EXISTS receipt_mime varchar(40);
ALTER TABLE fuel_transactions ADD COLUMN IF NOT EXISTS receipt_size integer;
COMMIT;
