// Local synthetic browser checks: no application database or credentials.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const http = require('node:http');
const net = require('node:net');
const {once} = require('node:events');
const {spawn, spawnSync} = require('node:child_process');
const root = path.resolve(__dirname, '..');
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
async function waitFor(check, label) { for (let i=0;i<100;i++) { if(await check()) return; await delay(100); } throw new Error('Timed out: '+label); }
class DevTools {
  constructor(ws) { this.ws=ws; this.next=0; this.pending=new Map(); this.errors=[];
    ws.addEventListener('message',event=>{const data=JSON.parse(event.data);if(data.id){const p=this.pending.get(data.id);if(!p)return;this.pending.delete(data.id);data.error?p.reject(new Error(data.error.message)):p.resolve(data.result);}if(data.method==='Runtime.exceptionThrown')this.errors.push(data.params.exceptionDetails.text);});
  }
  send(method,params={}) { const id=++this.next; return new Promise((resolve,reject)=>{this.pending.set(id,{resolve,reject});this.ws.send(JSON.stringify({id,method,params}));}); }
  async evaluate(expression) { const r=await this.send('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true});if(r.exceptionDetails)throw new Error(r.exceptionDetails.text);return r.result.value; }
}
(async()=>{
  fs.mkdirSync(path.join(root,'var'),{recursive:true});
  const runtime=fs.mkdtempSync(path.join(root,'var','ui-browser-'));
  const fixture=spawnSync(process.env.PHP_BINARY||'php',[path.join(__dirname,'ui-fixtures.php'),runtime],{encoding:'utf8',windowsHide:true});
  if(fixture.error) throw fixture.error;
  assert.equal(fixture.status,0,fixture.stdout+fixture.stderr);
  let identityRequests=0;
  const crmSearches=[];
  const server=http.createServer((req,res)=>{
    const url=new URL(req.url,'http://localhost');
    if(url.pathname.endsWith('/identity')) {identityRequests++;res.writeHead(200,{'Content-Type':'application/json','Cache-Control':'no-store'});res.end(JSON.stringify({identity:'900101-14-5678'}));return;}
    if(url.pathname==='/tickets/crm') crmSearches.push(url.searchParams.get('crm_id'));
    const file=url.pathname==='/tickets/crm'?path.join(runtime,'crm.html'):(url.pathname.startsWith('/assets/')?path.join(root,'public',url.pathname):path.join(runtime,url.pathname));
    if(!fs.existsSync(file)||!fs.statSync(file).isFile()){res.writeHead(404);res.end();return;}
    res.setHeader('Content-Type',({'.html':'text/html; charset=utf-8','.css':'text/css','.js':'text/javascript','.png':'image/png'})[path.extname(file)]||'application/octet-stream');res.end(fs.readFileSync(file));
  });
  server.listen(0,'127.0.0.1');await once(server,'listening');
  const probe=net.createServer();probe.listen(0,'127.0.0.1');await once(probe,'listening');const debugPort=probe.address().port;await new Promise(r=>probe.close(r));
  const chromePath=process.env.CHROME_BINARY||'C:/Program Files/Google/Chrome/Application/chrome.exe';
  const chrome=spawn(chromePath,['--headless=new','--disable-gpu','--no-first-run','--no-default-browser-check','--remote-debugging-port='+debugPort,'--user-data-dir='+path.join(runtime,'profile'),'about:blank'],{windowsHide:true,stdio:'ignore'});
  let cdp;
  try {
    let pages;
    await waitFor(async()=>{try{pages=await(await fetch('http://127.0.0.1:'+debugPort+'/json/list')).json();return pages.length;}catch{return false;}},'browser start');
    const ws=new WebSocket(pages[0].webSocketDebuggerUrl);await once(ws,'open');cdp=new DevTools(ws);await cdp.send('Runtime.enable');await cdp.send('Page.enable');
    const base='http://127.0.0.1:'+server.address().port;
    const visit=async name=>{await cdp.send('Page.navigate',{url:base+'/'+name+'.html'});await waitFor(()=>cdp.evaluate('document.readyState === "complete" && !!document.querySelector("main")'),'page load');};
    const screenshot=async name=>{const shot=await cdp.send('Page.captureScreenshot',{format:'png',captureBeyondViewport:true});fs.writeFileSync(path.join(runtime,name+'.png'),Buffer.from(shot.data,'base64'));};
    const checkArrow=async (page,width,initial=false)=>{
      const geometry=await cdp.evaluate(`(() => {
        const button=document.querySelector('[data-sidebar-toggle], [data-menu-toggle]');
        const arrow=button.getBoundingClientRect();
        const blocks=[...document.querySelectorAll('.form-card,.status-card,.tm-card,.hero,.tm-heading')];
        const overlaps=blocks.filter(el=>{const box=el.getBoundingClientRect();return arrow.left<box.right && arrow.right>box.left && arrow.top<box.bottom && arrow.bottom>box.top;}).map(el=>el.className);
        const target=document.querySelector('.form-card,.tm-queue').getBoundingClientRect();
        return {overlaps,left:arrow.left,right:arrow.right,top:arrow.top,bottom:arrow.bottom,targetTop:target.top,targetLeft:target.left,headerBottom:document.querySelector('.topbar').getBoundingClientRect().bottom,accessible:document.elementFromPoint(arrow.left+16,arrow.top+16)?.closest('button')===button};
      })()`);
      assert.deepEqual(geometry.overlaps,[],`${page} arrow overlaps content at ${width}: ${JSON.stringify(geometry)}`);
      assert.ok(geometry.left>=0 && geometry.right<=width && geometry.top>=geometry.headerBottom,JSON.stringify(geometry));
      assert.ok(geometry.accessible,`${page} arrow is obstructed at ${width}`);
      assert.ok(geometry.right<=geometry.targetLeft-7,`${page} needs a clear gutter`);
      if(initial) assert.ok(geometry.bottom<=geometry.targetTop-7,`${page} arrow must sit above its content block`);
    };
    for(const width of [1440,1200,768,390,320]) {
      await cdp.send('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:false});
      for(const page of ['home','queue','crm','details','conversation','documents']) {
        await visit(page);
        const overflow=await cdp.evaluate('({width:innerWidth,scroll:document.documentElement.scrollWidth})');
        assert.ok(overflow.scroll<=width+1,`${page} overflows at ${width}: ${JSON.stringify(overflow)}`);
        assert.equal(await cdp.evaluate('!!window.emailScriptExecuted'),false);
        assert.equal(await cdp.evaluate('!!document.querySelector(".top-navigation a[href=\\"/request-history\\"]")'),false);
        if(['crm','details','conversation'].includes(page)) {
          const email=await cdp.evaluate('({text:document.querySelector(".tm-email-plain").textContent,space:getComputedStyle(document.querySelector(".tm-email-plain")).whiteSpace})');
          assert.ok(email.text.includes('Salam Sejahtera,\n\nSaya lampirkan'));
          assert.equal(email.space,'pre-wrap');
        }
        if(width===1440 || width===390) await screenshot(page+'-'+width);
        if(page==='home') {
          assert.ok(await cdp.evaluate('document.querySelector(".hero h1").getBoundingClientRect().height < 55'));
          assert.equal(await cdp.evaluate('!!document.querySelector(".process-card")'),false);
          assert.equal(await cdp.evaluate('!!document.querySelector(".status-card a[href=\\"/request-history\\"]")'),true);
          await checkArrow(page,width,true);
          await cdp.evaluate('document.querySelector("[data-sidebar-toggle]").click()');
          assert.equal(await cdp.evaluate('document.querySelector("[data-sidebar-toggle]").getAttribute("aria-expanded")'),width>760?'false':'true');
          await checkArrow(page,width,true);
          await cdp.evaluate('window.scrollTo(0,500)');
          await checkArrow(page,width);
          await cdp.evaluate('window.scrollTo(0,0)');
          await cdp.evaluate('document.querySelector("[data-sidebar-toggle]").click()');
        }
        if(page==='queue') {
          assert.equal(await cdp.evaluate('!!document.querySelector(".tm-toolbar a[href=\\"/tickets/crm\\"]")'),false);
          assert.equal(await cdp.evaluate('document.querySelector(".tm-queue-crm-search input").placeholder'),'CRM Search');
          assert.ok(await cdp.evaluate('document.querySelector(".tm-filters").getBoundingClientRect().height < 115'));
          const button='document.querySelector("[data-menu-toggle]")';
          await checkArrow(page,width,true);
          if(width>760) {
            const before=await cdp.evaluate('document.querySelector(".tm-sidebar").getBoundingClientRect().top');
            await cdp.evaluate('window.scrollTo(0,500)');
            assert.equal(await cdp.evaluate('document.querySelector(".tm-sidebar").getBoundingClientRect().top'),before);
            await cdp.evaluate('window.scrollTo(0,0)');
            await cdp.evaluate(button+'.click()');
            assert.equal(await cdp.evaluate('document.getElementById("ticket-menu").hidden'),true);
            await checkArrow(page,width,true);
          }
          await cdp.evaluate(button+'.click()');
          assert.equal(await cdp.evaluate('document.getElementById("ticket-menu").hidden'),false);
          assert.equal(await cdp.evaluate('document.getElementById("ticket-menu").getBoundingClientRect().left'),width>760?0:48);
          await checkArrow(page,width,true);
          await cdp.evaluate('window.scrollTo(0,500)');
          await checkArrow(page,width);
          await cdp.evaluate('window.scrollTo(0,0)');
        }
        if(page==='crm' && width===1440) {
          assert.ok(await cdp.evaluate('document.body.textContent.includes("20 Nov 2026, 08:30")'));
          const rows=await cdp.evaluate('[...document.querySelectorAll(".tm-grid>.tm-card")].slice(1).map(e=>e.getBoundingClientRect().top)');
          assert.equal(rows[0],rows[1]);
        }
        if(['crm','details'].includes(page) && width===1440) {
          assert.equal(await cdp.evaluate('document.querySelector("[data-identity-value]").textContent'),'**********5678');
          await cdp.evaluate('document.querySelector("[data-identity-url]").click()');
          await waitFor(()=>cdp.evaluate('document.querySelector("[data-identity-url]").getAttribute("aria-pressed")==="true"'),'ID reveal');
          assert.equal(await cdp.evaluate('document.querySelector("[data-identity-value]").textContent'),'900101-14-5678');
          await cdp.evaluate('document.querySelector("[data-identity-url]").click()');
          assert.equal(await cdp.evaluate('document.querySelector("[data-identity-value]").textContent'),'**********5678');
        }
      }
    }
    assert.equal(identityRequests,2);
    await visit('queue');
    await cdp.evaluate('document.querySelector(".tm-queue-crm-search input").value="CRM-DEMO-001"; document.querySelector(".tm-queue-crm-search button").click()');
    await waitFor(()=>cdp.evaluate('location.pathname === "/tickets/crm" && document.readyState === "complete"'),'CRM search submission');
    assert.deepEqual(crmSearches,['CRM-DEMO-001']);
    assert.deepEqual(cdp.errors,[]);
    console.log('Passed 30 page/viewport combinations, fixed sidebar, collapse/expand, ID reveal/hide, compact filters, CRM alignment, and email safety.');
    console.log('Synthetic screenshots: '+runtime);
  } finally { if(cdp){await cdp.send('Browser.close').catch(()=>{});cdp.ws.close();}chrome.kill();await new Promise(r=>server.close(r)); }
})().catch(error=>{console.error(error);process.exitCode=1;});
