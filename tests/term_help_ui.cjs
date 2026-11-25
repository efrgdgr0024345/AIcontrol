// Real local PHP + SQLite; provider replies are synthetic, never billed.
const assert=require('node:assert/strict');
const {candidates}=require('../assets/term-help.js');
const sample='Bayesian inference and a counterfactual can reduce uncertainty. NASA uses interoperability.';
const matches=candidates(sample);
assert(matches.some(x=>x.text==='Bayesian inference'));
assert(!candidates('authenticationist uncorrelation understanding information').some(x=>['authentication','correlation','understanding','information'].includes(x.text)));
assert.equal(candidates('entropy entropy ENTROPY').length,1);
assert.equal(candidates(sample,2).length,2);
for(const item of matches)assert.equal(sample.slice(item.start,item.end),item.text);
const {chromium}=require('playwright'),fs=require('fs'),os=require('os'),path=require('path'),net=require('net');
const {spawn,spawnSync}=require('child_process');
(async()=>{
 const root=path.resolve(__dirname,'..'),php=process.env.PHP_BINARY||'php',tmp=fs.mkdtempSync(path.join(os.tmpdir(),'aicontrol-term-ui-')),home=path.join(tmp,'private');
 const env={...process.env,AICONTROL_PRIVATE_DIR:home,AICONTROL_LOCAL_DEV:'1',AICONTROL_PIN_VERIFIED:'1',AICONTROL_OPENAI_API_KEY:'synthetic-term-ui'};
 const setup=spawnSync(php,['bin/setup.php','--access-mode=local','--private-dir='+home,'--public-root='+root],{cwd:root,env,encoding:'utf8'});if(setup.status)throw Error(setup.stderr);
 const seed=spawnSync(php,['-r',String.raw`require 'src/Vault.php';require 'src/AgentRunner.php';require 'src/Research.php';$r=new AIcontrol\Research(getenv('AICONTROL_PRIVATE_DIR'));$p=$r->create('Learning fixture','Use counterfactual thinking and Bayesian inference to compare repair options.');$r->create('Other project','Unrelated private text.');$e=new AIcontrol\Investigation(getenv('AICONTROL_PRIVATE_DIR'));$run=$e->createRun($p);$e->appendNode($run,null,'model_idea','complete','Compare assumptions across long research outputs: consider counterfactual reasoning, changing constraints and the information available to a decision-maker','Unchecked prior. Claim: Counterfactual analysis compares alternate cases. Bayesian inference updates beliefs with new observations.');$e->appendNode($run,null,'model_idea','complete','Second concept','Uncertainty remains.');$e->stop($run);`],{cwd:root,env,encoding:'utf8'});if(seed.status)throw Error(seed.stderr+seed.stdout);
 const freePort=()=>new Promise(resolve=>{const s=net.createServer();s.listen(0,'127.0.0.1',()=>{const p=s.address().port;s.close(()=>resolve(p));});});
 const ready=async port=>{for(let i=0;i<100;i++){const connected=await new Promise(resolve=>{const s=net.connect(port,'127.0.0.1');s.once('connect',()=>{s.destroy();resolve(true);});s.once('error',()=>resolve(false));});if(connected)return;await new Promise(resolve=>setTimeout(resolve,50));}throw Error('Local fixture server did not start');};
 const port=await freePort(),url=`http://127.0.0.1:${port}/loader.php`,server=spawn(php,['-S',`127.0.0.1:${port}`,'tests/fixtures/term_help_router.php'],{cwd:root,env,stdio:'ignore'});let browser,demo;
 const calls=()=>{const file=path.join(home,'term-fixture-calls');return fs.existsSync(file)?fs.readFileSync(file,'utf8').trim().split('\n').length:0;};
 try{
  browser=await chromium.launch({headless:true,executablePath:process.env.CHROMIUM_PATH||['/usr/bin/google-chrome','/usr/bin/chromium'].find(fs.existsSync),args:['--no-sandbox']});
  await ready(port);
  const context=await browser.newContext({viewport:{width:1366,height:900}}),page=await context.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.goto(url+'?view=research&project=1&map=0');await page.locator('[data-project-menu] > summary').click();
  const brief=page.locator('.research-project-brief p[data-term-kind]'),term=brief.locator('.term-help-word').filter({hasText:/^counterfactual$/i});
  await term.waitFor();assert.equal(await brief.textContent(),'Use counterfactual thinking and Bayesian inference to compare repair options.');
  await term.hover();await term.focus();await page.mouse.wheel(0,150);await page.waitForTimeout(500);assert.equal(calls(),0,'Discovery/hover/focus/scroll made paid call');
  await term.click();const popup=page.locator('#term-help-popup');await popup.getByText('A counterfactual describes a different possible situation.',{exact:true}).waitFor();assert.match(await popup.locator('.term-help-note').textContent(),/unfinished/);assert.equal(await popup.getByText(/compare a repair with and without/).count(),0,'Final output arrived before partial text could be read');await popup.getByText(/compare a repair with and without/).waitFor();assert.equal(calls(),1);assert.equal(await popup.locator('img').count(),0,'Provider output interpreted as HTML');
  await popup.getByRole('button',{name:'Close term explanation'}).click();await term.hover();await popup.waitFor({state:'visible'});await term.click();await page.waitForTimeout(250);await popup.waitFor({state:'visible'});assert.equal(calls(),1,'Cached hover/click made call');
  await page.keyboard.press('Escape');await term.press('Enter');await popup.waitFor({state:'visible'});assert.equal(calls(),1,'Repeated click made call');
  await page.reload();await page.locator('[data-project-menu] > summary').click();await term.waitFor();await term.hover();await page.waitForTimeout(200);assert.equal(calls(),1);await term.click();await popup.getByText(/Saved explanation/).waitFor();assert.equal(calls(),1,'Reload cache called provider');
  await page.route('**/loader.php',async route=>{if((route.request().postData()||'').includes('research_define_term'))return route.fulfill({contentType:'text/event-stream',body:'event: delta\ndata: {"text":"Partial learning aid"}\n\n'});return route.continue();});
  await brief.locator('.term-help-word').filter({hasText:'Bayesian inference'}).click();await popup.getByText(/Unfinished explanation/).waitFor();assert.match(await popup.locator('.term-help-definition').textContent(),/Partial learning aid/);assert.equal(calls(),1);await page.unroute('**/loader.php');
  const csrf=await page.locator('input[name=csrf]').first().inputValue(),form={csrf,action:'research_define_term',project_id:'1',source_kind:'node',node_id:'1',term:'counterfactual'};
  let response=await page.request.post(url,{form:{...form,csrf:'invalid'}});assert.equal(response.status(),403);
  response=await page.request.post(url,{form:{...form,project_id:'2'}});assert.equal(response.status(),404);
  response=await page.request.post(url,{form:{...form,term:'nonexistent'}});assert.equal(response.status(),422);assert.equal(calls(),1);
  const fresh=await browser.newContext();response=await fresh.request.post(url,{form});assert.equal(response.status(),403);await fresh.close();
  await page.goto(url+'?view=research&project=1&map=1');await page.locator('[data-branch-id="1"]').click();
  const reader=page.locator('[data-map-inspector] .map-inspector-text'),readerText=await reader.textContent();await reader.locator('.term-help-word').first().waitFor();assert.equal(calls(),1);
  const mapTerm=reader.locator('.term-help-word').filter({hasText:/^counterfactual$/i});await mapTerm.focus();await page.keyboard.press('Enter');await popup.getByText(/Fast AI explanation/).waitFor();assert.equal(calls(),2);assert.equal(await reader.textContent(),readerText,'Highlighting changed research text');
  const explain=page.locator('[data-explain-output]');assert.equal(await explain.evaluate(el=>el.nextElementSibling?.dataset.branchExpand),'1','Explain must precede Expand');
  assert.equal(calls(),2,'Selecting a concept automatically requested a rewrite');await explain.click();await page.locator('[data-output-explanation-text]').getByText('A different choice can change the result.',{exact:true}).waitFor();assert.match(await page.locator('[data-output-explanation-status]').textContent(),/unfinished/);
  await page.locator('[data-branch-id="2"]').click();await page.waitForTimeout(100);assert.equal(await page.locator('[data-output-explanation-box]').isVisible(),false,'Old rewrite leaked to another branch');await page.waitForTimeout(1300);await page.locator('[data-branch-id="1"]').click();await page.locator('[data-output-explanation-status]').getByText('Rewrite saved.',{exact:true}).waitFor();assert.equal(await reader.textContent(),readerText,'Explain overwrote B');await explain.click();assert.equal(calls(),3,'Saved rewrite was regenerated');
  const audit=async()=>{await page.evaluate(require('axe-core').source);const v=await page.evaluate(async()=>(await axe.run(document,{runOnly:{type:'tag',values:['wcag2a','wcag2aa','wcag21aa','wcag22aa']}})).violations.map(v=>({id:v.id,nodes:v.nodes.map(n=>n.target)})));assert.deepEqual(v,[]);};
  await audit();fs.mkdirSync(path.join(root,'test-artifacts'),{recursive:true});await page.screenshot({path:path.join(root,'test-artifacts','ui-term-help-desktop.png')});
  await page.setViewportSize({width:390,height:844});await page.emulateMedia({colorScheme:'dark',reducedMotion:'reduce'});await mapTerm.click();await popup.getByText(/repair with and without/).waitFor();await page.waitForTimeout(350);await popup.waitFor({state:'visible'});
  const moved=await page.locator('[data-map-inspector]').evaluate(el=>{const before=el.scrollTop;el.scrollTop=before+6;return el.scrollTop!==before;});assert(moved,'Long mobile fixture did not exercise reader scrolling');await page.waitForTimeout(200);await popup.waitFor({state:'visible'});
  const bounds=await popup.boundingBox();assert(bounds.x>=0&&bounds.y>=0&&bounds.x+bounds.width<=390&&bounds.y+bounds.height<=844,'Mobile popup outside screen');assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1),false);await audit();await page.screenshot({path:path.join(root,'test-artifacts','ui-term-help-mobile.png')});assert.equal(calls(),3);
  // Public-testing sessions have neither a highlight config nor a paid endpoint.
  const demoPort=await freePort();demo=spawn(php,['-S',`127.0.0.1:${demoPort}`,'tests/fixtures/term_help_router.php'],{cwd:root,env:{...env,AICONTROL_PIN_VERIFIED:'',AICONTROL_PUBLIC_TESTING:'1'},stdio:'ignore'});
  await ready(demoPort);
  const demoPage=await context.newPage();await demoPage.goto(`http://127.0.0.1:${demoPort}/loader.php?view=research&project=1`);assert.equal(await demoPage.locator('[data-term-help-config]').count(),0);
  const demoCsrf=await demoPage.locator('input[name=csrf]').first().inputValue();response=await demoPage.request.post(`http://127.0.0.1:${demoPort}/loader.php`,{form:{...form,csrf:demoCsrf}});assert.equal(response.status(),403);assert.equal(calls(),3);
  assert.deepEqual(errors,[]);console.log('PASS: local phrase detection; zero calls on discovery/hover/focus/scroll; one click, saved/reload reuse; keyboard, safe text, map reader, auth/CSRF/project/demo boundaries, desktop/mobile/dark axe (synthetic provider)');
 }finally{
  if(browser)await browser.close();for(const child of [server,demo].filter(Boolean)){child.kill();await new Promise(resolve=>child.exitCode!==null?resolve():child.once('exit',resolve));}fs.rmSync(tmp,{recursive:true,force:true});
 }
})().catch(e=>{console.error(e.stack||e.message);process.exitCode=1;});
