#!/usr/bin/env python3
"""Build a clean, dependency-bundled deployment ZIP; never read runtime data into it."""
from __future__ import annotations

import argparse
import datetime as dt
import hashlib
import json
import os
import re
from pathlib import Path, PurePosixPath
import shutil
import stat
import subprocess
import tempfile
import zipfile

PROJECT = Path(__file__).resolve().parent.parent
TREES = {
    'app': {'.php'}, 'config': {'.php'}, 'lang': {'.php'},
    'database/migrations': {'.php'}, 'resources/views': {'.php'},
    'routes': {'.php'}, 'public/assets': {'.css', '.js', '.webp'},
}
FILES = [
    '.env.example', 'artisan', 'composer.json', 'composer.lock',
    'bootstrap/app.php', 'bootstrap/providers.php', 'bootstrap/preflight.php',
    'bootstrap/installation.php', 'bootstrap/update-gateway.php', 'bootstrap/update-public.pem',
    'bootstrap/update-legacy.json', 'public/index.php',
    'public/robots.txt', 'deploy/nginx.conf.example', 'deploy/baota-rewrite.conf', 'docs/deployment.md',
    'docs/backup-restore.md', 'docs/installer-environment.md',
]
EMPTY_DIRS = [
    'bootstrap/cache', 'public/media', 'storage/app/tmp', 'storage/app/previews',
    'storage/app/originals', 'storage/app/disabled', 'storage/app/installation',
    'storage/framework/sessions', 'storage/framework/views',
    'storage/framework/cache/data', 'storage/framework/cache/locks', 'storage/logs',
]
BAD_NAMES = {'.DS_Store', 'auth.json', 'credentials.json', 'owner-token', 'installed.json',
             'journal.json', 'pending.env', 'environment.backup', '.env', '.env.testing'}
BAD_PARTS = {'.tools', 'node_modules', '__MACOSX', '.git', '.github', '.idea', '.vscode', '__pycache__'}
BAD_SUFFIXES = {'.so', '.dylib', '.dll', '.exe', '.o', '.a', '.pyc', '.log', '.bak'}
NATIVE_MAGIC = {b'\x7fELF', b'\xcf\xfa\xed\xfe', b'\xfe\xed\xfa\xcf', b'\xca\xfe\xba\xbe', b'\xce\xfa\xed\xfe', b'\xfe\xed\xfa\xce'}


def sha(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def run(args: list[str], cwd: Path, env: dict[str, str]) -> str:
    result = subprocess.run(args, cwd=cwd, env=env, text=True, stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
    if result.returncode:
        raise RuntimeError(f'Build command failed ({args[1:3]}):\n{result.stdout}')
    return result.stdout


def copy_file(source: Path, destination: Path) -> None:
    if source.is_symlink() or not source.is_file():
        raise RuntimeError(f'Expected ordinary source file: {source.relative_to(PROJECT)}')
    destination.parent.mkdir(parents=True, exist_ok=True)
    shutil.copyfile(source, destination)


def verify_tree(root: Path, lock: dict) -> dict:
    metadata = json.loads((root / 'vendor/composer/installed.json').read_text())
    installed = {item['name']: item['version'] for item in metadata['packages']}
    expected = {item['name']: item['version'] for item in lock['packages']}
    if installed != expected or metadata.get('dev') is not False:
        raise RuntimeError('Production vendor does not exactly match lock packages / no-dev metadata.')
    forbidden_packages = {item['name'] for item in lock['packages-dev']}
    if forbidden_packages & installed.keys():
        raise RuntimeError('Development dependency included.')
    for relative in EMPTY_DIRS:
        if not (root / relative).is_dir() or any((root / relative).iterdir()):
            raise RuntimeError(f'Runtime directory must be empty: {relative}')
    for path in root.rglob('*'):
        relative = path.relative_to(root)
        if path.is_symlink():
            raise RuntimeError(f'Symlink rejected: {relative}')
        if (path.name in BAD_NAMES and relative.as_posix() != 'vendor/composer/installed.json') or any(part in BAD_PARTS for part in relative.parts) or path.suffix.lower() in BAD_SUFFIXES:
            raise RuntimeError(f'Excluded file in package: {relative}')
        if not path.is_file():
            continue
        if relative.parts[0] != 'vendor' and any(part.lower() in {'tests', 'test', 'factories', 'backups'} for part in relative.parts):
            raise RuntimeError(f'Development/runtime data included: {relative}')
        body = path.read_bytes()
        if body[:4] in NATIVE_MAGIC or body[:2] == b'MZ':
            raise RuntimeError(f'Native platform binary rejected: {relative}')
        if re.search(rb'-----BEGIN (?:RSA |EC |ENCRYPTED |OPENSSH )?PRIVATE KEY-----', body):
            raise RuntimeError(f'Private key material detected in {relative}; no value printed.')
        for match in re.finditer(rb'(?m)^\s*(?:APP_KEY|DB_PASSWORD|AWS_SECRET_ACCESS_KEY)\s*=([^\r\n]*)', body):
            value = match.group(1).strip().strip(b'\'"')
            if value:
                raise RuntimeError(f'Embedded environment secret detected in {relative}; no value printed.')
        if re.search(rb'/(?:Volumes|Users)/', body):
            raise RuntimeError(f'Local machine path detected: {relative}')
    return installed


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', default=str(PROJECT / 'bin/php-local'), help='Build-host PHP executable or wrapper with required extensions.')
    parser.add_argument('--composer', default=str(PROJECT / '.tools/composer'), help='Build-host Composer PHAR; never included in ZIP.')
    parser.add_argument('--output-dir', default=str(PROJECT / 'release'))
    parser.add_argument('--date', default=dt.datetime.now(dt.timezone(dt.timedelta(hours=8))).date().isoformat())
    parser.add_argument('--version', default='1.0.0', help='Program version recorded separately from schema migrations.')
    parser.add_argument('--variant', default='', help='Optional lowercase release suffix, for example installer-ui1.')
    args = parser.parse_args()
    if args.variant and not re.fullmatch(r'[a-z][a-z0-9]*(?:-[a-z0-9]+)*', args.variant):
        parser.error('variant must be a lowercase hyphen-separated identifier')
    if not re.fullmatch(r'(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)', args.version):
        parser.error('version must be a stable semantic version')
    release_date = dt.date.fromisoformat(args.date)
    php, composer = str(Path(args.php).resolve()), str(Path(args.composer).resolve())
    output = Path(args.output_dir).resolve()
    output.mkdir(parents=True, exist_ok=True)
    suffix = f'-{args.variant}' if args.variant else ''
    archive = output / f'image-vault-{release_date:%Y%m%d}{suffix}-production.zip'
    if archive.exists():
        raise RuntimeError('Refusing to overwrite an existing release archive; choose another output directory.')
    lock = json.loads((PROJECT / 'composer.lock').read_text())
    with tempfile.TemporaryDirectory(prefix='image-vault-release-build-') as temporary:
        working = Path(temporary)
        root = working / 'image-vault'
        root.mkdir()
        for relative, suffixes in TREES.items():
            for source in sorted((PROJECT / relative).rglob('*')):
                if source.is_symlink():
                    raise RuntimeError(f'Source symlink rejected: {source.relative_to(PROJECT)}')
                if source.is_file() and source.suffix.lower() in suffixes:
                    copy_file(source, root / source.relative_to(PROJECT))
        for relative in FILES:
            copy_file(PROJECT / relative, root / relative)
        copy_file(PROJECT / 'deploy/package-readme.md', root / 'README.md')
        for relative in EMPTY_DIRS:
            (root / relative).mkdir(parents=True, exist_ok=True)
        env = {key: value for key, value in os.environ.items()
               if not key.startswith(('APP_', 'DB_', 'LOG_', 'SESSION_', 'CACHE_', 'QUEUE_', 'IMAGE_', 'MAIL_', 'COMPOSER_'))}
        cache = run([php, composer, 'config', 'cache-dir'], PROJECT, env).strip()
        env.update({'COMPOSER_HOME': str(working / 'composer-home'), 'COMPOSER_CACHE_DIR': cache,
                    'COMPOSER_NO_INTERACTION': '1', 'COMPOSER_ALLOW_SUPERUSER': '0'})
        print('Installing locked production vendor in isolated staging...', flush=True)
        run([php, composer, 'install', '--no-dev', '--prefer-dist', '--no-interaction',
                           '--no-scripts', '--no-plugins', '--optimize-autoloader', '--no-progress'], root, env)
        platform_log = run([php, composer, 'check-platform-reqs', '--no-dev'], root, env)
        run([php, 'artisan', 'package:discover', '--no-interaction'], root, env)
        for path in (root / 'bootstrap/cache').iterdir():
            if path.is_file() and path.name in {'packages.php', 'services.php'}:
                path.unlink()
            else:
                raise RuntimeError('Unexpected generated bootstrap cache.')
        program = ('require "vendor/autoload.php"; require "bootstrap/preflight.php"; '
                   'foreach(vault_preflight_checks(getcwd()) as $c){if(!$c["ok"]){exit(2);}} '
                   '$s=(new App\\Services\\Installation\\InstallationState(getcwd()))->inspect(); '
                   'if($s["status"]!=="fresh"){exit(3);} echo PHP_VERSION;')
        php_version = run([php, '-r', program], root, env).strip()
        omitted = []
        # Symfony's Windows-only password helper is not used on the Linux target.
        for relative in ['vendor/symfony/console/Resources/bin/hiddeninput.exe']:
            optional = root / relative
            if optional.is_file():
                optional.unlink()
                omitted.append(relative)
        omitted_ci = []
        # Exact upstream CI-only metadata; keep SDK runtime, licenses and test helpers.
        for relative in [
            'vendor/aliyuncs/oss-sdk-php/.github/workflows/main.yml',
            'vendor/aliyuncs/oss-sdk-php/.github/workflows/release.yml',
            'vendor/aliyuncs/oss-sdk-php/.coveralls.yml',
            'vendor/aliyuncs/oss-sdk-php/.travis.yml',
        ]:
            metadata = root / relative
            if metadata.is_file() and not metadata.is_symlink():
                metadata.unlink()
                omitted_ci.append(relative)
        for relative in ['vendor/aliyuncs/oss-sdk-php/.github/workflows', 'vendor/aliyuncs/oss-sdk-php/.github']:
            directory = root / relative
            if directory.is_dir() and not any(directory.iterdir()):
                directory.rmdir()
        installed = verify_tree(root, lock)
        build = {'release_date': release_date.isoformat(), 'variant': args.variant, 'php_build_version': php_version,
                 'composer_lock_sha256': sha(PROJECT / 'composer.lock'), 'dependencies': 'locked production only; no-dev',
                 'packages': installed, 'runtime_data': 'empty; no env/token/installed lock', 'target': 'Linux PHP 8.4',
                 'omitted_optional_platform_files': omitted, 'omitted_upstream_ci_files': omitted_ci}
        (root / 'BUILD.json').write_text(json.dumps(build, ensure_ascii=False, indent=2) + '\n')
        managed = sorted([p.relative_to(root).as_posix() for p in root.rglob('*') if p.is_file()] + ['release.json', 'FILES.sha256'])
        (root / 'release.json').write_text(json.dumps({'program': args.version,
            'schema': sorted(p.stem for p in (root / 'database/migrations').glob('*.php')),
            'managed_files': managed, 'update_protocol': 1,
            'media_migrations': False}, ensure_ascii=False, indent=2) + '\n')
        manifest = ''.join(f'{sha(p)}  {p.relative_to(root).as_posix()}\n' for p in sorted(root.rglob('*')) if p.is_file())
        (root / 'FILES.sha256').write_text(manifest)
        verify_tree(root, lock)
        for path in [root, *root.rglob('*')]:
            mode = 0o700 if path.is_dir() and path.relative_to(root).parts[:1] == ('storage',) else 0o755 if path.is_dir() else 0o644
            path.chmod(mode)
        timestamp = (release_date.year, release_date.month, release_date.day, 0, 0, 0)
        temporary_archive = output / (archive.name + '.partial')
        try:
            with zipfile.ZipFile(temporary_archive, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as bundle:
                for path in [root, *sorted(root.rglob('*'))]:
                    name = path.relative_to(working).as_posix() + ('/' if path.is_dir() else '')
                    info = zipfile.ZipInfo(name, timestamp)
                    info.create_system = 3
                    info.external_attr = (path.stat().st_mode & 0xFFFF) << 16
                    if path.is_dir():
                        info.external_attr |= 0x10
                    info.compress_type = zipfile.ZIP_DEFLATED
                    bundle.writestr(info, b'' if path.is_dir() else path.read_bytes(), compresslevel=9)
            with zipfile.ZipFile(temporary_archive) as bundle:
                if bundle.testzip() is not None:
                    raise RuntimeError('ZIP CRC check failed.')
                for info in bundle.infolist():
                    member = PurePosixPath(info.filename)
                    expected = 0o700 if info.is_dir() and len(member.parts) > 1 and member.parts[1] == 'storage' else 0o755 if info.is_dir() else 0o644
                    if stat.S_IMODE(info.external_attr >> 16) != expected:
                        raise RuntimeError(f'Incorrect ZIP permissions: {member}')
                    if member.is_absolute() or '..' in member.parts or stat.S_ISLNK(info.external_attr >> 16):
                        raise RuntimeError('Unsafe ZIP member.')
            temporary_archive.replace(archive)
        finally:
            if temporary_archive.exists():
                temporary_archive.unlink()
        checksum = ''.join(f'{sha(p)}  {p.name}\n' for p in sorted(output.glob('image-vault-*-production.zip')))
        (output / 'SHA256SUMS').write_text(checksum)
        (output / 'production-platform-check.txt').write_text(platform_log)
        (output / 'production-packages.json').write_text(json.dumps(installed, indent=2) + '\n')
        print(json.dumps({'archive': str(archive), 'bytes': archive.stat().st_size,
                          'production_packages': len(installed), 'sha256': sha(archive),
                          'preflight': 'passed', 'installation_state': 'fresh'}, ensure_ascii=False))


if __name__ == '__main__':
    main()
