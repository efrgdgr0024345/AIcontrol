// Synthetic streams only. Never sends a request to an AI provider.
const {chromium}=require('playwright');const fs=require('fs'),os=require('os'),path=require('path'),net=require('net');const {spawn,spawnSync}=require('child_process');
(async()=>{
 const root=path.resolve(__dirname,'..'),php=process.env.PHP_BINARY||'php',temporary=fs.mkdtempSync(path.join(os.tmpdir(),'aicontrol-brief-ui-')),home=path.join(temporary,'private');
 const port=await new Promise(resolve=>{const s=net.createServer();s.listen(0,'127.0.0.1',()=>{const p=s.address().port;s.close(()=>resolve(p));});});const url=`http://127.0.0.1:${port}`;
 const setup=spawnSync(php,['bin/setup.php','--access-mode=local','--private-dir='+home,'--public-root='+root],{cwd:root,encoding:'utf8'});if(setup.status)throw Error(setup.stderr);
 const server=spawn(php,['-S',`127.0.0.1:${port}`,'bin/dev-router.php'],{cwd:root,env:{...process.env,AICONTROL_PRIVATE_DIR:home,AICONTROL_LOCAL_DEV:'1',AICONTROL_PIN_VERIFIED:'1',AICONTROL_OPENAI_API_KEY:'synthetic-brief-ui'},stdio:'ignore'});let browser;
 const artifacts=path.join(root,'test-artifacts');fs.mkdirSync(artifacts,{recursive:true});
 try{
  browser=await chromium.launch({headless:true,executablePath:process.env.CHROMIUM_PATH||['/usr/bin/google-chrome','/usr/bin/chromium'].find(x=>fs.existsSync(x)),args:['--no-sandbox']});
  const context=await browser.newContext({viewport:{width:1440,height:1050}}),page=await context.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));
  let calls=0,mode='normal',releaseOld;const suggestions=['Is solar payback worthwhile for a Perth household in 2026 using independent tariff data?','Which system size changes the payback under current retail tariffs?','What evidence would overturn the expected household savings?'];
  const stream=(texts)=>['event: start\ndata: {"remaining":19}\n\n','event: delta\ndata: '+JSON.stringify({text:texts[0]+'\n'})+'\n\n','event: delta\ndata: '+JSON.stringify({text:texts.slice(1).join('\n')})+'\n\n','event: done\ndata: '+JSON.stringify({suggestions:texts,remaining:19})+'\n\n'].join('');
  await page.route('**/loader.php?view=research',async route=>{
   if(!(route.request().postData()||'').includes('research_brief_suggest'))return route.continue();calls++;
   if(mode==='hold'){mode='normal';await new Promise(resolve=>releaseOld=resolve);return route.fulfill({contentType:'text/event-stream',body:stream(['STALE suggestion must never appear','Old second','Old third'])}).catch(()=>{});}
   if(mode==='busy')return route.fulfill({status:409,contentType:'application/json',body:JSON.stringify({error:'Research or learning is active. Your draft can still be saved.'})});
   if(mode==='limited')return route.fulfill({status:429,contentType:'application/json',body:JSON.stringify({error:'Live writing help has used its 20 updates this hour.'})});
   if(mode==='early')return route.fulfill({contentType:'text/event-stream',body:'event: delta\ndata: {"text":"Incomplete alternative"}\n\n'});
   return route.fulfill({contentType:'text/event-stream',body:stream(suggestions)});
  });
  await page.goto(url+'/loader.php?view=research');await page.getByRole('heading',{name:'What do you want to find out?',exact:true}).waitFor();
  if(await page.locator('[data-brief-create]').evaluate(el=>!!el.closest('details')))throw Error('Create form is buried');
  const draft=page.getByLabel('Research brief',{exact:true});if(Number(await draft.getAttribute('rows'))<6)throw Error('Brief is too small');
  await page.getByLabel('Working title',{exact:true}).fill('A focused solar question');await draft.fill('short');await page.waitForTimeout(650);if(calls)throw Error('Short draft made an API call');
  await page.getByRole('button',{name:'Perth solar',exact:true}).click();await page.locator('[data-brief-status]').filter({hasText:'Suggestions ready'}).waitFor({timeout:6000}).catch(async()=>{throw Error(JSON.stringify({status:await page.locator('[data-brief-status]').innerText(),calls,errors}));});
  for(const key of ['question','context','evidence','focus'])if(await page.locator(`[data-quality=${key}]`).getAttribute('data-met')!=='true')throw Error('Checklist did not update '+key);
  const initial=await draft.inputValue();if(!initial.includes('Perth household'))throw Error('Starter did not insert');
  await page.getByRole('button',{name:'Use this suggestion 1',exact:true}).click();if(await draft.inputValue()!==suggestions[0])throw Error('Use did not replace');
  await page.getByRole('button',{name:'Undo last change',exact:true}).click();if(await draft.inputValue()!==initial)throw Error('Undo failed');
  await page.getByRole('button',{name:'Refresh suggestions',exact:true}).click();await page.locator('[data-brief-status]').filter({hasText:'Suggestions ready'}).waitFor();
  await page.getByRole('button',{name:'Append suggestion 2',exact:true}).click();if(!await draft.inputValue().then(x=>x.includes(initial)&&x.includes(suggestions[1])))throw Error('Append failed');
  mode='hold';await draft.fill('First draft requesting delayed solar evidence?');await page.waitForRequest(r=>(r.postData()||'').includes('First draft requesting delayed'));await draft.fill('New draft asking what independent evidence changes Perth solar payback?');
  releaseOld();await page.locator('[data-brief-status]').filter({hasText:'Suggestions ready'}).waitFor();if((await page.locator('[data-brief-suggestions]').innerText()).includes('STALE'))throw Error('Old request replaced new suggestions');
  mode='busy';await page.getByRole('button',{name:'Refresh suggestions',exact:true}).click();await page.locator('[data-brief-status]').filter({hasText:'Research or learning is active'}).waitFor();
  mode='limited';await page.getByRole('button',{name:'Refresh suggestions',exact:true}).click();await page.locator('[data-brief-status]').filter({hasText:'20 updates'}).waitFor();
  mode='early';await page.getByRole('button',{name:'Refresh suggestions',exact:true}).click();await page.locator('[data-brief-status]').filter({hasText:'stream ended early'}).waitFor();
  if(!await draft.inputValue().then(x=>x.startsWith('New draft')))throw Error('Failure changed user draft');
  mode='normal';await page.getByRole('button',{name:'Refresh suggestions',exact:true}).click();await page.locator('[data-brief-status]').filter({hasText:'Suggestions ready'}).waitFor();
  if(await page.locator('[data-brief-suggestions]').evaluate(el=>el.contains(document.activeElement)))throw Error('Stream stole focus');
  await page.evaluate(require('axe-core').source);const audit=await page.evaluate(()=>axe.run(document,{runOnly:{type:'tag',values:['wcag2a','wcag2aa','wcag21aa','wcag22aa']}}));if(audit.violations.length)throw Error(JSON.stringify(audit.violations.map(x=>({id:x.id,nodes:x.nodes.map(n=>n.target)}))));
  await page.screenshot({path:path.join(artifacts,'ui-brief-create-desktop.png'),fullPage:true});
  await page.setViewportSize({width:390,height:844});await page.reload();if(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1))throw Error('Mobile overflow');
  if(await page.locator('[data-brief-tips]').getAttribute('open')!==null)throw Error('Mobile tips not collapsed');await page.screenshot({path:path.join(artifacts,'ui-brief-create-mobile.png'),fullPage:true});
  const beforeSubmit=calls;await page.getByLabel('Working title',{exact:true}).fill('Saved without auto-start');await draft.fill('Is solar worthwhile for a Perth household using independent 2026 data?');await page.getByRole('button',{name:'Create research project',exact:true}).click();
  await page.getByRole('heading',{name:'Saved without auto-start',exact:true}).waitFor();await page.getByRole('button',{name:'Start research',exact:true}).waitFor();await page.waitForTimeout(650);if(calls!==beforeSubmit)throw Error('Submitting create sent a suggestion');if(await page.locator('[data-investigation]').count())throw Error('Creation started research');
  await page.getByRole('link',{name:'New research',exact:true}).click();await page.getByRole('heading',{name:'What do you want to find out?',exact:true}).waitFor();await page.getByRole('heading',{name:'Open an existing project',exact:true}).waitFor();
  const noJs=await browser.newContext({javaScriptEnabled:false}),fallback=await noJs.newPage();await fallback.goto(url+'/loader.php?view=research&new=1');
  await fallback.getByLabel('Working title',{exact:true}).fill('No JavaScript project');await fallback.getByLabel('Research brief',{exact:true}).fill('Which local evidence could change a Perth household decision?');
  await fallback.getByText('Read or copy starter examples',{exact:true}).click();await fallback.locator('.brief-examples dt').filter({hasText:'Decision'}).waitFor();await fallback.getByRole('button',{name:'Create research project',exact:true}).click();await fallback.getByRole('heading',{name:'No JavaScript project',exact:true}).waitFor();
  if(errors.length)throw Error(errors.join('\n'));console.log('PASS: first-class create, starters, checklist, streamed suggestions, apply/append/undo, abort/stale handling, errors, mobile, axe and no-JS creation');
 }finally{if(browser)await browser.close();server.kill();await new Promise(resolve=>server.exitCode!==null?resolve():server.once('exit',resolve));fs.rmSync(temporary,{recursive:true,force:true});}
})().catch(e=>{console.error(e.stack||e.message);process.exitCode=1;});
