#!/usr/bin/env python3
"""Validate pinned application inputs and emit only public build identities."""
import argparse
import hashlib
import json
from pathlib import Path
import subprocess


def head(path):
    return subprocess.check_output(['git', '-C', str(path), 'rev-parse', 'HEAD'], text=True).strip()


def digest(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def record(root, matrix, role, checkout):
    expected = matrix['applications'][role]
    revision = head(checkout)
    contract = digest(checkout / 'contracts/providentia-v1.json')
    if revision != expected['revision'] or contract != expected['contractSha256']:
        raise ValueError(f'{role}: checkout revision or contract digest differs from pinned matrix')
    return {'revision': revision, 'contractSha256': contract, 'scope': expected['scope']}


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--client', type=Path)
    parser.add_argument('--admin', type=Path)
    parser.add_argument('--output', required=True, type=Path)
    args = parser.parse_args()
    root = Path(__file__).resolve().parent.parent
    matrix = json.loads((root / 'tests/fixtures/frontend-compatibility.json').read_text())
    backend_contract = root / 'contracts/openapi/providentia-v1.json'
    contract = json.loads(backend_contract.read_text())
    if contract['info']['version'] != matrix['backendContractVersion']:
        raise ValueError('Backend contract version differs from compatibility matrix')
    result = {'backend': {'revision': head(root), 'contractSha256': digest(backend_contract),
                          'contractVersion': contract['info']['version']},
              'purpose': matrix['purpose'], 'applications': {}}
    for role in ['client', 'admin']:
        checkout = getattr(args, role)
        if checkout is not None:
            result['applications'][role] = record(root, matrix, role, checkout.resolve())
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(json.dumps(result, indent=2) + '\n')
    print(json.dumps(result, indent=2))


if __name__ == '__main__':
    main()
