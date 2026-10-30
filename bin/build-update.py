#!/usr/bin/env python3
"""Derive a legacy bridge and an authenticated update from a verified full release."""
from __future__ import annotations

import argparse
import base64
import datetime as dt
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import stat
import subprocess
import tempfile
import zipfile

PROJECT = Path(__file__).resolve().parent.parent


def digest(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def info(name: str, timestamp: tuple[int, int, int, int, int, int]) -> zipfile.ZipInfo:
    entry = zipfile.ZipInfo(name, timestamp)
    entry.create_system = 3
    entry.external_attr = (stat.S_IFREG | 0o644) << 16
    entry.compress_type = zipfile.ZIP_DEFLATED
    return entry


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('full_release', type=Path)
    parser.add_argument('--key', type=Path, required=True, help='Local signing key, never copied or displayed.')
    parser.add_argument('--from-min', default='0.0.0')
    parser.add_argument('--from-max', default='0.0.0')
    parser.add_argument('--release-notes', default='')
    parser.add_argument('--output-dir', type=Path, default=PROJECT / 'release')
    args = parser.parse_args()
    if args.key.is_symlink() or stat.S_IMODE(args.key.stat().st_mode) != 0o600:
        raise RuntimeError('Signing key must be a regular private file with permissions 0600.')
    with zipfile.ZipFile(args.full_release) as source:
        if source.testzip():
            raise RuntimeError('Full release CRC failed.')
        files = {}
        for entry in source.infolist():
            if entry.is_dir():
                continue
            if not entry.filename.startswith('image-vault/'):
                raise RuntimeError('Unexpected full release prefix.')
            relative = entry.filename.removeprefix('image-vault/')
            if stat.S_IMODE(entry.external_attr >> 16) != 0o644:
                raise RuntimeError('Full release file permissions must be 0644.')
            if relative == '.env' or relative.startswith(('storage/', 'public/media/', '.tools/')):
                raise RuntimeError('Runtime content is not allowed.')
            files[relative] = source.read(entry)
    release = json.loads(files['release.json'])
    release_date = dt.date.fromisoformat(json.loads(files['BUILD.json'])['release_date'])
    timestamp = (release_date.year, release_date.month, release_date.day, 0, 0, 0)
    manifest = {'format': 1, 'program': release['program'], 'schema': release['schema'],
                'release_notes': args.release_notes or '首次接入签名更新：自动识别既有迁移基线，增加后台上传检查与确认、完整备份、分阶段执行及独立受保护恢复入口。保留既有管理员、配置、图片和链接。',
                'compatible': {'min': args.from_min, 'max': args.from_max}, 'php_min': '8.4.1',
                'media_migrations': False,
                'files': {name: {'sha256': digest(body), 'size': len(body), 'mode': 0o644}
                          for name, body in sorted(files.items())}}
    body = (json.dumps(manifest, ensure_ascii=False, separators=(',', ':')) + '\n').encode()
    with tempfile.TemporaryDirectory(prefix='vault-sign-') as temporary:
        root = Path(temporary)
        message = root / 'manifest.json'
        signature = root / 'signature'
        message.write_bytes(body)
        subprocess.run(['openssl', 'dgst', '-sha256', '-sign', str(args.key), '-out', str(signature), str(message)],
                       check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        subprocess.run(['openssl', 'dgst', '-sha256', '-verify', str(PROJECT / 'bootstrap/update-public.pem'),
                        '-signature', str(signature), str(message)], check=True,
                       stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        signed = signature.read_bytes()
    args.output_dir.mkdir(parents=True, exist_ok=True)
    legacy = args.from_min == args.from_max == '0.0.0'
    update = args.output_dir / f'image-vault-{release["program"]}-{"legacy-" if legacy else ""}signed-update.zip'
    bridge = args.output_dir / f'image-vault-{release["program"]}-legacy-transition.zip'
    destinations = (update, bridge) if legacy else (update,)
    for destination in destinations:
        if destination.exists() or destination.is_symlink():
            raise RuntimeError('Refusing to overwrite an existing update artifact.')
    with tempfile.TemporaryDirectory(prefix='.vault-update-', dir=args.output_dir) as temporary:
        staged = Path(temporary)
        with zipfile.ZipFile(staged / update.name, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
            archive.writestr(info('manifest.json', timestamp), body)
            archive.writestr(info('signature.txt', timestamp), base64.b64encode(signed) + b'\n')
            for name, content in sorted(files.items()):
                archive.writestr(info('payload/' + name, timestamp), content)
        if legacy:
            with zipfile.ZipFile(staged / bridge.name, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
                directories = {'image-vault/'}
                for name in files:
                    directories.update(parent.as_posix()+'/' for parent in (Path('image-vault') / name).parents if parent != Path('.'))
                for name in sorted(directories):
                    entry = info(name, timestamp)
                    entry.external_attr = ((stat.S_IFDIR | 0o755) << 16) | 0x10
                    archive.writestr(entry, b'')
                for name, content in sorted(files.items()):
                    archive.writestr(info('image-vault/' + name, timestamp), content)
        for destination in destinations:
            with zipfile.ZipFile(staged / destination.name) as archive:
                if archive.testzip():
                    raise RuntimeError('Artifact CRC validation failed.')
                if any(stat.S_IMODE(entry.external_attr >> 16) != (0o755 if entry.is_dir() else 0o644) for entry in archive.infolist()):
                    raise RuntimeError('Artifact permissions failed.')
        for destination in destinations:
            os.link(staged / destination.name, destination)
    for destination in destinations:
        print(json.dumps({'artifact': str(destination), 'bytes': destination.stat().st_size,
                          'sha256': digest(destination.read_bytes()), 'program': release['program'],
                          'applicable': 'recognized legacy-20261028 only' if legacy else f'{args.from_min} through {args.from_max}'}, ensure_ascii=False))
    checksum = args.output_dir / 'UPDATE-SHA256SUMS'
    known = {}
    if checksum.exists():
        for line in checksum.read_text().splitlines():
            value, _, name = line.partition('  ')
            known[name] = value
    for path in (args.full_release, *destinations):
        known[path.name] = digest(path.read_bytes())
    checksum.write_text(''.join(f'{value}  {name}\n' for name, value in sorted(known.items())))


if __name__ == '__main__':
    main()
