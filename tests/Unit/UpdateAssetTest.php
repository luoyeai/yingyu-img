<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class UpdateAssetTest extends TestCase
{
    public function test_same_page_progress_success_failure_restore_and_connection_retry(): void
    {
        $program = <<<'JS'
const assert = require('assert/strict');
const vm = require('vm');
const source = require('fs').readFileSync(process.argv[1], 'utf8');
async function scenario(mode) {
    const handlers = {};
    const element = name => ({hidden: true, disabled: false, textContent: '', addEventListener: (event, handler) => {handlers[name + event] = handler;}});
    const status = element('status'), retry = element('retry'), done = element('done'), fallback = element('fallback'), detail = element('detail');
    const restore = element('restore'), button = element('button'), csrf = {value: ''};
    restore.querySelector = () => button;
    restore.elements = {namedItem: () => csrf};
    const selectors = {'[data-update-status]': status, '[data-update-retry]': retry, '[data-update-done]': done, '[data-update-fallback]': fallback, '[data-update-restore]': restore, '[data-update-detail]': detail, '[data-update-confirm]': {remove: () => {}}};
    const screen = {dataset: {finished: 'false'}, querySelector: selector => selectors[selector]};
    const calls = [];
    const report = (stage, state = 'running') => ({ok: true, stage, status: state, confirmed: true, csrf: 'bound-csrf', error: '', log: []});
    let replies = mode === 'success' ? [report('prepared', 'prepared'), report('files'), report('health'), report('complete', 'complete')]
        : mode === 'restore' ? [report('files', 'failed'), report('restored', 'restored')] : [null, report('complete', 'complete')];
    class Form {constructor(form) {this.values = form ? {csrf: csrf.value, action: 'restore', confirmation: 'restore'} : {};} set(key, value) {this.values[key] = value;}}
    const context = {document: {querySelector: selector => selector === '[data-update-screen]' ? screen : null}, FormData: Form, fetch: async (url, options) => {
        calls.push([url, options]); const data = replies.shift(); if (!data) throw Error('offline');
        return {ok: true, headers: {get: () => 'application/json'}, json: async () => data};
    }, window: {location: {reload: () => {throw Error('unexpected reload');}}}};
    vm.runInNewContext(source, context);
    const settle = () => new Promise(resolve => setImmediate(resolve));
    await settle();
    if (mode === 'restore') {
        assert.equal(restore.hidden, false); assert.equal(status.textContent, '更新未完成，请恢复后重试。');
        handlers.restoresubmit({preventDefault: () => {}}); await settle();
        assert.equal(calls[1][1].body.values.action, 'restore');
        assert.equal(status.textContent, '已恢复更新前版本。');
    } else if (mode === 'retry') {
        assert.equal(retry.hidden, false); assert.equal(fallback.hidden, false);
        handlers.retryclick(); await settle(); assert.equal(status.textContent, '更新完成。');
    } else {
        assert.equal(calls.length, 4); assert.equal(status.textContent, '更新完成。');
        assert.equal(calls[1][1].body.values.stage, 'prepared');
        assert.equal(calls[2][1].body.values.stage, 'files');
        assert.equal(calls[1][1].body.values.csrf, 'bound-csrf');
    }
    assert.equal(done.hidden, false); assert.equal(restore.hidden, true);
    for (const [url, options] of calls) {assert.equal(url, '/update-recovery'); assert.equal(options.credentials, 'same-origin');}
}
(async () => {for (const mode of ['success', 'restore', 'retry']) await scenario(mode);})().catch(error => {console.error(error); process.exitCode = 1;});
JS;
        $process = proc_open(['node', '-e', $program, dirname(__DIR__, 2).'/public/assets/update.js'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $output.$error);
    }

    public function test_progress_script_uses_action_attribute_when_action_input_shadows_form_property(): void
    {
        $script = dirname(__DIR__, 2).'/public/assets/update.js';
        $program = <<<'JS'
const fs = require('fs');
const source = fs.readFileSync(process.argv[1], 'utf8');
const status = {textContent: ''};
const button = {disabled: false};
const form = {
  action: {name: 'action', value: 'advance'},
  getAttribute: name => name === 'action' ? '/update-recovery' : null,
  querySelector: () => button,
  addEventListener: () => {}
};
global.document = {querySelector: selector => selector === '[data-update-progress]' ? form : (selector === '[data-update-status]' ? status : null)};
global.FormData = class { constructor() {} };
let target;
global.fetch = async url => {target = url; return {ok: true, headers: {get: () => 'application/json'}};};
global.window = {setTimeout: callback => callback(), location: {reload: () => {}}};
eval(source);
setImmediate(() => process.exit(target === '/update-recovery' ? 0 : 2));
JS;
        $process = proc_open(['node', '-e', $program, $script], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $output.$error);
    }
}
