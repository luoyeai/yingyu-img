(() => {
    'use strict';
    const $ = (selector, root = document) => root.querySelector(selector);
    const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];
    const csrf = $('meta[name="csrf-token"]')?.content || '';
    let toastTimer;
    function toast(message) {
        const element = $('#toast');
        if (!element) return;
        clearTimeout(toastTimer);
        element.textContent = message;
        element.hidden = false;
        toastTimer = setTimeout(() => { element.hidden = true; }, 4000);
    }
    function createElement(tag, className, text) {
        const element = document.createElement(tag);
        if (className) element.className = className;
        if (text !== undefined) element.textContent = text;
        return element;
    }
    const formatBytes = bytes => bytes >= 1048576 ? `${(bytes / 1048576).toFixed(2)} MB` : `${(bytes / 1024).toFixed(1)} KB`;
    const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
    const escapeMarkdown = value => String(value ?? '').replace(/[\\\[\]`*_<>]/g, '\\$&').replace(/[\r\n]/g, ' ');
    function imageLink(image, format = 'url') {
        const alt = image.alt || image.display_name || image.original_name || '';
        if (format === 'html') return `<img src="${escapeHtml(image.url)}" alt="${escapeHtml(alt)}" width="${Number(image.width)}" height="${Number(image.height)}">`;
        if (format === 'markdown') return `![${escapeMarkdown(alt)}](<${String(image.url).replace(/>/g, '%3E').replace(/</g, '%3C')}>)`;
        return image.url;
    }
    async function copyText(text) {
        try {
            if (navigator.clipboard && window.isSecureContext) await navigator.clipboard.writeText(text);
            else {
                const input = createElement('textarea', 'clipboard-input');
                input.value = text;
                input.setAttribute('aria-hidden', 'true');
                document.body.append(input);
                input.select();
                const copied = document.execCommand('copy');
                input.remove();
                if (!copied) throw new Error('copy');
            }
            toast('已复制到剪贴板');
        } catch {
            window.prompt('浏览器未允许自动复制，请手动复制：', text);
        }
    }
    $('[data-nav-toggle]')?.addEventListener('click', event => {
        const button = event.currentTarget;
        const open = $('#navigation').classList.toggle('is-open');
        button.setAttribute('aria-expanded', String(open));
    });
    $$('[data-close-dialog]').forEach(button => button.addEventListener('click', () => button.closest('dialog').close()));
    $$('dialog').forEach(dialog => dialog.addEventListener('click', event => { if (event.target === dialog) { const box = dialog.getBoundingClientRect(); if (event.clientX < box.left || event.clientX > box.right || event.clientY < box.top || event.clientY > box.bottom) dialog.close(); } }));
    $$('form[data-confirm]').forEach(form => form.addEventListener('submit', event => {
        if (!window.confirm(form.dataset.confirm)) event.preventDefault();
    }));
    $$('[data-copy-image]').forEach(button => button.addEventListener('click', () => copyText(imageLink(JSON.parse(button.dataset.copyImage), button.dataset.format))));
    let detailImage;
    $$('[data-open-image]').forEach(button => button.addEventListener('click', () => {
        detailImage = JSON.parse(button.dataset.openImage);
        $('#detail-preview').src = detailImage.preview_url;
        $('#detail-preview').alt = detailImage.alt || detailImage.display_name;
        $('#detail-meta').textContent = `原名：${detailImage.original_name} · ${detailImage.source_width} × ${detailImage.source_height} / ${formatBytes(detailImage.source_bytes)} → ${detailImage.width} × ${detailImage.height} / ${formatBytes(detailImage.bytes)}${detailImage.disabled_at ? ' · 链接已停用' : ''}`;
        $('#detail-url').value = detailImage.url;
        $('#detail-name').value = detailImage.display_name;
        $('#detail-alt').value = detailImage.alt || '';
        $('#detail-group').value = detailImage.group_id || '';
        $('#image-edit-form').action = detailImage.edit_url;
        const original = $('#detail-original');
        original.hidden = !detailImage.original_url;
        if (detailImage.original_url) original.href = detailImage.original_url;
        $('#image-dialog').showModal();
    }));
    $$('[data-detail-copy]').forEach(button => button.addEventListener('click', () => { if (detailImage) copyText(imageLink(detailImage, button.dataset.detailCopy)); }));
    $('#detail-url')?.addEventListener('click', event => event.currentTarget.select());
    const batchForm = $('#batch-form');
    if (batchForm) {
        const checkboxes = $$('input[name="ids[]"]', batchForm);
        const updateSelection = () => {
            const count = checkboxes.filter(input => input.checked).length;
            $('#selected-count').textContent = `已选 ${count} 张`;
            $('#select-all').checked = checkboxes.length > 0 && count === checkboxes.length;
            $('#select-all').indeterminate = count > 0 && count < checkboxes.length;
        };
        $('#select-all').addEventListener('change', event => { checkboxes.forEach(input => { input.checked = event.target.checked; }); updateSelection(); });
        checkboxes.forEach(input => input.addEventListener('change', updateSelection));
        batchForm.addEventListener('submit', event => {
            const count = checkboxes.filter(input => input.checked).length;
            if (!count) { event.preventDefault(); toast('请先选择图片'); return; }
            const action = $('#batch-action').value;
            $('#batch-confirmation').value = '';
            if (action === 'purge') {
                if (!window.confirm(`永久删除所选 ${count} 张图片及其 WebP、缩略图与私有原图？公开链接将失效，操作不可恢复。`)) { event.preventDefault(); return; }
                const confirmation = window.prompt(`即将永久删除 ${count} 张图片。请输入“永久删除”确认：`);
                if (confirmation !== '永久删除') { event.preventDefault(); toast('未确认永久删除，操作已取消'); return; }
                $('#batch-confirmation').value = confirmation;
            } else if (action === 'disable' && !window.confirm(`立即停用所选 ${count} 张图片的公开链接？CDN、浏览器和第三方缓存副本无法立即撤回，需要另行清理CDN缓存。`)) event.preventDefault();
            else if (action === 'trash' && !window.confirm(`将所选 ${count} 张图片移入回收站？保留 30 天，期间外链仍然有效。`)) event.preventDefault();
        });
    }
    const uploadApp = $('#upload-app');
    if (!uploadApp) return;
    const options = JSON.parse(uploadApp.dataset.options);
    const group = $('#upload-group');
    try { const savedGroup = localStorage.getItem('vault:last-group'); if (savedGroup && [...group.options].some(option => option.value === savedGroup)) group.value = savedGroup; } catch {}
    group.addEventListener('change', () => { try { localStorage.setItem('vault:last-group', group.value); } catch {} });
    const preset = $('#upload-preset');
    const quality = $('#upload-quality');
    const maxEdge = $('#upload-max-edge');
    const lossless = $('#upload-lossless');
    function presetDescription() {
        $('#quality-value').value = quality.value;
        $('#preset-description').textContent = preset.value === 'standard' ? '通用预设使用设置中的默认参数。更改处理参数将切换至自定义。' : preset.value === 'high' ? '高清预设：质量 95，保留原尺寸；静态 WebP 尽量避免重复有损编码。' : '自定义参数用于后续加入队列的图片，已排队图片保留加入时的设置。';
    }
    preset.addEventListener('change', () => {
        if (preset.value === 'standard') { quality.value = options.quality; maxEdge.value = options.max_edge; lossless.checked = Boolean(Number(options.lossless)); }
        if (preset.value === 'high') { quality.value = 95; maxEdge.value = 0; lossless.checked = false; }
        presetDescription();
    });
    [quality, maxEdge, lossless].forEach(input => input.addEventListener('input', () => { preset.value = 'custom'; presetDescription(); }));
    presetDescription();
    const core = window.VaultUpload;
    const controller = new core.Queue(sendItem, updateQueue);
    const queue = controller.items;
    let checking = 0;
    let exportedSignature = '';
    const signature = () => JSON.stringify(core.records(queue));
    const hasPendingFiles = () => queue.some(item => ['checking','pending','uploading','retrying'].includes(item.state));
    async function sendItem(item) {
        const body = new FormData(); body.append('file', item.file);
        Object.entries(item.settings).forEach(([key,value]) => body.append(key,value));
        const abort = new AbortController();
        const timer = setTimeout(() => abort.abort(), 120000);
        try {
            const response = await fetch(uploadApp.dataset.endpoint, {method:'POST', credentials:'same-origin', headers:{'X-CSRF-TOKEN':csrf, 'Accept':'application/json'}, body, signal:abort.signal});
            return await core.readResponse(response);
        } finally { clearTimeout(timer); }
    }
    function updateQueue() {
        let failed = false;
        for (const item of queue) {
            try { renderItem(item); } catch { failed = true; }
        }
        if (failed) {
            controller.paused = true;
            controller.reason = '部分结果无法显示，队列已暂停；请导出记录后刷新。';
        }
        summary();
    }
    let dragDepth = 0;
    const input = $('#file-input');
    $('#choose-files').addEventListener('click', () => input.click());
    input.addEventListener('change', () => { void enqueueFiles([...input.files]); input.value = ''; });
    const dropzone = $('#dropzone');
    dropzone.addEventListener('dragenter', event => { event.preventDefault(); dragDepth++; dropzone.classList.add('dragging'); });
    dropzone.addEventListener('dragover', event => { event.preventDefault(); event.dataTransfer.dropEffect = 'copy'; });
    dropzone.addEventListener('dragleave', event => { event.preventDefault(); if (--dragDepth <= 0) { dropzone.classList.remove('dragging'); dragDepth = 0; } });
    dropzone.addEventListener('drop', async event => {
        event.preventDefault(); dragDepth = 0; dropzone.classList.remove('dragging');
        if (!maxEdge.checkValidity()) { maxEdge.reportValidity(); return; }
        const selection = uploadSelection();
        checking++; summary();
        const status = $('#directory-status'); status.hidden = false; status.textContent = '正在读取图片和文件夹，请保持页面打开…';
        try {
            const {sources, errors} = await core.droppedSources(event.dataTransfer);
            status.textContent = errors.length ? `有 ${errors.length} 项未能读取：${errors.slice(0, 3).join('、')}。请检查权限后重新拖入；其余可读取文件已加入队列。` : sources.length ? `已读取 ${sources.length} 个文件，自动分批排队、逐张上传。` : '未找到可读取文件；若文件夹非空，请使用支持目录拖拽的浏览器或多选图片上传。';
            await enqueueFiles(sources, selection);
        } catch { status.textContent = '目录读取失败，请检查文件访问权限后重试，或多选图片上传。'; }
        finally { checking--; summary(); if (!checking) void controller.run(); }
    });
    document.addEventListener('paste', event => {
        if (event.target instanceof Element && event.target.closest('input,textarea,[contenteditable="true"]')) return;
        const files = [...event.clipboardData.items].filter(item => item.kind === 'file').map(item => item.getAsFile()).filter(Boolean);
        if (files.length) { event.preventDefault(); enqueueFiles(files); }
    });
    window.addEventListener('dragover', event => { if (event.dataTransfer?.types.includes('Files')) event.preventDefault(); });
    window.addEventListener('drop', event => { if (event.dataTransfer?.types.includes('Files')) event.preventDefault(); });
    function summary() {
        const completed = queue.filter(item => ['done','reused'].includes(item.state)).length;
        const failed = queue.filter(item => item.state === 'error').length;
        const unknown = queue.filter(item => item.state === 'uncertain').length;
        const pending = queue.filter(item => ['checking','pending','uploading','retrying'].includes(item.state)).length;
        const unavailable = queue.filter(item => item.state === 'unavailable').length;
        const cancelled = queue.filter(item => item.state === 'cancelled').length;
        const waiting = queue.find(item => item.state === 'retrying');
        $('#queue-count').textContent = `(${queue.length})`;
        $('#queue-summary').textContent = queue.length ? `成功/复用 ${completed} · 失败 ${failed} · 结果未确认 ${unknown} · 待处理 ${pending} · 链接不可用 ${unavailable} · 取消 ${cancelled}${checking ? ' · 正在读取/检查文件，请保持页面打开' : ''}${controller.paused ? ` · 已暂停：${controller.reason}` : waiting ? ' · 等待退避重试' : controller.running ? ' · 逐张处理中' : ''}` : '选择或拖入图片、文件夹后自动分批处理，单张永久失败不影响其他图片。';
        $('#queue-empty').hidden = queue.length > 0;
        $('#pause-queue').hidden = controller.paused;
        $('#pause-queue').disabled = !controller.running && !pending;
        $('#resume-queue').hidden = !controller.paused;
        $('#clear-queue').disabled = controller.running || checking > 0;
        for (const id of ['export-csv','export-json']) $( '#' + id).disabled = !queue.length;
    }
    function uploadSelection() {
        const storage = $('#upload-storage');
        return {settings:{storage_configuration_id:storage.value, preset:preset.value, group_id:group.value, quality:quality.value, max_edge:maxEdge.value || '0', lossless:lossless.checked ? '1' : '0'}, storageName:storage.selectedOptions[0].textContent};
    }
    async function enqueueFiles(files, selection) {
        if (!files.length) return;
        if (!selection) {
            if (!maxEdge.checkValidity()) { maxEdge.reportValidity(); return; }
            selection = uploadSelection();
        }
        const batchSize = Math.max(1, Math.floor(Number(options.batch_limit) || 50));
        checking++;
        try {
            for (let offset = 0; offset < files.length; offset += batchSize) {
                const items = files.slice(offset, offset + batchSize).map(source => {
                    const file = source.file || source;
                    const item = controller.add(file, selection.settings, selection.storageName, source.sourcePath);
                    item.element = createElement('article', 'upload-result');
                    $('#upload-results').append(item.element); return item;
                });
                updateQueue();
                for (const item of items) {
                    try {
                        if (item.file.size > Number(options.max_file_mb) * 1048576) throw new Error(`单张文件不能超过 ${options.max_file_mb} MB`);
                        const inspection = await core.preflight(item.file);
                        item.format = inspection.format;
                        if (inspection.error) throw new Error(inspection.error);
                        item.state = 'pending';
                    } catch (error) { item.state = 'error'; item.invalid = true; item.errorCode = 'preflight_failed'; item.error = error.message || '无法读取文件头，请重新选择。'; }
                    updateQueue();
                }
                await new Promise(resolve => setTimeout(resolve, 0));
            }
        } finally { checking--; summary(); if (!checking) void controller.run(); }
    }
    function renderItem(item) {
        const rendering = JSON.stringify([item.state, item.error, item.errorCode, item.attempts, item.retriesUsed, item.retryAt, item.format, item.result]);
        if (rendering === item.rendering) return;
        const row = item.element;
        row.replaceChildren();
        if (item.result) {
            const thumbnail = createElement('img', 'result-thumbnail');
            thumbnail.src = item.result.image.preview_url;
            thumbnail.alt = item.file.name;
            row.append(thumbnail);
        } else {
            const placeholder = createElement('div', 'result-thumbnail');
            placeholder.setAttribute('aria-hidden','true');
            row.append(placeholder);
        }
        const identity = createElement('div');
        identity.append(createElement('p', 'result-filename', item.file.name));
        if (item.sourcePath) identity.append(createElement('p', 'caption result-source', item.sourcePath));
        let status = core.labels[item.state];
        if (item.error) status += `：${item.error}`;
        if (item.state === 'retrying') status += ` · 不早于 ${new Date(item.retryAt).toLocaleTimeString()} 重试`;
        if (item.result?.trashed) status += '：相同图片在回收站，请先恢复';
        else if (item.result?.image.disabled_at) status += '：链接已停用，请在图片库恢复';
        if (item.attempts) status += ` · 已尝试 ${item.attempts} 次（自动重试 ${item.retriesUsed}/${core.MAX_RETRIES}）`;
        identity.append(createElement('p', `result-status${['error','uncertain'].includes(item.state) ? ' result-error' : ''}`, status));
        row.append(identity);
        const measure = createElement('div', 'result-measure');
        if (item.result) {
            const image = item.result.image;
            measure.append(createElement('div', '', `原图 ${image.source_width} × ${image.source_height} · ${formatBytes(image.source_bytes)}`));
            measure.append(createElement('strong', '', `WebP ${image.width} × ${image.height} · ${formatBytes(image.bytes)}`));
        } else measure.append(createElement('div', '', `原文件 ${formatBytes(item.file.size)}`));
        row.append(measure);
        const actions = createElement('div', 'result-actions');
        if (['error','uncertain'].includes(item.state) && !item.invalid) {
            const retry = createElement('button', 'button small', '重试此项'); retry.type = 'button';
            retry.addEventListener('click', () => { void controller.retry(item); }); actions.append(retry);
        }
        if (['pending','retrying'].includes(item.state)) {
            const cancel = createElement('button', 'button small', '取消此项'); cancel.type = 'button';
            cancel.addEventListener('click', () => controller.cancel(item)); actions.append(cancel);
        }
        if (item.result) {
            if (item.result.trashed) { const link = createElement('a', 'button small', '前往回收站'); link.href = uploadApp.dataset.trashUrl; actions.append(link); }
            else if (!item.result.image.disabled_at) {
                const format = createElement('select'); format.setAttribute('aria-label', `选择 ${item.file.name} 的链接格式`);
                [['url','直链'],['markdown','Markdown'],['html','HTML']].forEach(([value,label]) => { const option = createElement('option','',label); option.value = value; format.append(option); });
                const copy = createElement('button','button small','复制链接'); copy.type = 'button'; copy.addEventListener('click', () => copyText(imageLink(item.result.image, format.value)));
                actions.append(format,copy);
                const compare = createElement('button','text-button','局部对比'); compare.type = 'button'; compare.addEventListener('click', () => compareImages(item)); actions.append(compare);
            }
        }
        row.append(actions);
        item.rendering = rendering;
    }
    const compareDialog = $('#compare-dialog');
    function setCompareScale() {
        const scale = Number($('#compare-zoom').value) / 100;
        $('#compare-scale').value = `${Math.round(scale * 100)}%`;
        ['#compare-before','#compare-after'].forEach(selector => { const image = $(selector); if (image.naturalWidth) { image.setAttribute('width', String(Math.round(image.naturalWidth * scale))); image.setAttribute('height', String(Math.round(image.naturalHeight * scale))); } });
    }
    $('#compare-zoom').addEventListener('input',setCompareScale);
    ['#compare-before','#compare-after'].forEach(selector => $(selector).addEventListener('load',setCompareScale));
    function compareImages(item) {
        const image = item.result.image;
        if (!item.originalUrl) item.originalUrl = URL.createObjectURL(item.file);
        $('#compare-before').src = item.originalUrl;
        $('#compare-after').src = image.url;
        $('#compare-before-meta').textContent = `${image.source_width} × ${image.source_height} / ${formatBytes(image.source_bytes)}`;
        $('#compare-after-meta').textContent = `${image.width} × ${image.height} / ${formatBytes(image.bytes)}`;
        $('#compare-zoom').value = '100';
        setCompareScale();
        compareDialog.showModal();
    }
    $('#pause-queue').addEventListener('click', () => controller.pause());
    $('#resume-queue').addEventListener('click', () => { void controller.resume(); });
    function downloadRecords(format) {
        const content = format === 'csv' ? core.csv(queue) : JSON.stringify(core.records(queue), null, 2);
        const url = URL.createObjectURL(new Blob([content], {type:format === 'csv' ? 'text/csv;charset=utf-8' : 'application/json;charset=utf-8'}));
        const link = createElement('a'); link.href = url; link.download = `yingyu-upload-${new Date().toISOString().replace(/[:.]/g, '-')}.${format}`;
        document.body.append(link); link.click(); link.remove(); setTimeout(() => URL.revokeObjectURL(url), 1000);
        exportedSignature = signature(); toast('已发起记录下载；后续状态变化后请重新导出。');
    }
    $('#export-csv').addEventListener('click', () => downloadRecords('csv'));
    $('#export-json').addEventListener('click', () => downloadRecords('json'));
    $('#clear-queue').addEventListener('click', () => {
        if (controller.running || checking) { toast('请先暂停并等待当前处理完成'); return; }
        const pendingFiles = hasPendingFiles();
        if (queue.length && (pendingFiles || signature() !== exportedSignature) && !window.confirm(pendingFiles ? '队列仍有待处理文件；导出记录不包含原文件。清空后无法继续这些上传，仍要清空？' : '本次来源记录尚未导出或已变化。清空后无法恢复；建议先导出 CSV/JSON。仍要清空？')) return;
        queue.forEach(item => { if (item.originalUrl) URL.revokeObjectURL(item.originalUrl); item.element.remove(); });
        queue.length = 0; controller.paused = false; controller.reason = ''; controller.consecutive = 0; exportedSignature = ''; summary();
    });
    window.addEventListener('beforeunload', event => {
        if (controller.running || checking || hasPendingFiles() || (queue.length && signature() !== exportedSignature)) { event.preventDefault(); event.returnValue = ''; }
    });
    window.addEventListener('pagehide', event => { if (!event.persisted) queue.forEach(item => { if (item.originalUrl) URL.revokeObjectURL(item.originalUrl); }); });
    summary();
})();
