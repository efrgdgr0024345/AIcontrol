// Real PHP exploration actions using the synthetic provider fixture; no billed calls.
const {chromium}=require('playwright'),fs=require('fs'),os=require('os'),path=require('path'),net=require('net');const {spawn,spawnSync}=require('child_process');
(async()=>{
 const root=path.resolve(__dirname,'..'),php=process.env.PHP_BINARY||'php',tmp=fs.mkdtempSync(path.join(os.tmpdir(),'aicontrol-auto-map-')),home=path.join(tmp,'private'),env={...process.env,AICONTROL_PRIVATE_DIR:home,AICONTROL_LOCAL_DEV:'1',AICONTROL_PIN_VERIFIED:'1',AICONTROL_OPENAI_API_KEY:'synthetic-only',AICONTROL_RESEARCH_HOURLY_LIMIT:'30'};
 const setup=spawnSync(php,['bin/setup.php','--access-mode=local','--private-dir='+home,'--public-root='+root],{cwd:root,env,encoding:'utf8'});if(setup.status)throw Error(setup.stderr);
 const seed=spawnSync(php,['-r',`require 'src/Vault.php';require 'src/AgentRunner.php';require 'src/Research.php';(new \\AIcontrol\\Research(getenv('AICONTROL_PRIVATE_DIR')))->create('Automatic growth fixture with a very long unbroken title ABCDEFGHIJKLMNOPQRSTUVWXYZABCDEFGHIJKLMNOPQRSTUVWXYZABCDEFGHIJKLMNOPQRSTUVWXYZ','Explore ideas about repair hubs.');$e=new \\AIcontrol\\Investigation(getenv('AICONTROL_PRIVATE_DIR'));$e->createRun(1);`],{cwd:root,env,encoding:'utf8'});if(seed.status)throw Error(seed.stderr);
 const port=await new Promise(resolve=>{const s=net.createServer();s.listen(0,'127.0.0.1',()=>{const p=s.address().port;s.close(()=>resolve(p));});}),url=`http://127.0.0.1:${port}/loader.php`;
 const server=spawn(php,['-S',`127.0.0.1:${port}`,'tests/fixtures/exploration_router.php'],{cwd:root,env,stdio:'ignore'});let browser;
 try{
  browser=await chromium.launch({headless:true,executablePath:process.env.CHROMIUM_PATH||['/usr/bin/google-chrome','/usr/bin/chromium'].find(fs.existsSync),args:['--no-sandbox']});const context=await browser.newContext({viewport:{width:1440,height:1000},reducedMotion:'reduce'}),page=await context.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.goto(url+'?view=research&project=1&run=1&map=1');await page.waitForFunction(()=>document.querySelector('[data-investigation]')?.dataset.phase==='exploring');
  const read=async()=>{const run=await page.locator('[data-investigation]').getAttribute('data-run-id'),csrf=await page.locator('input[name=csrf]').first().inputValue();const r=await page.request.post(url+'?research_api=1',{form:{csrf,run_id:run,action:'research_status'}});return r.json();};
  const header=page.locator('.map-screen-header'),toggle=page.locator('[data-map-run-toggle]'),rounds=page.getByLabel('Automatic growth rounds',{exact:true});
  await header.getByRole('button',{name:'Stop research',exact:true}).waitFor();if(await toggle.count()!==1||await page.locator('.map-controls-tray').isVisible())throw Error('Compact controls are not available with the tray closed');
  const s0=await read(),startNodes=new Set(s0.nodes.map(n=>n.id)),anchor=String(s0.nodes.find(n=>n.type==='dream_claim').id),sent=[],manual=[];
  page.on('request',request=>{const data=new URLSearchParams();for(const [,name,value]of (request.postData()||'').matchAll(/name="([^"]+)"\r\n\r\n([^\r]*)/g))data.append(name,value);if(data.get('action')==='research_explore')sent.push(data);if(data.get('action')==='research_expand_pathway')manual.push(data);});
  const focus=async id=>{await page.locator('[data-map-controls]').click();await page.getByLabel('Find an idea or evidence card',{exact:true}).selectOption(String(id));await page.getByRole('button',{name:'Focus selected star',exact:true}).click();};
  const park=await page.request.post(url+'?research_api=1',{form:{csrf:await page.locator('input[name=csrf]').first().inputValue(),run_id:String(s0.run.id),action:'research_park','node_ids[]':anchor,park_reason:'Older interest to bring back into focus'}});if(!park.ok())throw Error('Could not park the focus fixture');await page.reload();await page.waitForFunction(()=>document.querySelector('[data-investigation]')?.dataset.phase==='exploring');
  await focus(anchor);const label=page.locator(`[data-map-node="${anchor}"] .map-node-title`);if(!await label.evaluate(n=>{const s=getComputedStyle(n),card=n.closest('[data-map-node]');return s.fontSize==='16px'&&s.webkitLineClamp==='none'&&card.dataset.starSize==='1.0000'&&card.dataset.starBrightness==='1.0000'&&card.dataset.labelSuppressed==='false';}))throw Error('Selected path did not regain full size, light and readable text');
  await page.getByRole('button',{name:'Close details',exact:true}).click();await rounds.fill('0');if(!await page.getByRole('button',{name:'Start automatic growth',exact:true}).isDisabled())throw Error('Invalid round count allowed');await rounds.fill('5');await page.getByRole('button',{name:'Start automatic growth',exact:true}).click();
  await page.locator('[data-map-grow-progress]').filter({hasText:'5/5'}).waitFor();await page.getByRole('button',{name:'Start automatic growth',exact:true}).waitFor();await page.waitForFunction(()=>!document.querySelector('[data-map-auto-grow]').disabled);
  const grown=await read(),requests=grown.requests.filter(r=>r.kind==='model_explore');if(requests.length!==5||requests.some(r=>r.status!=='complete')||grown.sources.length||grown.run.phase!=='exploring')throw Error('Five rounds did not produce exactly five model-only requests');
  const added=grown.nodes.filter(n=>!startNodes.has(n.id)&&['model_idea','association','counter','edge_case','reframing'].includes(n.type));if(added.length!==5||added.slice(1).some((n,i)=>Number(n.parent_id)!==Number(added[i].id)))throw Error('Rounds did not grow through the newly-created frontier');
  if(sent.length!==5||sent.some(data=>data.getAll('interest_ids[]').join(',')!==anchor)||Number(added[0].parent_id)!==Number(anchor))throw Error('Auto-play lost its selected interest while following the frontier');
  // Change direction while an earlier request is in flight: only the next dispatch changes.
  const alternate=String(s0.nodes.filter(n=>n.type==='dream_claim').at(-1).id);let releaseSteer,steerHeld=false;const steeringHold=new Promise(resolve=>releaseSteer=resolve);let steerRequests=0;
  const steerRoute=async route=>{if(!(route.request().postData()||'').includes('research_explore'))return route.continue();const response=await route.fetch();if(++steerRequests===1){steerHeld=true;await steeringHold;}await route.fulfill({response});};await page.route('**/loader.php?**',steerRoute);await rounds.fill('2');await page.getByRole('button',{name:'Start automatic growth',exact:true}).click();for(let i=0;i<100&&!steerHeld;i++)await page.waitForTimeout(20);if(!steerHeld)throw Error('Steering round did not dispatch');await focus(alternate);releaseSteer();await page.locator('[data-map-grow-progress]').filter({hasText:'2/2'}).waitFor();await page.waitForFunction(()=>!document.querySelector('[data-map-auto-grow]').disabled);await page.unroute('**/loader.php?**',steerRoute);await page.getByRole('button',{name:'Close details',exact:true}).click();if(sent.at(-1).get('node_ids[]')!==alternate||sent.at(-1).get('interest_ids[]')!==alternate)throw Error('Newly selected path did not steer the next round');
  const audit=async()=>{await page.waitForFunction(()=>document.getAnimations().every(a=>a.playState!=='running'||a.effect.getComputedTiming().iterations===Infinity));await page.evaluate(require('axe-core').source);const v=await page.evaluate(async()=>(await axe.run(document,{runOnly:{type:'tag',values:['wcag2a','wcag2aa','wcag21aa','wcag22aa']}})).violations.map(v=>({id:v.id,nodes:v.nodes.map(n=>n.target)})));if(v.length)throw Error(JSON.stringify(v));};await audit();fs.mkdirSync(path.join(root,'test-artifacts'),{recursive:true});await page.screenshot({path:path.join(root,'test-artifacts/ui-map-auto-grow-desktop.png')});
  // Hold a confirmed first round's response; Stop must cancel the next round and ignore stale UI state.
  let release,held=false,finished;const hold=new Promise(resolve=>release=resolve),fulfilled=new Promise(resolve=>finished=resolve);const route=async route=>{if(!(route.request().postData()||'').includes('research_explore'))return route.continue();const response=await route.fetch();held=true;await hold;await route.fulfill({response});finished();};await page.route('**/loader.php?**',route);
  await rounds.fill('5');await page.getByRole('button',{name:'Start automatic growth',exact:true}).click();await page.waitForFunction(()=>document.querySelector('[data-map-auto-grow]')?.getAttribute('aria-pressed')==='true');for(let i=0;i<100&&!held;i++)await page.waitForTimeout(20);if(!held)throw Error('Round did not dispatch');
  await header.getByRole('button',{name:'Stop research',exact:true}).click();await page.waitForFunction(()=>document.querySelector('[data-investigation]')?.dataset.status==='interrupted');release();await fulfilled;await page.unroute('**/loader.php?**',route);await page.waitForTimeout(800);
  const stopped=await read();
  if(stopped.run.status!=='interrupted'||stopped.requests.filter(r=>r.kind==='model_explore').length!==8)throw Error('Stop dispatched another round or stale response reactivated the map');
  const verifyContinuation=async(selected,before)=>{
    const start=header.getByRole('button',{name:'Continue from selected pathway',exact:true});
    await start.click();
    await page.waitForFunction(()=>document.querySelector('[data-investigation]').dataset.requestBusy==='0');
    const after=await read(),oldIds=new Set(before.nodes.map(n=>n.id));
    const idea=after.nodes.find(n=>!oldIds.has(n.id)&&n.type==='model_idea');
    const request=after.requests.at(-1);
    const phase=after.nodes.find(n=>n.id===request.node_id);
    const context=JSON.parse(phase.data_json);
    if(after.run.id!==before.run.id||after.run.status!=='running'||after.run.phase!=='exploring'||after.requests.length!==before.requests.length+1||request.kind!=='model_explore'||request.status!=='complete'||String(request.path_node_id)!==selected||String(idea?.parent_id)!==selected||String(context.interest_ids[0])!==selected||manual.at(-1)?.get('node_id')!==selected||sent.length!==8||after.sources.length)throw Error('Compact Start lost the selected pathway or dispatched an unexpected request');
    if(!before.nodes.every(n=>after.nodes.some(saved=>saved.id===n.id)))throw Error('Continuing dropped a saved node');
    return after;
  };
  const resumed=await verifyContinuation(alternate,stopped);
  await focus(String(resumed.nodes.at(-1).id));await page.getByRole('button',{name:'Close details',exact:true}).click();
  await verifyContinuation(String(resumed.nodes.at(-1).id),resumed);
  if(manual.length!==2)throw Error('Compact Start did not make one request per click');
  // A manual response that arrives after Stop must not reopen the map.
  let releasePathway,confirmPathway,finishPathway;
  const pathwayHeld=new Promise(resolve=>confirmPathway=resolve);
  const pathwayRelease=new Promise(resolve=>releasePathway=resolve);
  const pathwayDone=new Promise(resolve=>finishPathway=resolve);
  const holdPathway=async route=>{
    if(!(route.request().postData()||'').includes('research_expand_pathway'))return route.continue();
    const response=await route.fetch();confirmPathway();
    await pathwayRelease;await route.fulfill({response});finishPathway();
  };
  await page.route('**/loader.php?**',holdPathway);
  await header.getByRole('button',{name:'Continue from selected pathway',exact:true}).click();
  await Promise.race([pathwayHeld,page.waitForTimeout(5000).then(()=>{throw Error('Manual pathway did not dispatch');})]);
  await header.getByRole('button',{name:'Stop research',exact:true}).click();
  await page.waitForFunction(()=>document.querySelector('[data-investigation]').dataset.status==='interrupted');
  releasePathway();await pathwayDone;await page.unroute('**/loader.php?**',holdPathway);
  await page.waitForFunction(()=>document.querySelector('[data-investigation]').dataset.requestBusy==='0');
  if(await page.locator('[data-investigation]').getAttribute('data-status')!=='interrupted')throw Error('Late manual response reopened a stopped map');
  // Selecting the project root still offers a fresh run after Stop.
  await focus('root');await page.getByRole('button',{name:'Close details',exact:true}).click();
  await toggle.click();await page.waitForFunction(old=>{const r=document.querySelector('[data-investigation]');return r?.dataset.phase==='exploring'&&r.dataset.status==='running'&&r.dataset.runId!==old;},String(grown.run.id));const fresh=await read();if(fresh.run.id===grown.run.id||fresh.requests.filter(r=>r.kind==='model_explore').length)throw Error('Start toggle did not start a fresh, manual run');
  await page.setViewportSize({width:390,height:844});await audit();if(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1))throw Error('Compact map controls overflow mobile');const b=await toggle.boundingBox();if(!b||b.y>70||b.width>40)throw Error('Start/Stop is not a compact top control');await page.screenshot({path:path.join(root,'test-artifacts/ui-map-auto-grow-mobile.png')});
  // Repeated provider output ends a batch after one confirmed call without growing duplicate stars.
  await rounds.fill('1');await page.getByRole('button',{name:'Start automatic growth',exact:true}).click();await page.locator('[data-map-grow-progress]').filter({hasText:'1/1'}).waitFor();await page.waitForFunction(()=>!document.querySelector('[data-map-auto-grow]').disabled);const beforeRepeat=await read(),sentBefore=sent.length;fs.writeFileSync(path.join(home,'repeat-idea-fixture'),'repeat');await rounds.fill('5');await page.getByRole('button',{name:'Start automatic growth',exact:true}).click();await page.waitForFunction(()=>document.querySelector('[data-map-auto-grow]').getAttribute('aria-pressed')==='false');await page.waitForTimeout(350);const afterRepeat=await read();fs.unlinkSync(path.join(home,'repeat-idea-fixture'));if(sent.length!==sentBefore+1||afterRepeat.nodes.filter(n=>n.type==='model_idea').length!==beforeRepeat.nodes.filter(n=>n.type==='model_idea').length||!(await page.locator('[data-map-grow-progress]').getAttribute('title')).includes('no new ideas'))throw Error('Duplicate-only round grew stars or automatically retried');
  // A transport failure ends the sequence, with no automatic retry.
  let attempts=0;await page.route('**/loader.php?**',async route=>{if((route.request().postData()||'').includes('research_explore')){attempts++;return route.fulfill({status:503,contentType:'application/json',body:'{"error":"Synthetic growth outage"}'});}return route.continue();});await page.getByRole('button',{name:'Start automatic growth',exact:true}).click();await page.locator('[data-map-grow-progress]').filter({hasText:'0/5'}).waitFor();await page.waitForFunction(()=>document.querySelector('[data-map-auto-grow]')?.getAttribute('aria-pressed')==='false');await page.waitForTimeout(500);if(attempts!==1)throw Error('Failed automatic request was retried');
  // The header has one message slot even when the project title would overflow it.
  for(const width of [390,1440]){
    await page.setViewportSize({width,height:900});
    const clear=await header.evaluate(el=>{
      const ticker=el.querySelector('.map-idea-sentence'),bounds=ticker.getBoundingClientRect();
      const controls=el.querySelector('.map-quick-controls').getBoundingClientRect();
      const handle=el.querySelector('[data-map-controls]').getBoundingClientRect();
      return bounds.width>0&&bounds.left>=controls.right&&bounds.right<=handle.left&&
        ticker.querySelectorAll('span').length===1&&!el.querySelector('.map-project-label').checkVisibility();
    });
    if(!clear)throw Error('Long project title overlaps the reading queue');
  }

  const validVoiceRun=await read();
  const voiceAnchor=validVoiceRun.nodes.find(n=>n.type==='dream_claim');
  const invalidVoice=await page.request.post(url,{form:{action:'research_voice',run_id:String(validVoiceRun.run.id),
    csrf:await page.locator('input[name=csrf]').first().inputValue(),sdp:'bad'}});
  if(invalidVoice.status()!==422||(await invalidVoice.json()).error.includes('OpenAI'))throw Error('Malformed voice signaling reached the provider');
  await page.evaluate(()=>{
    const wire=window.voiceFixture={sent:[],stopped:false,closed:false};
    const track={addEventListener(){},stop(){wire.stopped=true;}};
    Object.defineProperty(navigator.mediaDevices,'getUserMedia',{configurable:true,
      value:async()=>({getTracks:()=>[track],getAudioTracks:()=>[track]})});
    window.RTCPeerConnection=class extends EventTarget{
      addTrack(){}
      createDataChannel(){
        const channel=wire.channel=new EventTarget();
        channel.send=message=>wire.sent.push(JSON.parse(message));
        return channel;
      }
      async createOffer(){return {type:'offer',sdp:'v=0\r\nfixture-offer'};}
      async setLocalDescription(offer){this.localDescription=offer;}
      async setRemoteDescription(){wire.channel.readyState='open';wire.channel.dispatchEvent(new Event('open'));}
      close(){wire.closed=true;}
    };
  });
  await page.route('**/loader.php',route=>route.request().postData()?.includes('research_voice')
    ? route.fulfill({contentType:'application/sdp',body:'v=0\r\nfixture-answer'}) : route.continue());
  await page.getByRole('button',{name:'Start research voice chat'}).click();
  await page.waitForFunction(()=>window.voiceFixture.sent.some(e=>e.type==='response.create'));
  const opening=await page.evaluate(()=>window.voiceFixture.sent);
  const marker='APP MAP DATA (not spoken by the user): ';
  const mapContext=opening.map(e=>e.item?.content?.[0]?.text).find(value=>value?.startsWith(marker));
  if(!mapContext||!JSON.parse(mapContext.slice(marker.length)).ideas.some(idea=>idea.origin==='model_latent')||
    !opening.some(e=>e.response?.instructions?.includes('one short sentence about a hypothesis you generated')))throw Error('Voice opening lost its concise style or map authorship');
  const deliverVoice=(id,source)=>page.evaluate(({id,source})=>{
    const channel=window.voiceFixture.channel;
    const emit=event=>channel.dispatchEvent(new MessageEvent('message',{data:JSON.stringify(event)}));
    if(source==='user'){
      emit({type:'input_audio_buffer.speech_started'});
      emit({type:'input_audio_buffer.speech_stopped'});
    }
    const response={id:source,status:'completed',metadata:{source},output:[
      {type:'function_call',name:'expand_pathway',call_id:source,arguments:JSON.stringify({node_id:id})},
    ]};
    emit({type:'response.created',response});
    emit({type:'response.done',response});
  },{id:Number(voiceAnchor.id),source});
  const manualBeforeVoice=manual.length;
  await deliverVoice(Number(voiceAnchor.id),'map');
  await deliverVoice(Number(voiceAnchor.id),'user');
  await page.waitForFunction(()=>window.voiceFixture.sent.some(e=>e.item?.type==='function_call_output'&&
    JSON.parse(e.item.output).added===1));
  const afterVoice=await read();
  if(manual.length!==manualBeforeVoice+1||manual.at(-1).get('node_id')!==String(voiceAnchor.id)||
    afterVoice.requests.length!==validVoiceRun.requests.length+1)throw Error('Spoken expansion did not reuse exactly one saved map action');
  await page.evaluate(()=>window.voiceFixture.channel.dispatchEvent(new MessageEvent('message',{data:JSON.stringify({
    type:'response.done',response:{id:'readback',status:'completed',output:[]},
  })})));
  await page.waitForFunction(()=>window.voiceFixture.sent.some(e=>e.response?.instructions?.includes('one of the new ideas')));
  await page.getByRole('button',{name:'End research voice chat'}).click();
  if(!await page.evaluate(()=>window.voiceFixture.stopped&&window.voiceFixture.closed))throw Error('Ending voice left the mic or call open');
  if(errors.length)throw Error(errors.join('\n'));console.log('PASS: compact Start continues selected ideas in active and stopped runs, root Start, selected-path readability, automatic steering, Stop/stale response, no-retry and desktop/mobile axe, single ticker and simulated Realtime pathway control (synthetic research provider)');
 }finally{if(browser)await browser.close();server.kill();await new Promise(resolve=>server.exitCode!==null?resolve():server.once('exit',resolve));fs.rmSync(tmp,{recursive:true,force:true});}
})().catch(e=>{console.error(e.stack||e.message);process.exitCode=1;});
