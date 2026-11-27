// Real portal HTTP/multipart/cURL paths, synthetic OCR/RPA and read-only auth DB.
// No production .env, credentials, customer files or database are accessed.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const http = require('node:http');
const net = require('node:net');
const {once} = require('node:events');
const {spawn, spawnSync} = require('node:child_process');
const root = path.resolve(__dirname,'..');
const php = process.env.PHP_BINARY || 'php';
const delay = ms => new Promise(resolve => setTimeout(resolve,ms));
async function freePort() { const s=net.createServer(); s.listen(0,'127.0.0.1'); await once(s,'listening'); const port=s.address().port; await new Promise(r=>s.close(r)); return port; }

(async () => {
  fs.mkdirSync(path.join(root,'var'),{recursive:true});
  const runtime=fs.mkdtempSync(path.join(root,'var','ocr-http-'));
  for (const folder of ['src','views','public']) fs.cpSync(path.join(root,folder),path.join(runtime,folder),{recursive:true});
  fs.mkdirSync(path.join(runtime,'sessions'));
  fs.copyFileSync(path.join(__dirname,'ocr-http-database.php'),path.join(runtime,'src','ocr-http-database.php'));
  const bootstrap=path.join(runtime,'src','bootstrap.php');
  const original=fs.readFileSync(bootstrap,'utf8');
  const replaced=original.replace(/function db\(\): PDO\s*\{[\s\S]*?\r?\n\}\r?\nfunction e\(/, "function db(): PDO { require_once __DIR__.'/ocr-http-database.php'; return new OcrFixturePdo(); }\nfunction e(");
  assert.notEqual(replaced,original,'Isolated fixture must replace the DB connector'); fs.writeFileSync(bootstrap,replaced);
  const hash=spawnSync(php,['-r','echo password_hash($argv[1], PASSWORD_DEFAULT);','fixture-password'],{encoding:'utf8',windowsHide:true}); if(hash.error)throw hash.error; assert.equal(hash.status,0,hash.stderr);
  const accounts={}; ['admin','manager','agent'].forEach((role,index)=>accounts[role]={id:`00000000-0000-4000-8000-00000000000${index+1}`,username:role,password_hash:hash.stdout,role,active:true});
  const saveAccounts=()=>fs.writeFileSync(path.join(runtime,'accounts.json'),JSON.stringify(accounts)); saveAccounts();
  const png=Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aN1cAAAAASUVORK5CYII=','base64');
  const ocrCalls=[], rpaCalls=[]; let rejectRpa=false;
  const fakeResult={verified:true,status:'MANUAL_REVIEW',message:'Synthetic verification outcome.',extracted_name:'SAMPLE CUSTOMER',extracted_document_number:'900101-10-1234'};
  let crossOutFixture=false;
  const server=http.createServer((req,res)=>{ let chunks=[]; req.on('data',b=>chunks.push(b)); req.on('end',()=>{
    const body=Buffer.concat(chunks).toString();
    if(req.url==='/rpa') {rpaCalls.push({headers:req.headers,body:JSON.parse(body)});res.writeHead(rejectRpa?503:204);res.end();return;}
    ocrCalls.push({url:req.url,headers:req.headers,body});
    const response={...fakeResult};
    if(req.headers['x-ocr-diagnostics']==='1') response.diagnostics={checks:{name:{state:'present',reason:'Synthetic text'},cross_out:{state:'present',reason:'Synthetic detector'},customer_signature:{state:'present',reason:'Synthetic reference exclusions'},embedded_signature:{state:'absent',reason:'No embedded candidate'},cidb_wording:{state:'absent',reason:'Not required'}},pages:[{page:1,width:1,height:1,preview:'data:image/png;base64,'+png.toString('base64'),visual_state:'checked',reference_state:'matched',detections:[],reference_regions:[],ocr_lines:[{text:'SAMPLE CUSTOMER',confidence:.99}]}],pages_checked:1,processing_ms:10,model:{release_id:'synthetic'}};
    if(crossOutFixture && response.diagnostics) {
      response.verified=false;
      response.message='Signature verification is not implemented in the OpenCV cross-out stage.';
      response.diagnostics.model={release_id:'opencv-crossout-v3',method:'opencv_crossout'};
      response.diagnostics.checks.customer_signature={state:'not_checked',reason:response.message};
      response.diagnostics.checks.embedded_signature={state:'not_checked',reason:'Reference matching is not run in this stage.'};
      Object.assign(response.diagnostics.pages[0],{reference_state:'not_checked',visual_reason:response.message,
        cross_out_reasons:['Cross-out stroke evidence found using OpenCV rules.'],
        detections:[{label:'cross_out',state:'present',method:'contour+hough',box:[0,0,1,1],line:[0,1,1,0],reason:'Long contrasting stroke.'},
                    {label:'cross_out',state:'uncertain',method:'hough',box:[0,0,1,1],line:[0,0,1,0],reason:'Possible printed rule.'},
                    ...Array.from({length:10},()=>({label:'cross_out',state:'uncertain',method:'hough',display:false,box:[0,0,1,1],line:[0,0,1,0],reason:'Filtered background candidate.'})),
                    ...Array.from({length:6},()=>({label:'cross_out',state:'uncertain',method:'hough',display:true,box:[0,0,1,1],line:[0,0,1,1],reason:'Additional candidate for display-cap test.'}))]});
    }
    res.writeHead(200,{'Content-Type':'application/json'});res.end(JSON.stringify(response));
  });});
  let app; let logs='';
  try {
    server.listen(0,'127.0.0.1'); await once(server,'listening');
    const mock=`http://127.0.0.1:${server.address().port}`;
    fs.writeFileSync(path.join(runtime,'.env'),['SESSION_SECURE_COOKIE=false',`OCR_SERVICE_URL=${mock}`,'OCR_API_KEY=private-ocr-key','OCR_RPA_API_KEY=inbound-key','OCR_TEST_SERVICE_URL=','OCR_TEST_API_KEY=',`RPA_BOT_ENDPOINT=${mock}/rpa`,'RPA_BOT_API_KEY=private-rpa-key','OCR_RPA_RESULT_ENABLED=true'].join('\n'));
    const port=await freePort(),origin=`http://127.0.0.1:${port}`;
    app=spawn(php,['-d',`session.save_path=${path.join(runtime,'sessions')}`,'-d','upload_max_filesize=16M','-d','post_max_size=17M','-S',`127.0.0.1:${port}`,'-t','public','public/index.php'],{cwd:runtime,windowsHide:true,stdio:['ignore','pipe','pipe']});
    app.stdout.on('data',b=>logs+=b);app.stderr.on('data',b=>logs+=b);
    let started=false;for(let n=0;n<60;n++){try{await fetch(origin+'/login');started=true;break;}catch{await delay(100);}} assert.ok(started,logs);
    let cookie='';
    async function request(route,{method='GET',body,headers={}}={}) {const response=await fetch(origin+route,{method,body,headers:{...(cookie?{Cookie:cookie}:{}),...headers},redirect:'manual'});const set=response.headers.get('set-cookie');if(set)cookie=set.split(';')[0];return {response,text:await response.text()};}
    const csrf=html=>{const match=html.match(/name="_csrf" value="([^"]+)"/);assert.ok(match,'CSRF field');return match[1];};
    async function login(role) {cookie='';const page=await request('/login');const result=await request('/login',{method:'POST',body:new URLSearchParams({_csrf:csrf(page.text),username:role,password:'fixture-password'})});assert.equal(result.response.status,303);}
    function upload(mode,token) {const form=new FormData(); form.set('mode',mode);if(token)form.set('_csrf',token);
      const keys={mykad:['mykad_front','mykad_back'],passport:['passport_image'],image:['image_file'],form_pdf:['document_pdf'],mykad_pdf:['document_pdf']}[mode]||[];
      for(const key of keys){const pdf=key==='document_pdf';form.set(key,new Blob([pdf?Buffer.from('%PDF-1.4\n%%EOF'):png],{type:pdf?'application/pdf':'image/png'}),pdf?'fixture.pdf':'fixture.png');}return form;}
    assert.equal((await request('/ocr-testing')).response.status,401);
    assert.equal((await request('/ocr-testing/run',{method:'POST',body:upload('image')})).response.status,401);
    await login('agent');assert.equal((await request('/ocr-testing')).response.status,403);
    await login('manager');assert.equal((await request('/ocr-testing')).response.status,200);
    await login('admin');let page=await request('/ocr-testing');assert.equal(page.response.status,200);assert.match(page.text,/href="\/ocr-testing"/);assert.match(page.response.headers.get('cache-control'),/no-store/);
    assert.doesNotMatch(page.text,/private-ocr-key|private-rpa-key|inbound-key/);
    const token=csrf(page.text);
    assert.equal((await request('/ocr-testing/run',{method:'POST',body:upload('image','bad')})).response.status,419);
    assert.equal(ocrCalls.length,0);
    const routes={mykad:'/api/verify-document',passport:'/api/verify-document',image:'/api/verify-mykad-image',form_pdf:'/api/verify-cancellation-form-pdf',mykad_pdf:'/api/verify-mykad-pdf'};
    for(const [mode,route] of Object.entries(routes)) {const result=await request('/ocr-testing/run',{method:'POST',body:upload(mode,token)});assert.equal(result.response.status,200,result.text);assert.ok(JSON.parse(result.text).diagnostics);const call=ocrCalls.at(-1);assert.equal(call.url,route);assert.equal(call.headers['x-api-key'],'private-ocr-key');assert.equal(call.headers['x-ocr-diagnostics'],'1');assert.doesNotMatch(call.body,/entered_name/);}
    assert.equal(rpaCalls.length,0,'Testing must not call RPA, even with delivery enabled');
    const bad=upload('image',token);bad.set('image_file',new Blob(['not an image'],{type:'image/png'}),'fake.png');assert.equal((await request('/ocr-testing/run',{method:'POST',body:bad})).response.status,400);
    assert.equal((await request('/ocr-testing/run',{method:'POST',body:upload('arbitrary',token)})).response.status,400);
    accounts.admin.active=false;saveAccounts();assert.equal((await request('/ocr-testing/run',{method:'POST',body:upload('image',token)})).response.status,403);accounts.admin.active=true;saveAccounts();
    const sql=fs.readFileSync(path.join(runtime,'sql.log'),'utf8');assert.doesNotMatch(sql,/INSERT|UPDATE|DELETE|portal_ticket|portal_request/i,'Test page must only read authentication data');
    const production=upload('passport');production.set('document_type','passport');
    let result=await request('/api/ocr/verify-document',{method:'POST',body:production,headers:{'X-API-Key':'inbound-key','X-Correlation-ID':'request-001'}});
    assert.equal(result.response.status,200,result.text);assert.deepEqual(JSON.parse(result.text),fakeResult);assert.equal(ocrCalls.at(-1).headers['x-ocr-diagnostics'],undefined);
    assert.equal(rpaCalls.length,1);assert.deepEqual(rpaCalls[0].body,{extracted_name:'SAMPLE CUSTOMER',extracted_document_number:'900101-10-1234',verification_status:'VERIFIED'});
    assert.equal(rpaCalls[0].headers['x-ocr-result-type'],'identity-extraction-v1');assert.equal(rpaCalls[0].headers['x-correlation-id'],'request-001');assert.equal(rpaCalls[0].headers['x-api-key'],'private-rpa-key');assert.match(rpaCalls[0].headers['idempotency-key'],/^ocr-[a-f0-9]{64}$/);
    rejectRpa=true;result=await request('/api/ocr/verify-document',{method:'POST',body:production,headers:{'X-API-Key':'inbound-key','X-Correlation-ID':'request-001'}});assert.deepEqual(JSON.parse(result.text),fakeResult);assert.equal(rpaCalls[1].headers['idempotency-key'],rpaCalls[0].headers['idempotency-key']);
    // Expose only synthetic fixtures for optional browser checks in the same run.
    if(process.argv.includes('--browser')) await require('./ocr-testing-browser.cjs')({origin,runtime,png,port:await freePort(),cookie,token,selectCrossOutFixture:()=>{crossOutFixture=true;}});
    console.log('Portal HTTP: authentication, role revocation, CSRF, five upload modes, real cURL forwarding, zero testing writes/callbacks, three-field RPA delivery and failure isolation passed.');
  } finally {if(app && app.exitCode === null){app.kill();await once(app,'exit').catch(()=>{});}await new Promise(resolve=>server.close(resolve));}
})().catch(error=>{console.error(error);process.exitCode=1;});
