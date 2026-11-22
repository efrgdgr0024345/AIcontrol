// Real local PHP actions with synthetic provider replies; no billed AI calls.
const {chromium}=require('playwright'),fs=require('fs'),os=require('os'),path=require('path'),net=require('net');const {spawn,spawnSync}=require('child_process');
(async()=>{
 const root=path.resolve(__dirname,'..'),php=process.env.PHP_BINARY||'php',tmp=fs.mkdtempSync(path.join(os.tmpdir(),'aicontrol-living-')),home=path.join(tmp,'private');
 const env={...process.env,AICONTROL_PRIVATE_DIR:home,AICONTROL_LOCAL_DEV:'1',AICONTROL_PIN_VERIFIED:'1',AICONTROL_OPENAI_API_KEY:'synthetic-only',AICONTROL_RESEARCH_HOURLY_LIMIT:'30'};
 const setup=spawnSync(php,['bin/setup.php','--access-mode=local','--private-dir='+home,'--public-root='+root],{cwd:root,env,encoding:'utf8'});if(setup.status)throw Error(setup.stderr);
 const seed=spawnSync(php,['-r',`require 'src/Vault.php';require 'src/AgentRunner.php';require 'src/Research.php';$r=new \\AIcontrol\\Research(getenv('AICONTROL_PRIVATE_DIR'));$r->create('Living map fixture','How might repair expand our options?');`],{cwd:root,env,encoding:'utf8'});if(seed.status)throw Error(seed.stderr);
 const port=await new Promise(r=>{const s=net.createServer();s.listen(0,'127.0.0.1',()=>{const p=s.address().port;s.close(()=>r(p));});});const url=`http://127.0.0.1:${port}/loader.php`;
 const server=spawn(php,['-S',`127.0.0.1:${port}`,'tests/fixtures/exploration_router.php'],{cwd:root,env,stdio:'ignore'});let browser;
 try{
  browser=await chromium.launch({headless:true,executablePath:process.env.CHROMIUM_PATH||['/usr/bin/google-chrome','/usr/bin/chromium'].find(fs.existsSync),args:['--no-sandbox']});const context=await browser.newContext({viewport:{width:1440,height:1000},reducedMotion:'reduce'}),page=await context.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.goto(url+'?view=research&project=1');await page.getByRole('button',{name:'Start research',exact:true}).click();
  await page.waitForFunction(()=>document.querySelector('[data-investigation]')?.dataset.phase==='exploring');
  const list=page.locator('[data-branch-list]'),rows=list.locator('[data-branch-id]');await rows.first().waitFor();if(await rows.count()!==2)throw Error('Concept branches missing');
  const width=await page.locator('[data-branch-browser]').evaluate(n=>n.getBoundingClientRect().width);if(width<350)throw Error('Branch browser is too narrow');
  const a=await rows.nth(0).getAttribute('data-branch-id'),b=await rows.nth(1).getAttribute('data-branch-id');
  await rows.nth(0).click();await page.locator('[data-map-inspector]').waitFor({state:'visible'});if(await page.locator('[data-map-advanced]').evaluate(n=>n.open))throw Error('Advanced controls crowded the reading window');
  await page.locator('[data-concept-chat-toggle]').click();await page.getByLabel('Your question',{exact:true}).fill('FIRST-BRANCH-QUESTION: explain this idea simply.');
  await page.getByRole('button',{name:'Ask about this concept',exact:true}).click();await page.locator('.map-conversation-turn').getByText(/Synthetic concept reply/).waitFor();
  await page.locator('[data-branch-back]').click();await rows.nth(1).click();if((await page.locator('.map-conversation-thread').innerText()).includes('FIRST-BRANCH-QUESTION'))throw Error('Thread leaked across concepts');
  await page.getByLabel('Your question',{exact:true}).fill('Draft for second concept');await page.locator('[data-branch-back]').click();await rows.nth(0).click();if(!(await page.locator('.map-conversation-thread').innerText()).includes('FIRST-BRANCH-QUESTION'))throw Error('Saved thread lost');
  await page.locator('[data-branch-back]').click();await rows.nth(1).click();if(await page.getByLabel('Your question',{exact:true}).inputValue()!=='Draft for second concept')throw Error('Switching lost draft');
  await page.locator('[data-branch-back]').click();await page.getByLabel('Search branches',{exact:true}).fill('no-such-concept');if(await rows.count())throw Error('Search did not filter');await page.getByLabel('Search branches',{exact:true}).fill('');
  await rows.nth(0).click();await page.getByRole('button',{name:'Expand this pathway',exact:true}).click();await page.waitForFunction(()=>document.querySelectorAll('[data-branch-id]').length>2);
  const csrf=await page.locator('input[name=csrf]').first().inputValue();const run=await page.locator('[data-investigation]').getAttribute('data-run-id');
  let response=await page.request.post(url+'?research_api=1',{form:{csrf:'invalid',action:'research_concept_chat',run_id:run,node_id:a,question:'Should fail'}});if(response.status()!==403)throw Error('Chat bypassed CSRF');
  response=await page.request.post(url+'?research_api=1',{form:{csrf,action:'research_concept_chat',run_id:run,node_id:'999999',question:'Should fail'}});if(response.status()!==422)throw Error('Chat accepted another node');
  await page.reload();await list.locator(`[data-branch-id="${a}"]`).waitFor();await list.locator(`[data-branch-id="${a}"]`).click();await page.locator('[data-concept-chat-toggle]').click();await page.locator('.map-conversation-turn').getByText(/FIRST-BRANCH-QUESTION/).waitFor();
  const audit=async()=>{await page.waitForTimeout(500);await page.evaluate(require('axe-core').source);const issues=await page.evaluate(async()=>(await axe.run(document,{runOnly:{type:'tag',values:['wcag2a','wcag2aa','wcag21aa','wcag22aa']}})).violations.map(v=>({id:v.id,nodes:v.nodes.map(n=>n.target)})));if(issues.length)throw Error(JSON.stringify(issues));};
  await audit();fs.mkdirSync(path.join(root,'test-artifacts'),{recursive:true});await page.screenshot({path:path.join(root,'test-artifacts','ui-branch-reading-chat-desktop.png')});
  await page.locator('[data-branch-back]').click();await page.screenshot({path:path.join(root,'test-artifacts','ui-branch-list-desktop.png')});
  await page.setViewportSize({width:390,height:844});await audit();await list.locator(`[data-branch-id="${a}"]`).click();await audit();await page.screenshot({path:path.join(root,'test-artifacts','ui-branch-chat-mobile.png')});
  await page.getByLabel('Your question',{exact:true}).focus();await page.keyboard.press('Escape');if(!await page.locator('[data-map-inspector]').isHidden())throw Error('Escape did not return to branches');
  if(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1))throw Error('Branch view overflows mobile');if(errors.length)throw Error(errors.join('\n'));
  console.log('PASS: spacious searchable branch list, full concept reader, one-click scoped growth, saved isolated chat/drafts/reload, CSRF/node boundaries, desktop/mobile axe and keyboard return (synthetic provider)');
 }finally{if(browser)await browser.close();server.kill();await new Promise(r=>server.exitCode!==null?r():server.once('exit',r));fs.rmSync(tmp,{recursive:true,force:true});}
})().catch(e=>{console.error(e.stack||e.message);process.exitCode=1;});
