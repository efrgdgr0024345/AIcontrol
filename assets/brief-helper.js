// Coalesced writing help: finish dispatched requests, retain alternatives, never auto-retry failures.
(() => {
 'use strict';
 const root=document.querySelector('[data-brief-create]');if(!root)return;
 const form=root.querySelector('[data-create-research]'),draft=form.elements.brief,title=form.elements.name;
 const enabled=root.querySelector('[data-brief-enabled]'),status=root.querySelector('[data-brief-status]'),stream=root.querySelector('[data-brief-stream]'),cards=root.querySelector('[data-brief-suggestions]'),allowance=root.querySelector('[data-brief-allowance]');
 const refresh=root.querySelector('[data-brief-refresh]'),undo=root.querySelector('[data-brief-undo]'),connected=root.dataset.connected==='1';
 const TYPE_DELAY=2000,ACTIVITY_DELAY=1000,ACTIVITY_GAP=8000;
 let timer,pending=null,inFlight=false,controller,revision=0,submitting=false,submitQueued=false,paused=false,previous='',lastStarted=0,lastRequested='',pointer=null;
 const replacements=new Set(),bytes=text=>new TextEncoder().encode(text).length;
 root.querySelector('[data-brief-live]').hidden=false;root.querySelector('[data-starter-hint]').hidden=false;
 if(matchMedia('(max-width:760px)').matches)root.querySelector('[data-brief-tips]').open=false;
 function showAllowance(value){if(!allowance||!value)return;allowance.textContent=`${value.requests} / ${value.limit} writing-help requests in the last 60 minutes (workspace-wide). $${Number(value.estimated_usd).toFixed(6)} USD estimated usage; $${Number(value.reserved_usd).toFixed(6)} reserved for unverified usage. Limit: $${Number(value.cap_usd).toFixed(2)} USD.`+(value.next_expiry_seconds?` Oldest request leaves the window in ${Math.ceil(value.next_expiry_seconds/60)} min.`:' No requests in this window.');}
 function quality(){
  draft.setCustomValidity(bytes(draft.value)>4000?'Keep your saved brief within 4,000 bytes.':'');title.setCustomValidity(bytes(title.value)>160?'Keep your working title within 160 bytes.':'');
  const text=draft.value.trim(),checks={question:text.length>=25&&(/[?？]/.test(text)||/^(is|are|how|what|which|can|does|do|compare|should|will|why)\b/i.test(text)),context:/\b(20\d{2}|current|recent|today|year|month|household|business|people|audience|patient|student|organisation|organization|city|region|perth|australia|local|in\s+[A-Z][a-z]+)\b/i.test(text),evidence:/\b(evidence|data|source|measure|change|settle|falsif|disprov|independent|compare|contradict)\w*/i.test(text),focus:text.length>=40&&!/\b(everything|anything|all\s+about|tell\s+me\s+about)\b/i.test(text)};
  for(const [key,met]of Object.entries(checks)){const item=root.querySelector(`[data-quality=${key}]`);item.dataset.met=String(met);item.setAttribute('aria-label',`${item.textContent}: ${met?'included in draft':'consider adding'}`);}
  root.querySelector('[data-brief-count]').textContent=`${bytes(draft.value).toLocaleString()} / 4,000 bytes · suggestions up to 2,000 bytes`;
 }
 function valid(){return !submitting&&!document.hidden&&connected&&draft.value.trim().length>=16&&bytes(draft.value)<=2000&&bytes(title.value)<=160;}
 function cancelPending(){clearTimeout(timer);pending=null;}
 function edited(){revision++;stream.hidden=true;stream.textContent='';quality();}
 function queue(kind,delay,explicit=false,force=false){
  if(!valid()){if(!connected)status.textContent='Connect OpenAI in Admin for writing help. You can create your project now.';else if(draft.value.trim().length<16)status.textContent='Type at least 16 characters for suggestions.';else status.textContent='You can save this draft. Writing help accepts a brief up to 2,000 bytes and a title up to 160 bytes.';return;}
  if(!explicit&&(!enabled.checked||paused))return;
  if(pending?.explicit&&!explicit)return;
  if(kind!=='refresh'&&replacements.size)kind='replace';
  const due=Date.now()+delay;pending={kind,explicit,force,due:explicit?due:Math.max(due,lastStarted+ACTIVITY_GAP)};
  clearTimeout(timer);if(!inFlight)timer=setTimeout(pump,Math.max(0,pending.due-Date.now()));
  if(!inFlight)status.textContent=kind==='replace'?'Preparing one replacement; your other alternatives stay available.':'Preparing suggestions after a short pause…';
 }
 function put(text,append,card){previous=draft.value;draft.value=append?(draft.value.trim()+'\n\n'+text).trim():text;edited();undo.hidden=false;draft.focus();
  if(card){card.querySelectorAll('button').forEach(button=>button.disabled=true);replacements.add(card);card.querySelector('[data-alternative-state]').textContent='Applied to your brief. Waiting for one replacement.';queue('replace',ACTIVITY_DELAY,true,true);}
 }
 function cardFor(text,index){const card=document.createElement('article');card.className='brief-suggestion';card.dataset.alternative=String(index);card.dataset.text=text;const p=document.createElement('p');p.textContent=text;card.append(p);
  const actions=document.createElement('div');actions.className='brief-suggestion-actions';for(const [label,append]of [['Use this',false],['Append',true]]){const button=document.createElement('button');button.type='button';button.className='btn btn-sm btn-outline-primary';button.textContent=label;button.setAttribute('aria-label',`${label} suggestion ${index+1}`);button.addEventListener('click',()=>put(card.dataset.text,append,card));actions.append(button);}card.append(actions);const state=document.createElement('small');state.dataset.alternativeState='';state.setAttribute('role','status');card.append(state);return card;
 }
 function renderSet(texts){replacements.clear();cards.replaceChildren(...texts.map((text,index)=>cardFor(text,index)));}
 function renderReplacement(card,text){if(!card.isConnected)return;const index=Number(card.dataset.alternative);card.replaceWith(cardFor(text,index));replacements.delete(card);}
 async function pump(){
  clearTimeout(timer);if(inFlight||!pending)return;if(Date.now()<pending.due){timer=setTimeout(pump,pending.due-Date.now());return;}
  const job=pending;pending=null;if(!valid()||(!job.explicit&&(!enabled.checked||paused)))return;
  for(const card of replacements)if(!card.isConnected)replacements.delete(card);
  const target=job.kind==='replace'?[...replacements][0]:null,count=target?1:3;
  const signature=title.value+'\0'+draft.value;if(!job.force&&signature===lastRequested)return;
  const current=revision,avoid=[...cards.querySelectorAll('[data-alternative]')].map(c=>c.dataset.text).slice(0,4);
  inFlight=true;lastStarted=Date.now();lastRequested=signature;controller=new AbortController();refresh.disabled=true;status.textContent=count===1?'Writing one new alternative…':'Thinking of focused alternatives…';
  const payload=new FormData();payload.set('action','research_brief_suggest');payload.set('csrf',form.elements.csrf.value);payload.set('draft',draft.value);payload.set('name',title.value);payload.set('count',String(count));avoid.forEach(text=>payload.append('avoid[]',text));
  let done=false;
  try{
   const response=await fetch(new URL(form.getAttribute('action'),location.href),{method:'POST',body:payload,credentials:'same-origin',signal:controller.signal,cache:'no-store',headers:{Accept:'text/event-stream'}});
   if(!response.ok||!(response.headers.get('content-type')||'').includes('text/event-stream')){let message='Writing help is unavailable. Your draft can still be saved.';if((response.headers.get('content-type')||'').includes('application/json')){const data=await response.json();showAllowance(data.allowance);if(typeof data.error==='string')message=data.error;}throw Error(message);}
   if(!response.body)throw Error('Streaming is unavailable. Your draft is unchanged.');
   const reader=response.body.getReader(),decoder=new TextDecoder();let buffer='',output='';
   function event(block){const lines=block.split('\n'),type=lines.find(l=>l.startsWith('event:'))?.slice(6).trim(),data=JSON.parse(lines.filter(l=>l.startsWith('data:')).map(l=>l.slice(5).trim()).join('\n')||'{}');
    if(type==='error'){showAllowance(data.allowance);throw Error(data.error||'Writing help stopped.');}
    if(type==='delta'&&typeof data.text==='string'){output+=data.text;if(output.length>12000)throw Error('Suggestion output was too long.');if(current===revision){stream.hidden=false;stream.textContent=output;}}
    if(type==='done'){showAllowance(data.allowance);if(!Array.isArray(data.suggestions)||data.suggestions.length<(count===1?1:2))throw Error('No usable alternatives returned.');done=true;
     if(current!==revision){if(target){replacements.delete(target);target.querySelectorAll('button').forEach(button=>button.disabled=false);target.querySelector('[data-alternative-state]').textContent='The brief changed; this older replacement was discarded.';}return;}
     if(target){const fresh=data.suggestions.find(text=>typeof text==='string'&&!avoid.includes(text));if(!fresh)throw Error('No new replacement returned. The other alternatives are retained.');renderReplacement(target,fresh);}else renderSet(data.suggestions);
     stream.hidden=true;status.textContent=`Suggestions ready · ${data.remaining} requests remaining this hour. Choose one or keep your own brief.`;
    }
   }
   while(true){const chunk=await reader.read();buffer+=decoder.decode(chunk.value||new Uint8Array(),{stream:!chunk.done});buffer=buffer.replace(/\r\n/g,'\n');if(buffer.length>24000)throw Error('Suggestion stream exceeded its limit.');let i;while((i=buffer.indexOf('\n\n'))!==-1){event(buffer.slice(0,i));buffer=buffer.slice(i+2);}if(chunk.done)break;}
   if(!done)throw Error('The suggestion stream ended early. It will not be retried automatically.');
  }catch(error){if(error.name!=='AbortError'&&!submitting){paused=true;cancelPending();replacements.clear();cards.querySelectorAll('button').forEach(button=>button.disabled=false);stream.hidden=true;status.textContent=error.message+' Automatic suggestions paused. Use Refresh suggestions when ready; your draft and alternatives are retained.';}}
  finally{inFlight=false;controller=null;refresh.disabled=!connected;if(submitQueued){submitQueued=false;submitting=false;form.requestSubmit();return;}if(pending&&!submitting)timer=setTimeout(pump,Math.max(0,pending.due-Date.now()));else if(replacements.size&&!paused&&!submitting)queue('replace',ACTIVITY_DELAY,true,true);}
 }
 root.querySelectorAll('[data-brief-starter]').forEach(button=>{button.hidden=false;button.addEventListener('click',()=>{put(button.dataset.briefStarter,false,null);queue('typing',TYPE_DELAY);});});
 undo.addEventListener('click',()=>{const text=previous;previous=draft.value;draft.value=text;edited();cancelPending();replacements.clear();cards.querySelectorAll('button').forEach(button=>button.disabled=false);status.textContent='Last change undone.';draft.focus();});
 for(const input of [draft,title])input.addEventListener('input',()=>{edited();queue('typing',TYPE_DELAY);});
 enabled.addEventListener('change',()=>{paused=false;cancelPending();if(enabled.checked)queue('typing',TYPE_DELAY);else status.textContent='Automatic suggestions are off. Refresh suggestions still requests a full set.';});
 refresh.addEventListener('click',()=>{paused=false;replacements.clear();queue('refresh',0,true,true);});
 document.addEventListener('pointermove',event=>{if(!event.isTrusted)return;if(pointer&&Math.hypot(event.clientX-pointer.x,event.clientY-pointer.y)<8)return;pointer={x:event.clientX,y:event.clientY};queue('activity',ACTIVITY_DELAY,false,true);},{passive:true});
 const scrollIntent=event=>{if(!event.isTrusted)return;queue('activity',ACTIVITY_DELAY,false,true);};
 document.addEventListener('wheel',scrollIntent,{passive:true});document.addEventListener('touchmove',scrollIntent,{passive:true});
 document.addEventListener('keydown',event=>{if(['PageDown','PageUp','Home','End','ArrowDown','ArrowUp',' '].includes(event.key)&&!event.target.closest('input,textarea,select,button'))scrollIntent(event);});
 document.addEventListener('visibilitychange',()=>{if(document.hidden)cancelPending();});
 form.addEventListener('submit',event=>{if(inFlight){event.preventDefault();submitQueued=true;status.textContent='Finishing the current writing-help request, then saving and opening your research map…';}submitting=true;revision++;cancelPending();root.querySelector('[data-create-submit]').disabled=true;});
 window.addEventListener('pagehide',()=>{submitting=true;cancelPending();controller?.abort();});quality();refresh.disabled=!connected;if(!connected)status.textContent='Connect OpenAI in Admin for writing help. You can create your project now.';
})();
