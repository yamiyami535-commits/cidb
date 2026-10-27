// Opt-in browser checks using real read models and an isolated loopback session.
// PostgreSQL connections are forced read-only; no submission/dispatch is invoked.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const net = require('node:net');
const {spawn, spawnSync} = require('node:child_process');
const {once} = require('node:events');
const root = path.resolve(__dirname, '..');
if (!process.argv.includes('--live')) { console.log('Usage: node tests/workspace-browser.cjs --live'); process.exit(2); }
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
async function freePort() { const s=net.createServer(); s.listen(0,'127.0.0.1'); await once(s,'listening'); const port=s.address().port; await new Promise(r=>s.close(r)); return port; }
async function waitFor(check, label, limit=15000) {
  const until=Date.now()+limit;
  while(Date.now()<until) { try { if(await check()) return; } catch {} await delay(100); }
  throw new Error('Timed out: '+label);
}
class DevTools {
  constructor(ws) { this.ws=ws; this.next=0; this.pending=new Map(); this.errors=[]; this.polls=0;
    ws.addEventListener('message',event=>{const data=JSON.parse(event.data); if(data.id) { const p=this.pending.get(data.id); if(!p)return; this.pending.delete(data.id); data.error?p.reject(new Error(data.error.message)):p.resolve(data.result); }
      if(data.method==='Runtime.exceptionThrown') this.errors.push(data.params.exceptionDetails.text);
      if(data.method==='Network.responseReceived' && data.params.response.url.includes('/request-status/')) this.polls++;
    });
  }
  send(method,params={}) { const id=++this.next; return new Promise((resolve,reject)=>{this.pending.set(id,{resolve,reject});this.ws.send(JSON.stringify({id,method,params}));}); }
  async evaluate(expression) {const r=await this.send('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true});if(r.exceptionDetails)throw new Error(r.exceptionDetails.text);return r.result.value;}
}
(async()=>{
  const runtime=fs.mkdtempSync(path.join(root,'var','workspace-browser-'));
  let server,chrome,cdp,session;
  const readEnv={...process.env,PGOPTIONS:'-c default_transaction_read_only=on'};
  try {
    const seed=spawnSync(process.env.PHP_BINARY||'php',['-r',`
      require $argv[1].'/src/bootstrap.php';
      require $argv[1].'/src/RequestHistory.php';require $argv[1].'/src/request-result.php';
      $user=db()->query("SELECT u.id,u.username FROM portal_users u WHERE u.is_active=true AND u.role IN ('admin','manager','agent') ORDER BY (SELECT count(*) FROM portal_requests r WHERE r.user_id=u.id) DESC,u.id LIMIT 1")->fetch();
      if(!$user)exit(2);
      $pending=null;foreach((new RequestHistory(db()))->list($user['id']) as $row)if(!request_result($row)['complete']){$pending=$row['id'];break;}
      ini_set('session.save_path',$argv[2]);session_name('cidb_portal');session_id(bin2hex(random_bytes(20)));session_start();$_SESSION=['user'=>$user];$id=session_id();session_write_close();echo json_encode(['session'=>$id,'pending'=>$pending]);
    `,root,runtime],{encoding:'utf8',windowsHide:true,env:readEnv});
    assert.equal(seed.status,0,seed.stderr); const seedData=JSON.parse(seed.stdout); session=seedData.session;
    const port=await freePort(),debugPort=await freePort(),origin='http://127.0.0.1:'+port;
    server=spawn(process.env.PHP_BINARY||'php',['-d',`session.save_path="${runtime.replaceAll('\\','/')}"`,'-S','127.0.0.1:'+port,'-t','public','public/index.php'],{cwd:root,env:readEnv,windowsHide:true,stdio:'ignore'});
    await waitFor(async()=> (await fetch(origin+'/login')).ok,'PHP server');
    const executable=process.env.CHROME_BINARY||'C:/Program Files/Google/Chrome/Application/chrome.exe';
    chrome=spawn(executable,['--headless','--disable-gpu','--no-first-run','--no-default-browser-check','--disable-background-networking','--remote-debugging-address=127.0.0.1','--remote-debugging-port='+debugPort,'--user-data-dir='+path.join(runtime,'browser'),'--window-size=1880,1000','about:blank'],{windowsHide:true,stdio:'ignore'});
    await waitFor(async()=> (await fetch('http://127.0.0.1:'+debugPort+'/json/version')).ok,'Chrome');
    const target=await (await fetch('http://127.0.0.1:'+debugPort+'/json/new?about:blank',{method:'PUT'})).json();
    const ws=new WebSocket(target.webSocketDebuggerUrl); await new Promise(r=>ws.addEventListener('open',r,{once:true})); cdp=new DevTools(ws);
    await cdp.send('Page.enable'); await cdp.send('Runtime.enable'); await cdp.send('Network.enable');
    await cdp.send('Emulation.setEmulatedMedia',{features:[{name:'prefers-reduced-motion',value:'reduce'}]});
    await cdp.send('Network.setCookie',{name:'cidb_portal',value:session,url:origin,httpOnly:true,sameSite:'Lax'});
    const ready=selector=>waitFor(()=>cdp.evaluate(`document.readyState==='complete' && !!document.querySelector(${JSON.stringify(selector)})`),selector);
    const visit=async(url,selector)=>{await cdp.send('Page.navigate',{url:origin+url});await ready(selector);};
    const screenshot=async(name)=>{await cdp.evaluate('document.fonts.ready.then(()=>true)');const r=await cdp.send('Page.captureScreenshot',{format:'png',captureBeyondViewport:false});fs.writeFileSync(path.join(runtime,name+'.png'),Buffer.from(r.data,'base64'));};
    const noOverflow=async()=>assert.ok(await cdp.evaluate('document.documentElement.scrollWidth <= innerWidth + 1'),'No page-level horizontal overflow');
    await cdp.send('Emulation.setDeviceMetricsOverride',{width:1880,height:1000,deviceScaleFactor:1,mobile:false});
    await visit('/','[data-submit-form]');
    assert.deepEqual(await cdp.evaluate(`[...document.querySelectorAll('[data-submit-form] input[required]')].map(e=>e.name)`),['name','id_number','email','crm']);
    assert.ok(await cdp.evaluate(`document.querySelector('#ticket-sidebar a[href="/tickets"]').getBoundingClientRect().width > 0`),'Ticket management entry is visible');
    assert.ok(await cdp.evaluate(`document.querySelector('main').classList.contains('portal-main') && !document.body.classList.contains('ticket-workspace')`));
    const homeWidth=await cdp.evaluate(`document.querySelector('#ticket-sidebar').getBoundingClientRect().width`);assert.equal(Math.round(homeWidth),262);
    await screenshot('home'); await noOverflow();
    await cdp.evaluate(`document.querySelector('[data-sidebar-toggle]').click()`);assert.ok(await cdp.evaluate(`document.querySelector('.portal-workspace').classList.contains('is-collapsed')`));
    await cdp.evaluate(`document.querySelector('[data-sidebar-toggle]').click()`);
    await cdp.evaluate(`document.querySelector('#ticket-sidebar a[href="/tickets"]').click()`);await ready('.tm-queue');
    assert.equal(await cdp.evaluate('location.pathname'),'/tickets');
    assert.ok(await cdp.evaluate(`document.querySelectorAll('.tm-table tbody tr').length > 0`)); await noOverflow();await screenshot('ticket-queue');
    const firstIds=await cdp.evaluate(`[...document.querySelectorAll('.tm-ticket-title')].map(e=>e.pathname)`);
    if(await cdp.evaluate(`!!document.querySelector('[rel="next"]')`)) {
      await cdp.evaluate(`document.querySelector('[rel="next"]').click()`);await waitFor(()=>cdp.evaluate(`location.search.includes('page=2') && document.readyState==='complete'`),'Queue page two');
      const nextIds=await cdp.evaluate(`[...document.querySelectorAll('.tm-ticket-title')].map(e=>e.pathname)`);assert.ok(!nextIds.some(id=>firstIds.includes(id)));
    }
    await cdp.evaluate(`document.querySelector('.tm-ticket-title').click()`);await ready('.tm-tabs');await screenshot('ticket-details');
    const ticketPath=await cdp.evaluate('location.pathname');
    for(const tab of ['conversation','activity','history','audit','documents','actions']) {
      await cdp.evaluate(`document.querySelector('.tm-tabs a[href$="tab=${tab}"]').click()`);
      await waitFor(()=>cdp.evaluate(`location.search==='?tab=${tab}' && document.readyState==='complete' && !!document.querySelector('.tm-tabs [aria-current]')`),'Ticket tab '+tab);
      assert.ok(!(await cdp.evaluate(`document.body.innerText.includes('Unable to open this view')`)));
    }
    await visit('/tickets/crm','.tm-crm-form');
    await cdp.evaluate(`document.querySelector('#crm-id').value='NO-SUCH-CRM-BROWSER-TEST';document.querySelector('.tm-crm-form button').click()`);
    await waitFor(()=>cdp.evaluate(`document.body.innerText.includes('No customer found')`),'CRM empty state');
    await visit('/request-history','[data-request-history]');
    assert.deepEqual(await cdp.evaluate(`[...document.querySelectorAll('.history-filters a')].map(e=>e.textContent.trim())`),['All','Form','Email']);
    assert.equal(Math.round(await cdp.evaluate(`document.querySelector('main').getBoundingClientRect().width`)),1180);
    assert.ok(!(await cdp.evaluate(`!!document.querySelector('link[href="/assets/tickets.css"]')`)));
    await screenshot('request-history'); await noOverflow();
    await cdp.evaluate(`document.querySelector('.history-filters [data-history-source="email"]').click()`);
    await waitFor(()=>cdp.evaluate(`document.querySelector('[data-request-history]').dataset.historySource==='email' && !document.querySelector('[data-request-history]').hasAttribute('aria-busy')`),'Email history filter');
    assert.equal(await cdp.evaluate('location.pathname+location.search'),'/request-history');
    if(await cdp.evaluate(`!!document.querySelector('.history-pagination [rel="next"]')`)) {
      await cdp.evaluate(`document.querySelector('.history-pagination [rel="next"]').click()`);
      await waitFor(()=>cdp.evaluate(`document.querySelector('[data-request-history]').dataset.historyPage==='2' && !document.querySelector('[data-request-history]').hasAttribute('aria-busy')`),'History pagination');
    }
    if(await cdp.evaluate(`!!document.querySelector('.history-list-row')`)) {
      await cdp.evaluate(`document.querySelector('.history-list-row').click()`);await ready('.details-grid');
      assert.ok(!(await cdp.evaluate(`!!document.querySelector('.response-raw')`)));await screenshot('request-details');
    }
    if(seedData.pending) {
      await visit('/request-history/'+seedData.pending,'.details-grid');
      await waitFor(()=>cdp.polls>0,'Live request status polling');
    } else console.log('No pending live requests; polling transitions are covered by the isolated HTTP and JavaScript suites.');
    await cdp.send('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:true});
    await visit('/tickets','.tm-queue');await noOverflow();
    assert.ok(await cdp.evaluate(`document.querySelector('#ticket-menu').hidden`));await screenshot('ticket-mobile');
    await cdp.evaluate(`document.querySelector('[data-menu-toggle]').click()`);assert.ok(!(await cdp.evaluate(`document.querySelector('#ticket-menu').hidden`)));
    await visit(ticketPath,'.tm-tabs');await noOverflow();await screenshot('ticket-details-mobile');
    await visit('/','[data-submit-form]');await noOverflow();
    assert.deepEqual(cdp.errors,[],'No uncaught browser JavaScript exceptions');
    console.log('Browser checks passed: visible home navigation, restored form/history/details, live queue pagination, all ticket tabs, CRM, status polling and mobile layouts.');
    console.log('Screenshots: '+runtime);
  } finally {
    if(cdp) { try { await cdp.send('Browser.close'); } catch {} cdp.ws.close(); }
    for(const p of [chrome,server]) if(p && p.exitCode===null) p.kill();
    if(session) {const file=path.join(runtime,'sess_'+session);if(fs.existsSync(file))fs.unlinkSync(file);}
  }
})().catch(error=>{console.error(error);process.exitCode=1;});
