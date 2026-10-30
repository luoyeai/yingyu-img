const assert = require('node:assert/strict');
const {Blob} = require('node:buffer');
require('../../public/assets/upload.js');
const {Queue, preflight, readResponse, records, csv} = globalThis.VaultUpload;
const image = {id:1, url:'https://example.test/media/1.webp', preview_url:'/images/1/preview', source_width:10, source_height:10, width:10, height:10, source_bytes:100, bytes:60, disabled_at:null};
const good = () => ({image:{...image}, duplicate:false, trashed:false});
const response = (status, payload, type='application/json', headers={}) => ({status, ok:status>=200 && status<300, headers:{get:key=>headers[key] || (key==='Content-Type'?type:null)}, json:async()=>{if (typeof payload==='function')return payload();return payload;}});
const file = (name='same.png', path='') => Object.assign(new Blob([Buffer.from('89504e470d0a1a0a','hex')]), {name, webkitRelativePath:path});
const add = (q, name='same.png', path='') => {const item=q.add(file(name,path), {storage_configuration_id:'2'}, 'Cloud');item.state='pending';return item;};
const failure = (extra={}) => ({code:'gateway_error', message:'网关异常', retryable:true, scope:'file', uncertain:true,...extra});
const tick = () => new Promise(resolve=>setImmediate(resolve));
const suite = process.argv[2];
(async()=>{
if(suite==='response') {
    assert.equal((await readResponse(response(200,good()))).image.id,1);
    for(const bad of [null,{}, {...good(), image:{...image,url:''}}, {...good(),image:{...image,url:'garbage'}}, {...good(),image:{...image,url:'   '}}, {...good(),image:{...image,url:'javascript:alert(1)'}}, {...good(),image:{...image,preview_url:'garbage'}}, {...good(),image:{...image,preview_url:''}}]) {
        await assert.rejects(readResponse(response(200,bad)), e=>e.code==='invalid_response' && e.uncertain);
    }
    for(const status of [413,422,401,419,429,502,504,503,500]) {
        await assert.rejects(readResponse(response(status,null,'text/html')), e=>e.status===status && !e.message.includes('<html>'));
    }
    await assert.rejects(readResponse({...response(200,good()),redirected:true}),e=>e.code==='authentication_required' && e.scope==='queue');
    await assert.rejects(readResponse(response(200,()=>{throw Error('bad json')})),e=>e.code==='invalid_response');
    await assert.rejects(readResponse(response(429,null,'text/html',{'Retry-After':'5'}),1000),e=>e.delay===5000);
    await assert.rejects(readResponse(response(429,null,'text/html',{'Retry-After':'Thu, 29 Oct 2026 08:00:10 GMT'}),Date.parse('2026-10-29T08:00:00Z')),e=>e.delay===10000);
    for(const code of ['maintenance','storage_pending','storage_configuration','storage_unavailable','write_lock_error']) await assert.rejects(readResponse(response(503,{code,retryable:true})),e=>!e.retryable && e.scope==='queue');
    await assert.rejects(readResponse(response(503,{code:'storage_busy',retryable:true})),e=>e.retryable && !e.uncertain);
    await assert.rejects(readResponse(response(422,{errors:{file:['不支持 GIF']}})),e=>e.message==='不支持 GIF');
}
if(suite==='queue') {
    let now=0, calls=0, active=0, max=0;
    const environment={now:()=>now, random:()=>0.5, wait:async delay=>{now+=delay;}};
    const q=new Queue(async()=>{active++;max=Math.max(max,active);await tick();active--;if(++calls<3)throw failure();return good();},()=>{},environment);
    const first=add(q);add(q);await q.run();assert.equal(first.attempts,3);assert.equal(first.retriesUsed,2);assert.equal(max,1);assert.equal(q.running,false);assert.equal(first.state,'done');assert.equal(records([first])[0].automatic_retries,2);assert.ok(csv([first]).includes('"automatic_retries"'));
    let resolve;
    const paused=new Queue(()=>new Promise(r=>resolve=r)); const a=add(paused);const b=add(paused);const run=paused.run();paused.pause();resolve(good());await run;
    assert.equal(a.state,'done');assert.equal(b.attempts,0);assert.equal(paused.running,false);
    paused.send=async()=>good();await paused.resume();assert.equal(b.state,'done');
    const stop=new Queue(async()=>{throw failure();},()=>{},environment);for(let i=0;i<4;i++)add(stop);await stop.run();assert.equal(stop.paused,true);assert.deepEqual(stop.items.map(i=>i.attempts),[4,4,4,0]);
    await stop.resume();assert.equal(stop.items[3].attempts,4);const spent=stop.items[0];await stop.retry(spent);assert.equal(spent.attempts,5);assert.equal(spent.retriesUsed,3);
    const permanent=new Queue(async i=>{if(i.id==='1')throw failure({retryable:false,uncertain:false,code:'validation_failed'});return good();},()=>{},environment);add(permanent);add(permanent);await permanent.run();assert.equal(permanent.items[0].state,'error');assert.equal(permanent.items[1].state,'done');
    const auth=new Queue(async()=>{throw failure({scope:'queue',code:'authentication_required',retryable:false,uncertain:false});});add(auth);add(auth);await auth.run();assert.equal(auth.paused,true);assert.equal(auth.items[1].attempts,0);
    const render=new Queue(async()=>good(),()=>{throw Error('render');});add(render);await render.run();assert.equal(render.running,false);assert.equal(render.paused,true);
    let count=0;const uncertain=new Queue(async()=>{if(++count===1)throw failure();throw failure({code:'validation_failed',retryable:false,uncertain:false});},()=>{},environment);const u=add(uncertain);await uncertain.run();assert.equal(u.state,'uncertain');assert.equal(records([u])[0].result_uncertain,true);
    let q429, observed=[];
    now=0;
    q429=new Queue(async item=>{observed.push([item.id,now]);if(item.id==='1')throw failure({code:'rate_limited',uncertain:false,scope:'queue',delay:10000});return good();},()=>{}, {...environment,wait:async delay=>{q429.cancel(q429.items[0]);now+=delay;}});
    add(q429);add(q429);await q429.run();assert.equal(observed[1][1],10000);assert.equal(q429.items[0].state,'cancelled');
    now=0;let waitingResolve;
    const cooldown=new Queue(async()=>{throw failure({code:'rate_limited',scope:'queue',uncertain:false,delay:10000});},()=>{}, {...environment,wait:()=>new Promise(r=>{waitingResolve=r;})});const c=add(cooldown);const coolRun=cooldown.run();await tick();cooldown.pause();waitingResolve();await coolRun;assert.equal(c.attempts,1);assert.equal(cooldown.blockedUntil,10000);
    cooldown.cancel(c);const next=add(cooldown);cooldown.send=async()=>good();cooldown.wait=async delay=>{now+=delay;};await cooldown.resume();assert.equal(now,10000);assert.equal(next.attempts,1);
    const large=new Queue(async()=>good());for(let n=0;n<207;n++)add(large,'same.png',`folder${n}/same.png`);await large.run();assert.equal(records(large.items).length,207);assert.equal(new Set(records(large.items).map(row=>row.source_id)).size,207);assert.equal(large.items.filter(item=>item.state==='done').length,207);
    const reused=new Queue(async()=>({...good(),duplicate:true}));const x=add(reused,'same.png','a/same.png');const y=add(reused,'same.png','b/same.png');await reused.run();assert.equal(records(reused.items).length,2);assert.equal(x.state,'reused');assert.equal(y.state,'reused');assert.notEqual(x.id,y.id);
    const unavailable=new Queue(async()=>({...good(),trashed:true}));add(unavailable);await unavailable.run();assert.equal(unavailable.items[0].state,'unavailable');assert.equal(records(unavailable.items)[0].link_available,false);assert.equal(records(unavailable.items)[0].code,'image_trashed');
    const disabled=new Queue(async()=>({...good(),image:{...image,disabled_at:'2026-10-29'}}));add(disabled);await disabled.run();assert.equal(records(disabled.items)[0].code,'image_disabled');assert.equal(records(disabled.items)[0].link_available,false);
    now=0;
    let cancelledRetry;
    cancelledRetry=new Queue(async()=>{throw failure({code:'rate_limited',scope:'queue',uncertain:false,delay:10000});},()=>{}, {...environment,wait:async delay=>{
        const item=cancelledRetry.items[0];
        assert.equal(records([item])[0].automatic_retries,0,'scheduled retry has not been sent');
        cancelledRetry.cancel(item);now+=delay;
    }});
    const cancelled=add(cancelledRetry);await cancelledRetry.run();
    assert.equal(cancelled.attempts,1);assert.equal(cancelled.retriesUsed,0);assert.equal(cancelled.state,'cancelled');
    assert.ok(!cancelled.error.includes('未发送上传请求'),'cancelled retry must acknowledge the previous request');
    const unsent=add(cancelledRetry);cancelledRetry.cancel(unsent);assert.equal(unsent.attempts,0);assert.ok(unsent.error.includes('未发送上传请求'));
    now=0;let pausedRetry, pausedWait;
    pausedRetry=new Queue(async item=>{if(item.attempts===1)throw failure();assert.equal(item.retriesUsed,1);return good();},()=>{}, {...environment,wait:()=>new Promise(resolve=>{pausedWait=resolve;})});
    const resumed=add(pausedRetry);const retryRun=pausedRetry.run();await tick();pausedRetry.pause();pausedWait();await retryRun;
    assert.equal(resumed.retriesUsed,0);assert.equal(resumed.attempts,1);
    pausedRetry.wait=async delay=>{now+=delay;};await pausedRetry.resume();assert.equal(resumed.retriesUsed,1);assert.equal(resumed.attempts,2);assert.equal(resumed.state,'done');
}
if(suite==='format') {
    async function check(bytes,name){return preflight(Object.assign(new Blob([bytes]),{name}));}
    for(const signature of ['GIF87a','GIF89a'])assert.equal((await check(signature,'lazyload.jpg')).format,'GIF');
    assert.equal((await check(Buffer.from('ffd8ff','hex'),'x.bin')).format,'JPEG');
    assert.equal((await preflight(file())).format,'PNG');
    const animated=Buffer.alloc(30);animated.write('RIFF');animated.write('WEBP',8);animated.write('VP8X',12);animated[20]=2;assert.equal((await check(animated,'x.webp')).format,'动态 WebP');animated[20]=0;assert.equal((await check(animated,'x.webp')).format,'WebP');
    const apng=Buffer.concat([Buffer.from('89504e470d0a1a0a','hex'),Buffer.from([0,0,0,8]),Buffer.from('acTL'),Buffer.alloc(12)]);assert.equal((await check(apng,'x.png')).format,'APNG');
    assert.ok((await check('<svg/>','x.png')).error);assert.ok((await check('','x.jpg')).error);
    let requested=0;await preflight({size:999999999,slice:(start,end)=>{requested=end;return new Blob([Buffer.from('ffd8ff','hex')]);}});assert.equal(requested,131072);
}
if(suite==='export') {
    const q=new Queue(async()=>good());const a=add(q,'=HYPERLINK("x"),中文\n.png','root/sub/=HYPERLINK("x"),中文\n.png');const b=add(q,'same.png','other/same.png');await q.run();
    const output=csv(q.items);assert.equal(output.charCodeAt(0),0xfeff);assert.ok(output.includes("\"'=HYPERLINK(\"\"x\"\"),中文\n.png\""));assert.ok(output.includes('root/sub'));assert.ok(output.includes('\r\n'));
    const data=records(q.items);assert.equal(data[0].filename,a.file.name);assert.equal(data[0].relative_directory,'root/sub');assert.notEqual(data[0].source_id,data[1].source_id);
    for(const path of ['/Users/private/x.png','C:\\private\\x.png','../private/x.png']){const i=add(q,'x.png',path);assert.equal(i.sourcePath,'');}
    const empty=add(q);empty.state='error';empty.error='unsupported';assert.equal(records([empty])[0].url,'');assert.equal(records([empty])[0].relative_directory,'');
    assert.equal(JSON.parse(JSON.stringify(data))[0].filename,a.file.name);
    for(const prefix of ['+','-','@','\t=','\n=','  =']){const i=add(q,prefix+'bad.png');assert.ok(csv([i]).includes('"\''+prefix));}
}
if(suite==='dom') await require('./upload-dom-audit.cjs')();
console.log('PASS '+suite);
})().catch(error=>{console.error(error);process.exitCode=1;});
