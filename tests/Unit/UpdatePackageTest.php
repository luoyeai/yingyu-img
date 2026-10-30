<?php

namespace Tests\Unit;

use App\Services\Updates\UpdatePackage;
use App\Services\Updates\UpdateState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZipArchive;

class UpdatePackageTest extends TestCase
{
    private string $root;

    private \OpenSSLAsymmetricKey $key;

    private const SCHEMA = ['0001_01_01_000000_create_users_table'];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/vault-update-package-'.bin2hex(random_bytes(8));
        mkdir($this->root.'/bootstrap', 0700, true);
        $this->key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        file_put_contents($this->root.'/bootstrap/update-public.pem', openssl_pkey_get_details($this->key)['key']);
        file_put_contents($this->root.'/bootstrap/update-gateway.php', '<?php // stable');
        mkdir($this->root.'/public', 0700);
        file_put_contents($this->root.'/public/index.php', '<?php // index');
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            if ($entry->isDir() && ! $entry->isLink()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($this->root);
    }

    public function test_valid_signed_package_is_extracted_and_private_state_is_atomic_and_locked(): void
    {
        $path = $this->package(['app/example.php' => '<?php echo "fixture";', 'LICENSE' => 'MIT fixture']);
        $package = new UpdatePackage($this->root);
        $manifest = $package->inspect($path, $this->current());
        $this->assertSame('1.2.0', $manifest['program']);
        $extracted = $package->extract($path, $this->root.'/stage', $this->current());
        $this->assertSame('<?php echo "fixture";', file_get_contents($extracted['payload'].'/app/example.php'));
        $this->assertSame('MIT fixture', file_get_contents($extracted['payload'].'/LICENSE'));
        $this->assertSame(0600, fileperms($extracted['payload'].'/app/example.php') & 0777);
        $state = new UpdateState($this->root);
        $state->write('version.json', $this->current() + ['managed_files' => ['app/example.php']]);
        $this->assertSame('1.0.0', $state->version()['program']);
        $this->assertSame(0600, fileperms($state->directory().'/version.json') & 0777);
        $state->locked(function () use ($state): void {
            try {
                $state->locked(fn () => null);
                $this->fail('Concurrent lock accepted.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('另一个', $exception->getMessage());
            }
        });
        $this->assertSame(42, $state->locked(fn () => 42));
    }

    public function test_payload_tampering_and_untrusted_signature_are_rejected(): void
    {
        $path = $this->package(['app/example.php' => 'original']);
        $zip = new ZipArchive;
        $zip->open($path);
        $zip->addFromString('payload/app/example.php', 'tampered');
        $zip->close();
        $this->assertRejected($path);
        $path = $this->package(['app/example.php' => 'original']);
        $zip->open($path);
        $zip->addFromString('signature.txt', base64_encode(random_bytes(256)));
        $zip->close();
        $this->assertRejected($path);
    }

    #[DataProvider('unsafePaths')]
    public function test_private_and_noncanonical_paths_are_rejected(string $path): void
    {
        $this->assertRejected($this->package([$path => 'private']));
    }

    public static function unsafePaths(): array
    {
        return array_map(fn (string $path): array => [$path], [
            '../escape.php', '/absolute.php', 'app/../../escape.php', 'app\\escape.php', 'app//empty.php',
            '.env', 'storage/app/updates/version.json', 'public/media/image.webp', 'public/assets/image.png',
            'bootstrap/cache/config.php', '.tools/private.pem', 'vendor/.env', 'app/tests/example.php',
        ]);
    }

    public function test_symlinks_duplicate_names_and_extra_unlisted_files_are_rejected(): void
    {
        $path = $this->package(['app/example.php' => 'original']);
        $zip = new ZipArchive;
        $zip->open($path);
        $zip->setExternalAttributesName('payload/app/example.php', ZipArchive::OPSYS_UNIX, 0120777 << 16);
        $zip->close();
        $this->assertRejected($path);
        $path = $this->package(['app/example.php' => 'original']);
        $zip->open($path);
        $zip->addFromString('payload/app/extra.php', 'unlisted');
        $zip->close();
        $this->assertRejected($path);
        $path = $this->package(['app/example.php' => 'original', 'app/examplf.php' => 'original']);
        file_put_contents($path, str_replace('app/examplf.php', 'app/example.php', file_get_contents($path)));
        $this->assertRejected($path);
    }

    public function test_zip_bomb_and_forged_permissions_are_rejected(): void
    {
        $this->assertRejected($this->package(['app/large.php' => str_repeat('A', 2200000)]));
        $this->assertRejected($this->package(['app/example.php' => 'original'], function (array $manifest): array {
            $manifest['files']['app/example.php']['mode'] = 0600;

            return $manifest;
        }));
    }

    public function test_archive_file_permissions_must_match_the_public_readable_program_policy(): void
    {
        $path = $this->package(['app/example.php' => 'original']);
        $zip = new ZipArchive;
        $zip->open($path);
        $zip->setExternalAttributesName('payload/app/example.php', ZipArchive::OPSYS_UNIX, 0100600 << 16);
        $zip->close();
        $this->assertRejected($path);
    }

    public function test_fixed_trust_and_recovery_gateway_cannot_be_replaced(): void
    {
        $this->assertRejected($this->package(['bootstrap/update-public.pem' => 'new-public-key']));
        file_put_contents($this->root.'/bootstrap/update-gateway.php', '<?php // stable');
        $this->assertRejected($this->package(['bootstrap/update-gateway.php' => '<?php // replaced']));
        $path = $this->package(['bootstrap/update-public.pem' => file_get_contents($this->root.'/bootstrap/update-public.pem')]);
        $this->assertSame('1.2.0', (new UpdatePackage($this->root))->inspect($path, $this->current())['program']);
    }

    #[DataProvider('invalidVersions')]
    public function test_downgrades_unknown_baselines_and_incompatible_ranges_are_rejected(array $changes): void
    {
        $path = $this->package(['app/example.php' => 'original'], fn (array $manifest): array => array_replace($manifest, $changes));
        $this->assertRejected($path);
    }

    public static function invalidVersions(): array
    {
        return [
            [['program' => '0.9.0']], [['program' => '1.0.0']], [['program' => '2.0.0']],
            [['compatible' => ['min' => '1.1.0', 'max' => '1.9.0']]],
            [['compatible' => ['min' => '0.1.0', 'max' => '0.9.0']]],
            [['schema' => ['2026_10_27_000001_create_new_table']]], [['php_min' => '99.0.0']],
            [['program' => '1.2.0-beta']], [['schema' => []]], [['media_migrations' => true]],
        ];
    }

    public function test_only_explicit_recognized_legacy_baseline_can_enter_version_one(): void
    {
        $path = $this->package(['app/example.php' => 'original'], fn (array $manifest): array => array_replace($manifest, [
            'program' => '1.0.0', 'compatible' => ['min' => '0.0.0', 'max' => '0.0.0'],
        ]));
        $legacy = ['program' => '0.0.0', 'schema' => self::SCHEMA, 'baseline' => 'legacy-20261028'];
        $this->assertSame('1.0.0', (new UpdatePackage($this->root))->inspect($path, $legacy)['program']);
        unset($legacy['baseline']);
        $this->expectException(RuntimeException::class);
        (new UpdatePackage($this->root))->inspect($path, $legacy);
    }

    public function test_sparse_signed_program_is_rejected_and_production_vendor_metadata_is_allowed(): void
    {
        $this->assertRejected($this->package(['app/example.php' => 'fixture'], function (array $manifest): array {
            unset($manifest['files']['artisan']);

            return $manifest;
        }));
        foreach (['vendor/nesbot/carbon/src/Carbon/Lang/aa_ER@saaho.php', 'vendor/symfony/clock/Test/ClockSensitiveTrait.php', 'vendor/league/commonmark/.phpstorm.meta.php'] as $path) {
            $this->assertTrue(UpdatePackage::allowedPath($path));
        }
    }

    public function test_state_refuses_symlink_and_invalid_names(): void
    {
        $state = new UpdateState($this->root);
        symlink($this->root.'/bootstrap/update-public.pem', $state->directory().'/version.json');
        try {
            $state->write('version.json', []);
            $this->fail('State followed symlink.');
        } catch (RuntimeException) {
            $this->assertStringContainsString('PUBLIC KEY', file_get_contents($this->root.'/bootstrap/update-public.pem'));
        }
        $this->expectException(RuntimeException::class);
        $state->write('../outside.json', []);
    }

    public function test_current_official_archives_match_source_manifests_and_locked_production_vendor(): void
    {
        $project = dirname(__DIR__, 2);
        $fullPath = $project.'/release/image-vault-20261030-update-v1-1-4-production.zip';
        $signedPath = $project.'/release/image-vault-1.1.4-signed-update.zip';
        if (! is_file($fullPath) || ! is_file($signedPath)) {
            $this->markTestSkipped('Build both official 1.1.4 artifacts before acceptance.');
        }
        $full = new ZipArchive;
        $signed = new ZipArchive;
        $this->assertTrue($full->open($fullPath, ZipArchive::CHECKCONS));
        $this->assertTrue($signed->open($signedPath, ZipArchive::CHECKCONS));
        $manifestBody = $signed->getFromName('manifest.json');
        $manifest = json_decode($manifestBody, true, 64, JSON_THROW_ON_ERROR);
        $this->assertSame(1, openssl_verify($manifestBody, base64_decode(trim($signed->getFromName('signature.txt')), true), file_get_contents($project.'/bootstrap/update-public.pem'), OPENSSL_ALGO_SHA256));
        $this->assertSame('1.1.4', $manifest['program']);
        $this->assertSame(['min' => '1.1.1', 'max' => '1.1.3'], $manifest['compatible']);
        $this->assertFalse($manifest['media_migrations']);
        $files = [];
        $errors = [];
        for ($index = 0; $index < $full->numFiles; $index++) {
            $entry = $full->statIndex($index);
            $name = $entry['name'];
            $full->getExternalAttributesIndex($index, $system, $attributes);
            $relative = substr($name, strlen('image-vault/'));
            $directory = str_ends_with($name, '/');
            $mode = $directory ? (str_starts_with($relative, 'storage/') ? 0700 : 0755) : 0644;
            if (! str_starts_with($name, 'image-vault/') || str_contains($name, '..') || (($attributes >> 16) & 07777) !== $mode || (($attributes >> 16) & 0170000) !== ($directory ? 0040000 : 0100000)) {
                $errors[] = 'path/mode: '.$name;
            }
            if ($directory) {
                continue;
            }
            $body = $full->getFromIndex($index);
            $files[$relative] = hash('sha256', $body);
            if (! UpdatePackage::allowedPath($relative) || str_starts_with($relative, 'storage/') || str_starts_with($relative, 'public/media/')) {
                $errors[] = 'runtime/path: '.$relative;
            }
            if ($signed->getFromName('payload/'.$relative) !== $body || ($manifest['files'][$relative]['sha256'] ?? '') !== $files[$relative] || ($manifest['files'][$relative]['size'] ?? -1) !== strlen($body)) {
                $errors[] = 'signed mismatch: '.$relative;
            }
            $source = $relative === 'README.md' ? 'deploy/package-readme.md' : $relative;
            if (! str_starts_with($relative, 'vendor/') && ! in_array($relative, ['BUILD.json', 'FILES.sha256', 'release.json'], true) && (! is_file($project.'/'.$source) || file_get_contents($project.'/'.$source) !== $body)) {
                $errors[] = 'source mismatch: '.$relative;
            }
        }
        $this->assertSame([], array_slice($errors, 0, 10), 'Archive errors: '.count($errors));
        $this->assertCount(count($files), $manifest['files']);
        $this->assertSame(count($files) + 2, $signed->numFiles);
        $hashes = [];
        foreach (explode("\n", trim($full->getFromName('image-vault/FILES.sha256'))) as $line) {
            [$hash, $name] = explode('  ', $line, 2);
            $hashes[$name] = $hash;
        }
        $expectedHashes = $files;
        unset($expectedHashes['FILES.sha256']);
        ksort($expectedHashes);
        ksort($hashes);
        $this->assertSame($expectedHashes, $hashes);
        $release = json_decode($full->getFromName('image-vault/release.json'), true, 64, JSON_THROW_ON_ERROR);
        $this->assertSame('1.1.4', $release['program']);
        $managed = $release['managed_files'];
        sort($managed);
        $names = array_keys($files);
        sort($names);
        $this->assertSame($names, $managed);
        $this->assertSame($release['schema'], $manifest['schema']);
        $this->assertCount(8, $release['schema']);
        $installed = json_decode($full->getFromName('image-vault/vendor/composer/installed.json'), true, 64, JSON_THROW_ON_ERROR);
        $this->assertFalse($installed['dev']);
        $actual = array_column($installed['packages'], 'version', 'name');
        $lock = json_decode(file_get_contents($project.'/composer.lock'), true, 64, JSON_THROW_ON_ERROR);
        $expected = array_column($lock['packages'], 'version', 'name');
        ksort($expected);
        ksort($actual);
        $this->assertSame($expected, $actual);
        $this->assertCount(82, $actual);
        $this->assertSame([], array_intersect(array_column($lock['packages-dev'], 'name'), array_keys($actual)));
        $build = json_decode($full->getFromName('image-vault/BUILD.json'), true, 64, JSON_THROW_ON_ERROR);
        $this->assertSame('2026-10-30', $build['release_date']);
        $this->assertSame(hash_file('sha256', $project.'/composer.lock'), $build['composer_lock_sha256']);
        $this->assertCount(4, $build['omitted_upstream_ci_files']);
        for ($index = 0; $index < $signed->numFiles; $index++) {
            $signed->getExternalAttributesIndex($index, $system, $attributes);
            if (($attributes >> 16) !== 0100644 || $signed->statIndex($index)['mtime'] !== $full->statIndex(0)['mtime']) {
                $errors[] = 'signed mode/date: '.$signed->getNameIndex($index);
            }
        }
        $this->assertSame([], array_slice($errors, 0, 10), 'Archive errors: '.count($errors));
        $full->close();
        $signed->close();
    }

    private function current(): array
    {
        return ['program' => '1.0.0', 'schema' => self::SCHEMA];
    }

    private function package(array $files, ?callable $change = null): string
    {
        $manifest = ['format' => 1, 'program' => '1.2.0', 'schema' => self::SCHEMA,
            'compatible' => ['min' => '1.0.0', 'max' => '1.9.99'], 'php_min' => '8.4.1', 'media_migrations' => false, 'files' => []];
        $core = array_fill_keys(UpdatePackage::REQUIRED_FILES, '<?php // fixture');
        foreach (['bootstrap/update-public.pem', 'bootstrap/update-gateway.php', 'public/index.php'] as $protected) {
            $core[$protected] = file_get_contents($this->root.'/'.$protected);
        }
        $core['database/migrations/'.self::SCHEMA[0].'.php'] = '<?php // migration';
        $files = array_replace($core, $files);
        $files['release.json'] = json_encode(['program' => '1.2.0', 'schema' => self::SCHEMA, 'managed_files' => array_keys($files)], JSON_THROW_ON_ERROR);
        foreach ($files as $path => $body) {
            $manifest['files'][$path] = ['sha256' => hash('sha256', $body), 'size' => strlen($body), 'mode' => 0644];
        }
        if ($change !== null) {
            $manifest = $change($manifest);
        }
        if (isset($manifest['files']['release.json']) && json_decode($files['release.json'], true) !== null) {
            $files['release.json'] = json_encode(['program' => $manifest['program'], 'schema' => $manifest['schema'], 'managed_files' => array_keys($manifest['files'])], JSON_THROW_ON_ERROR);
            $manifest['files']['release.json']['sha256'] = hash('sha256', $files['release.json']);
            $manifest['files']['release.json']['size'] = strlen($files['release.json']);
        }
        $body = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        openssl_sign($body, $signature, $this->key, OPENSSL_ALGO_SHA256);
        $path = $this->root.'/package-'.bin2hex(random_bytes(6)).'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::EXCL);
        $zip->addFromString('manifest.json', $body);
        $zip->addFromString('signature.txt', base64_encode($signature));
        foreach ($files as $name => $contents) {
            $zip->addFromString('payload/'.$name, $contents);
            $zip->setExternalAttributesName('payload/'.$name, ZipArchive::OPSYS_UNIX, 0100644 << 16);
        }
        $zip->close();

        return $path;
    }

    private function assertRejected(string $path): void
    {
        try {
            (new UpdatePackage($this->root))->inspect($path, $this->current());
            $this->fail('Unsafe package accepted.');
        } catch (RuntimeException $exception) {
            $this->assertNotSame('', $exception->getMessage());
            $this->assertDirectoryDoesNotExist($this->root.'/stage');
        }
    }
}
