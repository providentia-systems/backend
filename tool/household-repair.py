"""Temporary reviewed source transfer; removed before the final pull-request head."""
from pathlib import Path
import base64
import gzip
import hashlib
import io
import json
import subprocess
import zlib

root = Path(__file__).resolve().parents[1]
encoded = ''.join((root / f'tool/household-repair-{i}.b64').read_text().strip() for i in (1, 2, 3))
assert hashlib.sha256(encoded.encode()).hexdigest() == 'ebca5e5cad45ad5494635fdfba2156af95a87aa678560c5ee15530065a9c6b7b'
payload = json.loads(zlib.decompress(base64.b64decode(encoded)))
patch = payload['patch'].encode()
reverse = subprocess.run(['git', 'apply', '--reverse', '--check'], input=patch, cwd=root, capture_output=True)
if reverse.returncode != 0:
    subprocess.run(['git', 'apply', '--check'], input=patch, cwd=root, check=True)
    subprocess.run(['git', 'apply'], input=patch, cwd=root, check=True)
for name, content in payload['files'].items():
    path = root / name
    if path.exists() and path.read_text() != content:
        raise RuntimeError('Unrecognized existing source: ' + name)
    path.write_text(content)
archive = root / 'contracts/source/providentia-v1.json.gz'
contract = json.loads(gzip.decompress(archive.read_bytes()))
for path, value in payload['edits']:
    target = contract
    for key in path[:-1]:
        target = target[key]
    target[path[-1]] = value
raw = (json.dumps(contract, ensure_ascii=False, indent=2) + '\n').encode()
assert hashlib.sha256(raw).hexdigest() == 'ef5714a6298326d6fb449b966117e8b61c74de67d1bfc274ad8ec431aecd802d'
output = io.BytesIO()
with gzip.GzipFile(filename='', mode='wb', fileobj=output, mtime=0, compresslevel=9) as stream:
    stream.write(raw)
assert hashlib.sha256(output.getvalue()).hexdigest() == 'd20ba3f9b769b5e30e59f38ecb83816ff6825a9bb646509440fb731cfc012ff1'
archive.write_bytes(output.getvalue())
(root / 'contracts/openapi/providentia-v1.json').write_bytes(raw)
