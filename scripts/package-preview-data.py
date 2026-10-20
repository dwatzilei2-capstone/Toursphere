"""Bundle runtime preview fixtures and generators alongside the private SQL export."""
import hashlib, json, pathlib, zipfile
root=pathlib.Path(__file__).resolve().parents[1]
folder=sorted((root/'storage/private').glob('latest-database-*'))[-1]
paths=[
 'database/preview/cost-by-vehicle.json',
 'database/preview/cost-overview.json',
 'database/preview/cost-trends-cases.json',
 'includes/dashboard-operations-samples.php',
 'modules/driver-trip-performance/trip-performance-preview.php',
]
note='''PREVIEW / ISOLATED DATA — DEPLOYMENT

1. Import latest-full.sql.gz into an empty PostgreSQL public schema.
2. Deploy the latest application code from the current workspace.
3. Extract preview-data.zip INTO THE APPLICATION ROOT, keeping folder paths.
   Do not import the ZIP or JSON/PHP files into the database.

Included:
- Cost Analysis: three JSON fixture files in database/preview/.
- Dashboard: generator for 20 calendar examples and dense fictional trend data.
- Trip Performance: read-only generator for 40 isolated sample trips,
  28 finalized evaluations and 6 sample drivers in temporary tables.

Preview links after signing in with an authorized staff account:
dashboard.php?sample=1
modules/cost-analysis/cost-by-vehicle.php?source=preview
modules/cost-analysis/cost-overview.php?source=preview
modules/cost-analysis/cost-trends.php?source=preview
modules/driver-trip-performance/trip-performance-preview.php

These fixtures/generators accompany the DATABASE export. They intentionally
remain isolated from actual operational tables and totals. They require the
latest application code and are not a standalone website installation.
Tests/fixtures are automated test inputs, not deployable preview datasets.
Private uploaded photos, receipts and evidence are not contained in this ZIP.
'''
(folder/'PREVIEW_SETUP.txt').write_text(note,encoding='utf-8')
with zipfile.ZipFile(folder/'preview-data.zip','w',compression=zipfile.ZIP_DEFLATED,compresslevel=9) as archive:
 for path in paths:
  full=root/path
  if path.endswith('.json'): json.loads(full.read_text(encoding='utf-8-sig'))
  archive.write(full,path)
 archive.writestr('PREVIEW_SETUP.txt',note)
with zipfile.ZipFile(folder/'preview-data.zip') as archive:
 if archive.testzip(): raise RuntimeError('ZIP integrity check failed')
 for path in paths:
  if hashlib.sha256(archive.read(path)).digest()!=hashlib.sha256((root/path).read_bytes()).digest(): raise RuntimeError('File mismatch')
manifest=json.loads((folder/'manifest.json').read_text())
manifest['preview_package']={'file':'preview-data.zip','bytes':(folder/'preview-data.zip').stat().st_size,'files':paths,'integrity_verified':True,'isolated_from_operational_tables':True}
(folder/'manifest.json').write_text(json.dumps(manifest,indent=2))
print(json.dumps(manifest['preview_package'],indent=2))
