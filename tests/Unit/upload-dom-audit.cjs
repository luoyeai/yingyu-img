const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const {Blob} = require('node:buffer');
require('../../public/assets/upload.js');

function uploadPage() {
    let queue;
    class Element {
        constructor() {
            this.events = {};
            this.children = [];
            this.value = '';
            this.options = [];
            this.selectedOptions = [{textContent:'Local'}];
            this.dataset = {};
            this.classList = {add() {}, remove() {}, toggle() { return false; }};
            this.files = [];
        }
        addEventListener(name, handler) { this.events[name] = handler; }
        append(...children) { this.children.push(...children); }
        replaceChildren() { this.children = []; }
        setAttribute(name, value) { this[name] = value; }
        remove() {}
        click() { this.events.click?.({target:this, currentTarget:this}); }
        showModal() {}
        checkValidity() { return true; }
    }
    const nodes = new Map();
    const node = selector => {
        if (selector === '#batch-form') return null;
        if (!nodes.has(selector)) nodes.set(selector, new Element());
        return nodes.get(selector);
    };
    node('#upload-app').dataset = {options:JSON.stringify({quality:90, max_edge:0, lossless:0, batch_limit:50, max_file_mb:100}), endpoint:'/upload', libraryUrl:'/library', trashUrl:'/trash'};
    node('#upload-preset').value = 'standard';
    const events = {};
    const issued = [];
    const revoked = [];
    const confirmations = [];
    const copied = [];
    const window = {
        VaultUpload:{...globalThis.VaultUpload, Queue:class extends globalThis.VaultUpload.Queue {
            constructor(...args) { super(...args); queue = this; }
        }},
        addEventListener:(name, handler) => { events[name] = handler; },
        confirm:message => { confirmations.push(message); return false; },
        isSecureContext:true,
    };
    const document = {querySelector:node, querySelectorAll:() => [], createElement:() => new Element(), addEventListener() {}, body:new Element()};
    vm.runInNewContext(fs.readFileSync(require.resolve('../../public/assets/app.js'), 'utf8'), {
        window, document, Element, navigator:{clipboard:{writeText:async text => { copied.push(text); }}}, localStorage:{getItem() { return null; }}, Blob,
        URL:{createObjectURL:() => { const url = 'blob:' + issued.length; issued.push(url); return url; }, revokeObjectURL:url => revoked.push(url)},
        setTimeout:(callback, delay) => delay === 0 ? setTimeout(callback, 0) : 1, clearTimeout() {},
    });
    const add = state => {
        const item = queue.add({name:'source.png', size:100}, {storage_configuration_id:'1'}, 'Local');
        item.state = state;
        item.element = new Element();
        queue.notify();
        return item;
    };
    const navigate = () => {
        let prevented = false;
        events.beforeunload({preventDefault() { prevented = true; }});
        return prevented;
    };
    return {queue, node, events, issued, revoked, confirmations, copied, window, add, navigate};
}

module.exports = async () => {
    for (const state of ['pending', 'retrying']) {
        const page = uploadPage();
        page.add(state);
        page.queue.pause();
        page.node('#export-json').click();
        assert.equal(page.navigate(), true, `exported ${state} files still need an unload warning`);
        page.node('#clear-queue').click();
        assert.equal(page.confirmations.length, 1, 'clearing exported unfinished files requires confirmation');
        assert.equal(page.queue.items.length, 1, 'declining clear retains the files');
        page.window.confirm = () => true;
        page.node('#clear-queue').click();
        assert.equal(page.queue.items.length, 0);
        assert.equal(page.navigate(), false);
    }
    const directoryPage = uploadPage();
    directoryPage.queue.pause();
    directoryPage.node('#upload-storage').value = '1';
    const png = name => Object.assign(new Blob([Buffer.from('89504e470d0a1a0a','hex')]), {name});
    const leaf = name => ({name, isFile:true, file:resolve => resolve(png(name))});
    const directory = (name, children) => ({name, isDirectory:true, createReader() {
        let offset = 0;
        return {readEntries(resolve) { const chunk = children.slice(offset, offset + 100); offset += chunk.length; resolve(chunk); }};
    }});
    const nested = directory('nested', Array.from({length:207}, (_, index) => leaf(`image-${index}.png`)));
    const bad = {name:'denied', isDirectory:true, createReader:() => ({readEntries(resolve, reject) { reject(Error('denied')); }})};
    const root = directory('photos', [nested, bad]);
    const transfer = {items:[{kind:'file', webkitGetAsEntry:() => root, getAsFile:() => null}], files:[]};
    const ingest = directoryPage.node('#dropzone').events.drop({preventDefault() {}, dataTransfer:transfer});
    assert.equal(directoryPage.navigate(), true, 'directory reading protects navigation before files arrive');
    assert.equal(directoryPage.node('#clear-queue').disabled, true);
    transfer.items.length = 0;
    directoryPage.node('#upload-storage').value = '2';
    directoryPage.node('#upload-max-edge').checkValidity = () => false;
    await ingest;
    assert.equal(directoryPage.queue.items.length, 207, 'all readEntries pages and automatic 50-file batches are retained');
    assert.ok(directoryPage.queue.items.every(item => item.state === 'pending' && item.attempts === 0));
    assert.ok(directoryPage.queue.items.every(item => item.settings.storage_configuration_id === '1'), 'drop keeps the settings captured before directory enumeration');
    assert.equal(directoryPage.queue.items[206].sourcePath, 'photos/nested/image-206.png');
    assert.ok(directoryPage.node('#directory-status').textContent.includes('photos/denied'));
    const exported = globalThis.VaultUpload.records(directoryPage.queue.items);
    assert.equal(exported.length, 207);
    assert.equal(exported[206].relative_directory, 'photos/nested');
    let active = 0, maxActive = 0;
    directoryPage.queue.send = async () => {
        active++; maxActive = Math.max(maxActive, active); await Promise.resolve(); active--;
        return {image:{id:1,url:'https://example.test/1.webp',preview_url:'/preview/1',source_width:1,source_height:1,width:1,height:1,source_bytes:10,bytes:10},duplicate:true,trashed:false};
    };
    await directoryPage.queue.resume();
    assert.equal(maxActive, 1);
    assert.ok(directoryPage.queue.items.every(item => item.state === 'reused'));
    const ordinary = await globalThis.VaultUpload.droppedSources({items:[],files:[png('plain.png')]});
    assert.equal(ordinary.sources.length, 1);
    const unsupported = await globalThis.VaultUpload.droppedSources({items:[{kind:'file',getAsFile:() => null}],files:[]});
    assert.equal(unsupported.errors.length, 1);
    const template = fs.readFileSync(require.resolve('../../resources/views/upload.blade.php'), 'utf8');
    assert.ok(!template.includes('choose-folder') && !template.includes('folder-input'));
    assert.ok(!template.includes('每批最多'));
    const descendants = element => [element, ...element.children.flatMap(descendants)];
    for (const availability of ['active', 'trashed', 'disabled', 'both']) {
        const duplicatePage = uploadPage();
        const duplicate = duplicatePage.add(availability === 'active' ? 'reused' : 'unavailable');
        duplicate.result = {image:{id:71, display_name:'Original <name>', original_name:'first.webp', group_id:9, disabled_at:['disabled','both'].includes(availability) ? '2026-10-30' : null, url:'https://example.test/71.webp', preview_url:'/preview/71', source_width:10, source_height:10, width:10, height:10, source_bytes:100, bytes:70}, duplicate:true, trashed:['trashed','both'].includes(availability)};
        duplicatePage.queue.notify();
        const elements = descendants(duplicate.element);
        assert.ok(duplicatePage.node('#queue-summary').textContent.includes('新增 0 · 重复 1 · 失败 0'));
        assert.equal(elements.some(element => element.textContent === '复制链接'), availability === 'active');
        assert.equal(elements.some(element => element.textContent === '局部对比'), availability === 'active');
        assert.equal(elements.some(element => element.className === 'result-link'), availability === 'active');
        const view = elements.find(element => element.textContent === '查看原记录');
        assert.ok(view);
        view.click();
        assert.equal(duplicatePage.node('#existing-name').textContent, 'Original <name>');
        assert.ok(duplicatePage.node('#existing-meta').textContent.includes('记录 #71'));
        assert.equal(duplicatePage.node('#existing-library').href, duplicate.result.trashed ? '/trash' : '/library');
        assert.ok(duplicatePage.node('#existing-status').textContent.includes(duplicate.result.trashed ? '本次未恢复' : availability === 'disabled' ? '本次未启用' : '图片已存在，已复用原链接'));
        const record = globalThis.VaultUpload.records([duplicate])[0];
        assert.equal(record.url, availability === 'active' ? duplicate.result.image.url : '');
        assert.equal(record.duplicate, true);
        assert.equal(record.link_available, availability === 'active');
    }
    const page = uploadPage();
    const item = page.add('done');
    item.format = 'PNG';
    item.result = {image:{id:1, url:'https://example.test/1.webp', preview_url:'/preview/1', source_width:10, source_height:10, width:10, height:10, source_bytes:100, bytes:70}, duplicate:false, trashed:false};
    item.result.image.url = 'https://example.test/' + 'long-directory/'.repeat(30) + '1.webp';
    item.result.image.alt = '<script>alert("fixture")</script>';
    page.queue.notify();
    let actions = item.element.children.at(-1);
    let link = actions.children.find(element => element.className === 'result-link');
    let format = actions.children[0];
    const copy = () => actions.children.find(element => element.textContent === '复制链接').click();
    assert.equal(link.textContent, item.result.image.url);
    assert.equal(link.title, item.result.image.url);
    assert.equal(link['aria-readonly'], 'true');
    assert.equal(actions.children.indexOf(link) + 1, actions.children.findIndex(element => element.textContent === '复制链接'));
    copy();
    assert.equal(page.copied.at(-1), item.result.image.url, 'copy includes the full long URL');
    format.value = 'html'; format.events.change();
    assert.equal(item.format, 'PNG', 'link format must not change the source format used in exports');
    assert.ok(link.textContent.startsWith('<img src="'));
    assert.ok(link.textContent.includes('&lt;script&gt;'));
    assert.equal(link.children.length, 0, 'HTML format is displayed as text');
    copy(); assert.equal(page.copied.at(-1), link.textContent);
    page.queue.notify();
    actions = item.element.children.at(-1);
    link = actions.children.find(element => element.className === 'result-link');
    format = actions.children[0];
    assert.equal(format.value, 'html', 'queue rendering preserves the chosen format');
    format.value = 'markdown'; format.events.change();
    assert.ok(link.textContent.startsWith('!['));
    copy(); assert.equal(page.copied.at(-1), link.textContent);
    assert.equal(page.navigate(), true, 'completed unexported records need an unload warning');
    page.node('#export-csv').click();
    assert.equal(page.navigate(), false, 'completed exported records allow navigation');
    assert.ok(page.node('#queue-summary').textContent.includes('新增 1 · 重复 0'));
    const compare = descendants(item.element).find(element => element.textContent === '局部对比');
    compare.click();
    const original = page.node('#compare-before').src;
    page.events.pagehide({persisted:true});
    compare.click();
    assert.equal(page.revoked.includes(page.node('#compare-before').src), false, 'restored comparison must not use a revoked URL');
    assert.equal(page.node('#compare-before').src, original);
    page.events.pagehide({persisted:false});
    assert.ok(page.revoked.includes(original), 'leaving without page caching releases the original URL');
};
