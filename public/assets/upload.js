/* Upload queue and source records. No persistent file storage or request-idempotency claim. */
((root) => {
    'use strict';
    const MAX_RETRIES = 3;
    const FAILURE_LIMIT = 3;
    const labels = {checking:'检查格式', pending:'未上传', uploading:'处理中', retrying:'等待重试', done:'成功', reused:'复用', unavailable:'链接不可用', error:'失败', uncertain:'结果未确认', cancelled:'已取消'};
    const problem = (code, message, extra = {}) => ({code, message, retryable:false, scope:'file', uncertain:false, status:0, ...extra});
    function retryAfter(value, now = Date.now()) {
        if (!value) return 0;
        const milliseconds = /^\d+(?:\.\d+)?$/.test(value.trim()) ? Number(value) * 1000 : Date.parse(value) - now;
        return Number.isFinite(milliseconds) ? Math.max(0, milliseconds) : 0;
    }
    function validUrl(value, relative = false) {
        if (typeof value !== 'string' || !value.trim()) return false;
        if (value !== value.trim() || /[\s\\]/.test(value)) return false;
        if (relative && /^\/(?!\/)/.test(value)) return true;
        try { const url = new URL(value); return ['http:', 'https:'].includes(url.protocol) && !url.username && !url.password; } catch { return false; }
    }
    function validResult(payload) {
        const image = payload?.image;
        return image && Number.isInteger(image.id) && image.id > 0 && typeof payload.duplicate === 'boolean' && typeof payload.trashed === 'boolean'
            && validUrl(image.url) && validUrl(image.preview_url, true)
            && ['source_width','source_height','width','height','source_bytes','bytes'].every(key => Number.isFinite(image[key]) && image[key] > 0);
    }
    async function readResponse(response, now = Date.now()) {
        const status = response.status;
        if (response.redirected || status === 401 || status === 419) {
            throw problem(status === 419 ? 'session_expired' : 'authentication_required', status === 419 ? '会话已过期；请先导出记录，再刷新并重新登录。' : '登录已失效或请求被重定向；请先导出记录，再重新登录。', {status, scope:'queue'});
        }
        const delay = retryAfter(response.headers.get('Retry-After'), now);
        let payload = null;
        if ((response.headers.get('Content-Type') || '').includes('json')) {
            try { payload = await response.json(); } catch {}
        }
        if (status === 413) throw problem('request_too_large', '文件超出服务器请求上限（HTTP 413）。', {status});
        if (status === 422) {
            const errors = payload?.errors && typeof payload.errors === 'object' ? Object.values(payload.errors).flat().filter(value => typeof value === 'string') : [];
            throw problem('validation_failed', errors.join('；') || '文件或上传参数不符合要求（HTTP 422）。', {status});
        }
        if (status === 429) throw problem('rate_limited', '请求被限流（HTTP 429）。', {status, retryable:true, delay, scope:'queue'});
        if (!response.ok) {
            const known = {
                maintenance:['系统正在更新，请等待维护完成后继续。', false, 'queue'],
                write_lock_error:['服务器写入锁不可用，请检查私有目录权限。', false, 'queue'],
                storage_pending:['存储有未完成操作，请在设置中处理后重试。', false, 'queue'],
                storage_configuration:['存储配置不可用，请检查设置。', false, 'queue'],
                storage_unavailable:['所选存储不可用，请重新选择目标后加入队列。', false, 'queue'],
                storage_busy:['存储正在处理其他操作，稍后重试。', true, 'file'],
                storage_error:['保存结果未确认，请检查磁盘、数据库和存储状态。', false, 'queue'],
            };
            if (known[payload?.code]) {
                const [message, retryable, scope] = known[payload.code];
                throw problem(payload.code, message, {status, retryable:retryable && payload.retryable === true, scope, delay, uncertain:payload.code === 'storage_error'});
            }
            if ([502, 504].includes(status)) throw problem('gateway_error', `网关异常（HTTP ${status}），服务器可能已保存图片。`, {status, retryable:true, uncertain:true, delay});
            throw problem('http_error', `服务器返回异常（HTTP ${status}），上传结果未确认；请检查运行状态。`, {status, scope:'queue', uncertain:true});
        }
        if (!validResult(payload)) throw problem('invalid_response', '服务器响应结构无效，上传结果未确认。', {status, retryable:true, uncertain:true});
        return payload;
    }
    async function preflight(file) {
        if (!file.size) return {format:'unknown', error:'文件为空。'};
        const bytes = new Uint8Array(await file.slice(0, 131072).arrayBuffer());
        const text = (start, length) => String.fromCharCode(...bytes.slice(start, start + length));
        if (text(0, 6) === 'GIF87a' || text(0, 6) === 'GIF89a') return {format:'GIF', error:'实际格式为 GIF；当前不支持静态或动态 GIF。'};
        if (bytes[0] === 255 && bytes[1] === 216 && bytes[2] === 255) return {format:'JPEG'};
        if (text(0, 8) === '\x89PNG\r\n\x1a\n') {
            const view = new DataView(bytes.buffer);
            let offset = 8;
            while (offset + 8 <= bytes.length) {
                const length = view.getUint32(offset);
                const type = text(offset + 4, 4);
                if (['acTL','fcTL','fdAT'].includes(type)) return {format:'APNG', error:'不支持动态 PNG（APNG），请先导出为静态图片。'};
                if (length > bytes.length - offset - 12 || type === 'IEND') break;
                offset += length + 12;
            }
            return {format:'PNG'};
        }
        if (text(0, 4) === 'RIFF' && text(8, 4) === 'WEBP') {
            if (text(12, 4) === 'ANIM' || text(12, 4) === 'ANMF' || (text(12, 4) === 'VP8X' && bytes.length > 20 && (bytes[20] & 2))) return {format:'动态 WebP', error:'不支持动态 WebP，请先导出为静态图片。'};
            return {format:'WebP'};
        }
        return {format:'unknown', error:'头部未识别为 JPEG、PNG 或 WebP；其他格式不支持。'};
    }
    function relativePath(file) {
        const path = String(file.webkitRelativePath || '').replace(/\\/g, '/');
        if (!path || path.startsWith('/') || /^[a-z]:/i.test(path) || path.split('/').includes('..')) return '';
        return path;
    }
    async function droppedSources(transfer) {
        // Capture entries synchronously while the drop event still grants access.
        const roots = [...(transfer.items || [])].filter(item => item.kind === 'file').map(item => ({
            entry: item.getAsEntry?.() || item.webkitGetAsEntry?.(), file: item.getAsFile(),
        }));
        const fallback = [...(transfer.files || [])];
        const sources = [], errors = [];
        async function visit(entry, parent = '') {
            const path = parent + entry.name;
            try {
                if (entry.isFile) {
                    const file = await new Promise((resolve, reject) => entry.file(resolve, reject));
                    sources.push({file, sourcePath:path});
                } else if (entry.isDirectory) {
                    const reader = entry.createReader();
                    while (true) {
                        const children = await new Promise((resolve, reject) => reader.readEntries(resolve, reject));
                        if (!children.length) break;
                        for (const child of children) await visit(child, path + '/');
                    }
                } else errors.push(path);
            } catch { errors.push(path); }
        }
        if (roots.length) {
            for (const root of roots) {
                if (root.entry) await visit(root.entry);
                else if (root.file) sources.push({file:root.file, sourcePath:relativePath(root.file)});
                else errors.push('目录读取接口不可用，请多选图片上传');
            }
        } else for (const file of fallback) sources.push({file, sourcePath:relativePath(file)});
        return {sources, errors};
    }
    function records(items) {
        return items.map(item => {
            const path = item.sourcePath;
            return {source_id:item.id, relative_directory:path.includes('/') ? path.slice(0, path.lastIndexOf('/')) : '', filename:item.file.name,
                relative_path:path, format:item.format || '', storage_target:item.storageName, storage_configuration_id:item.settings.storage_configuration_id,
                url:item.result?.image.url || '', status:labels[item.state], code:item.errorCode || (item.result?.trashed ? 'image_trashed' : item.result?.image.disabled_at ? 'image_disabled' : ''),
                reason:item.error || (item.result?.trashed ? '相同图片在回收站，请先恢复。' : item.result?.image.disabled_at ? '链接已停用，请在图片库恢复。' : ''),
                duplicate:Boolean(item.result?.duplicate), link_available:Boolean(item.result && !item.result.trashed && !item.result.image.disabled_at), attempts:item.attempts, automatic_retries:item.retriesUsed,
                http_status:item.httpStatus || '', result_uncertain:Boolean(item.uncertain)};
        });
    }
    function csv(items) {
        const rows = records(items);
        const headers = ['source_id','relative_directory','filename','relative_path','format','storage_target','storage_configuration_id','url','status','code','reason','duplicate','link_available','attempts','automatic_retries','http_status','result_uncertain'];
        const cell = value => {
            let text = String(value ?? '');
            if (/^[\s\u0000-\u001f]*[=+\-@]/.test(text) || /^[\t\r\n]/.test(text)) text = "'" + text;
            return '"' + text.replace(/"/g, '""') + '"';
        };
        return '\ufeff' + [headers, ...rows.map(row => headers.map(key => row[key]))].map(row => row.map(cell).join(',')).join('\r\n') + '\r\n';
    }
    class Queue {
        constructor(send, change = () => {}, environment = {}) {
            this.items = []; this.running = false; this.paused = false; this.reason = ''; this.consecutive = 0; this.nextId = 1;
            this.send = send; this.change = change; this.now = environment.now || Date.now; this.random = environment.random || Math.random;
            this.wait = environment.wait || (delay => new Promise(resolve => { const timer = setTimeout(resolve, Math.min(delay, 2147483647)); this.wake = () => {clearTimeout(timer); resolve();}; }));
        }
        notify() {
            try { this.change(this); } catch { this.paused = true; this.reason = '界面更新失败，队列已暂停；请导出记录后刷新。'; }
        }
        add(file, settings, storageName, sourcePath = relativePath(file)) {
            const item = {id:String(this.nextId++), file, settings:{...settings}, storageName, sourcePath:relativePath({webkitRelativePath:sourcePath}), state:'checking', result:null, error:'', errorCode:'', format:'', attempts:0, retriesUsed:0, retryAt:0, uncertain:false};
            this.items.push(item); return item;
        }
        pause(reason = '已手动暂停；当前一张会完成，随后停止派发。') { this.paused = true; this.reason = reason; this.wake?.(); this.notify(); }
        resume() { this.paused = false; this.reason = ''; this.consecutive = 0; return this.run(); }
        retry(item) {
            if (item.invalid || !['error','uncertain'].includes(item.state)) return;
            item.state = 'pending'; item.error = ''; item.retryAt = 0; this.notify(); return this.run();
        }
        cancel(item) {
            if (!['pending','retrying'].includes(item.state)) return;
            item.state = item.uncertain ? 'uncertain' : 'cancelled'; item.error = item.uncertain ? '已停止重试；此前请求结果未确认。' : item.attempts ? '已停止重试；此前请求未成功。' : '已取消，未发送上传请求。';
            this.wake?.(); this.notify();
        }
        async run() {
            if (this.running || this.paused) return;
            this.running = true; this.notify();
            try {
                let item;
                while (!this.paused && (item = this.items.find(candidate => ['pending','retrying'].includes(candidate.state)))) {
                    const readyAt = Math.max(item.retryAt, this.blockedUntil || 0);
                    if (readyAt > this.now()) { await this.wait(readyAt - this.now()); continue; }
                    if (item.state === 'retrying') item.retriesUsed++;
                    item.state = 'uploading'; item.attempts++; this.notify();
                    try {
                        const result = await this.send(item);
                        if (!validResult(result)) throw problem('invalid_response', '服务器响应结构无效，上传结果未确认。', {retryable:true, uncertain:true});
                        item.result = result; item.uncertain = false; item.error = ''; item.errorCode = ''; item.httpStatus = 200;
                        item.state = result.trashed || result.image.disabled_at ? 'unavailable' : result.duplicate ? 'reused' : 'done'; this.consecutive = 0;
                    } catch (caught) {
                        const error = caught?.code && typeof caught.retryable === 'boolean' ? caught : problem('network_error', '网络中断或请求超时，服务器可能已保存图片。', {retryable:true, uncertain:true});
                        item.error = error.message; item.errorCode = error.code; item.httpStatus = error.status || 0; item.uncertain ||= error.uncertain;
                        if (error.code === 'rate_limited') this.blockedUntil = this.now() + (error.delay || 2000);
                        if (error.retryable && item.retriesUsed < MAX_RETRIES) {
                            const delay = Math.max(error.delay || 0, 2000 * 2 ** item.retriesUsed * (0.75 + this.random() * 0.5));
                            item.retryAt = this.now() + delay; item.state = 'retrying';
                        } else {
                            item.state = item.uncertain ? 'uncertain' : 'error';
                            if (error.retryable) this.consecutive++;
                            else if (error.scope === 'file') this.consecutive = 0;
                            if (error.scope === 'queue' || this.consecutive >= FAILURE_LIMIT) this.pause(error.scope === 'queue' ? error.message : `连续 ${FAILURE_LIMIT} 个文件发生临时故障，已暂停；请检查网络与服务状态。`);
                        }
                    }
                    this.notify();
                }
            } finally { this.running = false; this.wake = null; this.notify(); }
        }
    }
    root.VaultUpload = {Queue, preflight, droppedSources, readResponse, records, csv, labels, MAX_RETRIES, FAILURE_LIMIT, problem};
})(typeof window === 'undefined' ? globalThis : window);
