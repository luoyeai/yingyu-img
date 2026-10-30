<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class UpdateBuildTest extends TestCase
{
    public function test_signing_build_publishes_only_complete_archives_and_can_retry_after_write_failure(): void
    {
        $program = <<<'PY'
import contextlib
import importlib.util
import io
import json
from pathlib import Path
import stat
import sys
import tempfile
from unittest.mock import patch
import zipfile

sys.dont_write_bytecode = True
spec = importlib.util.spec_from_file_location('vault_update_builder', sys.argv[1])
builder = importlib.util.module_from_spec(spec)
spec.loader.exec_module(builder)
timestamp = (2026, 10, 29, 0, 0, 0)

for legacy, failed_member in [(False, 'payload/app/example.php'), (True, 'image-vault/app/example.php')]:
    with tempfile.TemporaryDirectory(prefix='vault-build-test-') as directory:
        root = Path(directory)
        output = root / 'output'
        output.mkdir()
        existing = output / 'published-keep.zip'
        existing.write_bytes(b'existing release must remain unchanged')
        key = root / 'fixture-key'
        key.write_text('fixture only; signing subprocess is mocked')
        key.chmod(0o600)
        version = '1.0.0' if legacy else '1.1.2'
        source = root / 'fixture-full.zip'
        files = {'release.json': json.dumps({'program': version, 'schema': []}).encode(),
                 'BUILD.json': b'{"release_date":"2026-10-29"}', 'app/example.php': b'<?php // fixture'}
        with zipfile.ZipFile(source, 'w') as archive:
            for name, body in files.items():
                archive.writestr(builder.info('image-vault/' + name, timestamp), body)
        arguments = ['build-update.py', str(source), '--key', str(key), '--output-dir', str(output)]
        if not legacy:
            arguments += ['--from-min', '1.1.1', '--from-max', '1.1.1']
        update = output / f'image-vault-{version}-{"legacy-" if legacy else ""}signed-update.zip'
        bridge = output / f'image-vault-{version}-legacy-transition.zip'
        def mock_sign(arguments, **options):
            if '-sign' in arguments:
                Path(arguments[arguments.index('-out') + 1]).write_bytes(b'fixture-signature')
        write_entry = zipfile.ZipFile.writestr
        def fail_write(archive, entry, *args, **kwargs):
            name = entry.filename if isinstance(entry, zipfile.ZipInfo) else entry
            if name == failed_member:
                raise OSError('injected archive write failure')
            return write_entry(archive, entry, *args, **kwargs)
        with patch.object(sys, 'argv', arguments), patch.object(builder.subprocess, 'run', mock_sign), contextlib.redirect_stdout(io.StringIO()):
            with patch.object(zipfile.ZipFile, 'writestr', fail_write):
                try:
                    builder.main()
                    raise AssertionError('Injected archive write must fail')
                except OSError as failure:
                    assert str(failure) == 'injected archive write failure'
            assert not update.exists(), 'Failed build published the final update name'
            assert not bridge.exists(), 'Failed build published the final bridge name'
            assert sorted(p.name for p in output.iterdir()) == [existing.name], 'Failed build left artifacts'
            builder.main()
            before = update.read_bytes()
            with zipfile.ZipFile(update) as archive:
                assert archive.testzip() is None
                assert archive.read('payload/app/example.php') == files['app/example.php']
            if legacy:
                with zipfile.ZipFile(bridge) as archive:
                    assert archive.testzip() is None
                    assert archive.read('image-vault/app/example.php') == files['app/example.php']
            try:
                builder.main()
                raise AssertionError('Existing update was overwritten')
            except RuntimeError as failure:
                assert 'overwrite' in str(failure)
            assert update.read_bytes() == before
            assert existing.read_bytes() == b'existing release must remain unchanged'
PY;
        $process = proc_open(['python3', '-c', $program, dirname(__DIR__, 2).'/bin/build-update.py'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $output.$error);
    }
}
