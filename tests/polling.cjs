const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require('node:path').join(__dirname, '../public/assets/app.js'), 'utf8');

async function scenario(responses, ids=['request-a']) {
  const timers=new Map(), calls=[];
  let nextTimer=0, ready;
  const containers=ids.map(id=>{
    const message={textContent:'Waiting'}, notification={textContent:'Queued'}, status={textContent:'In progress',classList:{remove(){},add(){}},hasAttribute(){return true;}};
    return {dataset:{requestId:id,requestComplete:'false'},message,notification,status,querySelectorAll(selector){
      if(selector==='[data-request-message]') return [message];
      if(selector==='[data-notification-message]') return [notification];
      if(selector==='[data-request-status], [data-request-status-dot]') return [status];
      return [];
    }};
  });
  vm.runInNewContext(source, {
    document:{addEventListener(event,fn){ready=fn;},querySelector(){return null;},querySelectorAll(selector){return selector.startsWith('[data-request-id]')?containers:[];}},
    // Functional polling must still run with reduced motion enabled.
    window:{matchMedia(){return {matches:true};}},
    AbortController,
    setTimeout(fn,ms){const id=++nextTimer;timers.set(id,{fn,ms});return id;},
    clearTimeout(id){timers.delete(id);},
    async fetch(url,options){
      calls.push({url,options});
      const response=responses.shift();
      if(response instanceof Error) throw response;
      assert.ok(response, 'Unexpected additional poll');
      return {ok:response.status===200,status:response.status,async json(){return response.body;}};
    }
  });
  const flush=()=>new Promise(resolve=>setImmediate(resolve));
  ready(); await flush();
  return {containers,calls,timers,async tick(){const [id,timer]=timers.entries().next().value;timers.delete(id);timer.fn();await flush();}};
}
const result=(id,complete,message,status='pending')=>({status:200,body:{id,complete,message,status,completed_at:complete?'Completed':'In progress'}});
(async()=>{
  const final='Your request is complete. <script>literal text</script>';
  const flow=await scenario([result('request-a',false,'Processing'),result('request-a',true,final,'success')]);
  assert.equal(flow.calls[0].url,'/request-status/request-a');
  assert.equal(flow.calls[0].options.cache,'no-store');
  assert.equal(flow.calls[0].options.credentials,'same-origin');
  assert.equal(flow.containers[0].message.textContent,'Processing');
  assert.equal(flow.timers.size,1);
  await flow.tick();
  assert.equal(flow.containers[0].message.textContent,final);
  assert.equal(flow.containers[0].status.textContent,'Success');
  assert.equal(flow.containers[0].dataset.requestComplete,'true');
  assert.equal(flow.timers.size,0, 'Stop once the final message arrives');

  const retry=await scenario([new Error('Network unavailable'),{status:500},result('request-a',true,'Done','success')]);
  assert.equal(retry.containers[0].message.textContent,'Waiting');
  await retry.tick(); await retry.tick();
  assert.equal(retry.containers[0].message.textContent,'Done');
  assert.equal(retry.timers.size,0);
  for(const status of [401,403,404]) assert.equal((await scenario([{status}])).timers.size,0);

  const mismatch=await scenario([result('another-request',true,'Wrong result','success')]);
  assert.equal(mismatch.containers[0].message.textContent,'Waiting');
  assert.equal(mismatch.timers.size,1);
  const concurrent=await scenario([result('request-a',true,'A','success'),result('request-b',false,'B')],['request-a','request-b']);
  assert.equal(concurrent.containers[0].message.textContent,'A');
  assert.equal(concurrent.containers[1].message.textContent,'B');
  assert.equal(concurrent.timers.size,1, 'Only the unfinished request keeps polling');
  const failure=await scenario([result('request-a',true,'Unable to complete','failed')]);
  assert.equal(failure.containers[0].status.textContent,'Failed');
  assert.equal(failure.timers.size,0);
  const waiting=result('request-a',false,'In progress','processing');
  waiting.body.rpa_display_message='Obsolete message';
  const updated=result('request-a',true,'Failed','failed');
  const statusOnly=await scenario([waiting,updated]);
  assert.equal(statusOnly.containers[0].status.textContent,'In progress');
  assert.equal(statusOnly.containers[0].message.textContent,'In progress');
  await statusOnly.tick();
  assert.equal(statusOnly.containers[0].status.textContent,'Failed');
  assert.equal(statusOnly.timers.size,0,'RPA failure completes polling without a display message');
  console.log('Frontend polling regression checks passed.');
})().catch(error=>{console.error(error);process.exitCode=1;});
