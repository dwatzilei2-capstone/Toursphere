"""Package a pg_dump INSERT export into independently importable gzip parts."""
import gzip, hashlib, json, pathlib, re

root = pathlib.Path(__file__).resolve().parents[1]
folder = sorted((root / 'storage/private').glob('latest-database-*'))[-1]
source = folder / 'latest.sql'
sql = source.read_text(encoding='utf-8')
# psql-only commands are not accepted by web SQL importers.
sql = re.sub(r'^\\(?:un)?restrict .*\n', '', sql, flags=re.M)
sql = re.sub(r'^SET transaction_timeout = 0;\n', '', sql, flags=re.M)
sql = sql.replace('CREATE SCHEMA public;', 'CREATE SCHEMA IF NOT EXISTS public;')

def statements(text):
    start = i = 0
    quote = None
    dollar = None
    line_comment = False
    block = 0
    while i < len(text):
        ch = text[i]
        nxt = text[i:i+2]
        if line_comment:
            if ch == '\n': line_comment = False
        elif block:
            if nxt == '/*': block += 1; i += 1
            elif nxt == '*/': block -= 1; i += 1
        elif dollar:
            if text.startswith(dollar, i): i += len(dollar)-1; dollar = None
        elif quote:
            if ch == quote:
                if i+1 < len(text) and text[i+1] == quote: i += 1
                else: quote = None
        elif nxt == '--': line_comment = True; i += 1
        elif nxt == '/*': block = 1; i += 1
        elif ch in "'\"": quote = ch
        elif ch == '$':
            match = re.match(r'\$(?:[A-Za-z_][A-Za-z_0-9]*)?\$', text[i:])
            if match: dollar = match.group(); i += len(dollar)-1
        elif ch == ';':
            yield text[start:i+1]+'\n'; start = i+1
        i += 1
    if quote or dollar or block: raise RuntimeError('Unterminated SQL statement')
    if text[start:].strip(): yield text[start:]+'\n'

header = "-- TourSphere latest database. Import numbered parts in order into an EMPTY database.\nBEGIN;\nSET standard_conforming_strings = on;\nSELECT pg_catalog.set_config('search_path', '', false);\n"
tail = '\nCOMMIT;\n'
limit = 990_000
parts = []
current = ''
def compressed(body): return gzip.compress((header+body+tail).encode(), compresslevel=9, mtime=0)
def save(body):
    data = compressed(body)
    if len(data) >= limit: raise RuntimeError('A single SQL statement exceeds the upload limit')
    name = f'part_{len(parts)+1:03d}.sql.gz'
    (folder/name).write_bytes(data)
    parts.append({'file': name, 'bytes': len(data), 'sha256': hashlib.sha256(data).hexdigest()})

# Bound both compressed upload and uncompressed request sizes. Never split a statement.
for statement in statements(sql):
    candidate = current+statement
    if current and (len(candidate.encode()) > 900_000):
        save(current); current = statement
    else: current = candidate
if current: save(current)
full = gzip.compress(sql.encode(), compresslevel=9, mtime=0)
(folder/'latest-full.sql.gz').write_bytes(full)
(folder/'latest.sql').write_text(sql, encoding='utf-8', newline='')
manifest = {'created_from': 'Current local public schema and all its table data', 'contains_sensitive_account_data': True, 'full_compressed_bytes': len(full), 'upload_limit_bytes': 1_000_000, 'parts': parts, 'restore_verified': False}
(folder/'manifest.json').write_text(json.dumps(manifest, indent=2))
(folder/'IMPORT_ORDER.txt').write_text('BACK UP THE DEPLOYMENT DATABASE FIRST.\nTarget: PostgreSQL EMPTY public schema, not MySQL/phpMyAdmin.\nImport numbered .sql.gz files ONE AT A TIME in the listed order. Wait for success before continuing.\nDo not import latest-full.sql.gz as well as the parts. The full file is an alternative.\nIf the web editor does not accept gzip, extract SQL locally and use its SQL editor or psql.\nDo not import latest.sql over an existing populated schema.\nThis export contains account hashes, settings and existing local sample/test records. Keep private.\nDatabase exports do NOT include uploaded photos, receipts, PODs or other filesystem files. Transfer required private uploads separately.\n\n'+ '\n'.join(f"{p['file']} — {p['bytes']:,} bytes" for p in parts)+'\n', encoding='utf-8')
print(json.dumps({'folder':str(folder),'parts':len(parts),'largest_part':max(p['bytes'] for p in parts),'full_gzip_bytes':len(full)}))
