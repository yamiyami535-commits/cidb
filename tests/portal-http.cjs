// Real HTTP regression for login -> home when the email migration is incomplete.
// Uses an isolated app copy, in-memory PostgreSQL, and a local mock RPA endpoint.
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const os=require('node:os');
const http=require('node:http');
const net=require('node:net');
const {once}=require('node:events');
const {spawn,spawnSync}=require('node:child_process');
const {createRequire}=require('node:module');
if(typeof globalThis.CustomEvent==='undefined'){
 globalThis.CustomEvent=class CustomEvent extends Event{
  constructor(type,options={}){super(type);this.detail=options.detail;}
 };
}
const root=path.resolve(__dirname,'..');
const dep=createRequire(path.join(root,'var/email-test-runtime/package.json'));
const {PGlite}=dep('@electric-sql/pglite');
const {pgcrypto}=dep('@electric-sql/pglite/contrib/pgcrypto');
const {PGLiteSocketServer}=dep('@electric-sql/pglite-socket');
const php=process.env.PHP_BINARY || 'php';
async function freePort(){const s=net.createServer();s.listen(0,'127.0.0.1');await once(s,'listening');const p=s.address().port;await new Promise(r=>s.close(r));return p;}

(async()=>{
 const temp=fs.mkdtempSync(path.join(os.tmpdir(),'cidb-portal-http-'));
 const db=await PGlite.create({extensions:{pgcrypto}});
 const dbPort=await freePort();
 const socket=new PGLiteSocketServer({db,host:'127.0.0.1',port:dbPort});
 let app,logs='',cookie='';const submissions=[];
 const bot=http.createServer((req,res)=>{let body='';req.on('data',b=>body+=b);req.on('end',()=>{
   submissions.push(JSON.parse(body));res.writeHead(200,{'Content-Type':'application/json'});res.end('{"status":"inserted"}');
 });});
 try {
   await db.exec(fs.readFileSync(path.join(root,'database/schema.sql'),'utf8').replace(/^\\ir .*$/gm,''));
   await db.exec("ALTER TABLE portal_requests ADD COLUMN request_source varchar(8) NOT NULL DEFAULT 'form'");
   const hashed=spawnSync(php,['-r','echo password_hash($argv[1], PASSWORD_DEFAULT);','local-fixture-password'],{encoding:'utf8',windowsHide:true});
   if(hashed.error)throw hashed.error;
   assert.equal(hashed.status,0,hashed.stderr);
   const user=(await db.query('INSERT INTO portal_users(username,email,password_hash) VALUES ($1,$2,$3) RETURNING id',['fixture','fixture@example.test',hashed.stdout])).rows[0];
   await socket.start();bot.listen(0,'127.0.0.1');await once(bot,'listening');
   // Copy only application files, never the actual .env or existing sessions.
   for(const dir of ['src','public','views'])fs.cpSync(path.join(root,dir),path.join(temp,dir),{recursive:true});
   fs.mkdirSync(path.join(temp,'sessions'));
   fs.writeFileSync(path.join(temp,'.env'),[
     'DB_HOST=127.0.0.1',`DB_PORT=${dbPort}`,'DB_DATABASE=postgres','DB_USERNAME=postgres','DB_PASSWORD=',
     'SESSION_SECURE_COOKIE=false',`RPA_BOT_ENDPOINT=http://127.0.0.1:${bot.address().port}/mock`,'RPA_BOT_API_KEY=fixture-only','EMAIL_ENABLED=false'
   ].join('\n'));
   const port=await freePort(),origin=`http://127.0.0.1:${port}`;
   app=spawn(php,['-d',`session.save_path=${path.join(temp,'sessions').replaceAll('\\','/')}`,'-S',`127.0.0.1:${port}`,'-t','public','public/index.php'],{cwd:temp,windowsHide:true,stdio:['ignore','pipe','pipe']});
   app.stdout.on('data',b=>logs+=b);app.stderr.on('data',b=>logs+=b);
   let started=false;
   for(let i=0;i<60;i++){try{await fetch(origin+'/login');started=true;break;}catch{await new Promise(r=>setTimeout(r,100));}}
   assert.ok(started,'PHP server started');
   async function request(url,form){const response=await fetch(origin+url,{redirect:'manual',method:form?'POST':'GET',headers:{...(cookie?{Cookie:cookie}:{}),...(form?{'Content-Type':'application/x-www-form-urlencoded'}:{})},body:form?new URLSearchParams(form):undefined});const set=response.headers.get('set-cookie');if(set)cookie=set.split(';')[0];return {response,text:await response.text()};}
   const field=(html,name)=>{const m=html.match(new RegExp(`name="${name}" value="([^"]+)"`));assert.ok(m,`Field ${name}`);return m[1];};
   assert.equal((await request('/')).response.status,303);
   let page=await request('/login');assert.equal(page.response.status,200);
   const csrf=field(page.text,'_csrf');
   page=await request('/login',{_csrf:csrf,username:'fixture',password:'wrong'});
   assert.equal(page.response.status,200);assert.match(page.text,/not recognised/);
   page=await request('/login',{_csrf:csrf,username:'fixture',password:'local-fixture-password'});
   assert.equal(page.response.status,303);assert.equal(page.response.headers.get('location'),'/');
   page=await request('/');assert.equal(page.response.status,200);assert.match(page.text,/Submit your information/);
   assert.doesNotMatch(page.text,/name="location_area"|Location Area/);
   const token=field(page.text,'_csrf');
   await request('/submit',{_csrf:token});page=await request('/');
   assert.equal(page.response.status,200);assert.match(page.text,/Name is required/);assert.equal(submissions.length,0);
   const key=field(page.text,'submission_key');
   const data={_csrf:token,submission_key:key,name:'Fixture Customer',id_number:'TEST001',email:'customer@example.test',crm:'TEST-CRM',language:'en'};
   page=await request('/submit',data);assert.equal(page.response.status,303);
   assert.equal(submissions.length,1);assert.equal(submissions[0].fields.sCRMID,'TEST-CRM');
   assert.equal(Object.hasOwn(submissions[0].fields,'sLocationArea'),false,'Form dispatch omits location');
   page=await request('/');assert.equal(page.response.status,200);assert.match(page.text,/In progress/);
   const row=(await db.query('SELECT id,status FROM portal_requests WHERE user_id=$1',[user.id])).rows[0];
   assert.equal(row.status,'processing');
   await request('/submit',data);assert.equal(submissions.length,1,'Duplicate submit remains protected');
   for(const route of ['/request-history','/request-history?source=form','/request-history?source=email',`/request-history/${row.id}`])assert.equal((await request(route)).response.status,200,route);
   assert.doesNotMatch((await request(`/request-history/${row.id}`)).text,/Location Area submitted/);
   page=await request(`/request-status/${row.id}`);assert.equal(JSON.parse(page.text).complete,false);
   await db.query("UPDATE portal_requests SET status='success' WHERE id=$1",[row.id]);
   page=await request(`/request-status/${row.id}`);assert.equal(JSON.parse(page.text).message,'Success');assert.equal(JSON.parse(page.text).complete,true);
   // Exercise real history routes against filtered database pages, not hidden DOM rows.
   page=await request('/request-history?source=email');
   assert.match(page.text,/No requests yet/);assert.doesNotMatch(page.text,/class="history-pagination"/);
   page=await request('/request-history?source=form');assert.doesNotMatch(page.text,/class="history-pagination"/);
   const other=(await db.query("INSERT INTO portal_users(username,password_hash) VALUES ('other','unused') RETURNING id")).rows[0];
   for(const [source,owner,count] of [['form',user.id,31],['email',other.id,31],['form',other.id,17]]) {
     await db.query(`INSERT INTO portal_requests(user_id,submission_key,applicant_name,id_number,applicant_email,crim,rpa_request_payload,request_source,created_at)
       SELECT $1,gen_random_uuid(),'History fixture','TEST','fixture@example.test','FIXTURE-CRM','{}',$2,'2026-01-01'::timestamptz + (n/3)*interval '1 minute' FROM generate_series(1,$3::int) n`,[owner,source,count]);
   }
   const historyIds=html=>[...html.matchAll(/data-request-id="([a-f0-9-]+)"/g)].map(match=>match[1]);
   async function checkHistoryPages(scenario) {
     for(const source of ['all','form','email']) {
       const expected=(await db.query(`SELECT id FROM portal_requests WHERE (request_source='email' OR (request_source='form' AND user_id=$1))
         AND ($2='all' OR request_source=$2) ORDER BY created_at DESC,id`,[user.id,source])).rows.map(item=>item.id);
       const pages=Math.ceil(expected.length/15),seen=[];
       for(let number=1;number<=pages;number++) {
         const result=await request(`/request-history?source=${source}&page=${number}`);
         assert.equal(result.response.status,200);const ids=historyIds(result.text);seen.push(...ids);
         assert.ok(result.text.includes(`data-request-history data-history-source="${source}" data-history-page="${number}"`),'Response contains replaceable history fragment');
         assert.ok(result.text.includes('data-history-link'),'History controls support in-page updates');
         assert.deepEqual(ids,expected.slice((number-1)*15,number*15),`${scenario} ${source} page ${number}`);
         assert.ok(ids.length<=15);assert.match(result.text,new RegExp(`aria-current="page" aria-label="Page ${number}"`));
         assert.ok(result.text.indexOf('class="history-pagination"')>result.text.lastIndexOf('class="history-list-row"'));
         assert.ok(result.text.includes(`href="/request-history?source=${source}&amp;page=`));
         assert.equal(result.text.includes('rel="prev"'),number>1);assert.equal(result.text.includes('rel="next"'),number<pages);
         for(const filter of ['all','form','email'])assert.ok(result.text.includes(`href="/request-history?source=${filter}"`),'Filter resets to first page');
       }
       assert.deepEqual(seen,expected,'No duplicates or missing rows');
       assert.deepEqual(historyIds((await request(`/request-history?source=${source}&page=999999`)).text),expected.slice((pages-1)*15),'Out-of-range page clamps to last');
       for(const bad of ['0','-1','abc','1.5','999999999999999999999999','%3Cscript%3E']) {
         assert.deepEqual(historyIds((await request(`/request-history?source=${source}&page=${bad}`)).text),expected.slice(0,15),'Invalid page uses first');
       }
       assert.deepEqual(historyIds((await request(`/request-history?source=${source}&page[]=2`)).text),expected.slice(0,15));
       assert.equal((await request(`/request-history/${expected[15]}`)).response.status,200,'Page two request opens');
     }
     const hidden=(await db.query("SELECT id FROM portal_requests WHERE user_id=$1 AND request_source='form' LIMIT 1",[other.id])).rows[0].id;
     assert.equal((await request(`/request-history/${hidden}`)).response.status,404,'Other users form details remain private');
     assert.equal((await request(`/request-status/${hidden}`)).response.status,404);
     assert.deepEqual(historyIds((await request('/request-history?source=invalid')).text),historyIds((await request('/request-history?source=all')).text));
     assert.deepEqual(historyIds((await request('/request-history?source[]=email')).text),historyIds((await request('/request-history?source=all')).text));
   }
   await checkHistoryPages('partial migration');
   await db.exec(fs.readFileSync(path.join(root,'database/migrations/20260925_email_reader.sql'),'utf8'));
   await checkHistoryPages('full migration');
   const emailId=(await db.query("SELECT id FROM portal_requests WHERE request_source='email' LIMIT 1")).rows[0].id;
   for(const id of [row.id,emailId]) {
     await db.query(`UPDATE portal_requests SET rpa_response=$2::jsonb,rpa_response_text='PRIVATE_RAW_TEXT',rpa_http_status=502,rpa_reference_id='PRIVATE_SCHEDULE_ID',
       error_code='PRIVATE_ERROR_CODE',error_detail='PRIVATE_DIAGNOSTIC',rpa_request_payload=$3::jsonb WHERE id=$1`,[id,
       JSON.stringify({runner_id:'PRIVATE_RUNNER',runner_name:'PRIVATE_NAME',schedule_id:'PRIVATE_SCHEDULE',control_room_ip:'PRIVATE_IP'}),
       JSON.stringify({company:'PRIVATE_COMPANY',fields:{sCRMID:'CUSTOMER-CRM',sLanguage:'en'},secret:'PRIVATE_PAYLOAD'})]);
     const detail=await request(`/request-history/${id}`);assert.equal(detail.response.status,200);
     assert.doesNotMatch(detail.text,/PRIVATE_|response-raw|technical-note|HTTP 502|RPA reference ID|Submission attempts/);
     assert.match(detail.text,/Your request fields/);assert.match(detail.text,/data-request-status/);
     const status=await request(`/request-status/${id}`);assert.doesNotMatch(status.text,/PRIVATE_/);
     const retained=(await db.query('SELECT rpa_response,rpa_request_payload FROM portal_requests WHERE id=$1',[id])).rows[0];
     assert.equal(retained.rpa_response.runner_id,'PRIVATE_RUNNER');assert.equal(retained.rpa_request_payload.secret,'PRIVATE_PAYLOAD');
   }
   await request('/logout',{_csrf:token});assert.equal((await request(`/request-status/${row.id}`)).response.status,401);
   assert.doesNotMatch(logs,/Portal application error|Portal request polling failed|Fatal error/);
   console.log('HTTP login, form submission, filtered pagination, private access, clean details, status polling and logout passed with partial and full email migrations.');
 } catch(error){console.error(logs);throw error;}
 finally {
   if(app && app.exitCode===null){const exited=once(app,'exit');app.kill();await exited;}
   if(bot.listening)await new Promise(r=>bot.close(r));
   await socket.stop();await db.close();
   // Only remove this generated temporary app copy, never the real project.
   const resolved=fs.realpathSync(temp),parent=fs.realpathSync(os.tmpdir());
   if(path.dirname(resolved)===parent && path.basename(resolved).startsWith('cidb-portal-http-'))fs.rmSync(resolved,{recursive:true,force:true});
 }
})().catch(error=>{console.error(error);process.exitCode=1;});
