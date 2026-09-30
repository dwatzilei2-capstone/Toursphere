BEGIN;

ALTER TABLE vehicle_documents
  ADD COLUMN IF NOT EXISTS extracted_data JSONB NOT NULL DEFAULT '{}'::jsonb,
  ADD COLUMN IF NOT EXISTS extraction_status VARCHAR(20) NOT NULL DEFAULT 'not_detected';

UPDATE vehicle_documents
   SET document_type = 'registration'
 WHERE UPPER(document_type) IN ('OR/CR', 'REGISTRATION DOCUMENT');

UPDATE vehicle_documents
   SET extracted_data = jsonb_build_object('plate_number', extracted_plate_number),
       extraction_status = 'extracted'
 WHERE extracted_plate_number IS NOT NULL
   AND extracted_data = '{}'::jsonb;

ALTER TABLE vehicle_documents
  DROP CONSTRAINT IF EXISTS vehicle_documents_extraction_status_check;
ALTER TABLE vehicle_documents
  ADD CONSTRAINT vehicle_documents_extraction_status_check
  CHECK (extraction_status IN ('extracted', 'needs_review', 'not_detected', 'test_fixture'));

CREATE INDEX IF NOT EXISTS idx_vehicle_documents_type_uploaded
  ON vehicle_documents (vehicle_id, document_type, uploaded_at DESC);

COMMIT;