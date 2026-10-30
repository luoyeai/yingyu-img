'use strict';
(() => {
    const screen = document.querySelector('[data-update-screen]');
    if (!screen) {
        // A pre-1.0.2 frozen recovery page must keep working after asset replacement.
        const legacy = document.querySelector('[data-update-progress]');
        if (!legacy) return;
        let running = false;
        const advance = async event => {
            event?.preventDefault();
            if (running) return;
            running = true;
            const button = legacy.querySelector('button');
            button.disabled = true;
            try {
                const response = await fetch(legacy.getAttribute('action'), {method: 'POST', credentials: 'same-origin', body: new FormData(legacy), headers: {'X-Requested-With': 'VaultUpdate'}});
                if (!response.ok || !response.headers.get('content-type')?.includes('application/json')) throw new Error('connection');
                window.location.reload();
            } catch {
                document.querySelector('[data-update-status]').textContent = '操作未完成，请刷新后重试。';
                button.disabled = false;
            } finally {
                running = false;
            }
        };
        legacy.addEventListener('submit', advance);
        window.setTimeout(() => advance(), 500);
        return;
    }
    if (screen.dataset.finished === 'true') return;
    const status = screen.querySelector('[data-update-status]');
    const retry = screen.querySelector('[data-update-retry]');
    const done = screen.querySelector('[data-update-done]');
    const fallback = screen.querySelector('[data-update-fallback]');
    const restore = screen.querySelector('[data-update-restore]');
    const detail = screen.querySelector('[data-update-detail]');
    const labels = {prepared: '正在备份…', backup: '正在备份…', files: '正在安装…', migrate: '正在更新数据…', 'migration-running': '正在更新数据…', health: '正在检查…', restoring: '正在恢复…'};
    let busy = false;
    let snapshot;
    const request = async body => {
        const response = await fetch('/update-recovery', {
            method: body ? 'POST' : 'GET', credentials: 'same-origin', cache: 'no-store',
            headers: {'X-Requested-With': 'VaultUpdate'}, ...(body ? {body} : {})
        });
        if (!response.headers.get('content-type')?.includes('application/json')) throw new Error('connection');
        const data = await response.json();
        if (!data || typeof data.stage !== 'string' || typeof data.status !== 'string' || typeof data.csrf !== 'string') throw new Error('connection');
        if (!response.ok && data.ok !== false) throw new Error('connection');
        return data;
    };
    const render = data => {
        snapshot = data;
        const version = document.querySelector('[data-update-version]');
        if (version && typeof data.version === 'string') version.textContent = data.version === '0.0.0' ? '旧版' : data.version;
        detail.textContent = [data.error, ...(data.log || [])].filter(Boolean).join('\n');
        restore.elements.namedItem('csrf').value = data.csrf;
        const finished = ['complete', 'restored', 'cancelled'].includes(data.status);
        const failed = data.status === 'failed';
        done.hidden = !finished;
        restore.hidden = !failed;
        retry.hidden = true;
        fallback.hidden = true;
        if (finished) {
            status.textContent = data.status === 'complete' ? '更新完成。' : '已恢复更新前版本。';
        } else if (failed) {
            status.textContent = '更新未完成，请恢复后重试。';
        } else if (!data.confirmed) {
            status.textContent = '等待确认';
        } else {
            screen.querySelector('[data-update-confirm]')?.remove();
            status.textContent = labels[data.stage] || '正在更新…';
        }
        return !finished && !failed && data.confirmed && data.ok;
    };
    const interrupted = () => {
        status.textContent = '连接中断，请重新检查。';
        retry.hidden = false;
        fallback.hidden = false;
    };
    const run = async (body = null) => {
        if (busy) return;
        busy = true;
        retry.disabled = true;
        restore.querySelector('button').disabled = true;
        try {
            let data = await request(body);
            while (render(data)) {
                const next = new FormData();
                next.set('csrf', data.csrf);
                next.set('stage', data.stage);
                next.set('action', data.status === 'restoring' ? 'restore' : 'advance');
                if (data.status === 'restoring') next.set('confirmation', 'restore');
                data = await request(next);
            }
            if (data.ok === false && data.status !== 'failed') {
                status.textContent = '操作未完成，请重新检查。';
                retry.hidden = false;
            }
        } catch {
            interrupted();
        } finally {
            busy = false;
            retry.disabled = false;
            restore.querySelector('button').disabled = false;
        }
    };
    retry.addEventListener('click', () => run());
    restore.addEventListener('submit', event => {
        event.preventDefault();
        if (!snapshot || busy) return;
        status.textContent = '正在恢复…';
        run(new FormData(restore));
    });
    run();
})();
