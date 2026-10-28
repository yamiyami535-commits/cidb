// Browser behavior fixtures: no packages, live database, or RPA connections.
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const path=require('node:path');
const source=fs.readFileSync(path.join(__dirname,'../public/assets/app.js'),'utf8');
const flush=()=>new Promise(resolve=>setImmediate(resolve));
const deferred=()=>{let resolve,reject;const promise=new Promise((a,b)=>{resolve=a;reject=b;});return {promise,resolve,reject};};
function row(id){
  const message={textContent:'Waiting'};
  return {dataset:{requestId:id,requestComplete:'false'},isConnected:true,message,querySelectorAll(selector){return selector==='[data-request-message]'?[message]:[];}};
}
function contents(source,page,ids){return {source,page:String(page),rows:ids.map(row),feedback:{hidden:true,textContent:''}};}
function root(content){
  return {content,dataset:{historySource:content.source,historyPage:content.page},attributes:{},childNodes:[content],
    querySelectorAll(){return this.content.rows.filter(r=>r.dataset.requestComplete==='false');},
    querySelector(selector){return selector==='[data-history-feedback]'?this.content.feedback:{focus:()=>{this.focused=true;}};},
    contains(node){return this.content.rows.includes(node) || node.owner===this;},
    addEventListener(event,fn){this.click=fn;},
    setAttribute(name,value){this.attributes[name]=value;},removeAttribute(name){delete this.attributes[name];},
    replaceChildren(content){this.content.rows.forEach(r=>r.isConnected=false);this.content=content;this.childNodes=[content];}
  };
}
async function scenario(search=''){
  const historyRoot=root(contents('all',1,['old-row'])),timers=new Map(),calls=[],replacements=[];
  const pages=[],polls=[];let ready,nextTimer=0;
  const location={pathname:'/request-history',search};
  vm.runInNewContext(source,{
    document:{addEventListener(event,fn){ready=fn;},querySelector(selector){return selector==='[data-request-history]'?historyRoot:null;},
      querySelectorAll(selector){return selector.startsWith('[data-request-id]')?historyRoot.querySelectorAll():[];}},
    window:{location,history:{state:null,replaceState(state,title,url){replacements.push(url);location.search='';location.pathname=url;}},matchMedia(){return {matches:true};}},
    DOMParser:class {parseFromString(text){const page=JSON.parse(text);return {querySelector(){return page.invalid?null:root(contents(page.source,page.page,page.ids));}};}},
    AbortController,
    setTimeout(fn,ms){const id=++nextTimer;timers.set(id,{fn,ms});return id;},clearTimeout(id){timers.delete(id);},
    async fetch(url,options){
      calls.push({url,options});
      if(url.startsWith('/request-status/')){
        const pending=polls.shift();if(pending)return pending;
        const id=url.split('/').pop();return {status:200,ok:true,json:async()=>({id,status:'processing',complete:false,message:'In progress'})};
      }
      const response=pages.shift();assert.ok(response,'Unexpected history request');return response;
    }
  });
  ready();await flush();
  function click(source,page,filter=false){
    const link={owner:historyRoot,href:`/request-history?source=${source}&page=${page}`,dataset:{historySource:source,historyPage:String(page)},
      closest(selector){return selector==='[data-history-link]'?this:filter?{}:null;}};
    const event={target:link,preventDefault(){this.prevented=true;}};
    const completion=historyRoot.click(event);assert.equal(event.prevented,true,'Click never navigates');return completion;
  }
  return {historyRoot,timers,calls,pages,polls,location,replacements,click};
}
function response(source,page,ids=[]){return {ok:true,status:200,text:async()=>JSON.stringify({source,page,ids})};}
(async()=>{
  const s=await scenario('?source=all&page=2');
  assert.deepEqual(s.replacements,['/request-history'],'Legacy address is cleaned without a redirect');
  assert.equal(s.timers.size,1,'Initial row polls');
  const old=s.historyRoot.content.rows[0],oldCall=s.calls[0];
  s.pages.push(response('all',2,['new-row']));await s.click('all',2);await flush();
  assert.equal(s.historyRoot.dataset.historyPage,'2');assert.equal(old.isConnected,false);
  assert.equal(oldCall.options.signal.aborted,true,'Removed row polling is cancelled');
  assert.equal(s.calls.at(-1).url,'/request-status/new-row','New rows start polling');
  assert.equal(s.timers.size,1,'Only current row has a poll timer');
  assert.equal(s.location.pathname,'/request-history');assert.equal(s.location.search,'');
  assert.equal(s.replacements.length,1,'Clicks do not update browser history');
  for(const [source,page,filter] of [['all',3,false],['all',2,false],['form',1,true],['form',2,false],['email',1,true],['email',2,false]]){
    s.pages.push(response(source,page,[`${source}-${page}`]));await s.click(source,page,filter);await flush();
    assert.equal(s.historyRoot.dataset.historySource,source);assert.equal(s.historyRoot.dataset.historyPage,String(page));
    assert.equal(s.historyRoot.content.rows[0].dataset.requestId,`${source}-${page}`);
    assert.equal(s.location.search,'');assert.equal(s.timers.size,1);
  }
  const retained=s.historyRoot.content;
  for(const failed of [{ok:false,status:500},{ok:true,status:200,redirected:true},{ok:false,status:401},Promise.reject(new Error('Network unavailable'))]){
    s.pages.push(failed);await s.click('all',1);
    assert.equal(s.historyRoot.content,retained,'Failures keep current rows and filter');
    assert.equal(retained.feedback.hidden,false);assert.ok(retained.feedback.textContent);
    assert.equal(s.historyRoot.attributes['aria-busy'],undefined);
  }
  // A slower earlier response must never replace a newer filter selection.
  const slow=deferred();s.pages.push(slow.promise,response('form',1,['latest']));
  const earlier=s.click('all',3);const earlierCall=s.calls.at(-1);
  await s.click('form',1,true);await flush();
  assert.equal(earlierCall.options.signal.aborted,true);
  slow.resolve(response('all',3,['stale']));await earlier;
  assert.equal(s.historyRoot.content.rows[0].dataset.requestId,'latest');
  assert.equal(s.historyRoot.dataset.historySource,'form');
  const latePoll=deferred();s.polls.push(latePoll.promise);
  s.pages.push(response('email',2,['pending-row']));await s.click('email',2);
  const removed=s.historyRoot.content.rows[0];
  s.pages.push(response('all',1,['current-row']));await s.click('all',1);await flush();
  latePoll.resolve({ok:true,status:200,json:async()=>({id:'pending-row',status:'success',complete:true,message:'Obsolete result'})});await flush();
  assert.equal(removed.message.textContent,'Waiting','Late polling response cannot update a removed row');
  const timedOut=deferred();s.pages.push(timedOut.promise);
  const loading=s.click('form',1,true),navigation=s.calls.at(-1);
  navigation.options.signal.addEventListener('abort',()=>timedOut.reject(new DOMException('Timeout','AbortError')));
  const [timeoutId,timeout]=[...s.timers].find(([,timer])=>timer.ms===15000);
  s.timers.delete(timeoutId);timeout.fn();await loading;
  assert.match(s.historyRoot.content.feedback.textContent,/timed out/);
  assert.equal(s.historyRoot.dataset.historySource,'all','Timeout retains the current filter and list');
  // Empty pages remove old polling, retain filters, and do not navigate.
  s.pages.push(response('email',1,[]));await s.click('email',1,true);await flush();
  assert.equal(s.historyRoot.content.rows.length,0);assert.equal(s.timers.size,0);
  assert.equal(s.location.pathname,'/request-history');assert.equal(s.location.search,'');
  let prevented=false;
  await s.historyRoot.click({target:{closest(){return null;}},preventDefault(){prevented=true;}});
  assert.equal(prevented,false,'Individual request links are not intercepted');
  for(const call of s.calls){assert.equal(call.options.credentials,'same-origin');assert.equal(call.options.cache,'no-store');}
  console.log('In-page pagination, filter changes, stable URL, stale response protection, errors and polling lifecycle passed.');
})().catch(error=>{console.error(error);process.exitCode=1;});
