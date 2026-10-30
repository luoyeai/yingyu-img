<?php

namespace App\Services\Updates;

use PDO;
use RuntimeException;
use Throwable;

/** A vendor-independent, complete MySQL 8 InnoDB snapshot, verified before destructive restore. */
class DatabaseSnapshot
{
    private const MAX_BYTES = 536870912;

    private const MAX_ROW_BYTES = 16777216;

    public function __construct(private PDO $pdo) {}

    /** @return list<string> */
    public function inspect(): array
    {
        $this->assertMetadataVisibility();
        $this->assertMysql();
        if ($this->objects() !== []) {
            throw new RuntimeException('数据库包含触发器、例程或事件，当前完整备份不支持，更新已阻止。');
        }
        $tables = [];
        $rows = $this->pdo->query('SELECT TABLE_NAME, TABLE_TYPE, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            if ($row['TABLE_TYPE'] !== 'BASE TABLE' || $row['ENGINE'] !== 'InnoDB') {
                throw new RuntimeException('数据库包含视图或非 InnoDB 表，更新已阻止。');
            }
            self::identifier($row['TABLE_NAME']);
            $tables[] = $row['TABLE_NAME'];
        }
        if ($tables === [] || count($tables) > 512) {
            throw new RuntimeException('已有安装数据库为空或表数量超出支持范围，不能更新。');
        }
        if ((int) $this->pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND (EXTRA LIKE '%VIRTUAL GENERATED%' OR EXTRA LIKE '%STORED GENERATED%')")->fetchColumn() !== 0) {
            throw new RuntimeException('数据库包含生成列，当前完整备份不支持，更新已阻止。');
        }

        return $tables;
    }

    public function checkPermissions(): void
    {
        $this->assertMetadataVisibility();
        $name = 'vault_update_probe_'.bin2hex(random_bytes(8));
        $quoted = self::identifier($name);
        $created = false;
        $failed = false;
        try {
            $this->pdo->exec('CREATE TABLE '.$quoted.' (id INT PRIMARY KEY) ENGINE=InnoDB');
            $created = true;
            $this->pdo->exec('ALTER TABLE '.$quoted.' ADD value INT NULL');
            $this->pdo->exec('INSERT INTO '.$quoted.' (id,value) VALUES (1,2)');
            $this->pdo->query('SELECT * FROM '.$quoted)->fetchAll();
            $this->pdo->exec('UPDATE '.$quoted.' SET value=3 WHERE id=1');
            $this->pdo->exec('DELETE FROM '.$quoted.' WHERE id=1');
        } catch (Throwable) {
            $failed = true;
        } finally {
            if ($created) {
                try {
                    $this->pdo->exec('DROP TABLE '.$quoted);
                } catch (Throwable) {
                    $failed = true;
                }
            }
        }
        if ($failed) {
            throw new RuntimeException('数据库更新和完整恢复权限检查失败，请核查遗留的 vault_update_probe_ 表。');
        }
    }

    /** @return array{sha256:string,database:string,tables:int,bytes:int} */
    public function create(string $directory): array
    {
        $tables = $this->inspect();
        self::directory($directory);
        $manifest = ['format' => 1, 'database' => $this->databaseName(), 'tables' => []];
        $bytes = 0;
        $buffered = $this->pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
        $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
        try {
            foreach ($tables as $table) {
                $quoted = self::identifier($table);
                $schema = $this->pdo->query('SHOW CREATE TABLE '.$quoted)->fetch(PDO::FETCH_NUM)[1];
                $columns = $this->pdo->query('SHOW COLUMNS FROM '.$quoted)->fetchAll(PDO::FETCH_COLUMN);
                $lengths = array_map(static fn (string $column): string => 'COALESCE(OCTET_LENGTH('.self::identifier($column).'),0)', $columns);
                $largest = (int) $this->pdo->query('SELECT COALESCE(MAX('.implode('+', $lengths).'),0) FROM '.$quoted)->fetchColumn();
                if ($largest * 4 / 3 + count($columns) * 8 + 2 > self::MAX_ROW_BYTES) {
                    throw new RuntimeException('数据库单行过大，无法在内存限制内安全备份；更新已阻止。');
                }
                $path = $directory.'/'.$table.'.rows';
                if (file_exists($path) || is_link($path)) {
                    throw new RuntimeException('备份目标已存在。');
                }
                $handle = fopen($path, 'xb');
                if ($handle === false) {
                    throw new RuntimeException('不能安全写入数据库备份。');
                }
                $statement = null;
                $count = 0;
                try {
                    if (! chmod($path, 0600)) {
                        throw new RuntimeException('不能保护数据库备份。');
                    }
                    $this->pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
                    $statement = $this->pdo->query('SELECT * FROM '.$quoted);
                    while ($row = $statement->fetch(PDO::FETCH_NUM)) {
                        $line = json_encode(array_map(static fn ($value) => $value === null ? null : base64_encode((string) $value), $row), JSON_THROW_ON_ERROR)."\n";
                        $bytes += strlen($line);
                        if (strlen($line) > self::MAX_ROW_BYTES || $bytes > self::MAX_BYTES || fwrite($handle, $line) !== strlen($line)) {
                            throw new RuntimeException('数据库备份超出单行 16 MiB、总计 512 MiB 限制或磁盘写入失败。');
                        }
                        $count++;
                    }
                    if (! fflush($handle) || ! fsync($handle)) {
                        throw new RuntimeException('数据库备份同步失败。');
                    }
                } finally {
                    fclose($handle);
                    $statement?->closeCursor();
                    $this->pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $buffered);
                }
                $manifest['tables'][$table] = ['schema' => $schema, 'columns' => $columns, 'rows' => $count, 'bytes' => filesize($path), 'sha256' => hash_file('sha256', $path)];
            }
            $this->pdo->commit();
        } catch (Throwable $failure) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $failure;
        }
        self::write($directory.'/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $descriptor = ['sha256' => hash_file('sha256', $directory.'/manifest.json'), 'database' => $manifest['database'], 'tables' => count($tables), 'bytes' => $bytes];
        $this->verify($directory, $descriptor);

        return $descriptor;
    }

    /** Validate every byte, row and schema before the caller can drop anything. */
    public function verify(string $directory, array $descriptor): array
    {
        [$manifest, $streams] = $this->openVerified($directory, $descriptor);
        $this->closeStreams($streams);

        return $manifest;
    }

    public function restore(string $directory, array $descriptor): void
    {
        $this->assertMysql();
        $this->assertMetadataVisibility();
        [$manifest, $streams] = $this->openVerified($directory, $descriptor);
        try {
            $objects = $this->objects();
            $current = $this->pdo->query('SELECT TABLE_NAME, TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME')->fetchAll(PDO::FETCH_ASSOC);
            foreach ($current as $table) {
                self::identifier($table['TABLE_NAME']);
                if (! in_array($table['TABLE_TYPE'], ['VIEW', 'BASE TABLE'], true)) {
                    throw new RuntimeException('恢复目标含未知数据库对象，未执行删除。');
                }
            }
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS=0');
            try {
                foreach ($objects as $object) {
                    $this->pdo->exec('DROP '.$object['type'].' '.$object['name']);
                }
                foreach ($current as $table) {
                    if ($table['TABLE_TYPE'] === 'VIEW') {
                        $this->pdo->exec('DROP VIEW '.self::identifier($table['TABLE_NAME']));
                    }
                }
                foreach ($current as $table) {
                    if ($table['TABLE_TYPE'] === 'BASE TABLE') {
                        $this->pdo->exec('DROP TABLE '.self::identifier($table['TABLE_NAME']));
                    }
                }
                foreach ($manifest['tables'] as $table => $metadata) {
                    $this->pdo->exec($metadata['schema']);
                    $columns = implode(',', array_map(self::identifier(...), $metadata['columns']));
                    $statement = $this->pdo->prepare('INSERT INTO '.self::identifier($table).' ('.$columns.') VALUES ('.implode(',', array_fill(0, count($metadata['columns']), '?')).')');
                    $handle = $streams[$table];
                    rewind($handle);
                    try {
                        $this->pdo->beginTransaction();
                        while (($line = $this->readRow($handle)) !== null) {
                            $row = json_decode($line, true, 8, JSON_THROW_ON_ERROR);
                            $statement->execute(array_map(static fn ($value) => $value === null ? null : base64_decode($value, true), $row));
                        }
                        $this->pdo->commit();
                    } finally {
                        if ($this->pdo->inTransaction()) {
                            $this->pdo->rollBack();
                        }
                    }
                    if ((int) $this->pdo->query('SELECT COUNT(*) FROM '.self::identifier($table))->fetchColumn() !== $metadata['rows']) {
                        throw new RuntimeException('恢复后表行数校验失败，维护状态保留。');
                    }
                }
            } finally {
                $this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');
            }
        } finally {
            $this->closeStreams($streams);
        }
    }

    public static function identifier(string $name): string
    {
        if (! preg_match('/\A[a-zA-Z0-9_]{1,64}\z/D', $name)) {
            throw new RuntimeException('数据库标识符不受支持。');
        }

        return '`'.$name.'`';
    }

    private function assertMysql(): void
    {
        $version = (string) $this->pdo->query('SELECT VERSION()')->fetchColumn();
        if (! preg_match('/\A8\./', $version) || stripos($version, 'mariadb') !== false) {
            throw new RuntimeException('更新仅支持 MySQL 8。');
        }
    }

    /** Direct database-wide ALL proves metadata is not filtered by per-object privileges or inactive roles. */
    private function assertMetadataVisibility(): void
    {
        $database = $this->databaseName();
        $grants = $this->pdo->query('SHOW GRANTS FOR CURRENT_USER()')->fetchAll(PDO::FETCH_COLUMN);
        $complete = false;
        foreach ($grants as $grant) {
            if (str_starts_with($grant, 'REVOKE ')) {
                throw new RuntimeException('数据库存在部分撤销授权，无法证明备份元数据完整可见。');
            }
            if (preg_match('/\AGRANT ALL PRIVILEGES ON (.+) TO /', $grant, $match)) {
                $scope = str_replace(['\\_', '\\%', '`'], ['_', '%', ''], $match[1]);
                if ($scope === '*.*' || $scope === $database.'.*') {
                    $complete = true;
                }
            }
        }
        if (! $complete) {
            throw new RuntimeException('数据库账号必须直接拥有目标库 ALL PRIVILEGES，以证明表、触发器、例程和事件完整可见；更新已阻止。');
        }
    }

    private function databaseName(): string
    {
        $database = (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn();
        self::identifier($database);

        return $database;
    }

    /** @return list<array{type:string,name:string}> */
    private function objects(): array
    {
        $objects = [];
        foreach ([['EVENTS', 'EVENT_SCHEMA', 'EVENT_NAME', 'EVENT'], ['TRIGGERS', 'TRIGGER_SCHEMA', 'TRIGGER_NAME', 'TRIGGER']] as [$table, $schema, $name, $type]) {
            foreach ($this->pdo->query('SELECT '.$name.' FROM information_schema.'.$table.' WHERE '.$schema.'=DATABASE() ORDER BY '.$name)->fetchAll(PDO::FETCH_COLUMN) as $object) {
                $objects[] = ['type' => $type, 'name' => self::identifier($object)];
            }
        }
        foreach ($this->pdo->query('SELECT ROUTINE_NAME, ROUTINE_TYPE FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE() ORDER BY ROUTINE_NAME')->fetchAll(PDO::FETCH_ASSOC) as $routine) {
            if (! in_array($routine['ROUTINE_TYPE'], ['FUNCTION', 'PROCEDURE'], true)) {
                throw new RuntimeException('数据库包含未知例程类型。');
            }
            $objects[] = ['type' => $routine['ROUTINE_TYPE'], 'name' => self::identifier($routine['ROUTINE_NAME'])];
        }

        return $objects;
    }

    /** @return array{array,array<string,resource>} */
    private function openVerified(string $directory, array $descriptor): array
    {
        self::directory($directory, false);
        $path = $directory.'/manifest.json';
        if (is_link($path) || ! is_file($path) || filesize($path) > 16777216) {
            throw new RuntimeException('数据库备份清单缺失或损坏；禁止恢复。');
        }
        $body = file_get_contents($path);
        if (! is_string($body) || ! hash_equals($descriptor['sha256'] ?? '', hash('sha256', $body))) {
            throw new RuntimeException('数据库备份清单缺失或损坏；禁止恢复。');
        }
        $manifest = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        if (($manifest['format'] ?? null) !== 1 || ($manifest['database'] ?? null) !== ($descriptor['database'] ?? null)
            || $manifest['database'] !== $this->databaseName() || ! is_array($manifest['tables'] ?? null)
            || count($manifest['tables']) !== ($descriptor['tables'] ?? null) || $manifest['tables'] === [] || count($manifest['tables']) > 512) {
            throw new RuntimeException('数据库备份目标或表清单不完整；禁止恢复。');
        }
        $free = disk_free_space(sys_get_temp_dir());
        if (! is_int($descriptor['bytes'] ?? null) || $descriptor['bytes'] < 0 || $descriptor['bytes'] > self::MAX_BYTES
            || $free === false || $free < $descriptor['bytes'] + 1048576) {
            throw new RuntimeException('私有临时目录空间不足以固定完整备份；禁止恢复。');
        }
        $streams = [];
        $bytes = 0;
        try {
            foreach ($manifest['tables'] as $table => $metadata) {
                $quoted = self::identifier($table);
                $path = $directory.'/'.$table.'.rows';
                if (! is_array($metadata['columns'] ?? null) || ! array_is_list($metadata['columns']) || $metadata['columns'] === []
                    || count($metadata['columns']) > 4096 || count(array_unique($metadata['columns'])) !== count($metadata['columns'])
                    || ! str_starts_with($metadata['schema'] ?? '', 'CREATE TABLE '.$quoted.' ')
                    || preg_match('/;\s*\S/', $metadata['schema']) || ! is_int($metadata['rows'] ?? null) || $metadata['rows'] < 0
                    || ! is_int($metadata['bytes'] ?? null) || $metadata['bytes'] < 0 || is_link($path) || ! is_file($path)) {
                    throw new RuntimeException('数据库表备份损坏；禁止恢复。');
                }
                foreach ($metadata['columns'] as $column) {
                    self::identifier($column);
                }
                $source = fopen($path, 'rb');
                $stream = tmpfile();
                if ($source === false || $stream === false) {
                    if (is_resource($source)) {
                        fclose($source);
                    }
                    throw new RuntimeException('无法固定已验证备份输入；禁止恢复。');
                }
                $streams[$table] = $stream;
                if ((fstat($stream)['mode'] & 0777) !== 0600) {
                    fclose($source);
                    throw new RuntimeException('临时备份副本权限不是 0600；禁止恢复。');
                }
                try {
                    $hash = hash_init('sha256');
                    $size = 0;
                    while (! feof($source)) {
                        $chunk = fread($source, 65536);
                        if ($chunk === false || ($chunk === '' && ! feof($source))) {
                            throw new RuntimeException('读取数据库备份失败；禁止恢复。');
                        }
                        $size += strlen($chunk);
                        $bytes += strlen($chunk);
                        if ($bytes > self::MAX_BYTES || $size > $metadata['bytes'] || fwrite($stream, $chunk) !== strlen($chunk)) {
                            throw new RuntimeException('数据库备份不完整或超过大小限制；禁止恢复。');
                        }
                        hash_update($hash, $chunk);
                    }
                    if ($size !== $metadata['bytes'] || ! hash_equals($metadata['sha256'] ?? '', hash_final($hash)) || ! fflush($stream)) {
                        throw new RuntimeException('数据库表备份损坏；禁止恢复。');
                    }
                } finally {
                    fclose($source);
                }
                rewind($stream);
                $count = 0;
                while (($line = $this->readRow($stream)) !== null) {
                    if (strlen($line) > self::MAX_ROW_BYTES || ! str_ends_with($line, "\n")) {
                        throw new RuntimeException('数据库备份行过大或不完整；禁止恢复。');
                    }
                    $row = json_decode($line, true, 8, JSON_THROW_ON_ERROR);
                    if (! is_array($row) || ! array_is_list($row) || count($row) !== count($metadata['columns'])) {
                        throw new RuntimeException('数据库备份行不完整；禁止恢复。');
                    }
                    foreach ($row as $value) {
                        if ($value !== null && (! is_string($value) || base64_decode($value, true) === false)) {
                            throw new RuntimeException('数据库备份行编码无效；禁止恢复。');
                        }
                    }
                    $count++;
                }
                if (! feof($stream) || $count !== $metadata['rows']) {
                    throw new RuntimeException('数据库备份行数不完整；禁止恢复。');
                }
                rewind($stream);
            }
            if ($bytes !== ($descriptor['bytes'] ?? null)) {
                throw new RuntimeException('数据库备份总量不完整；禁止恢复。');
            }

            return [$manifest, $streams];
        } catch (Throwable $failure) {
            $this->closeStreams($streams);
            throw $failure;
        }
    }

    /** @param resource $handle */
    private function readRow(mixed $handle): ?string
    {
        $line = '';
        while (($chunk = fgets($handle, 65536)) !== false) {
            $line .= $chunk;
            if (strlen($line) > self::MAX_ROW_BYTES) {
                throw new RuntimeException('数据库备份行过大；禁止恢复。');
            }
            if (str_ends_with($chunk, "\n")) {
                return $line;
            }
        }
        if ($line !== '' || ! feof($handle)) {
            throw new RuntimeException('数据库备份行不完整；禁止恢复。');
        }

        return null;
    }

    /** @param array<string,resource> $streams */
    private function closeStreams(array $streams): void
    {
        foreach ($streams as $stream) {
            fclose($stream);
        }
    }

    private static function directory(string $directory, bool $create = true): void
    {
        for ($path = $directory; $path !== dirname($path); $path = dirname($path)) {
            if (is_link($path)) {
                throw new RuntimeException('备份路径不能包含符号链接。');
            }
        }
        if ((! is_dir($directory) && (! $create || ! mkdir($directory, 0700, true))) || ! chmod($directory, 0700)) {
            throw new RuntimeException('备份目录不可用。');
        }
    }

    private static function write(string $path, string $content): void
    {
        if (is_link($path) || file_exists($path) || file_put_contents($path, $content, LOCK_EX) !== strlen($content) || ! chmod($path, 0600)) {
            throw new RuntimeException('备份清单写入失败。');
        }
    }
}
