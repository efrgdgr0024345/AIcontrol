// A quiet branch browser and a saved, concept-scoped conversation in the map.
(() => {
 'use strict';
 const root=document.querySelector('[data-investigation]'),map=document.querySelector('[data-mindmap]');if(!root||!map)return;
 const types=new Set(['dream_claim','question','model_idea','association','counter','edge_case','reframing']);
 const make=(tag,text,cls)=>{const n=document.createElement(tag);if(text!==undefined)n.textContent=text;if(cls)n.className=cls;return n;};
 const side=map.querySelector('[data-map-inspector]'),content=side.querySelector('[data-map-inspector-content]');
 let snapshot=null,selected='',verified=true,listStamp='',lastQuery='',threadStamp='',lastTrigger=null,pendingDraft=null;
 const drafts=new Map();
 const browser=make('aside',undefined,'map-branch-browser');browser.setAttribute('aria-label','Branches');browser.dataset.branchBrowser='1';
 const heading=make('div',undefined,'map-branch-heading');heading.append(make('h2','Branches'));const count=make('span');count.setAttribute('role','status');heading.append(count);
 const search=make('input',undefined,'form-control');search.type='search';search.placeholder='Find an idea…';search.setAttribute('aria-label','Search branches');
 const list=make('ul',undefined,'map-branch-list');list.dataset.branchList='1';const empty=make('p','Research ideas will appear here.','map-branch-empty');
 browser.append(heading,search,list,empty);map.append(browser);
 const actions=make('div',undefined,'map-concept-actions');const back=make('button','← Branches','btn btn-outline-secondary');back.type='button';back.dataset.branchBack='1';
 const expand=make('button','Expand this pathway','btn btn-primary');expand.type='button';expand.dataset.branchExpand='1';expand.title='Generate new ideas under this pathway';expand.disabled=true;expand.hidden=true;
 const branchToggle=side.querySelector('[data-map-toggle-branch]');
 const chat=make('button',undefined,'btn btn-outline-secondary map-concept-chat-toggle');chat.type='button';chat.dataset.conceptChatToggle='1';chat.setAttribute('aria-label','Chat about this concept');chat.setAttribute('aria-expanded','false');chat.setAttribute('aria-controls','map-concept-conversation');
 const icon=document.createElementNS('http://www.w3.org/2000/svg','svg');icon.setAttribute('viewBox','0 0 24 24');icon.setAttribute('aria-hidden','true');const path=document.createElementNS(icon.namespaceURI,'path');path.setAttribute('d','M5 4h14a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H9l-6 4V6a2 2 0 0 1 2-2ZM7 9h10M7 13h7');icon.append(path);chat.append(icon);
 const currentLabel=make('p','','map-concept-current');actions.append(currentLabel,back,expand,branchToggle,chat);side.insertBefore(actions,content);const problem=side.querySelector('.map-request-error')||make('p','','map-request-error');problem.hidden=true;problem.setAttribute('role','alert');side.insertBefore(problem,content);
 const advanced=make('details',undefined,'map-concept-advanced');advanced.dataset.mapAdvanced='1';advanced.append(make('summary','More options · layers, evidence and priority'));
 for(const child of [...side.children])if(child!==content&&child!==actions&&child!==problem&&!child.classList.contains('map-inspector-close'))advanced.append(child);
 side.append(advanced);
 const conversation=make('section',undefined,'map-concept-conversation');conversation.id='map-concept-conversation';conversation.hidden=true;conversation.setAttribute('aria-label','Selected concept conversation');
 conversation.append(make('h4','Talk about this concept'),make('p','Ask for an explanation, challenge an assumption, or explore an implication. AI discussion is not verified evidence.','map-conversation-note'));
 const thread=make('div',undefined,'map-conversation-thread');thread.setAttribute('role','log');thread.setAttribute('aria-live','polite');
 const form=make('form');form.method='post';form.action=root.dataset.entry||location.pathname;form.dataset.researchAction='research_concept_chat';
 for(const [name,value]of Object.entries({action:'research_concept_chat',csrf:root.querySelector('input[name=csrf]')?.value||'',run_id:root.dataset.runId||'',node_id:''})){const input=make('input');input.type='hidden';input.name=name;input.value=value;form.append(input);}
 const label=make('label','Your question');label.htmlFor='map-concept-question';const question=make('textarea',undefined,'form-control');question.id=label.htmlFor;question.name='question';question.rows=3;question.maxLength=2000;question.required=true;question.placeholder='What does this idea mean for the project?';
 const send=make('button','Ask about this concept','btn btn-primary');send.type='submit';send.disabled=true;const help=make('p','','map-conversation-note');help.setAttribute('role','status');form.append(label,question,send,help);conversation.append(thread,form);side.insertBefore(conversation,advanced);
 function close(){map.dispatchEvent(new Event('research:close-concept'));(lastTrigger?.isConnected?lastTrigger:(list.querySelector(`[data-branch-id="${selected}"]`)||search))?.focus({preventScroll:true});}
 back.addEventListener('click',close);side.querySelector('.map-inspector-close')?.addEventListener('click',()=>list.querySelector(`[data-branch-id="${selected}"]`)?.focus({preventScroll:true}));
 function openChat(){conversation.hidden=!conversation.hidden;chat.setAttribute('aria-expanded',String(!conversation.hidden));if(!conversation.hidden){renderThread();question.focus({preventScroll:true});conversation.scrollIntoView({block:'nearest'});}}
 chat.addEventListener('click',openChat);
 function select(id,trigger){lastTrigger=trigger;map.dispatchEvent(new CustomEvent('research:inspect-concept',{detail:{nodeId:id}}));const title=content.querySelector('h3');if(title){title.tabIndex=-1;title.focus({preventScroll:true});}}
 function renderList(){
  if(!snapshot)return;const all=snapshot.nodes.filter(n=>types.has(n.type));const query=search.value.trim().toLocaleLowerCase();
  const filtered=all.filter(n=>(n.title+' '+n.content).toLocaleLowerCase().includes(query));
  // Old runs may place non-concept records between an idea and its parent.
  const byId=new Map(all.map(n=>[String(n.id),n])),saved=new Map(snapshot.nodes.map(n=>[String(n.id),n])),parents=new Map(),children=new Map();
  for(const n of all){let parent=saved.get(String(n.parent_id));const seen=new Set([String(n.id)]);while(parent&&!seen.has(String(parent.id))&&!byId.has(String(parent.id))){seen.add(String(parent.id));parent=saved.get(String(parent.parent_id));}const id=String(n.id),parentId=parent&&!seen.has(String(parent.id))?String(parent.id):null;parents.set(id,parentId);if(!children.has(parentId))children.set(parentId,[]);children.get(parentId).push(n);}
  const shown=new Set(filtered.map(n=>String(n.id)));if(query)for(const n of filtered){let parent=parents.get(String(n.id));const seen=new Set([String(n.id)]);while(parent&&!seen.has(parent)){seen.add(parent);shown.add(parent);parent=parents.get(parent);}}
  const stamp=JSON.stringify([query,all.map(n=>[n.id,parents.get(String(n.id)),n.title,n.content,n.layer,n.locked_at]),snapshot.requests.filter(r=>r.status==='pending').map(r=>r.path_node_id)]);
  if(stamp!==listStamp){listStamp=stamp;const scroll=query===lastQuery?list.scrollTop:0;lastQuery=query;const focused=list.contains(document.activeElement)?document.activeElement.closest('[data-branch-id]')?.dataset.branchId:null;list.replaceChildren();
   const graph=window.AIcontrolMap.project(snapshot),families=new Map(graph.nodes.map(n=>[String(n.id),n.knowledge_line_id]));
   const placed=new Set(),matches=new Set(filtered.map(n=>String(n.id)));
   // Depth-first DOM order keeps new descendants beside their parent, not at the end.
   const appendTree=start=>{const stack=[{node:start,container:list,depth:0}];while(stack.length){const {node:n,container,depth}=stack.pop(),id=String(n.id);if(!shown.has(id)||placed.has(id))continue;placed.add(id);
    const row=make('li');row.dataset.branchDepth=String(depth);const button=make('button',undefined,'map-branch-item');button.type='button';button.dataset.branchId=id;button.style.setProperty('--branch-colour',window.AIcontrolMap.familyColour(families.get(id)));
    let title=window.AIcontrolMap.sentence(n.title||n.content).replace(/^(association|counter|reframing|edge case|idea|question|claim)\s*:\s*/i,'')||'Untitled idea';if(title.length>110)title=title.slice(0,107).replace(/\s+\S*$/,'')+'…';button.append(make('strong',title));button.title=n.title;button.setAttribute('aria-label',n.title);
    const working=snapshot.requests.some(r=>r.status==='pending'&&Number(r.path_node_id)===Number(n.id));const state=working?'Thinking…':n.locked_at?'Evidence locked':Number(n.layer)===2?'Parked idea':'Unchecked idea';const related=children.get(id)||[];
    button.append(make('small',(query&&!matches.has(id)?'Parent branch · ':'')+state+(related.length?' · '+related.length+' related idea'+(related.length===1?'':'s'):'')));button.addEventListener('click',()=>select(id,button));row.append(button);container.append(row);
    const descendants=related.filter(n=>shown.has(String(n.id))&&!placed.has(String(n.id)));if(descendants.length){const nested=make('ul',undefined,'map-branch-children');if(depth>=4)nested.classList.add('map-branch-deep');row.append(nested);for(let i=descendants.length-1;i>=0;i--)stack.push({node:descendants[i],container:nested,depth:depth+1});}
   }};
   for(const n of all)if(!shown.has(parents.get(String(n.id))))appendTree(n);
   // Retain orphaned or cyclic legacy records without duplicating any idea.
   for(const n of all)if(!placed.has(String(n.id)))appendTree(n);
   list.scrollTop=scroll;if(focused)list.querySelector(`[data-branch-id="${focused}"]`)?.focus({preventScroll:true});
  }
  for(const button of list.querySelectorAll('[data-branch-id]'))button.setAttribute('aria-current',button.dataset.branchId===selected?'true':'false');
  count.textContent=query?`${filtered.length} / ${all.length}`:String(all.length);count.title=query?'Matching ideas; parent branches are shown for context.':'Saved ideas';empty.hidden=filtered.length>0;empty.textContent=query?'No matching branches. Try another word.':'Research ideas will appear here.';
 }
 function renderThread(){
  if(!snapshot)return;const notes=snapshot.nodes.filter(n=>{if(n.type!=='learn'||String(n.parent_id)!==selected)return false;try{return JSON.parse(n.data_json).kind==='concept_chat';}catch{return false;}});
  const stamp=JSON.stringify([selected,notes.map(n=>[n.id,n.status,n.content])]);if(stamp===threadStamp)return;threadStamp=stamp;thread.replaceChildren();
  if(!notes.length)thread.append(make('p','Start a conversation about this idea.'));
  for(const note of notes){const saved=JSON.parse(note.data_json),turn=make('article',undefined,'map-conversation-turn');turn.append(make('strong','You'),make('p',saved.question),make('strong',note.status==='complete'?'AI response':'AI response · '+note.status));
   const prefix='Question: '+saved.question+'\n\n';turn.append(make('p',note.content.startsWith(prefix)?note.content.slice(prefix.length):(note.content||'Waiting for a response…')));thread.append(turn);
   if(note.status==='complete'&&pendingDraft?.id===selected&&pendingDraft.text===saved.question){if(question.value===pendingDraft.text){question.value='';drafts.delete(selected);}pendingDraft=null;}
  }
 }
 function updateControls(){
  if(!snapshot)return;const n=snapshot.nodes.find(n=>String(n.id)===selected),valid=!!n&&types.has(n.type),busy=snapshot.run.busy_since!=null||snapshot.requests.some(r=>r.status==='pending')||root.dataset.requestBusy==='1';
  currentLabel.textContent=n?window.AIcontrolMap.shortNote(n.title):'Concept details';currentLabel.title=n?.title||'';
  const capacity=root.dataset.connected!=='0'&&snapshot.budget?.remaining!==0;
  chat.disabled=!valid;form.elements.node_id.value=valid?selected:'';form.elements.run_id.value=snapshot.run.id;
  const phaseBlocked=['pending','running'].includes(snapshot.run.status)&&!['exploring','verification_review'].includes(snapshot.run.phase);
  send.disabled=!valid||busy||!capacity||!verified||phaseBlocked;
  help.textContent=!valid?'Select a concept first.':!verified?'Reconnect before asking another question.':!capacity?'Check the provider connection and remaining request budget.':busy||phaseBlocked?'Wait for the current research request.':'Uses one model request within the project budget. No web search; no automatic retry.';
 }
 search.addEventListener('input',renderList);question.addEventListener('input',()=>drafts.set(selected,question.value));form.addEventListener('submit',()=>{pendingDraft={id:selected,text:question.value};});
 map.addEventListener('research:concept-selected',event=>{const id=String(event.detail.nodeId);if(id!==selected){if(selected)drafts.set(selected,question.value);selected=id;question.value=drafts.get(id)||'';threadStamp='';}queueMicrotask(()=>{renderList();renderThread();updateControls();});});
 root.addEventListener('research:snapshot',event=>{snapshot=event.detail;verified=true;renderList();renderThread();updateControls();});
 root.addEventListener('research:controls',updateControls);root.addEventListener('research:error',event=>{const message=String(event.detail||'');problem.textContent=message;problem.hidden=!message;if(message)verified=false;updateControls();});root.addEventListener('research:contact',()=>{verified=true;updateControls();});
})();
