// Spatial view of the same persisted nodes. No provider or database operations live here.
(() => {
  'use strict';
  const root = document.querySelector('[data-investigation]');
  if (!root) return;
  const Engine=window.AIcontrolMap;if(!Engine)return;
  const map = root.querySelector('[data-mindmap]'), world = map.querySelector('[data-map-world]');
  const viewport = map.querySelector('[data-map-viewport]'), space = map.querySelector('[data-map-space]');
  const inspector = map.querySelector('[data-map-inspector-content]');
  const originLabel=value=>({model_latent:'Model idea',web_evidence:'Web evidence',user:'Your idea',synthesis:'Model assessment'}[value]||'Historical record');
  const provenanceLabel=value=>({provisional_model:'Provisional / unchecked',locked:'Locked for Evidence',grounded:'Grounded within source scope'}[value]||'Unchecked');
  const conceptTypes=['dream_claim','question','model_idea','association','counter','edge_case','reframing'];
  const dimLayers=new Set(),receded=new Set();
  const collapsed = new Set(), selectedSources = new Set();
  let lastCameraPaint=0,framePressure=0;
  let sceneCards=new Map(),edgeElements=[],presentation=new Map(),growthTimer=null,skipClick=false;
  const motion=matchMedia('(prefers-reduced-motion: reduce)');
  const camera=new window.AIcontrolCamera.CameraController({reducedMotion:()=>motion.matches,onChange:(state,extra)=>renderCamera(state,extra)});
  map.camera=camera;
  let positions=new Map(),layoutSignature='',initialCamera=true;const sizes=new Map(),reading=new Engine.ReadingQueue();
  const debug=new URLSearchParams(location.search).get('map_debug')==='1';
  let snapshot, nodes = [], visible = [], selected = 'root', filter = 'all', scale = 1, width = 600, height = 400, count = -1;
  const mapOption=new URLSearchParams(location.search).get('map');
  let view = ['1','stable'].includes(mapOption) || (mapOption!=='0' && matchMedia('(min-width: 900px)').matches) ? 'map' : 'list';
  const el = (tag, text, className) => { const node = document.createElement(tag); if (text !== undefined) node.textContent = text; if (className) node.className = className; return node; };
  const data = node => { try { return JSON.parse(node?.data_json || '{}') || {}; } catch (_) { return {}; } };
  const safe = value => { try { const url = new URL(value); return ['http:', 'https:'].includes(url.protocol) && !url.username && !url.password; } catch (_) { return false; } };
  const claimInputs = () => [...root.querySelectorAll('[data-dream-cards] input')];
  const reviewing = () => snapshot?.run.status === 'running' && snapshot.run.phase === 'dream_review';
  const home = document.createComment('Map location'); map.before(home);
  const header=el('header',undefined,'map-screen-header');
  const projectName=document.querySelector('.research-project-brief h2')?.textContent||'Research map';
  const heading=el('div',undefined,'map-project-label');heading.append(el('strong',projectName),el('span','','map-screen-status'));
  heading.querySelector('span').setAttribute('role','status');
  const ticker=el('button',undefined,'map-idea-sentence');ticker.type='button';ticker.dataset.ideaSentence='1';ticker.setAttribute('aria-label','Pause idea rotation');ticker.setAttribute('aria-pressed','false');
  ticker.append(el('span','Research ideas will appear here as the map develops.'));ticker.querySelector('span').setAttribute('role','status');ticker.querySelector('span').setAttribute('aria-live','polite');
  const handle=el('button','☰','map-controls-handle btn');handle.type='button';handle.dataset.mapControls='1';handle.setAttribute('aria-label','Map controls');handle.setAttribute('aria-expanded','false');handle.setAttribute('aria-controls','map-controls-tray');
  const tray=el('div',undefined,'map-controls-tray');tray.id='map-controls-tray';tray.hidden=true;tray.setAttribute('role','group');tray.setAttribute('aria-label','Map controls and usage');
  const actions=el('div',undefined,'map-screen-actions');
  const screenButton=(label,action,style='btn-outline-secondary')=>{const button=el('button',label,'btn '+style);button.type='button';button.dataset.mapScreenAction=action;return button;};
  actions.append(screenButton('▶ Start research','start','btn-primary'),screenButton('■ Stop research','stop','btn-outline-danger'),screenButton('Continue planned search','step'),screenButton('Finish and freeze','finish','btn-primary'),screenButton('Review gaps once','repair'),screenButton('Project menu','menu'),screenButton('Details','details'),screenButton('List','list'));
  actions.children[0].setAttribute('aria-label','Start research');actions.children[1].setAttribute('aria-label','Stop research');actions.children[1].setAttribute('aria-keyshortcuts','Alt+S');
  tray.append(actions,map.querySelector('.mindmap-toolbar'),map.querySelector('[data-map-outcomes]'));
  const layerControls=el('div',undefined,'map-layer-controls');layerControls.setAttribute('role','group');layerControls.setAttribute('aria-label','Map layers');
  for(const [layer,label] of [[1,'Dim Secondary'],[2,'Dim Tertiary']]){const button=el('button',label,'btn btn-outline-secondary');button.type='button';button.setAttribute('aria-pressed','false');button.addEventListener('click',()=>{dimLayers.has(layer)?dimLayers.delete(layer):dimLayers.add(layer);button.setAttribute('aria-pressed',String(dimLayers.has(layer)));renderCamera(camera.getState());});layerControls.append(button);}
  const activeFocus=el('button','Focus Active','btn btn-outline-secondary');activeFocus.type='button';activeFocus.addEventListener('click',()=>{const node=visible.find(n=>n.type!=='root'&&Number(n.layer||0)===0)||visible[0];if(node){inspect(node.id);world.querySelector(`[data-map-node="${node.id}"] button`)?.focus({preventScroll:true});}});layerControls.append(activeFocus);tray.append(layerControls);
  const navigator=el('div',undefined,'map-star-navigator'),findLabel=el('label','Find an idea or evidence card');findLabel.htmlFor='map-star-target';const targetPicker=el('select',undefined,'form-select');targetPicker.id='map-star-target';const focusButton=el('button','Focus selected star','btn btn-outline-secondary');focusButton.type='button';focusButton.addEventListener('click',()=>inspect(targetPicker.value));navigator.append(findLabel,targetPicker,focusButton);tray.append(navigator);
  const legend=el('p','Brightest: current logic line. Other ideas recede; none are deleted. Drag to pan · wheel or +/− to zoom. Size and light indicate attention, not truth.','map-star-legend');legend.id='map-star-help';map.append(legend);viewport.setAttribute('aria-describedby','map-star-help');

  const live=el('p');live.dataset.liveActivity='1';live.setAttribute('role','status');const clock=el('small');clock.dataset.liveClock='1';clock.setAttribute('aria-live','off');const spend=el('p');spend.dataset.projectSpend='1';
  const history=el('details',undefined,'map-idea-history'),historyTitle=el('summary','Idea history'),historyList=el('ol');history.append(historyTitle,historyList);tray.append(live,clock,spend,map.querySelector('[data-map-evidence-help]'),history);
  header.append(heading,ticker,handle,tray);map.prepend(header);
  const notification=el('button','','map-state-notice');notification.type='button';notification.hidden=true;notification.setAttribute('aria-label','Review research status');notification.addEventListener('click',()=>controls(true));map.append(notification);
  const mapError=el('p','','map-request-error');mapError.hidden=true;mapError.setAttribute('role','alert');tray.prepend(mapError);
  const focusError=el('p','','map-request-error');focusError.hidden=true;focusError.setAttribute('role','alert');map.querySelector('[data-map-focus]').before(focusError);
  let requestError='';
  root.addEventListener('research:error',event=>{requestError=String(event.detail||'');for(const node of [mapError,focusError]){node.textContent=requestError;node.hidden=!requestError;}screenControls();});
  let controlsTimer,hoverTimer,firstIdeas=true;const knownIdeas=new Set();
  function controls(open){clearTimeout(controlsTimer);tray.hidden=!open;handle.setAttribute('aria-expanded',String(open));}
  handle.addEventListener('click',()=>{clearTimeout(hoverTimer);controls(tray.hidden);});handle.addEventListener('pointerenter',event=>{if(event.pointerType==='mouse')hoverTimer=setTimeout(()=>controls(true),180);});
  const leaveControls=()=>{clearTimeout(hoverTimer);controlsTimer=setTimeout(()=>{if(!tray.contains(document.activeElement)&&document.activeElement!==handle)controls(false);},450);};handle.addEventListener('pointerleave',leaveControls);tray.addEventListener('pointerenter',()=>clearTimeout(controlsTimer));tray.addEventListener('pointerleave',leaveControls);
  let finishFade=null,fadeTimer;
  const tickers=[ticker,...document.querySelectorAll('[data-research-now] [data-idea-sentence]')];
  for(const button of tickers)button.addEventListener('click',()=>{const paused=!reading.paused;if(paused&&finishFade){clearTimeout(fadeTimer);finishFade();}reading.paused=paused;for(const item of tickers){item.setAttribute('aria-pressed',String(reading.paused));item.setAttribute('aria-label',reading.paused?'Resume idea rotation':'Pause idea rotation');}});
  setInterval(()=>{
    const next=reading.tick(Date.now(),!document.hidden);if(!next)return;historyTitle.textContent='Idea history · '+reading.items.length+' queued';
    for(const button of tickers)button.classList.add('idea-fading');finishFade=()=>{for(const button of tickers){button.querySelector('span').textContent=next;button.title=next;button.classList.remove('idea-fading');}finishFade=null;};
    if(matchMedia('(prefers-reduced-motion: reduce)').matches)finishFade();else fadeTimer=setTimeout(()=>finishFade?.(),220);
  },250);
  document.addEventListener('visibilitychange',()=>reading.tick(Date.now(),false));
  function enqueueIdeas(){
    const findings=[...(snapshot.findings||[])].filter(f=>f.status!=='collecting');
    const preview=finding=>(finding.provisional?'Provisional idea: ':({'supported':'Evidence suggests: ','weakened':'Reconsidering: ','contradicted':'Counter-evidence: ','open':'Open question: '}[finding.status]||finding.status+': '))+finding.text;
    if(firstIdeas)for(const finding of findings)reading.add(preview(finding));
    for(const node of snapshot.nodes.filter(n=>conceptTypes.includes(n.type))){if(!knownIdeas.has(node.id)&&(!firstIdeas||!findings.length))reading.add((node.type==='question'?'Untested idea: ':'Idea to test: ')+(data(node).claim||node.title));knownIdeas.add(node.id);}
    if(!firstIdeas)for(const finding of findings.reverse())reading.add(preview(finding));
    firstIdeas=false;historyTitle.textContent='Idea history · '+reading.items.length+' queued';historyList.replaceChildren();for(const text of reading.history){const item=el('li',text);const idea=snapshot.nodes.find(n=>conceptTypes.includes(n.type)&&text.includes(Engine.sentence(data(n).claim||n.title)));if(idea){const button=el('button','Focus idea','btn btn-outline-secondary');button.type='button';button.addEventListener('click',()=>{inspect(String(idea.id));world.querySelector(`[data-map-node="${idea.id}"] button`)?.focus({preventScroll:true});});item.append(button);}historyList.append(item);}
  }
  const side=map.querySelector('[data-map-inspector]');side.hidden=true;const closeSide=el('button','×','map-inspector-close btn');closeSide.type='button';closeSide.setAttribute('aria-label','Close details');closeSide.addEventListener('click',()=>{details(false);viewport.focus();});side.prepend(closeSide);
  let underlay=[],previousFocus=null,manualViewport=false;
  function showView(next) {
    view=next;map.hidden=view!=='map';if(view!=='map'){camera.cancel();clearTimeout(growthTimer);}root.querySelector('[data-investigate-list]').hidden=view!=='list';
    root.querySelectorAll('[data-investigate-view]').forEach(button=>button.setAttribute('aria-pressed',String(button.dataset.investigateView===view)));
    if(view==='map'){
      controls(false);details(false);previousFocus=document.activeElement;document.body.append(map);document.body.classList.add('research-map-open');
      map.setAttribute('role','dialog');map.setAttribute('aria-modal','true');map.setAttribute('aria-label','Full screen research map');
      underlay=[...document.body.children].filter(node=>node!==map).map(node=>({node,inert:node.inert,hidden:node.getAttribute('aria-hidden')}));
      underlay.forEach(({node})=>{node.inert=true;node.setAttribute('aria-hidden','true');node.classList.add('map-underlay');});
      viewport.focus({preventScroll:true});requestAnimationFrame(()=>{if(snapshot)draw();if(initialCamera&&visible.length){fit(false);initialCamera=false;}});
    }else{
      underlay.forEach(({node,inert,hidden})=>{node.inert=inert;hidden===null?node.removeAttribute('aria-hidden'):node.setAttribute('aria-hidden',hidden);node.classList.remove('map-underlay');});underlay=[];
      document.body.classList.remove('research-map-open');home.after(map);map.removeAttribute('role');map.removeAttribute('aria-modal');
      const back=previousFocus?.isConnected&&previousFocus!==document.body?previousFocus:root.querySelector('[data-investigate-view=list]');back?.focus({preventScroll:true});
    }
  }
  function screenControls(){
    if(!snapshot)return;
    const active=['pending','running'].includes(snapshot.run.status),pending=snapshot.requests.some(request=>request.status==='pending');
    actions.querySelector('[data-map-screen-action=start]').disabled=active||pending||root.dataset.connected==='0'||(snapshot.budget&&snapshot.budget.remaining<6);
    actions.querySelector('[data-map-screen-action=stop]').disabled=!(active||pending);
    actions.querySelector('[data-map-screen-action=step]').hidden=!active||!!snapshot.run.busy_since||snapshot.run.phase!=='evidence';
    const verificationReview=active&&snapshot.run.phase==='verification_review';
    map.querySelector('[data-map-command=collapse]').textContent=Number(snapshot.run.flow_version)>=2?'Recede all':'Collapse all';map.querySelector('[data-map-command=expand]').textContent=Number(snapshot.run.flow_version)>=2?'Restore all':'Expand all';
    map.querySelector('[data-map-focus]').hidden=Number(snapshot.run.flow_version)>=2;
    for(const action of ['finish','repair']){const button=actions.querySelector(`[data-map-screen-action=${action}]`);button.hidden=!verificationReview;button.disabled=root.querySelector(`[data-research-action=research_${action}] button`).disabled||pending;}
    map.querySelector('[data-map-evidence-help]').textContent=Number(snapshot.run.flow_version)?'Choose a direction for the next remaining planned search. Priority and model budgets still apply.':Number(snapshot.run.rigour_version)===1?'Choose a direction or continue the planned search. The reserved Challenge always seeks counter-evidence, including when redirected.':'Choose a direction or continue the planned search. Each uses a remaining slot; at most two searches per run.';
    heading.querySelector('strong').textContent=`${projectName} · #${snapshot.run.id}`;
    notification.hidden=!requestError&&!['failed','uncertain','incomplete','interrupted'].includes(snapshot.run.status)&&!verificationReview;notification.textContent=requestError?'Research needs attention · open controls':verificationReview?'Verification ready · review':snapshot.run.status+' · open controls';handle.dataset.state=snapshot.run.status;handle.title=active?'Research active · open controls to stop (Alt+S)':'Map controls and usage';
    heading.querySelector('span').textContent=`${reviewing()?'Review claims to continue':verificationReview?'Review verification findings · finish or review gaps':snapshot.run.status+' · '+snapshot.run.phase} · ${snapshot.run.token_input} in / ${snapshot.run.token_output} out tokens${snapshot.run.usage_unknown?' · some usage unknown':''}${snapshot.budget?' · '+snapshot.budget.remaining+'/'+(snapshot.budget.limit||10)+' requests remaining':''}`;
  }
  function details(open){if(open)controls(false);side.hidden=!open;map.classList.toggle('map-details-open',open);actions.querySelector('[data-map-screen-action=details]').setAttribute('aria-expanded',String(open));}
  header.addEventListener('click',event=>{
    const action=event.target.closest('[data-map-screen-action]')?.dataset.mapScreenAction;if(!action)return;
    if(action==='list'){showView('list');return;}
    if(action==='menu'){showView('list');const menu=document.querySelector('[data-project-menu]');menu.open=true;menu.querySelector('summary').focus();menu.scrollIntoView({block:'start'});return;}
    if(action==='details'){details(side.hidden);if(!side.hidden)closeSide.focus();return;}
    const form=root.querySelector(`[data-research-action=research_${action}]`);
    if(action==='start'&&form)form.dataset.researchFromMap='1';
    form?.requestSubmit();
  });
  map.addEventListener('keydown',event=>{
    if(event.altKey&&event.key.toLowerCase()==='s'){event.preventDefault();root.querySelector('[data-research-action=research_stop]')?.requestSubmit();return;}
    if(event.key==='Escape'){event.preventDefault();if(!tray.hidden){controls(false);handle.focus();}else if(!side.hidden){details(false);viewport.focus();}else showView('list');return;}
    if(event.key!=='Tab')return;
    const stops=[...map.querySelectorAll('button:not(:disabled),input:not(:disabled),select:not(:disabled),textarea:not(:disabled),a[href],[tabindex="0"]')].filter(node=>node.getClientRects().length);
    const first=stops[0],last=stops.at(-1);
    if(event.shiftKey&&document.activeElement===first){event.preventDefault();last?.focus({preventScroll:true});}
    else if(!event.shiftKey&&document.activeElement===last){event.preventDefault();first?.focus({preventScroll:true});}
  });
  viewport.addEventListener('wheel',event=>{event.preventDefault();manualViewport=true;const r=viewport.getBoundingClientRect();if(event.shiftKey)camera.panBy(-event.deltaY,-event.deltaX);else camera.zoomBy(Math.exp(-Math.max(-160,Math.min(160,event.deltaY))*.002),{x:event.clientX-r.left,y:event.clientY-r.top});queueGrowth();},{passive:false});
  viewport.addEventListener('scroll',()=>{if(viewport.scrollLeft||viewport.scrollTop){viewport.scrollLeft=0;viewport.scrollTop=0;}},{passive:true});
  let drag=null;
  viewport.addEventListener('pointerdown',event=>{if(event.button!==0||event.target.closest('input,label,a'))return;manualViewport=true;camera.interact();drag={id:event.pointerId,x:event.clientX,y:event.clientY,last:performance.now(),vx:0,vy:0,moved:false};});
  viewport.addEventListener('pointermove',event=>{if(!drag||event.pointerId!==drag.id)return;const now=performance.now(),dx=event.clientX-drag.x,dy=event.clientY-drag.y,dt=Math.max(1,now-drag.last);if(Math.abs(dx)+Math.abs(dy)>3||drag.moved){if(!drag.moved)viewport.setPointerCapture(event.pointerId);drag.moved=true;camera.panBy(dx,dy);drag.vx=dx/dt;drag.vy=dy/dt;}drag.x=event.clientX;drag.y=event.clientY;drag.last=now;});
  const release=event=>{if(!drag||event.pointerId!==drag.id)return;if(drag.moved){skipClick=true;setTimeout(()=>skipClick=false,0);camera.release(drag.vx,drag.vy);}if(viewport.hasPointerCapture(event.pointerId))viewport.releasePointerCapture(event.pointerId);drag=null;queueGrowth();};
  viewport.addEventListener('pointerup',release);viewport.addEventListener('pointercancel',event=>{if(drag?.id===event.pointerId){drag=null;camera.cancel();}});
  viewport.addEventListener('keydown',event=>{if(event.target!==viewport)return;const vectors={ArrowDown:[0,-70],ArrowUp:[0,70],ArrowLeft:[70,0],ArrowRight:[-70,0],PageDown:[0,-250],PageUp:[0,250]};if(vectors[event.key]){event.preventDefault();manualViewport=true;camera.panBy(...vectors[event.key]);queueGrowth();}if(['+','=','-','Home'].includes(event.key)){event.preventDefault();if(event.key==='Home')fit();else camera.zoomBy(event.key==='-'?1/1.2:1.2);}});
  world.addEventListener('focusin',event=>{const card=event.target.closest('[data-map-node]');if(card&&event.target.matches('.map-node-button:focus-visible')&&card.dataset.mapNode!==selected){selected=card.dataset.mapNode;renderCamera(camera.getState());camera.focus(selected);}});
  root.querySelector('[data-mindmap-switch]').hidden = false;
  root.querySelectorAll('[data-investigate-view]').forEach(button => button.addEventListener('click', () => showView(button.dataset.investigateView)));
  function normalize() {
    nodes = [{id:'root', parent_id:null, type:'root', status:snapshot.run.status, title:`Investigation #${snapshot.run.id}`, content:snapshot.run.stop_reason || 'Unchecked prior → review → plan → evidence → findings.'}, ...snapshot.nodes.map(node => ({...node,id:String(node.id),parent_id:node.parent_id === null ? 'root' : String(node.parent_id)}))];
    for (const source of snapshot.sources) {
      if (!nodes.some(node => node.id === String(source.node_id))) nodes.push({id:`source-${source.id}`,parent_id:'root',type:'source',title:source.title,content:source.extract,status:'complete',source_ids:[Number(source.id)]});
    }
    for (const node of nodes) if(node.type==='dream_claim')node.confidence ??= data(node).confidence || 'unknown';
    for(const passage of snapshot.ledger?.passages||[]){const source=snapshot.sources.find(s=>Number(s.id)===Number(passage.source_id));nodes.push({id:`passage-${passage.id}`,parent_id:source?String(source.node_id):'root',type:'passage',status:'complete',title:`Passage #${passage.id} · ${passage.fetch_scope}`,content:passage.exact_text+'\nLocator: '+(passage.locator||'not supplied')+'\nSHA-256: '+passage.content_hash,source_ids:[Number(passage.source_id)]});}
    for(const edge of snapshot.ledger?.edges||[])nodes.push({id:`edge-${edge.id}`,parent_id:String(edge.claim_id),type:'edge',status:'complete',title:edge.relation+' · '+(edge.strength||'unrated')+' model assessment',content:edge.rationale,source_ids:[Number(edge.source_id)],linked_claim_ids:[Number(edge.claim_id)]});
    for(const concept of Engine.project(snapshot).nodes){const saved=nodes.find(n=>n.id===concept.id);if(saved)Object.assign(saved,concept);}
    const ids = new Set(nodes.map(node => node.id));
    for (const node of nodes) if (node.id !== 'root' && !ids.has(node.parent_id)) node.parent_id = 'root';
  }
  function sourceIds(node) {
    return [...new Set([...(node.source_ids || []), ...snapshot.sources.filter(source => String(source.node_id) === node.id).map(source => Number(source.id))])].map(Number);
  }
  function links(node) {
    const ids=new Set((node.linked_claim_ids||[]).map(Number));
    const persisted=new Map(snapshot.nodes.map(item=>[String(item.id),item]));let current=persisted.get(node.id),seen=new Set();
    while(current&&!seen.has(current.id)){seen.add(current.id);if(current.type==='dream_claim')ids.add(Number(current.id));current=persisted.get(String(current.parent_id));}
    for(const edge of snapshot.ledger.edges)if(sourceIds(node).includes(Number(edge.source_id)))ids.add(Number(edge.claim_id));
    return [...ids].filter(id=>persisted.get(String(id))?.type==='dream_claim').sort((a,b)=>a-b);
  }
  function geometry(){camera.setGeometry(positions,Math.max(280,viewport.clientWidth),Math.max(200,viewport.clientHeight));}
  function setScale(value){camera.zoomBy(value/camera.getState().scale);}
  function fit(animate=true){if(!map.hidden){geometry();camera.fitGroup(visible.map(n=>n.id),{animate,fitPadding:.1});}}
  function queueGrowth(){clearTimeout(growthTimer);if(camera.pendingGrowth!==null&&!map.hidden)growthTimer=setTimeout(()=>{camera.flushGrowth();if(camera.pendingGrowth!==null)queueGrowth();},4200);}
  function renderCamera(state,extra={}){
    if(map.hidden||!visible.length)return;
    const paintStarted=performance.now(),gap=paintStarted-lastCameraPaint;lastCameraPaint=paintStarted;if(state.transitioning&&gap>28&&gap<250)framePressure=Math.min(8,framePressure+1);else if(gap<23)framePressure=Math.max(0,framePressure-.25);
    if(selected!==state.focusNodeId&&nodes.some(n=>n.id===state.focusNodeId)){selected=state.focusNodeId;updateInspector();}
    scale=state.scale;const w=camera.viewport.width,h=camera.viewport.height;
    world.style.transform=`translate(${w/2-state.x*scale}px, ${h/2-state.y*scale}px)`;world.querySelector('svg')?.style.setProperty('transform',`scale(${scale})`);
    map.dataset.cameraTransition=state.transitionType||'idle';map.dataset.cameraFocus=state.focusNodeId;
    if(extra.reducedMotion){world.classList.remove('map-short-crossfade');void world.offsetWidth;world.classList.add('map-short-crossfade');}
    map.dataset.detailLevel=visible.length>300||framePressure>=2?'compact':'full';
    presentation=Engine.starPresentation(visible,positions,state,camera.viewport,{receded,dimLayers});
    const labelBoxes=[],labelHidden=new Set();
    const labels=visible.filter(n=>{const light=presentation.get(n.id);return light&&!light.distant;}).sort((a,b)=>(b.id===state.focusNodeId)-(a.id===state.focusNodeId)||(presentation.get(b.id).logic_line?1:0)-(presentation.get(a.id).logic_line?1:0)||presentation.get(b.id).size_factor-presentation.get(a.id).size_factor);
    for(const node of labels){const p=positions.get(node.id),light=presentation.get(node.id),x=(p.x+p.w/2-state.x)*scale+w/2,y=(p.y+p.h/2-state.y)*scale+h/2,f=light.size_factor,box={left:x-115*f,right:x+115*f,top:y+14*f,bottom:y+75*f};if(x < -230||x>w+230||y < -150||y>h+150)continue;if(labelBoxes.some(b=>box.left<b.right+4&&box.right+4>b.left&&box.top<b.bottom+4&&box.bottom+4>b.top))labelHidden.add(node.id);else labelBoxes.push(box);}
    const cards=sceneCards;
    for(const node of visible){const card=cards.get(node.id),light=presentation.get(node.id);if(!card||!light)continue;const p=positions.get(node.id);card.style.transform=`translate(${(p.x+p.w/2)*(scale-1)}px, ${(p.y+p.h/2)*(scale-1)}px)`;const sx=(p.x+p.w/2-state.x)*scale+w/2,sy=(p.y+p.h/2-state.y)*scale+h/2;if(!light.logic_line&&(sx < -230||sx>w+230||sy < -150||sy>h+150))continue;if(reviewing()&&node.type==='dream_claim'){light.size_factor=1;light.brightness_factor=1;light.distant=false;}
      card.querySelector('.map-node-button').setAttribute('aria-pressed',String(node.id===state.focusNodeId));if(card._size===undefined||Math.abs(card._size-light.size_factor)>.015){card._size=light.size_factor;card.style.setProperty('--star-size',Math.max(.28,light.size_factor));card.style.setProperty('--star-glyph-size',light.size_factor/Math.max(.28,light.size_factor));}if(card._light===undefined||Math.abs(card._light-light.brightness_factor)>.025){card._light=light.brightness_factor;card.style.setProperty('--star-light',light.brightness_factor);}if(card._colour!==light.colour){card._colour=light.colour;card.style.setProperty('--star-colour',light.colour);}card.dataset.starSize=light.size_factor.toFixed(4);card.dataset.starBrightness=light.brightness_factor.toFixed(4);card.dataset.logicLine=String(light.logic_line);card.dataset.distant=String(light.distant);card.dataset.labelSuppressed=String(labelHidden.has(node.id));card.style.zIndex=node.id===state.focusNodeId?'3':light.logic_line?'2':'1';
    }
    for(const {path,edge}of edgeElements){const a=presentation.get(edge.from),b=presentation.get(edge.to);if(!a||!b)continue;const active=a.logic_line&&b.logic_line;path.style.stroke=b.colour;path.style.opacity=String(active?.85:Math.max(.06,Math.min(a.brightness_factor,b.brightness_factor)*.55));path.style.strokeWidth=String(active?1.7:1);}
    map.lastCameraPaintMs=performance.now()-paintStarted;
  }
  function pathIds(node) {
    const path = new Set([node.id, ...links(node).map(String)]);
    for (const id of sourceIds(node)) for (const source of snapshot.sources) if (Number(source.id) === id) path.add(String(source.node_id));
    for (const id of [...path]) { let current = nodes.find(item => item.id === id); const seen = new Set(); while (current && !seen.has(current.id)) { seen.add(current.id); path.add(current.id); current = nodes.find(parent => parent.id === current.parent_id); } }
    return path;
  }
  function syncSelection() {
    const inputs = claimInputs(), checked = new Set(inputs.filter(input => input.checked).map(input => input.value));
    for (const card of world.querySelectorAll('[data-map-node]')) {
      const node = nodes.find(item => item.id === card.dataset.mapNode)||visible.find(item=>item.id===card.dataset.mapNode);
      const input = card.querySelector('[data-map-claim]');
      if (input) { input.checked = checked.has(node.id); input.disabled = !reviewing(); }
      card.dataset.excluded = String(Number(snapshot.run.flow_version)<2 && node.type === 'dream_claim' && !(reviewing() ? checked.has(node.id) : Number(node.selected ?? 1) === 1));
    }
    map.querySelector('[data-map-review]').hidden = !reviewing();
    map.querySelector('[data-map-selection-count]').textContent = `${checked.size} of ${inputs.length} claims selected · ${Number(snapshot.run.rigour_version)===1?'up to '+snapshot.run.normal_search_limit+' normal searches + 1 challenge':'up to two searches'}`;
    map.querySelector('[data-map-continue]').disabled = !reviewing() || !checked.size;
  }
  function draw() {
    const projectionStart=performance.now();
    const graph=Engine.project(snapshot,filter),byId=new Map(graph.nodes.map(n=>[n.id,n])),children=new Map(graph.nodes.map(n=>[n.id,[]]));
    for(const node of graph.nodes){const saved=nodes.find(n=>n.id===node.id);if(!saved)nodes.push({...node});else if(!['root','dream_claim','question'].includes(node.type)){saved.source_ids=node.source_ids;saved.linked_claim_ids=node.linked_claim_ids;}}
    for(const node of graph.nodes)if(node.parent_id&&children.has(node.parent_id))children.get(node.parent_id).push(node);
    const active=pathIds(nodes.find(n=>n.id===selected)||nodes[0]);active.add(Engine.owner(snapshot,selected));
    visible=[];receded.clear();const visited=new Set();
    function collect(node,dim=false){if(!node||visited.has(node.id))return;visited.add(node.id);visible.push(node);if(dim)receded.add(node.id);if(!collapsed.has(node.id)||Number(snapshot.run.flow_version)>=2)for(const child of children.get(node.id)||[])collect(child,dim||collapsed.has(node.id));}
    collect(byId.get('root'));
    world.querySelector('svg')?.remove();
    for (const card of [...world.querySelectorAll('[data-map-node]')]) if (!visible.some(node => node.id === card.dataset.mapNode)) card.remove();
    for (const node of visible) {
      let card = [...world.querySelectorAll('[data-map-node]')].find(card => card.dataset.mapNode === node.id);
      if (!card) {
        card = el('article',undefined,'map-node'); card.dataset.mapNode=node.id;
        const button=el('button',undefined,'map-node-button'); button.type='button'; button.append(el('span','','map-node-type'),el('strong','','map-node-title'),el('span','','map-node-status')); card.append(button); world.append(card);
      }
      card.dataset.locked=String(!!node.locked_at);card.dataset.layer=String(node.layer||0);card.dataset.origin=node.origin||'';card.dataset.type=node.type;card.dataset.working=String(!!node.working);card.dataset.activity=node.activity||''; card.dataset.status=node.status; card.dataset.path=String(active.has(node.id));
      const priority=Number(node.path_weight||50);card.dataset.priority=priority>=75?'high':priority<40?'low':'normal';card.dataset.speculative=String(Number(node.speculative||0));
      const button=card.querySelector('button'); button.setAttribute('aria-pressed',String(node.id===selected));
      button.querySelector('.map-node-type').textContent=(node.type==='dream_claim'?'concept':node.type==='root'?'project':node.type.replaceAll('_',' ')) + (collapsed.has(node.id)?(Number(snapshot.run.flow_version)>=2?' · receded':' · collapsed'):'');
      button.querySelector('.map-node-title').textContent=node.title;button.setAttribute('aria-label',`${node.title}. ${node.type.replaceAll('_',' ')}. ${node.status}. ${originLabel(node.origin)}. ${provenanceLabel(node.provenance_tag)}`);
      const source=snapshot.sources.find(source => sourceIds(node).includes(Number(source.id)));
      button.querySelector('.map-node-status').textContent=source?`${source.domain} · ${links(node).map(id=>'claim #'+id).join(', ')}`:`${node.status}${node.confidence?' · '+node.confidence+' prior confidence':''}`;
      if(conceptTypes.includes(node.type))button.querySelector('.map-node-status').textContent+=(node.type==='question'?' · untested':'')+' · priority '+priority;
      if(node.type==='root'&&node.working)button.querySelector('.map-node-status').textContent=({planning:'Planning concepts',evidence:'Researching',discovery:'Discovering concepts',verification:'Checking findings'}[node.activity]||'Working');
      if(conceptTypes.includes(node.type)){button.querySelector('.map-node-status').textContent=(node.working?({planning:'Planning',evidence:'Researching',discovery:'Exploring',verification:'Checking'}[node.activity]||'Working')+' · ':node.verdict?node.verdict+' · ':'')+((node.source_ids||[]).length?node.source_ids.length+' sources · ':'')+'priority '+priority+(Number(node.speculative)?' · untested':'');}
      if(Number(snapshot.run.flow_version)>=2&&conceptTypes.includes(node.type)){button.querySelector('.map-node-status').textContent=`${['Active','Secondary','Tertiary'][Number(node.layer)||0]} · ${originLabel(node.origin)} · ${provenanceLabel(node.provenance_tag)}`;}
      if (node.type==='dream_claim' && !card.querySelector('[data-map-claim]')) {
        const label=el('label',undefined,'map-node-check'),input=el('input');input.type='checkbox';input.dataset.mapClaim=node.id;
        label.append(input,document.createTextNode(`Keep claim #${node.id}`));card.append(label);
      }
      if (source && node.type==='source' && !card.querySelector('[data-map-source]')) {
        const label=el('label',undefined,'map-node-check'),input=el('input');input.type='checkbox';input.dataset.mapSource=source.id;input.checked=selectedSources.has(Number(source.id));
        label.append(input,document.createTextNode(`Select source #${source.id}`));card.append(label);
      }
    }
    syncSelection();updateInspector();
    const pickerStamp=JSON.stringify([...new Map([...Engine.project(snapshot).nodes,...graph.nodes].map(n=>[n.id,n])).values()].map(n=>[n.id,n.title]));if(targetPicker.dataset.stamp!==pickerStamp){const old=targetPicker.value;targetPicker.replaceChildren();for(const node of [...new Map([...Engine.project(snapshot).nodes,...graph.nodes].map(n=>[n.id,n])).values()]){const option=el('option',node.title);option.value=node.id;option.selected=node.id===old;targetPicker.append(option);}targetPicker.dataset.stamp=pickerStamp;}
    const empty=map.querySelector('[data-map-empty]');if(empty){empty.hidden=!graph.empty;empty.textContent=graph.empty_reason||'No matching records stored for this run yet.';}
    if(map.hidden)return false;
    const cards=new Map([...world.querySelectorAll('[data-map-node]')].map(card=>[card.dataset.mapNode,card]));sceneCards=cards;
    for(const node of visible){let size=sizes.get(node.id);if(!size){const card=cards.get(node.id);size={w:210,h:126};sizes.set(node.id,size);}Object.assign(node,size);}
    const visibleIDs=new Set(visible.map(n=>n.id));const linksToDraw=graph.edges.filter(e=>visibleIDs.has(e.from)&&visibleIDs.has(e.to));
    // Layout inputs are structure and viewport only. Usage, stage badges and title updates do not move cards.
    const nextLayout=JSON.stringify([visible.map(n=>n.id).sort(),linksToDraw.map(e=>[e.from,e.to,e.kind]).sort(),viewport.clientWidth,viewport.clientHeight]);
    const layoutRan=nextLayout!==layoutSignature;
    if(layoutRan){
      layoutSignature=nextLayout;
      const layoutStart=performance.now();
      const packed=Engine.layout(visible,linksToDraw,Math.max(280,viewport.clientWidth-12),Math.max(260,viewport.clientHeight-(reviewing()?88:12)),positions,{constellations:true});
      for(const [id,position] of packed.positions)positions.set(id,position);
      width=packed.width;height=packed.height;
      if(debug)console.debug('Research map layout',JSON.stringify({nodes:visible.length,edges:linksToDraw.length,reused:packed.reused,new_count:packed.new_count,layout_ms:Number((performance.now()-layoutStart).toFixed(2))}));
    }
    for(const node of visible){const p=positions.get(node.id);if(!p)continue;Object.assign(node,p);const card=cards.get(node.id);card.style.left=p.x+'px';card.style.top=p.y+'px';card.style.width=p.w+'px';card.style.height=p.h+'px';}
    visible.sort((a,b)=>a.y-b.y||a.x-b.x);world.style.width='1px';world.style.height='1px';geometry();
    const svg=document.createElementNS('http://www.w3.org/2000/svg','svg');svg.setAttribute('width','1');svg.setAttribute('height','1');svg.setAttribute('aria-hidden','true');edgeElements=[];
    for(const edge of linksToDraw){const a=positions.get(edge.from),b=positions.get(edge.to);if(!a||!b)continue;const ax=a.x+a.w/2,ay=a.y+a.h/2,bx=b.x+b.w/2,by=b.y+b.h/2;
      const line=document.createElementNS(svg.namespaceURI,'path');line.setAttribute('d',`M${ax},${ay} Q${(ax+bx)/2-(by-ay)*.05},${(ay+by)/2+(bx-ax)*.05} ${bx},${by}`);line.setAttribute('class','map-edge'+(edge.kind==='shared_source'?' map-edge-shared':''));svg.append(line);edgeElements.push({path:line,edge});}
    world.prepend(svg);renderCamera(camera.getState());
    if(debug)console.debug('Research map projection',JSON.stringify({nodes:graph.nodes.length,edges:graph.edges.length,layout_ran:layoutRan,cached_positions:positions.size,total_ms:Number((performance.now()-projectionStart).toFixed(2))}));
    return layoutRan;
  }
  function updateInspector() {
    const node=nodes.find(node=>node.id===selected)||nodes[0];
    const linked=links(node),sourceIDs=sourceIds(node),sources=snapshot.sources.filter(source=>sourceIDs.includes(Number(source.id)));
    const passages=snapshot.ledger.passages.filter(p=>sourceIDs.includes(Number(p.source_id)));
    const edges=snapshot.ledger.edges.filter(edge=>node.type==='edge'?`edge-${edge.id}`===node.id:linked.includes(Number(edge.claim_id))||sourceIDs.includes(Number(edge.source_id)));
    const stages=snapshot.nodes.filter(n=>['plan_step','search','dead_end','synthesis'].includes(n.type)&&Engine.owner(snapshot,n.id)===node.id);
    // Keep open details and keyboard focus stable on heartbeats, token accounting and pending-request updates.
    const {working,activity,activity_text,status,...savedNode}=node;
    const living=map.querySelector('[data-living-controls]');
    if(living&&living.dataset.selectedNode!==node.id){living.dataset.selectedNode=node.id;for(const option of living.querySelector('[data-living-nodes]').options)option.selected=option.value===String(node.id);living.dispatchEvent(new Event('change'));}
    const stamp=JSON.stringify([savedNode,sources,passages,edges,stages,[...selectedSources].sort()]);
    if(inspector.dataset.stamp!==stamp){
      const opened=new Set([...inspector.querySelectorAll('details[open]')].map(item=>item.querySelector('summary')?.textContent));
      const title=el('h3',node.title),detail=el('p',`${node.type.replaceAll('_',' ')} · ${node.status}`,'map-inspector-status');
      inspector.replaceChildren(title,detail,el('p',node.dead_end_reason||node.content||'No details saved yet.','map-inspector-text'));
      if(conceptTypes.includes(node.type))inspector.append(el('p',`Priority ${node.path_weight||50} · ${node.model_tier||'primary'} model tier. ${node.weight_reason||'Priority is separate from evidence strength.'}${Number(node.speculative)?' Untested idea — may be wrong.':''}`));
      if(Number(snapshot.run.flow_version)>=2)inspector.append(el('p',`${['Active','Secondary','Tertiary'][Number(node.layer)||0]} · ${originLabel(node.origin)} · ${provenanceLabel(node.provenance_tag)}${node.park_reason?' · Park reason: '+node.park_reason:''}`));
      if(stages.length){const history=el('details');history.append(el('summary','Planning, evidence and findings history'));for(const stage of stages)history.append(el('h4',stage.title+' · '+stage.status),el('p',stage.content));inspector.append(history);}
      const usage=el('p');usage.dataset.mapPathUsage='1';inspector.append(usage);
      if(linked.length)inspector.append(el('p',`Linked claims: ${linked.map(id=>'#'+id).join(', ')}`));
      for(const source of sources){
        const card=el('article',undefined,'research-source');card.append(el('h4',source.title));
        if(safe(source.url)){const link=el('a',source.url);link.href=source.url;link.target='_blank';link.rel='noopener noreferrer';card.append(link);}
        for(const passage of passages.filter(p=>Number(p.source_id)===Number(source.id)))card.append(el('h5',`Passage #${passage.id} · ${passage.fetch_scope||'captured text'}`),el('blockquote',passage.exact_text),el('small','Locator: '+(passage.locator||'not supplied')));
        if(!passages.some(p=>Number(p.source_id)===Number(source.id)))card.append(el('p','Citation only: no exact passage stored for this source.'));
        const choice=el('label',undefined,'map-source-choice'),checkbox=el('input');checkbox.type='checkbox';checkbox.dataset.mapSource=source.id;checkbox.checked=selectedSources.has(Number(source.id));choice.append(checkbox,document.createTextNode('Use source #'+source.id+' for Learn'));card.append(choice);
        card.append(el('p',source.extract||''),el('small',source.domain||''));inspector.append(card);
      }
      if(edges.length){const block=el('section',undefined,'map-edge-details');block.append(el('h4','Stored evidence relations'));
        for(const edge of edges)block.append(el('h5',`${edge.relation} · ${edge.strength||'unrated'} · claim #${edge.claim_id}`),el('p',edge.rationale||'No rationale stored.'),el('small',edge.passage_id?'Passage #'+edge.passage_id:'No passage linked; this is not passage-backed evidence.'));
        inspector.append(block);
      }
      const persistedIDs=new Set(snapshot.nodes.map(row=>String(row.id)));
      const listID=persistedIDs.has(node.id)?node.id:sources.find(source=>persistedIDs.has(String(source.node_id)))?.node_id;
      if(listID){const jump=el('button','Jump to List entry','btn btn-outline-secondary');jump.type='button';jump.dataset.mapListNode=String(listID);inspector.append(jump);}
      for(const detail of inspector.querySelectorAll('details'))if(opened.has(detail.querySelector('summary')?.textContent))detail.open=true;
      inspector.dataset.stamp=stamp;
    }
    inspector.querySelector('.map-inspector-status').textContent=`${node.type.replaceAll('_',' ')} · ${node.status}`;
    const pathRequests=snapshot.requests.filter(q=>Number(q.path_node_id)===Number(node.id));
    const usage=inspector.querySelector('[data-map-path-usage]');usage.hidden=!pathRequests.length;
    if(pathRequests.length){const input=pathRequests.reduce((sum,q)=>sum+Number(q.token_input||0),0),output=pathRequests.reduce((sum,q)=>sum+Number(q.token_output||0),0),cost=pathRequests.reduce((sum,q)=>sum+Number(q.estimated_usd||0),0);usage.textContent=`${input.toLocaleString()} input / ${output.toLocaleString()} output tokens · $${cost.toFixed(6)} USD known estimate; unknown calls remain in the usage ledger.`;}
    const mapLedger=map.querySelector('[data-map-ledger]'),checks=snapshot.nodes.filter(n=>n.type==='phase'&&['Verification','Repair'].includes(n.title));
    const ledgerStamp=JSON.stringify([checks,snapshot.run.phase,snapshot.run.status]);
    if(mapLedger.dataset.stamp!==ledgerStamp){
      const opened=new Set([...mapLedger.querySelectorAll('details[open]')].map(item=>item.querySelector('summary')?.textContent));mapLedger.replaceChildren();
      for(const saved of checks){const record=data(saved),block=el('details');block.append(el('summary',saved.title+' findings'));for(const defect of record.defects||[])block.append(el('p',defect.severity+' · '+defect.kind+' · '+defect.description));for(const check of record.calculations||[])block.append(el('p',`Arithmetic ${check.operation}: ${check.arithmetic_correct?'passed':'failed'} on supplied operands; source accuracy remains unverified.`));if(saved.status==='complete'&&!(record.defects||[]).length)block.append(el('p','No defects reported by this grouped model check. This is not independent source verification.'));block.open=opened.has(saved.title+' findings');mapLedger.append(block);}
      if(snapshot.run.phase==='verification_review'&&snapshot.run.status==='running')for(const [action,label] of [['research_finish','Finish and freeze evidence'],['research_repair','Review high-impact gaps once']]){const button=el('button',label,'btn btn-primary');button.type='button';button.dataset.mapReviewAction=action;button.addEventListener('click',()=>root.querySelector(`[data-research-action=${action}]`).requestSubmit());mapLedger.append(button);}
      mapLedger.dataset.stamp=ledgerStamp;
    }
    for(const button of mapLedger.querySelectorAll('[data-map-review-action]'))button.disabled=root.querySelector(`[data-research-action=${button.dataset.mapReviewAction}] button`).disabled;
    const pending=snapshot.requests.some(request=>request.status==='pending'),busy=snapshot.run.busy_since!=null||pending;
    const capacity=root.dataset.connected!=='0'&&(!snapshot.budget||snapshot.budget.remaining>0);
    const rank=map.querySelector('[data-map-rank]'),pathNode=['dream_claim','question'].includes(node.type);
    rank.hidden=!pathNode||Number(node.selected??1)!==1||snapshot.run.status!=='running'||busy||!Number(snapshot.run.flow_version);
    const rankStamp=JSON.stringify([selected,node.path_weight,node.model_tier]);if(rank.dataset.stamp!==rankStamp&&!rank.contains(document.activeElement)){rank.querySelector('[name=node_id]').value=node.id;rank.querySelector('[name=path_weight]').value=node.path_weight||50;rank.querySelector('[name=model_tier]').value=node.model_tier||'primary';rank.dataset.stamp=rankStamp;}
    const persisted=/^\d+$/.test(node.id),rawByID=new Map(snapshot.nodes.map(row=>[String(row.id),row]));
    let focusAncestor=rawByID.get(node.id);const seenAncestors=new Set();
    while(focusAncestor&&focusAncestor.type!=='dream_claim'&&!seenAncestors.has(String(focusAncestor.id))){seenAncestors.add(String(focusAncestor.id));focusAncestor=rawByID.get(String(focusAncestor.parent_id));}
    const focusable=persisted&&['dream_claim','question','dead_end','synthesis'].includes(node.type)&&focusAncestor?.type==='dream_claim'&&Number(focusAncestor.selected??1)===1&&(node.type!=='question'||Number(node.selected??1)===1)&&(node.type!=='synthesis'||(data(node).verdict!=null?data(node).verdict==='open':node.title.toLowerCase().startsWith('open')));
    const plans=snapshot.nodes.filter(n=>n.type==='plan_step'),limit=Number(snapshot.run.flow_version)?6:Number(snapshot.run.rigour_version)===1?3:2;
    const slots=Number(snapshot.run.evidence_index||0)<Math.min(limit,plans.length)&&(!Number(snapshot.run.flow_version)||plans.some(n=>n.status==='pending'));
    const spend=snapshot.usage?.project,cap=Number(snapshot.usage?.cap_usd||0),capped=cap>0&&Number(spend?.estimated_usd||0)+Number(spend?.reserved_usd||0)>=cap;
    let reason='';
    if(!focusable)reason=persisted?'Select an included concept, question or linked dead end to choose a direction.':'Evidence display cards cannot be search targets. Select their owning concept.';
    else if(snapshot.run.status!=='running')reason='Available when a research run is active and its next Evidence search is ready.';
    else if(snapshot.run.phase!=='evidence')reason='Available when the next Evidence search is ready. Current stage: '+snapshot.run.phase+'.';
    else if(busy)reason='A provider request is pending. Wait for it to finish or stop research.';
    else if(root.dataset.connected==='0')reason='Connect a provider before searching this direction.';
    else if(!capacity)reason='The hourly request budget is exhausted. Wait for a slot to become available.';
    else if(capped)reason='The tracked project spend cap has been reached.';
    else if(!slots)reason='No planned Evidence search slots remain. This action does not add slots.';
    const canSearch=!reason,form=map.querySelector('[data-map-focus]'),help=map.querySelector('[data-map-focus-help]');
    form.hidden=Number(snapshot.run.flow_version)>=2;help.id='map-focus-help';help.textContent=reason||'Uses the next remaining planned search. The reserved Challenge still seeks counter-evidence.';
    map.querySelector('[data-map-search]').disabled=!canSearch;map.querySelector('[name=focus_note]').disabled=!canSearch;map.querySelector('[name=focus_node_id]').value=canSearch?node.id:'';map.querySelector('[data-map-search]').setAttribute('aria-describedby',help.id);
    for(const input of map.querySelectorAll('[data-map-source]'))input.checked=selectedSources.has(Number(input.dataset.mapSource));
    const learnIds=(selectedSources.size?[...selectedSources]:sourceIDs).filter(id=>snapshot.sources.some(source=>Number(source.id)===Number(id)));
    const inputs=map.querySelector('[data-map-source-inputs]'),inputStamp=learnIds.join(',');if(inputs.dataset.stamp!==inputStamp){inputs.replaceChildren();for(const id of learnIds){const input=el('input');input.type='hidden';input.name='source_ids[]';input.value=id;inputs.append(input);}inputs.dataset.stamp=inputStamp;}
    map.querySelector('[data-map-learn]').hidden=!learnIds.length;
    const learn=map.querySelector('[data-map-learn-button]');learn.disabled=!learnIds.length||busy||!capacity||capped||['pending','running'].includes(snapshot.run.status)||(Number(snapshot.run.rigour_version)===1&&!Number(snapshot.run.evidence_frozen));
    learn.textContent=selectedSources.size?`Learn from ${selectedSources.size} selected source${selectedSources.size===1?'':'s'}`:'Learn from this source';
    const toggle=map.querySelector('[data-map-toggle-branch]');toggle.disabled=!nodes.some(child=>child.parent_id===node.id);toggle.hidden=toggle.disabled;toggle.textContent=Number(snapshot.run.flow_version)>=2?(collapsed.has(node.id)?'Restore branch emphasis':'Recede branch'):(collapsed.has(node.id)?'Expand branch':'Collapse branch');
    map.querySelector('[data-map-copy]').hidden=!sources.some(source=>safe(source.url));
  }
  function inspect(id) {
    id=String(id);if(!nodes.some(n=>n.id===id))return;
    let node=nodes.find(n=>n.id===id);const seen=new Set();while(node&&!seen.has(node.id)){seen.add(node.id);collapsed.delete(node.id);node=nodes.find(n=>n.id===node.parent_id);}
    if(!visible.some(n=>n.id===id)){filter='all';map.querySelectorAll('[data-map-filter]').forEach(b=>b.setAttribute('aria-pressed',String(b.dataset.mapFilter==='all')));}
    controls(false);selected=id;details(true);draw();geometry();camera.focus(id);targetPicker.value=id;
  }
  map.addEventListener('click',async event=>{
    const command=event.target.closest('[data-map-command]');
    if(command){const key=command.dataset.mapCommand;if(key==='fit'){manualViewport=false;fit();}if(key==='in'){manualViewport=true;setScale(scale*1.2);}if(key==='out'){manualViewport=true;setScale(scale/1.2);}if(key==='reset'){positions.clear();sizes.clear();layoutSignature='';manualViewport=false;draw();fit();}if(key==='collapse'){nodes.filter(node=>node.id!=='root').forEach(node=>collapsed.add(node.id));draw();fit();}if(key==='expand'){collapsed.clear();draw();fit();}return;}
    const chip=event.target.closest('[data-map-filter]');if(chip){filter=chip.dataset.mapFilter;controls(false);details(false);map.querySelectorAll('[data-map-filter]').forEach(button=>button.setAttribute('aria-pressed',String(button===chip)));draw();fit();return;}
    const listJump=event.target.closest('[data-map-list-node]');if(listJump){showView('list');const entry=document.getElementById('research-node-'+listJump.dataset.mapListNode);if(entry){for(let parent=entry;parent;parent=parent.parentElement)if(parent.tagName==='DETAILS')parent.open=true;entry.setAttribute('tabindex','-1');entry.focus();entry.scrollIntoView({block:'center'});}return;}
    const nodeButton=event.target.closest('.map-node-button');if(nodeButton){if(skipClick)return;inspect(nodeButton.closest('[data-map-node]').dataset.mapNode);return;}
    if(event.target.closest('[data-map-toggle-branch]')){collapsed.has(selected)?collapsed.delete(selected):collapsed.add(selected);draw();return;}
    const selection=event.target.closest('[data-map-select]');if(selection){claimInputs().forEach(input=>input.checked=selection.dataset.mapSelect==='all');claimInputs()[0]?.dispatchEvent(new Event('change',{bubbles:true}));syncSelection();return;}
    if(event.target.closest('[data-map-continue]'))root.querySelector('[data-research-action=research_continue]').requestSubmit();
    const outcome=event.target.closest('[data-map-jump]');if(outcome){collapsed.clear();filter='all';inspect(Engine.owner(snapshot,outcome.dataset.mapJump));world.querySelector(`[data-map-node="${outcome.dataset.mapJump}"] button`)?.focus({preventScroll:true});}
    if(event.target.closest('[data-map-copy]')){
      const node=nodes.find(node=>node.id===selected),source=snapshot.sources.find(source=>sourceIds(node).includes(Number(source.id))&&safe(source.url));
      try {await navigator.clipboard.writeText(source.url);map.querySelector('[data-map-announcement]').textContent='Source link copied.';}catch(_){map.querySelector('[data-map-announcement]').textContent='Copy the source URL from the inspector link.';}
    }
  });
  map.addEventListener('change',event=>{
    const input=event.target;if(input.dataset.mapClaim){const original=claimInputs().find(item=>item.value===input.dataset.mapClaim);if(original){original.checked=input.checked;original.dispatchEvent(new Event('change',{bubbles:true}));}}
    if(input.dataset.mapSource){const id=Number(input.dataset.mapSource),inside=side.contains(input);input.checked?selectedSources.add(id):selectedSources.delete(id);updateInspector();if(inside)side.querySelector(`[data-map-source="${id}"]`)?.focus({preventScroll:true});}
  });
  root.addEventListener('change',event=>{if(event.target.matches('[data-dream-cards] input'))syncSelection();});
  world.addEventListener('keydown',event=>{
    const button=event.target.closest('.map-node-button');if(!button)return;
    const node=visible.find(node=>node.id===button.closest('[data-map-node]').dataset.mapNode);let next;
    if(event.key==='ArrowRight'){if(collapsed.has(node.id)){collapsed.delete(node.id);draw();}next=visible.find(child=>child.parent_id===node.id);}
    if(event.key==='ArrowLeft')next=visible.find(parent=>parent.id===node.parent_id);
    if(event.key==='ArrowDown')next=visible[visible.indexOf(node)+1];if(event.key==='ArrowUp')next=visible[visible.indexOf(node)-1];
    if(next){event.preventDefault();inspect(next.id);world.querySelector(`[data-map-node="${next.id}"] button`)?.focus({preventScroll:true});}
  });
  root.addEventListener('research:snapshot',event=>{
    const incoming=event.detail,oldFocus=snapshot?.nodes.find(n=>String(n.id)===selected),newFocus=incoming.nodes?.find(n=>String(n.id)===selected);
    const demoted=oldFocus&&newFocus&&Number(newFocus.layer)>Number(oldFocus.layer);
    const oldIDs=new Set(snapshot?.nodes.map(n=>String(n.id))||[]);const growth=!!snapshot&&incoming.nodes.some(n=>!oldIDs.has(String(n.id))&&conceptTypes.includes(n.type));
    snapshot={...incoming,nodes:incoming.nodes||[],sources:incoming.sources||[],requests:incoming.requests||[],ledger:{...(incoming.ledger||{}),passages:incoming.ledger?.passages||[],edges:incoming.ledger?.edges||[]}};
    normalize();screenControls();enqueueIdeas();
    if(demoted){const here=positions.get(selected);const alternatives=snapshot.nodes.filter(n=>conceptTypes.includes(n.type)&&Number(n.layer||0)===0&&String(n.id)!==selected);alternatives.sort((a,b)=>{const pa=positions.get(String(a.id))||here,pb=positions.get(String(b.id))||here;return here&&pa&&pb?Math.hypot(pa.x-here.x,pa.y-here.y)-Math.hypot(pb.x-here.x,pb.y-here.y):Number(a.id)-Number(b.id);});selected=alternatives.length?String(alternatives[0].id):'root';}
    const handedTo=selected;draw();
    if(!map.hidden&&initialCamera&&visible.length){fit(false);initialCamera=false;}
    else if(demoted&&!map.hidden){geometry();camera.focus(handedTo);}
    else if(growth&&!map.hidden){geometry();camera.growthFor(visible.map(n=>n.id));queueGrowth();}

    if(count!==nodes.length){count=nodes.length;map.querySelector('[data-map-announcement]').textContent=`${snapshot.nodes.length} saved nodes · ${snapshot.sources.length} citation sources. ${snapshot.run.phase==='evidence'?'Choose the direction for your next search.':''}`;}
    map.querySelector('[data-map-evidence-help]').hidden=Number(snapshot.run.flow_version)>=2||snapshot.run.status!=='running'||snapshot.run.phase!=='evidence';
    const strip=map.querySelector('[data-map-outcomes]');strip.replaceChildren();
    for(const verdict of ['supported','weakened','contradicted','open']){
      const latest=new Map();for(const node of nodes.filter(n=>n.type==='synthesis'&&n.parent_id&&nodes.some(c=>c.id===n.parent_id&&c.type==='dream_claim')))latest.set(node.parent_id,node);
      const outcomes=[...latest.values()].filter(node=>data(node).verdict===verdict||node.title.toLowerCase().startsWith(verdict+' ·'));
      const button=el('button',`${verdict}: ${outcomes.length}`,'map-outcome');button.type='button';button.disabled=!outcomes.length;if(outcomes.length)button.dataset.mapJump=outcomes[0].id;strip.append(button);
    }
  });
  root.addEventListener('research:controls',()=>{if(snapshot){updateInspector();screenControls();}});
  root.addEventListener('research:budget',event=>{if(snapshot){snapshot.budget=event.detail;updateInspector();screenControls();}});
  // Initialize after the status request; live processes are never assumed to survive navigation.
  let resizeTimer;new ResizeObserver(()=>{clearTimeout(resizeTimer);resizeTimer=setTimeout(()=>{if(!map.hidden&&snapshot){draw();geometry();renderCamera(camera.getState());}},160);}).observe(viewport);
  motion.addEventListener('change',()=>{if(motion.matches&&camera.transition){const state=camera.getState();camera.cancel();if(state.transitionType==='focus'&&positions.has(state.focusNodeId))camera.focus(state.focusNodeId);else camera.restoreState(state);}renderCamera(camera.getState());});
  document.addEventListener('visibilitychange',()=>{if(document.hidden)camera.cancel();});
  showView(view);
})();
