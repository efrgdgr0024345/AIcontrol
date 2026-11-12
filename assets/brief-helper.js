// Progressive writing help. The ordinary create form remains the source of truth.
(() => {
  'use strict';
  const root=document.querySelector('[data-brief-create]'); if(!root)return;
  const form=root.querySelector('[data-create-research]'),draft=form.elements.brief,title=form.elements.name;
  const live=root.querySelector('[data-brief-live]'),enabled=root.querySelector('[data-brief-enabled]');
  const status=root.querySelector('[data-brief-status]'),stream=root.querySelector('[data-brief-stream]'),cards=root.querySelector('[data-brief-suggestions]');
  const refresh=root.querySelector('[data-brief-refresh]'),undo=root.querySelector('[data-brief-undo]');
  const connected=root.dataset.connected==='1';let timer,controller,generation=0,submitting=false,previous='',lastRequested='';
  live.hidden=false;root.querySelector('[data-starter-hint]').hidden=false;
  if(matchMedia('(max-width:760px)').matches)root.querySelector('[data-brief-tips]').open=false;
  const bytes=text=>new TextEncoder().encode(text).length;
  function quality() {
    draft.setCustomValidity(bytes(draft.value)>4000?'Keep your saved brief within 4,000 bytes.':'');title.setCustomValidity(bytes(title.value)>160?'Keep your working title within 160 bytes.':'');
    const text=draft.value.trim();const checks={question:text.length>=25&&(/[?？]/.test(text)||/^(is|are|how|what|which|can|does|do|compare|should|will|why)\b/i.test(text)),
      context:/\b(20\d{2}|current|recent|today|year|month|household|business|people|audience|patient|student|organisation|organization|city|region|perth|australia|local|in\s+[A-Z][a-z]+)\b/i.test(text),
      evidence:/\b(evidence|data|source|measure|change|settle|falsif|disprov|independent|compare|contradict)\w*/i.test(text),
      focus:text.length>=40&&!/\b(everything|anything|all\s+about|tell\s+me\s+about)\b/i.test(text)};
    for(const [key,met]of Object.entries(checks)){const item=root.querySelector(`[data-quality=${key}]`);item.dataset.met=String(met);item.setAttribute('aria-label',`${item.textContent}: ${met?'included in draft':'consider adding'}`);}
    root.querySelector('[data-brief-count]').textContent=`${bytes(draft.value).toLocaleString()} / 4,000 bytes · live suggestions up to 2,000 bytes`;
  }
  function invalidate(){refresh.disabled=!connected||!enabled.checked;clearTimeout(timer);generation++;controller?.abort();controller=null;stream.hidden=true;stream.textContent='';cards.replaceChildren();}
  function put(text,append=false){previous=draft.value;invalidate();draft.value=append?(draft.value.trim()+'\n\n'+text).trim():text;undo.hidden=false;quality();status.textContent='Your brief is updated. Review it or refresh suggestions.';draft.focus();}
  root.querySelectorAll('[data-brief-starter]').forEach(button=>{button.hidden=false;button.addEventListener('click',()=>{put(button.dataset.briefStarter);schedule();});});
  undo.addEventListener('click',()=>{const text=previous;previous=draft.value;invalidate();draft.value=text;quality();status.textContent='Last change undone.';draft.focus();});
  function renderSuggestions(suggestions){cards.replaceChildren();for(const [index,text]of suggestions.entries()){
    if(typeof text!=='string')continue;const card=document.createElement('article');card.className='brief-suggestion';const p=document.createElement('p');p.textContent=text;card.append(p);
    const actions=document.createElement('div');actions.className='brief-suggestion-actions';
    for(const [label,append]of [['Use this',false],['Append',true]]){const button=document.createElement('button');button.type='button';button.className='btn btn-sm btn-outline-primary';button.textContent=label;button.setAttribute('aria-label',`${label} suggestion ${index+1}`);button.addEventListener('click',()=>put(text,append));actions.append(button);}card.append(actions);cards.append(card);
  }}
  function schedule(){invalidate();if(submitting)return;
    if(!connected){status.textContent='Connect OpenAI in Admin for live writing help. You can create your project now.';return;}
    if(!enabled.checked){status.textContent='Live writing help is off. Your brief can be saved as it is.';return;}
    if(draft.value.trim().length<16){status.textContent='Type at least 16 characters for live suggestions.';return;}
    if(bytes(draft.value)>2000||bytes(title.value)>160){status.textContent='This draft is ready to save. To request writing help, use a brief under 2,000 bytes and a title under 160 bytes.';return;}
    status.textContent='Preparing suggestions…';timer=setTimeout(()=>suggest(false),500);
  }
  async function suggest(manual){
    if(submitting||!connected||!enabled.checked||draft.value.trim().length<16||bytes(draft.value)>2000||bytes(title.value)>160)return;
    const signature=title.value+'\0'+draft.value;if(!manual&&signature===lastRequested){status.textContent='Use Refresh suggestions for another set of alternatives.';return;}
    invalidate();const current=generation;controller=new AbortController();lastRequested=signature;refresh.disabled=true;
    status.textContent='Thinking of focused alternatives…';
    const payload=new FormData();payload.set('action','research_brief_suggest');payload.set('csrf',form.elements.csrf.value);payload.set('draft',draft.value);payload.set('name',title.value);
    let done=false;try{
      const url=new URL(form.getAttribute('action'),location.href);const response=await fetch(url,{method:'POST',body:payload,credentials:'same-origin',signal:controller.signal,cache:'no-store',headers:{Accept:'text/event-stream'}});
      if(current!==generation)return;
      if(!response.ok||!(response.headers.get('content-type')||'').includes('text/event-stream')){
        let message='Writing help is unavailable. Your draft can still be saved.';
        if((response.headers.get('content-type')||'').includes('application/json')){const data=await response.json();if(typeof data.error==='string')message=data.error;}
        throw new Error(message);
      }
      if(!response.body)throw new Error('Streaming is unavailable in this browser. Your draft can still be saved.');
      const reader=response.body.getReader(),decoder=new TextDecoder();let buffer='',output='';
      function event(block){const lines=block.split('\n');const type=lines.find(line=>line.startsWith('event:'))?.slice(6).trim();const data=JSON.parse(lines.filter(line=>line.startsWith('data:')).map(line=>line.slice(5).trim()).join('\n')||'{}');
        if(type==='error')throw new Error(data.error||'Writing help stopped. Your draft is unchanged.');
        if(type==='delta'&&typeof data.text==='string'){output+=data.text;if(output.length>12000)throw new Error('Suggestion output was too long. Your draft is unchanged.');stream.hidden=false;stream.textContent=output;status.textContent='Receiving suggestions…';}
        if(type==='done'){if(!Array.isArray(data.suggestions)||data.suggestions.length<2)throw new Error('No usable alternatives returned. Your draft is unchanged.');done=true;stream.hidden=true;renderSuggestions(data.suggestions);status.textContent=`Suggestions ready · ${data.remaining} updates remaining this hour. Choose one or keep your own brief.`;}
      }
      while(true){const chunk=await reader.read();if(current!==generation){await reader.cancel();return;}buffer+=decoder.decode(chunk.value||new Uint8Array(),{stream:!chunk.done});buffer=buffer.replace(/\r\n/g,'\n');if(buffer.length>24000)throw new Error('Suggestion stream exceeded its limit.');let position;while((position=buffer.indexOf('\n\n'))!==-1){event(buffer.slice(0,position));buffer=buffer.slice(position+2);}if(chunk.done)break;}
      if(!done)throw new Error('The suggestion stream ended early. It will not be retried automatically. Your draft is unchanged.');
    }catch(error){if(current!==generation||error.name==='AbortError')return;stream.hidden=true;cards.replaceChildren();status.textContent=error.message;}
    finally{if(current===generation){controller=null;refresh.disabled=false;}}
  }
  draft.addEventListener('input',()=>{quality();schedule();});title.addEventListener('input',()=>{quality();schedule();});
  enabled.addEventListener('change',()=>{refresh.disabled=!enabled.checked;schedule();});
  refresh.addEventListener('click',()=>{lastRequested='';suggest(true);});
  form.addEventListener('submit',()=>{submitting=true;invalidate();root.querySelector('[data-create-submit]').disabled=true;});
  window.addEventListener('pagehide',invalidate);quality();if(!connected){refresh.disabled=true;status.textContent='Connect OpenAI in Admin for live writing help. You can create your project now.';}
})();
