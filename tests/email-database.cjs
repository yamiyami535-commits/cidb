// Optional isolated PostgreSQL/WASM harness. Never reads portal .env or contacts external services.
const { createRequire } = require('node:module');
const { join } = require('node:path');
const { spawn } = require('node:child_process');
if (typeof globalThis.CustomEvent === 'undefined') {
  globalThis.CustomEvent = class CustomEvent extends Event {
    constructor(type, options = {}) {
      super(type);
      this.detail = options.detail;
    }
  };
}
const testRequire=createRequire(join(__dirname,'../var/email-test-runtime/package.json'));
const { PGlite }=testRequire('@electric-sql/pglite');
const { pgcrypto }=testRequire('@electric-sql/pglite/contrib/pgcrypto');
const { PGLiteSocketServer }=testRequire('@electric-sql/pglite-socket');

(async()=>{
  const db=await PGlite.create({extensions:{pgcrypto}});
  const server=new PGLiteSocketServer({db,host:'127.0.0.1',port:55439});
  try {
    await server.start();
    for(const scenario of ['fresh','legacy']) {
      const code=await new Promise((resolve,reject)=>{
        const child=spawn(process.env.PHP_BINARY || 'php',[join(__dirname,'email-database.php'),scenario],{
          windowsHide:true,stdio:'inherit',env:{...process.env,EMAIL_TEST_DSN:'pgsql:host=127.0.0.1;port=55439;dbname=postgres;sslmode=disable',EMAIL_TEST_USER:'postgres',EMAIL_TEST_PASSWORD:''}
        });
        const timeout=setTimeout(()=>{child.kill();reject(new Error('Database test timed out'));},60000);
        child.on('error',reject); child.on('exit',code=>{clearTimeout(timeout);resolve(code);});
      });
      if(code!==0) throw new Error(`${scenario} database checks failed`);
    }
  } finally { await server.stop(); await db.close(); }
})().catch(error=>{console.error(error.message);process.exitCode=1;});
