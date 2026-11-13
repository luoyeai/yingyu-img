<?php

namespace Tests\Unit;

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ZipArchive;

class UpdateRecoveryBrowserTest extends TestCase
{
    /** @return array<string,array{bool}> */
    public static function recoveryRuntimes(): array
    {
        return ['current' => [false], 'repaired-frozen-1.0.2' => [true]];
    }

    #[DataProvider('recoveryRuntimes')]
    public function test_real_browser_reauthentication_stale_form_native_errors_and_explicit_restore(bool $legacy): void
    {
        $root = sys_get_temp_dir().'/vault-recovery-browser-'.bin2hex(random_bytes(8));
        $directory = $root.'/storage/app/updates';
        mkdir($directory.'/runtime', 0700, true);
        touch($directory.'/update.lock');
        foreach (['UpdateState', 'UpdatePackage', 'DatabaseSnapshot', 'UpdateRequirements', 'UpdateRunner', 'UpdateWeb'] as $class) {
            copy(dirname(__DIR__, 2).'/app/Services/Updates/'.$class.'.php', $directory.'/runtime/'.$class.'.php');
        }
        copy(dirname(__DIR__, 2).'/resources/views/update-recovery.php', $directory.'/runtime/update-recovery.php');
        mkdir($root.'/public/assets', 0755, true);
        foreach (['update.js', 'app.js', 'app.css'] as $asset) {
            copy(dirname(__DIR__, 2).'/public/assets/'.$asset, $root.'/public/assets/'.$asset);
        }
        mkdir($root.'/bootstrap', 0755);
        mkdir($root.'/vendor', 0755);
        copy(dirname(__DIR__, 2).'/public/index.php', $root.'/public/index.php');
        foreach (['preflight.php', 'update-gateway.php'] as $file) {
            copy(dirname(__DIR__, 2).'/bootstrap/'.$file, $root.'/bootstrap/'.$file);
        }
        file_put_contents($root.'/vendor/autoload.php', '<?php throw new RuntimeException("VENDOR_MUST_NOT_RUN");');
        file_put_contents($root.'/router.php', '<?php if (str_starts_with(parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH), "/assets/")) { return false; } require __DIR__."/public/index.php";');
        $task = str_repeat('a', 32);
        file_put_contents($directory.'/journal.json', json_encode(['id' => $task, 'stage' => 'prepared', 'status' => 'failed', 'active' => true, 'from' => '1.0.2', 'to' => '1.0.3', 'log' => [],
            'manifest' => ['program' => '1.0.3', 'schema' => [], 'compatible' => ['min' => '1.0.0', 'max' => '1.0.2'], 'php_min' => '8.4.1'], 'before' => ['schema' => []]]));
        file_put_contents($directory.'/recovery-account.json', json_encode(['task' => $task, 'email' => 'browser@example.invalid', 'password_hash' => password_hash('Browser-fixture-82!', PASSWORD_BCRYPT)]));
        if ($legacy) {
            $zip = new ZipArchive;
            $this->assertTrue($zip->open(dirname(__DIR__, 2).'/release/github-v1.0.2/image-vault-20261030-opensource-v1-0-2-production.zip'));
            foreach (['UpdateRunner', 'UpdateWeb'] as $class) {
                file_put_contents($directory.'/runtime/'.$class.'.php', $zip->getFromName('image-vault/app/Services/Updates/'.$class.'.php'));
            }
            file_put_contents($directory.'/runtime/update-recovery.php', $zip->getFromName('image-vault/resources/views/update-recovery.php'));
            file_put_contents($root.'/public/assets/update.js', $zip->getFromName('image-vault/public/assets/update.js'));
            $zip->close();
            $repair = proc_open([PHP_BINARY, dirname(__DIR__, 2).'/app/Services/Updates/update-rescue.php', $root, '--repair'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $repairPipes);
            fclose($repairPipes[0]);
            $repairOutput = stream_get_contents($repairPipes[1]).stream_get_contents($repairPipes[2]);
            fclose($repairPipes[1]);
            fclose($repairPipes[2]);
            $this->assertSame(0, proc_close($repair), $repairOutput);
        }
        $program = <<<'JS'
const assert = require('node:assert/strict');
const fs = require('node:fs');
const net = require('node:net');
const {spawn} = require('node:child_process');
const {chromium} = require('/Users/luoye/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const [root, php, legacy] = process.argv.slice(1);
const dir = root + '/storage/app/updates';
const journal = () => fs.readFileSync(dir + '/journal.json', 'utf8');
const writeJournal = data => fs.writeFileSync(dir + '/journal.json', JSON.stringify(data));
const pause = ms => new Promise(resolve => setTimeout(resolve, ms));
(async () => {
    const probe = net.createServer();
    await new Promise(resolve => probe.listen(0, '127.0.0.1', resolve));
    const port = probe.address().port;
    await new Promise(resolve => probe.close(resolve));
    const origin = 'http://127.0.0.1:' + port;
    const server = spawn(php, ['-S', '127.0.0.1:' + port, '-t', root + '/public', root + '/router.php'], {cwd: root, stdio: ['ignore', 'ignore', 'ignore']});
    let browser;
    try {
        for (let i = 0; i < 50; i++) {
            try {await fetch(origin + '/update-recovery'); break;} catch {await pause(100);}
        }
        browser = await chromium.launch({headless: true, channel: 'msedge'});
        const context = await browser.newContext();
        const page = await context.newPage();
        const errors = [];
        const posts = [];
        context.on('page', p => p.on('pageerror', error => errors.push(error.message)));
        page.on('pageerror', error => errors.push(error.message));
        context.on('request', request => {if (request.method() === 'POST') posts.push(request.url());});
        const login = async p => {
            await p.locator('[name=email]').fill('browser@example.invalid');
            await p.locator('[name=password]').fill('Browser-fixture-82!');
            await p.getByRole('button', {name: '验证并打开恢复入口'}).click();
            await p.waitForURL(origin + '/update-recovery?inspect=1');
            await p.waitForLoadState('networkidle');
        };
        await page.goto(origin + '/update-recovery');
        await page.waitForLoadState('networkidle');
        assert.equal(await page.locator('h1').textContent(), '恢复登录');
        const staleLogin = await context.newPage();
        await staleLogin.goto(origin + '/update-recovery');
        await staleLogin.waitForLoadState('networkidle');
        await login(page);
        // Submit a real old login form after the other tab has established valid authorization.
        await login(staleLogin);
        assert.equal(await staleLogin.locator('h1').textContent(), '系统更新');
        assert.equal(JSON.parse(journal()).status, 'failed');
        if (legacy === '1') {
            const before = journal();
            const native = await browser.newContext({javaScriptEnabled: false});
            await native.addCookies(await context.cookies());
            const nativePage = await native.newPage();
            await nativePage.goto(origin + '/update-recovery?inspect=1');
            await nativePage.locator('[name=confirmation]').check();
            await nativePage.locator('[name=csrf]').evaluate(input => input.value = 'stale');
            await nativePage.getByRole('button', {name: '确认恢复', exact: true}).click();
            assert.equal(await nativePage.locator('h1').textContent(), '恢复请求未执行');
            await nativePage.getByRole('link', {name: '重新打开恢复页', exact: true}).click();
            assert.equal(await nativePage.locator('h1').textContent(), '系统更新');
            assert.equal(journal(), before);
            assert.deepEqual(errors, []);
            console.log(JSON.stringify({scenarios: 3, result: 'passed', runtime: 'repaired-frozen-1.0.2'}));
            return;
        }
        await page.goto(origin + '/update-recovery?inspect=1');
        await page.waitForLoadState('networkidle');
        // Rotate the valid browser cookie in another tab, leaving this form token stale.
        await staleLogin.goto(origin + '/update-recovery?reauth=1');
        await login(staleLogin);
        const before = journal();
        const priorPosts = posts.length;
        await page.locator('[name=confirmation]').check();
        await page.getByRole('button', {name: '确认恢复', exact: true}).click();
        await page.waitForFunction(() => document.querySelector('[data-update-status]').textContent.includes('校验失败'));
        assert.match(await page.locator('[data-update-status]').textContent(), /校验失败/);
        assert.equal(await page.locator('[data-update-fallback]').getAttribute('href'), '/update-recovery?inspect=1');
        assert.equal(posts.length, priorPosts + 1);
        assert.equal(journal(), before);
        await page.locator('[data-update-fallback]').click();
        await page.waitForLoadState('networkidle');
        // A lost grant must show reauthentication instead of replaying the restore.
        const grant = JSON.parse(fs.readFileSync(dir + '/recovery-grant.json'));
        fs.writeFileSync(dir + '/recovery-grant.json', JSON.stringify({...grant, expires: 1}));
        await page.locator('[name=confirmation]').check();
        await page.getByRole('button', {name: '确认恢复', exact: true}).click();
        await page.waitForFunction(() => document.querySelector('[data-update-status]').textContent.includes('授权已失效'));
        assert.match(await page.locator('[data-update-status]').textContent(), /授权已失效/);
        await page.locator('[data-update-fallback]').click();
        await login(page);
        // Native POST with JS disabled still offers a working safe GET link.
        const native = await browser.newContext({javaScriptEnabled: false});
        await native.addCookies(await context.cookies());
        const nativePage = await native.newPage();
        await nativePage.goto(origin + '/update-recovery?inspect=1');
        const cookie = (await native.cookies()).find(c => c.name === 'vault_update_recovery');
        assert.equal(cookie.httpOnly, true);
        assert.equal(cookie.sameSite, 'Strict');
        await nativePage.locator('[name=confirmation]').check();
        await nativePage.locator('[name=csrf]').evaluate(input => input.value = 'stale');
        await nativePage.getByRole('button', {name: '确认恢复', exact: true}).click();
        assert.equal(await nativePage.locator('h1').textContent(), '恢复请求未执行');
        assert.equal(await nativePage.getByRole('link', {name: '重新打开恢复页', exact: true}).getAttribute('href'), '/update-recovery?inspect=1');
        assert.equal(journal(), before);
        await native.close();
        // Inspection after reentry must not auto-advance a confirmed running task.
        writeJournal({...JSON.parse(journal()), status: 'running'});
        const freshGrant = JSON.parse(fs.readFileSync(dir + '/recovery-grant.json'));
        fs.writeFileSync(dir + '/recovery-grant.json', JSON.stringify({...freshGrant, confirmed: true}));
        const beforeInspect = posts.length;
        await page.goto(origin + '/update-recovery?inspect=1');
        await page.waitForLoadState('networkidle');
        assert.equal(posts.length, beforeInspect);
        assert.equal(await page.locator('[data-update-continue]').isVisible(), true);
        assert.match(await page.locator('[data-update-status]').textContent(), /确认后继续/);
        // Even the normal automatic progress page must never replay a database restore on GET.
        writeJournal({...JSON.parse(journal()), status: 'restoring', stage: 'health'});
        const beforeRestoring = posts.length;
        await page.goto(origin + '/updates');
        await page.waitForLoadState('networkidle');
        assert.equal(posts.length, beforeRestoring);
        assert.equal(await page.locator('[data-update-restore]').isVisible(), true);
        assert.equal(await page.locator('[data-update-continue]').isVisible(), false);
        assert.match(await page.locator('[data-update-status]').textContent(), /确认后继续恢复/);
        writeJournal({...JSON.parse(journal()), stage: 'prepared'});
        writeJournal({...JSON.parse(journal()), status: 'failed'});
        await page.reload();
        await page.waitForLoadState('networkidle');
        // An interrupted POST is never replayed by the read-only retry button.
        await page.route('**/update-recovery', async route => {
            if (route.request().method() === 'POST') {await route.abort();} else {await route.continue();}
        });
        await page.locator('[name=confirmation]').check();
        await page.getByRole('button', {name: '确认恢复', exact: true}).click();
        await page.getByRole('button', {name: '重新检查', exact: true}).waitFor({state: 'visible'});
        const afterAbort = posts.length;
        await page.getByRole('button', {name: '重新检查', exact: true}).click();
        await page.waitForFunction(() => document.querySelector('[data-update-retry]').hidden);
        assert.equal(posts.length, afterAbort);
        await page.unroute('**/update-recovery');
        // Only a new, explicit restore submission reaches the real frozen engine.
        await page.locator('[name=confirmation]').check();
        await page.getByRole('button', {name: '确认恢复', exact: true}).click();
        await page.locator('[data-update-done]').waitFor({state: 'visible'});
        assert.equal(JSON.parse(journal()).status, 'cancelled');
        assert.equal(await page.locator('[data-update-done]').isVisible(), true);
        assert.deepEqual(errors, []);
        console.log(JSON.stringify({scenarios: 9, result: 'passed', database_accessed: false}));
    } finally {
        if (browser) await browser.close();
        server.kill('SIGTERM');
        await new Promise(resolve => server.once('exit', resolve));
    }
})().catch(error => {console.error(error); process.exitCode = 1;});
JS;
        try {
            $process = proc_open(['node', '-e', $program, $root, PHP_BINARY, $legacy ? '1' : '0'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
            fclose($pipes[0]);
            $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($process), $output);
            $this->assertStringContainsString('"result":"passed"', $output);
        } finally {
            (new Filesystem)->deleteDirectory($root);
        }
    }
}
