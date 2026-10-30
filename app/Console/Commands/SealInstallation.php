<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Installation\EnvFile;
use App\Services\Installation\InstallationState;
use Illuminate\Console\Command;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Throwable;

class SealInstallation extends Command
{
    protected $signature = 'vault:seal-installation';

    protected $description = '仅对已有完整站点补写安装锁，不创建账号或修改业务数据';

    public function handle(InstallationState $state): int
    {
        try {
            return $state->underLock(function () use ($state): int {
                if (! $this->validApplicationKey() || config('database.default') !== 'mysql') {
                    $this->error('有效APP_KEY和MySQL配置是必要条件。');

                    return self::FAILURE;
                }
                $lock = DB::selectOne("SELECT GET_LOCK('image_vault_admin_init', 0) AS acquired");
                if (! $lock->acquired) {
                    $this->error('初始化正在执行。');

                    return self::FAILURE;
                }
                try {
                    $expected = array_map(fn ($path) => basename($path, '.php'), glob(database_path('migrations/*.php')));
                    $completed = DB::table('migrations')->pluck('migration')->all();
                    if (User::count() !== 1 || array_diff($expected, $completed)) {
                        $this->error('尚未完成全部迁移或管理员数量不是1，拒绝标记完成。');

                        return self::FAILURE;
                    }
                    $installedPath = $state->path('installed.json');
                    if (is_link($installedPath) || (file_exists($installedPath) && ! is_file($installedPath))) {
                        $this->error('安装锁路径无效，请保留已有记录并检查私有目录。');

                        return self::FAILURE;
                    }
                    if (! is_file($installedPath)) {
                        (new EnvFile(base_path()))->atomicFile($installedPath, json_encode(['format' => 1, 'installed_at' => gmdate(DATE_ATOM), 'source' => 'sealed-existing'], JSON_THROW_ON_ERROR)."\n");
                    }
                    if (is_file($state->path('owner-token'))) {
                        unlink($state->path('owner-token'));
                    }
                    $this->info('安装锁已补齐，账号与业务数据未修改。');

                    return self::SUCCESS;
                } finally {
                    DB::select("SELECT RELEASE_LOCK('image_vault_admin_init')");
                }
            });
        } catch (Throwable) {
            $this->error('无法确认完整安装状态；请检查数据库与安装目录权限。');

            return self::FAILURE;
        }
    }

    private function validApplicationKey(): bool
    {
        $key = (string) config('app.key');
        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7), true);
        }

        return is_string($key) && Encrypter::supported($key, (string) config('app.cipher'));
    }
}
