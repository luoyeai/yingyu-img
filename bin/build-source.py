#!/usr/bin/env python3
"""Export reviewed source only; never walk runtime, credentials or release trees."""
from __future__ import annotations

import argparse
import hashlib
import re
from pathlib import Path
import stat
import zipfile

PROJECT = Path(__file__).resolve().parent.parent
FILES = (
    '.editorconfig', '.env.example', '.env.testing.example', '.gitattributes',
    '.gitignore', '.npmrc', 'LICENSE', 'README.md', 'artisan', 'composer.json',
    'composer.lock', 'phpunit.xml', 'bootstrap/app.php', 'bootstrap/providers.php',
    'bootstrap/preflight.php', 'bootstrap/installation.php',
    'bootstrap/update-gateway.php', 'bootstrap/update-public.pem',
    'bootstrap/update-legacy.json', 'bootstrap/cache/.gitignore',
    'public/.htaccess', 'public/index.php', 'public/robots.txt',
    'docs/deployment.md', 'docs/backup-restore.md', 'docs/installer-environment.md',
)
TREES = {
    'app': {'.php'}, 'config': {'.php'}, 'database': {'.php', '.gitignore'},
    'lang': {'.php'}, 'routes': {'.php'}, 'resources': {'.php', '.css', '.js'},
    'public/assets': {'.css', '.js', '.webp'}, 'tests': {'.php', '.cjs'},
    'deploy': {'.md', '.conf', '.example'}, 'docs/screenshots': {'.webp'},
}
SCRIPTS = (
    'php-local', 'mysql-local', 'nginx-local', 'test', 'router.php',
    'install-token.php', 'build-release.py', 'build-update.py', 'build-source.py',
)
RUNTIME_PLACEHOLDERS = (
    'app', 'app/private', 'app/public', 'framework', 'framework/views',
    'framework/testing', 'framework/sessions', 'framework/cache',
    'framework/cache/locks', 'framework/cache/data', 'logs',
)


def source_files(root: Path) -> list[Path]:
    paths = {root / name for name in FILES}
    paths.update(root / 'bin' / name for name in SCRIPTS)
    paths.update(root / 'storage' / name / '.gitignore' for name in RUNTIME_PLACEHOLDERS)
    for directory, suffixes in TREES.items():
        base = root / directory
        if base.is_symlink():
            raise RuntimeError(f'Source symlink rejected: {directory}')
        for path in base.rglob('*'):
            if path.is_symlink():
                raise RuntimeError(f'Source symlink rejected: {path.relative_to(root)}')
            if path.is_file() and (path.suffix in suffixes or path.name in suffixes):
                paths.add(path)
    for path in sorted(paths):
        if not path.is_file() or any(parent.is_symlink() for parent in [path, *path.parents] if parent != root.parent):
            raise RuntimeError(f'Expected regular source file: {path.relative_to(root)}')
    return sorted(paths)


def checked_body(path: Path, root: Path) -> bytes:
    body = path.read_bytes()
    if re.search(rb'-----BEGIN (?:RSA |EC |DSA |ENCRYPTED |OPENSSH )?PRIVATE KEY-----', body):
        raise RuntimeError(f'Private key detected: {path.relative_to(root)}; value suppressed')
    for match in re.finditer(rb'(?m)^\s*(?:APP_KEY|DB_PASSWORD|AWS_SECRET_ACCESS_KEY|OSS_ACCESS_KEY_SECRET)\s*=([^\r\n]*)', body):
        if match.group(1).strip().strip(b'\'"'):
            raise RuntimeError(f'Embedded environment secret detected: {path.relative_to(root)}; value suppressed')
    return body


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    output = args.output.absolute()
    output.parent.mkdir(parents=True, exist_ok=True)
    created = False
    try:
        with output.open('xb') as stream:
            created = True
            with zipfile.ZipFile(stream, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
                paths = source_files(PROJECT)
                for path in paths:
                    relative = path.relative_to(PROJECT).as_posix()
                    entry = zipfile.ZipInfo(relative)
                    entry.create_system = 3
                    mode = 0o755 if relative.startswith('bin/') and path.stat().st_mode & 0o111 else 0o644
                    entry.external_attr = (stat.S_IFREG | mode) << 16
                    entry.compress_type = zipfile.ZIP_DEFLATED
                    archive.writestr(entry, checked_body(path, PROJECT))
        with zipfile.ZipFile(output) as archive:
            if archive.testzip() is not None:
                raise RuntimeError('Source ZIP CRC validation failed')
    except BaseException:
        if created:
            output.unlink()
        raise
    print(f'{output}\nFiles: {len(paths)}\nSHA256: {hashlib.sha256(output.read_bytes()).hexdigest()}')


if __name__ == '__main__':
    main()
