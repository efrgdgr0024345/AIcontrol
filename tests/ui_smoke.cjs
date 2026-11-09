// UI test fixtures contain synthetic data only. No provider or GitHub calls.
const {chromium}=require('playwright');
const fs=require('fs'),os=require('os'),path=require('path'),crypto=require('crypto');
const {spawn,spawnSync}=require('child_process');
(async()=>{
 const root=path.resolve(__dirname,'..'),php=process.env.PHP_BINARY||'php';
 const artifacts=process.env.UI_ARTIFACT_DIR||path.join(root,'test-artifacts');fs.mkdirSync(artifacts,{recursive:true});
 const net=require('net');const freePort=()=>new Promise(resolve=>{const server=net.createServer();server.listen(0,'127.0.0.1',()=>{const port=server.address().port;server.close(()=>resolve(port));});});
 const pinPort=await freePort(),appPort=await freePort(),pinUrl=`http://127.0.0.1:${pinPort}`,appUrl=`http://127.0.0.1:${appPort}`;
 const temporary=fs.mkdtempSync(path.join(os.tmpdir(),'aicontrol-ui-'));
 const privateDir=path.join(temporary,'private'),publicDir=path.join(temporary,'public'),sessions=path.join(temporary,'sessions');
 fs.mkdirSync(publicDir);fs.mkdirSync(sessions,{mode:0o700});fs.copyFileSync(path.join(root,'loader.php'),path.join(publicDir,'loader.php'));
 const setup=spawnSync(php,['bin/setup.php','--access-mode=local',`--private-dir=${privateDir}`,`--public-root=${root}`],{cwd:root,encoding:'utf8'});
 if(setup.status!==0)throw new Error(setup.stderr);
 const first=spawn(php,['-d',`session.save_path=${sessions}`,'-S',`127.0.0.1:${pinPort}`,'-t',publicDir],{env:{...process.env,AICONTROL_OPENAI_API_KEY:'',AICONTROL_LOCAL_DEV:'1'},stdio:'ignore'});
 const second=spawn(php,['-S',`127.0.0.1:${appPort}`,'bin/dev-router.php'],{cwd:root,env:{...process.env,AICONTROL_OPENAI_API_KEY:'',AICONTROL_LOCAL_DEV:'1',AICONTROL_PRIVATE_DIR:privateDir,AICONTROL_PIN_VERIFIED:'1',AICONTROL_UPDATE_STATUS_URL:'/loader.php?update_api=status'},stdio:'ignore'});
 let browser;
 try{
  browser=await chromium.launch({executablePath:process.env.CHROMIUM_PATH||['/usr/bin/google-chrome','/usr/bin/chromium'].find(file=>fs.existsSync(file)),headless:true,args:['--no-sandbox']});
  const page=await browser.newPage({viewport:{width:1440,height:1000}});const errors=[],accessibility=[],auditedViews=[];
  let updateFixture={state:'up_to_date',installed_sha:'a'.repeat(40),latest_sha:'a'.repeat(40),checked_at:Math.floor(Date.now()/1000)};
  await page.route('**/loader.php?update_api=status*',route=>route.fulfill({contentType:'application/json',body:JSON.stringify(updateFixture)}));
  page.on('request',request=>{const url=new URL(request.url());if(['http:','https:'].includes(url.protocol)&&!['127.0.0.1','localhost'].includes(url.hostname))errors.push('Unexpected external UI request: '+url.hostname);});
  const audit=async(name)=>{await page.waitForFunction(()=>document.getAnimations().every(animation=>animation.playState!=='running'||animation.effect.getComputedTiming().iterations===Infinity));auditedViews.push(name);await page.evaluate(require('axe-core').source);const result=await page.evaluate(async()=>await axe.run(document,{runOnly:{type:'tag',values:['wcag2a','wcag2aa','wcag21aa','wcag22aa']}}));for(const violation of result.violations)accessibility.push({page:name,id:violation.id,impact:violation.impact,nodes:violation.nodes.map(node=>({target:node.target,summary:node.failureSummary}))});};
  page.on('pageerror',error=>errors.push(error.message));
  page.on('console',message=>{if(message.type()==='error'&& !message.text().includes('favicon')&&!message.text().includes('status of 401')) errors.push(message.text());});
  await page.goto(pinUrl+'/loader.php');
  await page.screenshot({path:path.join(artifacts,'ui-pin-desktop.png'),fullPage:true});await audit('PIN setup');
  await page.setViewportSize({width:390,height:844});if(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth))throw new Error('PIN screen overflows');await page.screenshot({path:path.join(artifacts,'ui-pin-mobile.png'),fullPage:true});await page.setViewportSize({width:1440,height:1000});
  const pin=String(crypto.randomInt(100000,999999));
  await page.getByLabel('PIN',{exact:true}).fill(pin);await page.getByLabel('Confirm PIN',{exact:true}).fill(pin);
  await page.getByRole('button',{name:'Set PIN',exact:true}).click();
  await page.getByRole('link',{name:'Continue to sign in',exact:true}).click();
  await page.getByLabel('PIN',{exact:true}).fill('000000');await page.getByRole('button',{name:'Sign in',exact:true}).click();await page.getByRole('alert').filter({hasText:'Incorrect PIN'}).waitFor();await audit('PIN login error');
  await page.getByLabel('PIN',{exact:true}).fill(pin);await page.getByRole('button',{name:'Sign in',exact:true}).click();
  await page.getByRole('button',{name:'Check GitHub',exact:true}).waitFor();
  await page.screenshot({path:path.join(artifacts,'ui-installer-desktop.png'),fullPage:true});await audit('Installer');
  await page.setViewportSize({width:390,height:844});await page.screenshot({path:path.join(artifacts,'ui-installer-mobile.png'),fullPage:true});
  await page.goto(appUrl+'/loader.php');await page.setViewportSize({width:1440,height:1000});
  await page.screenshot({path:path.join(artifacts,'ui-empty-desktop.png'),fullPage:true});
  await page.keyboard.press('Tab');if(await page.evaluate(()=>document.activeElement.textContent.trim())!=='Skip to content')throw new Error('Skip link is not keyboard accessible');await page.keyboard.press('Enter');
  await page.getByRole('link',{name:'Projects & tasks',exact:true}).click();
  for(const [name,brief] of [['AIcontrol launch','Build a useful control panel, then verify the installation and first task workflow.'],['Client website','Refresh the website structure and make the first-time experience clearer.'],['Reporting toolkit','Define a simple reporting workflow with observable, reviewable results.']]){
   await page.getByLabel('Name',{exact:true}).fill(name);await page.getByLabel('Brief and intended outcome').fill(brief);await page.getByRole('button',{name:'Create project',exact:true}).click();
  }
  for(const [project,title,criteria] of [['AIcontrol launch','Review the installation experience','Verify PIN setup, automatic settings and a clear checked-release installation.'],['Client website','Draft the homepage structure','Propose a concise page structure. List what needs client confirmation.'],['Reporting toolkit','Define reporting acceptance criteria','Describe the inputs, expected result and a repeatable validation method.']]){
   await page.getByLabel('Project',{exact:true}).selectOption({label:project});await page.getByLabel('Task',{exact:true}).fill(title);await page.getByLabel('Acceptance criteria').fill(criteria);await page.getByRole('button',{name:'Save proposal',exact:true}).click();
  }
  const seed=spawnSync(php,['-r',`require 'src/PanelStore.php'; $store=new \\AIcontrol\\PanelStore(getenv('TEST_UI_DB')); $run=$store->startAgent(1,'ui-test-fixture'); $store->finishAgent($run['id'],'in_review',"UI test fixture only. No provider request or code execution.\\nProposed next steps:\\n1. Verify the PIN flow.\\n2. Review the checked commit.\\n3. Confirm task results remain available.");`],{env:{...process.env,TEST_UI_DB:path.join(privateDir,'panel.sqlite')},encoding:'utf8'});
  if(seed.status!==0)throw new Error(seed.stderr||seed.stdout);
  for(const [name,file] of [['Overview','overview'],['Projects & tasks','projects'],['Agent runs','runs'],['Systems','systems'],['Activity','activity']]){
   await page.getByRole('link',{name,exact:true}).click();
   await page.screenshot({path:path.join(artifacts,`ui-${file}-desktop.png`),fullPage:true});await audit(name);
  }
  await page.getByRole('link',{name:'Projects & tasks',exact:true}).click();
  await page.getByRole('textbox',{name:'Search projects and tasks'}).fill('homepage');await page.getByRole('button',{name:'Search',exact:true}).click();
  await page.getByRole('heading',{name:'Draft the homepage structure',exact:true}).waitFor();
  if(await page.getByRole('heading',{name:'Review the installation experience',exact:true}).count())throw new Error('Project/task search did not filter');
  await page.getByRole('link',{name:'Clear search',exact:true}).click();
  updateFixture={state:'update_available',app_update:true,loader_update:true,installed_sha:'a'.repeat(40),latest_sha:'b'.repeat(40),release_title:'UI fixture release',checked_at:Math.floor(Date.now()/1000)};
  await page.getByRole('button',{name:'Check again',exact:true}).click();
  await page.getByText('An update is available',{exact:true}).waitFor();
  await page.getByRole('link',{name:'Review update',exact:true}).waitFor();await audit('Update available');
  for(const scenario of ['success','failure']){
    const events=[{event:'progress',step:1,total:9,label:'Verify selection',detail:'UI fixture only.'},{event:'progress',step:5,total:9,label:'Verify hashes',detail:'UI fixture only.'}];
    events.push(scenario==='success'?{event:'complete',message:'UI fixture installation completed.',summary:{loader_updated:true,changes:{added:['example.php'],changed:['loader.php'],removed:[]}}}:{event:'error',message:'UI fixture stopped safely. Prior code retained.'});
    await page.route('**/loader.php?ui_test_stream='+scenario+'&stream=1',route=>route.fulfill({contentType:'application/x-ndjson',body:events.map(event=>JSON.stringify(event)).join('\n')+'\n'}));
    await page.evaluate(({url,scenario})=>{const form=document.createElement('form');form.action=url+'/loader.php?ui_test_stream='+scenario;form.method='post';form.dataset.updateInstall='';const action=document.createElement('input');action.type='hidden';action.name='action';action.value='install';form.append(action);const button=document.createElement('button');button.textContent='Test '+scenario;form.append(button);document.querySelector('main').append(form);},{url:appUrl,scenario});
    await page.getByRole('button',{name:'Test '+scenario,exact:true}).click();
    const dialog=page.getByRole('dialog');await dialog.getByRole('heading',{name:scenario==='success'?'Update installed':'Update stopped',exact:true}).waitFor();
    if(scenario==='failure'&&await dialog.locator('progress').getAttribute('value')==='9')throw new Error('Failed update displayed 100%');
    await audit('Update '+scenario);await page.screenshot({path:path.join(artifacts,'ui-update-'+scenario+'.png'),fullPage:true});await page.keyboard.press('Escape');
  }
  updateFixture={state:'up_to_date',installed_sha:'a'.repeat(40),latest_sha:'a'.repeat(40),checked_at:Math.floor(Date.now()/1000)};
  await page.getByRole('button',{name:'Check again',exact:true}).click();
  await page.getByText('You’re up to date',{exact:true}).waitFor();

  await page.locator('summary').filter({hasText:'Agent run #1'}).click();
  await page.getByText('UI test fixture only.',{exact:false}).waitFor();
  for(const [name,file] of [['Overview','overview'],['Projects & tasks','projects'],['Agent runs','runs'],['Systems','systems'],['Activity','activity']]){
   await page.setViewportSize({width:390,height:844});await page.getByRole('link',{name,exact:true}).click();
   await page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));
   if(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth)){
    await page.screenshot({path:path.join(artifacts,'ui-overflow.png'),fullPage:true});
    const metrics=await page.evaluate(()=>({width:innerWidth,scroll:document.documentElement.scrollWidth,body:document.body.scrollWidth}));const bad=await page.evaluate(()=>[...document.querySelectorAll('body *')].map(element=>({tag:element.tagName,class:element.className,left:element.getBoundingClientRect().left,right:element.getBoundingClientRect().right})).filter(item=>item.right>innerWidth+1||item.left< -1).slice(0,15));
    throw new Error(`Horizontal overflow on ${name}: `+JSON.stringify({metrics,bad}));
   }
   await page.screenshot({path:path.join(artifacts,`ui-${file}-mobile.png`),fullPage:true});
  }
  fs.writeFileSync(path.join(artifacts,'ui-accessibility.json'),JSON.stringify({tool:'axe-core',version:require('axe-core/package.json').version,views:auditedViews,violations:accessibility},null,2));if(accessibility.length)throw new Error('Accessibility checks failed; inspect test-artifacts/ui-accessibility.json.');
  if(errors.length)throw new Error(errors.join('\n'));
  console.log('PASS: PIN/setup, all workspace views, project/task forms, search, agent-result fixture, mobile overflow and browser errors.');
 }finally{
  if(browser)await browser.close();const stop=server=>server.exitCode!==null?Promise.resolve():new Promise(resolve=>{server.once('exit',resolve);server.kill();});await Promise.all([stop(first),stop(second)]);
  const marker=path.join(publicDir,'.aicontrol-pin.php');if(fs.existsSync(marker)){const d=JSON.parse(fs.readFileSync(marker,'utf8').split('\n').slice(1).join('\n')).directory;if(d.startsWith('/tmp/aicontrol-pin-'))fs.rmSync(d,{recursive:true,force:true});}
  fs.rmSync(temporary,{recursive:true,force:true});
 }
})().catch(error=>{console.error(error.message);process.exitCode=1});
