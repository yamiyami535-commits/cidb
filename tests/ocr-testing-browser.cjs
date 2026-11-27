// Optional headless browser extension of the isolated HTTP fixture.
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const {spawn}=require('node:child_process');
const delay=ms=>new Promise(resolve=>setTimeout(resolve,ms));
async function wait(check,label){for(let i=0;i<80;i++){if(await check())return;await delay(100);}throw new Error('Timed out: '+label);}
module.exports=async({origin,runtime,png,port,cookie,selectCrossOutFixture})=>{
  const fixture=path.join(runtime,'fixture.png');fs.writeFileSync(fixture,png);
  const browser=spawn(process.env.CHROME_BINARY||'C:/Program Files/Google/Chrome/Application/chrome.exe',['--headless=new','--disable-gpu','--no-first-run','--no-default-browser-check',`--remote-debugging-port=${port}`,'--user-data-dir='+path.join(runtime,'browser-profile'),'about:blank'],{windowsHide:true,stdio:'ignore'});
  let ws;const pending=new Map();let sequence=0;const errors=[];
  const send=(method,params={})=>new Promise((resolve,reject)=>{const id=++sequence;pending.set(id,{resolve,reject});ws.send(JSON.stringify({id,method,params}));});
  const evaluate=async expression=>{const result=await send('Runtime.evaluate',{expression,awaitPromise:true,returnByValue:true});if(result.exceptionDetails)throw new Error(result.exceptionDetails.text);return result.result.value;};
  try{
    let pages;await wait(async()=>{try{pages=await(await fetch(`http://127.0.0.1:${port}/json/list`)).json();return pages.length>0;}catch{return false;}},'Chrome start');
    ws=new WebSocket(pages[0].webSocketDebuggerUrl);await new Promise(resolve=>ws.addEventListener('open',resolve,{once:true}));
    ws.addEventListener('message',event=>{const message=JSON.parse(event.data);if(message.id){const operation=pending.get(message.id);if(!operation)return;pending.delete(message.id);message.error?operation.reject(new Error(message.error.message)):operation.resolve(message.result);}if(message.method==='Runtime.exceptionThrown')errors.push(message.params.exceptionDetails.text);});
    await send('Runtime.enable');await send('Page.enable');await send('Network.enable');
    const split=cookie.indexOf('=');await send('Network.setCookie',{name:cookie.slice(0,split),value:cookie.slice(split+1),url:origin,path:'/',httpOnly:true});
    await send('Emulation.setDeviceMetricsOverride',{width:1280,height:1000,deviceScaleFactor:1,mobile:false});
    await send('Page.navigate',{url:origin+'/ocr-testing'});
    await wait(()=>evaluate('Boolean(document.querySelector("#ocr-upload-mykad_front"))'),'testing page');
    assert.equal(await evaluate('document.querySelectorAll("#ocr-file-fields input").length'),2);
    const document=await send('DOM.getDocument');
    for(const name of ['mykad_front','mykad_back']){const found=await send('DOM.querySelector',{nodeId:document.root.nodeId,selector:'#ocr-upload-'+name});await send('DOM.setFileInputFiles',{nodeId:found.nodeId,files:[fixture]});}
    await evaluate('document.querySelector("#ocr-run").click()');
    await wait(()=>evaluate('!document.querySelector("#ocr-results").hidden'),'OCR result');
    assert.equal(await evaluate('document.querySelector("#ocr-name").textContent'),'SAMPLE CUSTOMER');
    assert.equal(await evaluate('document.querySelector("#ocr-verification").textContent'),'Verified');
    assert.equal(await evaluate('document.querySelector("#ocr-service-status").textContent'),'MANUAL_REVIEW');
    assert.equal(await evaluate('document.querySelectorAll("#ocr-checks tr").length'),5);
    assert.equal(await evaluate('document.querySelectorAll("#ocr-pages canvas").length'),1);
    selectCrossOutFixture();
    await evaluate('window.ocrDrawnLabels=[]; window.ocrDrawnBoxes=0; const originalFillText=CanvasRenderingContext2D.prototype.fillText; CanvasRenderingContext2D.prototype.fillText=function(text,...args){window.ocrDrawnLabels.push(text);return originalFillText.call(this,text,...args);}; const originalStrokeRect=CanvasRenderingContext2D.prototype.strokeRect; CanvasRenderingContext2D.prototype.strokeRect=function(...args){window.ocrDrawnBoxes++;return originalStrokeRect.apply(this,args);}; document.querySelector("#ocr-run").click()');
    await wait(()=>evaluate('!document.querySelector("#ocr-results").hidden && document.querySelector("#ocr-metadata").textContent.includes("opencv-crossout-v3")'),'OpenCV diagnostics');
    await wait(()=>evaluate('window.ocrDrawnLabels.length >= 6'),'OpenCV overlays');
    assert.equal(await evaluate('document.querySelector("#ocr-verification").textContent'),'Not verified');
    assert.match(await evaluate('document.querySelector("#ocr-checks").textContent'),/Not checked/);
    assert.match(await evaluate('document.querySelector("#ocr-pages").textContent'),/Possible printed rule/);
    assert.deepEqual(await evaluate('window.ocrDrawnLabels'),['#1 Present','#2 Uncertain','#3 Uncertain','#4 Uncertain','#5 Uncertain','#6 Uncertain']);
    assert.equal(await evaluate('window.ocrDrawnBoxes'),0,'Stroke overlays must not draw overlapping bounding boxes');
    assert.match(await evaluate('document.querySelector("#ocr-pages").textContent'),/Showing 6 meaningful stroke candidates; 12 other candidates/);
    assert.equal(await evaluate('document.querySelector(".ocr-other-candidates").open'),false);
    assert.equal(await evaluate('document.querySelectorAll(".ocr-other-candidates p").length'),12,'Hidden candidates remain inspectable');
    const desktop=await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:true});fs.writeFileSync(path.join(runtime,'ocr-desktop.png'),Buffer.from(desktop.data,'base64'));
    await send('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:true});
    assert.ok(await evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'Mobile page must not overflow');
    const mobile=await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:true});fs.writeFileSync(path.join(runtime,'ocr-mobile.png'),Buffer.from(mobile.data,'base64'));
    await evaluate('document.querySelector("#ocr-clear").click()');
    assert.equal(await evaluate('document.querySelector("#ocr-results").hidden'),true);
    assert.equal(await evaluate('document.querySelector("#ocr-number").textContent'),'');
    assert.equal(await evaluate('document.querySelector("#ocr-upload-mykad_front").files.length'),0);
    await evaluate('document.querySelector("#ocr-mode").value="form_pdf"; document.querySelector("#ocr-mode").dispatchEvent(new Event("change"))');
    assert.equal(await evaluate('document.querySelector("#ocr-file-fields input").accept'),'application/pdf,.pdf');
    assert.deepEqual(errors,[],'No browser runtime errors');
    console.log('Browser: upload, identity/checks, OpenCV stroke overlays/reasons without fake confidence, withheld approval, clear action and mobile layout passed. Screenshots: '+runtime);
  }finally{if(ws?.readyState===1){await send('Browser.close').catch(()=>{});ws.close();}browser.kill();}
};
