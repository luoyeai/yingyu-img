<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Installation\EnvFile;
use App\Services\Installation\InstallationState;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Throwable;

class CreateAdmin extends Command
{
    protected $signature = 'vault:admin {email? : 管理员邮箱}';

    protected $description = '交互式初始化唯一管理员并关闭网页安装；已有管理员时拒绝创建';

    public function handle(InstallationState $state): int
    {
        if (! config('app.key')) {
            $this->error('请先配置有效 APP_KEY；全新站点可使用网页安装向导。');

            return self::FAILURE;
        }
        $email = $this->argument('email') ?: $this->ask('管理员邮箱');
        $password = $this->secret('管理员密码（至少12位）');
        $confirm = $this->secret('再次输入密码');
        $validation = Validator::make(['email' => $email, 'password' => $password, 'password_confirmation' => $confirm], ['email' => 'required|email|max:255', 'password' => 'required|string|min:12|max:72|confirmed']);
        if ($validation->fails() || strlen((string) $password) > 72) {
            $this->error($validation->errors()->first() ?: '密码最多72字节。');

            return self::FAILURE;
        }
        try {
            return $state->underLock(function () use ($state, $email, $password): int {
                $lock = DB::selectOne("SELECT GET_LOCK('image_vault_admin_init', 0) AS acquired");
                if (! $lock->acquired) {
                    $this->error('另一个初始化请求正在执行。');

                    return self::FAILURE;
                }
                try {
                    if (User::exists()) {
                        $this->error('已存在管理员，禁止创建第二个账号。');

                        return self::FAILURE;
                    }
                    User::create(['name' => '管理员', 'email' => $email, 'password' => Hash::make($password)]);
                    try {
                        (new EnvFile(base_path()))->atomicFile($state->path('installed.json'), json_encode(['format' => 1, 'installed_at' => gmdate(DATE_ATOM), 'source' => 'cli'], JSON_THROW_ON_ERROR)."\n");
                    } catch (Throwable) {
                        $this->error('管理员已创建，但安装锁写入失败。请修复私有目录权限后执行 vault:seal-installation；不要重复创建账号。');

                        return self::FAILURE;
                    }
                    $this->info('管理员已创建，网页安装已关闭。没有公开注册入口。');

                    return self::SUCCESS;
                } finally {
                    DB::select("SELECT RELEASE_LOCK('image_vault_admin_init')");
                }
            });
        } catch (Throwable) {
            $this->error('初始化未完成，请检查数据库及私有目录权限。');

            return self::FAILURE;
        }
    }
}
