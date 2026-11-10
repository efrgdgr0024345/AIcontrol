// Spatial view of the same persisted nodes. No provider or database operations live here.
(() => {
  'use strict';
  const root = document.querySelector('[data-investigation]');
  if (!root) return;
  const Engine=window.AIcontrolMap;if(!Engine)return;
  const map = root.querySelector('[data-mindmap]'), world = map.querySelector('[data-map-world]');
  const viewport = map.querySelector('[data-map-viewport]'), space = map.querySelector('[data-map-space]');
  const inspector = map.querySelector('[data-map-inspector-content]');
  const collapsed = new Set(), selectedSources = new Set();
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
    for(const node of snapshot.nodes.filter(n=>['dream_claim','question'].includes(n.type))){if(!knownIdeas.has(node.id)&&(!firstIdeas||!findings.length))reading.add((node.type==='question'?'Untested idea: ':'Idea to test: ')+(data(node).claim||node.title));knownIdeas.add(node.id);}
    if(!firstIdeas)for(const finding of findings.reverse())reading.add(preview(finding));
    firstIdeas=false;historyTitle.textContent='Idea history · '+reading.items.length+' queued';historyList.replaceChildren();for(const text of reading.history)historyList.append(el('li',text));
  }
  const side=map.querySelector('[data-map-inspector]');side.hidden=true;const closeSide=el('button','×','map-inspector-close btn');closeSide.type='button';closeSide.setAttribute('aria-label','Close details');closeSide.addEventListener('click',()=>{details(false);viewport.focus();});side.prepend(closeSide);
  let underlay=[],previousFocus=null,manualViewport=false;
  function showView(next) {
    view=next;map.hidden=view!=='map';root.querySelector('[data-investigate-list]').hidden=view!=='list';
    root.querySelectorAll('[data-investigate-view]').forEach(button=>button.setAttribute('aria-pressed',String(button.dataset.investigateView===view)));
    if(view==='map'){
      controls(false);details(false);previousFocus=document.activeElement;document.body.append(map);document.body.classList.add('research-map-open');
      map.setAttribute('role','dialog');map.setAttribute('aria-modal','true');map.setAttribute('aria-label','Full screen research map');
      underlay=[...document.body.children].filter(node=>node!==map).map(node=>({node,inert:node.inert,hidden:node.getAttribute('aria-hidden')}));
      underlay.forEach(({node})=>{node.inert=true;node.setAttribute('aria-hidden','true');node.classList.add('map-underlay');});
      viewport.focus({preventScroll:true});requestAnimationFrame(()=>{if(snapshot)draw();if(initialCamera){fit();initialCamera=false;}});
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
    const stops=[...map.querySelectorAll('button:not(:disabled),input:not(:disabled),textarea:not(:disabled),a[href],[tabindex="0"]')].filter(node=>node.getClientRects().length);
    const first=stops[0],last=stops.at(-1);
    if(event.shiftKey&&document.activeElement===first){event.preventDefault();last?.focus();}
    else if(!event.shiftKey&&document.activeElement===last){event.preventDefault();first?.focus();}
  });
  viewport.addEventListener('wheel',()=>manualViewport=true,{passive:true});viewport.addEventListener('pointerdown',()=>manualViewport=true);viewport.addEventListener('keydown',event=>{if(['PageDown','PageUp','Home','End','ArrowDown','ArrowUp','ArrowLeft','ArrowRight'].includes(event.key))manualViewport=true;});
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
  function setScale(value) {
    scale = Math.max(.65, Math.min(2.2, value));
    world.style.setProperty('--map-scale',scale); world.style.transform = `scale(${scale})`; space.style.width = `${width * scale}px`; space.style.height = `${height * scale}px`;
  }
  function fit() { if (!map.hidden) { setScale(Math.min(1, (viewport.clientWidth - 32) / width, (viewport.clientHeight - 32) / height)); viewport.scrollTo(0, 0); } }
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
      card.dataset.excluded = String(node.type === 'dream_claim' && !(reviewing() ? checked.has(node.id) : Number(node.selected ?? 1) === 1));
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
    visible=[];const visited=new Set();
    function collect(node){if(!node||visited.has(node.id))return;visited.add(node.id);visible.push(node);if(!collapsed.has(node.id))for(const child of children.get(node.id)||[])collect(child);}
    collect(byId.get('root'));
    world.querySelector('svg')?.remove();
    for (const card of [...world.querySelectorAll('[data-map-node]')]) if (!visible.some(node => node.id === card.dataset.mapNode)) card.remove();
    for (const node of visible) {
      let card = [...world.querySelectorAll('[data-map-node]')].find(card => card.dataset.mapNode === node.id);
      if (!card) {
        card = el('article',undefined,'map-node'); card.dataset.mapNode=node.id;
        const button=el('button',undefined,'map-node-button'); button.type='button'; button.append(el('span','','map-node-type'),el('strong','','map-node-title'),el('span','','map-node-status')); card.append(button); world.append(card);
      }
      card.dataset.type=node.type;card.dataset.working=String(!!node.working);card.dataset.activity=node.activity||''; card.dataset.status=node.status; card.dataset.path=String(active.has(node.id));
      const priority=Number(node.path_weight||50);card.dataset.priority=priority>=75?'high':priority<40?'low':'normal';card.dataset.speculative=String(Number(node.speculative||0));
      const button=card.querySelector('button'); button.setAttribute('aria-pressed',String(node.id===selected));
      button.querySelector('.map-node-type').textContent=(node.type==='dream_claim'?'concept':node.type==='root'?'project':node.type.replaceAll('_',' ')) + (collapsed.has(node.id)?' · collapsed':'');
      button.querySelector('.map-node-title').textContent=node.title;
      const source=snapshot.sources.find(source => sourceIds(node).includes(Number(source.id)));
      button.querySelector('.map-node-status').textContent=source?`${source.domain} · ${links(node).map(id=>'claim #'+id).join(', ')}`:`${node.status}${node.confidence?' · '+node.confidence+' prior confidence':''}`;
      if(['dream_claim','question'].includes(node.type))button.querySelector('.map-node-status').textContent+=(node.type==='question'?' · untested':'')+' · priority '+priority;
      if(node.type==='root'&&node.working)button.querySelector('.map-node-status').textContent=({planning:'Planning concepts',evidence:'Researching',discovery:'Discovering concepts',verification:'Checking findings'}[node.activity]||'Working');
      if(['dream_claim','question'].includes(node.type)){button.querySelector('.map-node-status').textContent=(node.working?({planning:'Planning',evidence:'Researching',discovery:'Exploring',verification:'Checking'}[node.activity]||'Working')+' · ':node.verdict?node.verdict+' · ':'')+((node.source_ids||[]).length?node.source_ids.length+' sources · ':'')+'priority '+priority+(Number(node.speculative)?' · untested':'');}
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
    const empty=map.querySelector('[data-map-empty]');if(empty){empty.hidden=!graph.empty;empty.textContent=graph.empty_reason||'No matching records stored for this run yet.';}
    if(map.hidden)return false;
    const cards=new Map([...world.querySelectorAll('[data-map-node]')].map(card=>[card.dataset.mapNode,card]));
    for(const node of visible){let size=sizes.get(node.id);if(!size){const card=cards.get(node.id);size={w:card.offsetWidth||194,h:card.offsetHeight||110};sizes.set(node.id,size);}Object.assign(node,size);}
    const linksToDraw=graph.edges.filter(e=>visited.has(e.from)&&visited.has(e.to));
    // Layout inputs are structure and viewport only. Usage, stage badges and title updates do not move cards.
    const nextLayout=JSON.stringify([visible.map(n=>n.id).sort(),linksToDraw.map(e=>[e.from,e.to,e.kind]).sort(),viewport.clientWidth,viewport.clientHeight]);
    const layoutRan=nextLayout!==layoutSignature;
    if(layoutRan){
      layoutSignature=nextLayout;
      const layoutStart=performance.now();
      const packed=Engine.layout(visible,linksToDraw,Math.max(280,viewport.clientWidth-12),Math.max(260,viewport.clientHeight-(reviewing()?88:12)),positions);
      for(const [id,position] of packed.positions)positions.set(id,position);
      width=packed.width;height=packed.height;
      if(debug)console.debug('Research map layout',JSON.stringify({nodes:visible.length,edges:linksToDraw.length,reused:packed.reused,new_count:packed.new_count,layout_ms:Number((performance.now()-layoutStart).toFixed(2))}));
    }
    for(const node of visible){const p=positions.get(node.id);if(!p)continue;Object.assign(node,p);const card=cards.get(node.id);card.style.left=p.x+'px';card.style.top=p.y+'px';card.style.width=p.w+'px';card.style.height=p.h+'px';}
    visible.sort((a,b)=>a.y-b.y||a.x-b.x);world.style.width=width+'px';world.style.height=height+'px';
    const svg=document.createElementNS('http://www.w3.org/2000/svg','svg');svg.setAttribute('width',width);svg.setAttribute('height',height);svg.setAttribute('aria-hidden','true');
    for(const edge of linksToDraw){const a=positions.get(edge.from),b=positions.get(edge.to);if(!a||!b)continue;const ax=a.x+a.w/2,ay=a.y+a.h/2,bx=b.x+b.w/2,by=b.y+b.h/2,dx=bx-ax,dy=by-ay;
      const start=Math.min(Math.abs((a.w/2)/(dx||.001)),Math.abs((a.h/2)/(dy||.001))),end=Math.min(Math.abs((b.w/2)/(dx||.001)),Math.abs((b.h/2)/(dy||.001)));
      const line=document.createElementNS(svg.namespaceURI,'path');line.setAttribute('d',`M${ax+dx*start},${ay+dy*start} Q${(ax+bx)/2-dy*.04},${(ay+by)/2+dx*.04} ${bx-dx*end},${by-dy*end}`);line.style.strokeWidth=String(Math.max(1,Math.min(3,edge.weight/30)));line.setAttribute('class','map-edge'+(active.has(edge.from)&&active.has(edge.to)?' map-edge-active':'')+(edge.kind==='shared_source'?' map-edge-shared':''));svg.append(line);}
    world.prepend(svg);setScale(scale);
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
    const stamp=JSON.stringify([savedNode,sources,passages,edges,stages,[...selectedSources].sort()]);
    if(inspector.dataset.stamp!==stamp){
      const opened=new Set([...inspector.querySelectorAll('details[open]')].map(item=>item.querySelector('summary')?.textContent));
      const title=el('h3',node.title),detail=el('p',`${node.type.replaceAll('_',' ')} · ${node.status}`,'map-inspector-status');
      inspector.replaceChildren(title,detail,el('p',node.dead_end_reason||node.content||'No details saved yet.','map-inspector-text'));
      if(['dream_claim','question'].includes(node.type))inspector.append(el('p',`Priority ${node.path_weight||50} · ${node.model_tier||'primary'} model tier. ${node.weight_reason||'Priority is separate from evidence strength.'}${Number(node.speculative)?' Untested idea — may be wrong.':''}`));
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
    form.hidden=false;help.id='map-focus-help';help.textContent=reason||'Uses the next remaining planned search. The reserved Challenge still seeks counter-evidence.';
    map.querySelector('[data-map-search]').disabled=!canSearch;map.querySelector('[name=focus_note]').disabled=!canSearch;map.querySelector('[name=focus_node_id]').value=canSearch?node.id:'';map.querySelector('[data-map-search]').setAttribute('aria-describedby',help.id);
    for(const input of map.querySelectorAll('[data-map-source]'))input.checked=selectedSources.has(Number(input.dataset.mapSource));
    const learnIds=(selectedSources.size?[...selectedSources]:sourceIDs).filter(id=>snapshot.sources.some(source=>Number(source.id)===Number(id)));
    const inputs=map.querySelector('[data-map-source-inputs]'),inputStamp=learnIds.join(',');if(inputs.dataset.stamp!==inputStamp){inputs.replaceChildren();for(const id of learnIds){const input=el('input');input.type='hidden';input.name='source_ids[]';input.value=id;inputs.append(input);}inputs.dataset.stamp=inputStamp;}
    map.querySelector('[data-map-learn]').hidden=!learnIds.length;
    const learn=map.querySelector('[data-map-learn-button]');learn.disabled=!learnIds.length||busy||!capacity||capped||['pending','running'].includes(snapshot.run.status)||(Number(snapshot.run.rigour_version)===1&&!Number(snapshot.run.evidence_frozen));
    learn.textContent=selectedSources.size?`Learn from ${selectedSources.size} selected source${selectedSources.size===1?'':'s'}`:'Learn from this source';
    const toggle=map.querySelector('[data-map-toggle-branch]');toggle.disabled=!nodes.some(child=>child.parent_id===node.id);toggle.hidden=toggle.disabled;toggle.textContent=collapsed.has(node.id)?'Expand branch':'Collapse branch';
    map.querySelector('[data-map-copy]').hidden=!sources.some(source=>safe(source.url));
  }
  function inspect(id) {
    controls(false);selected=id;details(true);draw();
    const card=[...world.querySelectorAll('[data-map-node]')].find(card=>card.dataset.mapNode===id);
    if(card&&!map.hidden)card.scrollIntoView({block:'nearest',inline:'nearest'});
  }
  map.addEventListener('click',async event=>{
    const command=event.target.closest('[data-map-command]');
    if(command){const key=command.dataset.mapCommand;if(key==='fit'){manualViewport=false;fit();}if(key==='in'){manualViewport=true;setScale(scale*1.2);}if(key==='out'){manualViewport=true;setScale(scale/1.2);}if(key==='reset'){positions.clear();sizes.clear();layoutSignature='';manualViewport=false;draw();fit();}if(key==='collapse'){nodes.filter(node=>node.id!=='root').forEach(node=>collapsed.add(node.id));draw();fit();}if(key==='expand'){collapsed.clear();draw();fit();}return;}
    const chip=event.target.closest('[data-map-filter]');if(chip){filter=chip.dataset.mapFilter;controls(false);details(false);map.querySelectorAll('[data-map-filter]').forEach(button=>button.setAttribute('aria-pressed',String(button===chip)));draw();fit();return;}
    const listJump=event.target.closest('[data-map-list-node]');if(listJump){showView('list');const entry=document.getElementById('research-node-'+listJump.dataset.mapListNode);if(entry){for(let parent=entry;parent;parent=parent.parentElement)if(parent.tagName==='DETAILS')parent.open=true;entry.setAttribute('tabindex','-1');entry.focus();entry.scrollIntoView({block:'center'});}return;}
    const nodeButton=event.target.closest('.map-node-button');if(nodeButton){inspect(nodeButton.closest('[data-map-node]').dataset.mapNode);return;}
    if(event.target.closest('[data-map-toggle-branch]')){collapsed.has(selected)?collapsed.delete(selected):collapsed.add(selected);draw();return;}
    const selection=event.target.closest('[data-map-select]');if(selection){claimInputs().forEach(input=>input.checked=selection.dataset.mapSelect==='all');claimInputs()[0]?.dispatchEvent(new Event('change',{bubbles:true}));syncSelection();return;}
    if(event.target.closest('[data-map-continue]'))root.querySelector('[data-research-action=research_continue]').requestSubmit();
    const outcome=event.target.closest('[data-map-jump]');if(outcome){collapsed.clear();filter='all';inspect(Engine.owner(snapshot,outcome.dataset.mapJump));world.querySelector(`[data-map-node="${outcome.dataset.mapJump}"] button`)?.focus();}
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
    if(next){event.preventDefault();inspect(next.id);world.querySelector(`[data-map-node="${next.id}"] button`)?.focus();}
  });
  root.addEventListener('research:snapshot',event=>{
    const incoming=event.detail;
    snapshot={...incoming,nodes:incoming.nodes||[],sources:incoming.sources||[],requests:incoming.requests||[],ledger:{...(incoming.ledger||{}),passages:incoming.ledger?.passages||[],edges:incoming.ledger?.edges||[]}};
    normalize();screenControls();enqueueIdeas();
    const laidOut=draw();
    if(laidOut&&!manualViewport)requestAnimationFrame(()=>{fit();initialCamera=false;});
    if(count!==nodes.length){count=nodes.length;map.querySelector('[data-map-announcement]').textContent=`${snapshot.nodes.length} saved nodes · ${snapshot.sources.length} citation sources. ${snapshot.run.phase==='evidence'?'Choose the direction for your next search.':''}`;}
    map.querySelector('[data-map-evidence-help]').hidden=snapshot.run.status!=='running'||snapshot.run.phase!=='evidence';
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
  let resizeTimer;new ResizeObserver(()=>{clearTimeout(resizeTimer);resizeTimer=setTimeout(()=>{if(!map.hidden&&snapshot){draw();if(!manualViewport)fit();}},160);}).observe(viewport);
  showView(view);
})();
