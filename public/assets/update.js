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
    const resume = screen.querySelector('[data-update-continue]');
    const labels = {prepared: '正在备份…', backup: '正在备份…', files: '正在安装…', migrate: '正在更新数据…', 'migration-running': '正在更新数据…', health: '正在检查…', restoring: '正在恢复…'};
    const setPresentationState = (state, label) => {
        screen.dataset.state = state;
        const badge = screen.querySelector('[data-update-state]');
        if (badge) badge.textContent = label;
    };
    let busy = false;
    let snapshot;
    let automatic = screen.dataset.manual !== 'true' && !/(?:^|[?&])inspect=1(?:&|$)/.test(window.location.search || '');
    const request = async body => {
        const response = await fetch('/update-recovery', {
            method: body ? 'POST' : 'GET', credentials: 'same-origin', cache: 'no-store',
            headers: {'X-Requested-With': 'VaultUpdate'}, ...(body ? {body} : {})
        });
        if (!response.headers.get('content-type')?.includes('application/json')) throw new Error('connection');
        const data = await response.json();
        if ([401, 419].includes(response.status) && ['recovery_auth_required', 'recovery_csrf_expired', 'recovery_login_csrf_expired'].includes(data?.code)) {
            const error = new Error(data.error);
            error.recoveryCode = data.code;
            throw error;
        }
        if (!data || typeof data.stage !== 'string' || typeof data.status !== 'string' || typeof data.csrf !== 'string') throw new Error('connection');
        if (!response.ok && data.ok !== false) throw new Error('connection');
        return data;
    };
    const render = data => {
        snapshot = data;
        const stateLabel = ({prepared:'待确认', running:'更新中', failed:'需要处理', restoring:'待恢复', complete:'已完成', restored:'已恢复', cancelled:'已取消'})[data.status] || '处理中';
        setPresentationState(data.status, !automatic && data.confirmed && data.ok && ['prepared', 'running'].includes(data.status) ? '待继续' : stateLabel);
        const steps = screen.querySelector('[data-update-steps]');
        if (steps) {
            const current = data.stage === 'prepared' && data.confirmed ? 1 : ({prepared:0, backup:1, files:2, migrate:3, 'migration-running':3, health:4, complete:5})[data.stage] || 0;
            steps.querySelectorAll('[data-update-step]').forEach(step => {
                const index = Number(step.dataset.updateStep);
                step.dataset.stepState = data.status === 'complete' || current > index ? 'complete' : current === index ? 'current' : 'pending';
                if (current === index) step.setAttribute('aria-current', 'step');
                else step.removeAttribute('aria-current');
            });
        }
        const version = document.querySelector('[data-update-version]');
        if (version && typeof data.version === 'string') version.textContent = data.version === '0.0.0' ? '旧版' : data.version;
        detail.textContent = [data.error, ...(data.log || [])].filter(Boolean).join('\n');
        restore.elements.namedItem('csrf').value = data.csrf;
        const finished = ['complete', 'restored', 'cancelled'].includes(data.status);
        const failed = data.status === 'failed';
        const restoring = data.status === 'restoring';
        if (data.confirmed || finished || failed || restoring) screen.querySelector('[data-update-confirm]')?.remove();
        done.hidden = !finished;
        restore.hidden = !failed && !restoring;
        retry.hidden = true;
        fallback.hidden = true;
        const resumable = !finished && !failed && !restoring && data.confirmed && data.ok;
        if (resume) resume.hidden = !resumable || automatic;
        if (resumable && !automatic && !resume) {
            fallback.hidden = false;
            fallback.href = '/update-recovery';
            fallback.textContent = '确认继续更新';
        }
        if (finished) {
            status.textContent = data.status === 'complete' ? '更新完成。' : data.status === 'cancelled' ? '更新已取消，当前版本未变更。' : '已恢复更新前版本。';
        } else if (failed) {
            status.textContent = '更新未完成，请恢复后重试。';
        } else if (restoring) {
            status.textContent = '恢复尚未完成，请确认后继续恢复。';
        } else if (!data.confirmed) {
            status.textContent = '等待确认';
        } else {
            status.textContent = automatic ? (labels[data.stage] || '正在更新…') : '已读取任务状态，请确认后继续。';
        }
        return resumable;
    };
    const interrupted = () => {
        automatic = false;
        setPresentationState('interrupted', '连接中断');
        status.textContent = '连接中断，请重新检查。';
        retry.hidden = false;
        fallback.hidden = false;
        fallback.href = '/update-recovery?inspect=1';
        fallback.textContent = '重新打开恢复页';
        if (resume) resume.hidden = true;
    };
    const run = async (body = null) => {
        if (busy) return;
        busy = true;
        retry.disabled = true;
        if (resume) resume.disabled = true;
        restore.querySelector('button').disabled = true;
        try {
            let data = await request(body);
            while (render(data) && automatic) {
                const next = new FormData();
                next.set('csrf', data.csrf);
                next.set('stage', data.stage);
                next.set('action', 'advance');
                data = await request(next);
            }
            if (data.ok === false && data.status !== 'failed') {
                setPresentationState('attention', '需要处理');
                status.textContent = '操作未完成，请重新检查。';
                retry.hidden = false;
            }
        } catch (error) {
            if (error.recoveryCode) {
                automatic = false;
                snapshot = null;
                setPresentationState('auth-required', '需要验证');
                status.textContent = error.message;
                restore.hidden = true;
                retry.hidden = true;
                if (resume) resume.hidden = true;
                fallback.hidden = false;
                const reauthenticate = error.recoveryCode !== 'recovery_csrf_expired';
                fallback.href = reauthenticate ? '/update-recovery?reauth=1' : '/update-recovery?inspect=1';
                fallback.textContent = reauthenticate ? '重新验证管理员身份' : '重新打开恢复页';
            } else {
                interrupted();
            }
        } finally {
            busy = false;
            retry.disabled = false;
            if (resume) resume.disabled = false;
            restore.querySelector('button').disabled = false;
        }
    };
    retry.addEventListener('click', () => { automatic = false; run(); });
    resume?.addEventListener('click', () => { automatic = true; run(); });
    restore.addEventListener('submit', event => {
        event.preventDefault();
        if (!snapshot || busy) return;
        setPresentationState('restoring', '恢复中');
        status.textContent = '正在恢复…';
        run(new FormData(restore));
    });
    run();
})();
