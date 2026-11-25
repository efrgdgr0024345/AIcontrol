// Explicit Explain streams C; A is a local short note and B stays unchanged.
(()=>{
 const config=document.querySelector('[data-term-help-config]');if(!config)return;
 const cache=new Map(),project=config.dataset.projectId,entry=config.dataset.entry||location.pathname;let current=null,queued=false;
 const make=(tag,text,cls)=>{const el=document.createElement(tag);if(text!==undefined)el.textContent=text;if(cls)el.className=cls;return el;};
 function identify(){const original=document.querySelector('[data-map-inspector-content] .map-inspector-text'),owner=original?.closest('[data-term-kind="node"]');if(!owner||!original||!/^[1-9][0-9]*$/.test(owner.dataset.termNode||''))return null;return {original,node:owner.dataset.termNode,key:JSON.stringify([project,owner.dataset.termNode,owner.querySelector('h3')?.textContent,original.textContent])};}
 const side=document.querySelector('[data-map-inspector]'),expand=document.querySelector('[data-branch-expand]');if(!side||!expand)return;
 const button=make('button','Explain','btn btn-outline-primary');button.type='button';button.dataset.explainOutput='';button.title='Rewrite this output using ASD-STE100 writing principles';expand.before(button);
 const area=make('section',undefined,'output-explanation');area.dataset.outputExplanation='';area.setAttribute('aria-label','C · Simplified rewrite');
 const box=make('div',undefined,'output-explanation-box');box.hidden=true;box.dataset.outputExplanationBox='';box.append(make('h4','C · Simplified rewrite'),make('p','ASD-STE100 writing principles · AI-generated; formal compliance is not certified. Original output B stays unchanged.','output-explanation-note'));
 const status=make('p','','output-explanation-status');status.setAttribute('role','status');status.dataset.outputExplanationStatus='';const text=make('div','','output-explanation-text');text.tabIndex=0;text.dataset.outputExplanationText='';text.setAttribute('aria-label','Simplified explanation text');const retry=make('button','Retry (another paid request)','btn btn-sm btn-outline-secondary');retry.type='button';retry.dataset.retryOutputExplanation='';retry.hidden=true;box.append(status,text,retry);area.append(box);side.querySelector('[data-map-inspector-content]').after(area);
 button.addEventListener('click',()=>{const source=identify();if(source)run(source,false);});retry.addEventListener('click',()=>{const source=identify();if(source)run(source,true);});
 function draw(){queued=false;const source=identify();current=source?{...source,area}:null;button.hidden=!source;paint();}
 function paint(){const state=current?cache.get(current.key):null;box.hidden=!state;button.disabled=state?.status==='pending';button.textContent=state?.status==='pending'?'Explaining…':'Explain';retry.hidden=!state?.retry;if(!state)return;text.textContent=state.text||'';status.textContent=state.status==='pending'?'Streaming rewrite · unfinished until saved.':state.status==='ready'?(state.cached?'Saved rewrite.':'Rewrite saved.'):(state.error||'Unfinished rewrite. No automatic retry was made.');}
 async function run(source,retry){const old=cache.get(source.key);if(old?.status==='ready'||old?.status==='pending'){paint();return;}if(old?.retry&&!retry){paint();return;}const state={status:'pending',text:'',retry:false};cache.set(source.key,state);paint();
  const form=new FormData();for(const [key,value]of Object.entries({action:'research_explain_output',csrf:document.querySelector('input[name=csrf]')?.value||'',project_id:project,node_id:source.node,retry:retry?'1':'0'}))form.set(key,value);
  let complete=false;
  try{
   const response=await fetch(entry,{method:'POST',body:form,credentials:'same-origin',cache:'no-store',headers:{Accept:'text/event-stream'}});
   if(!response.ok||!(response.headers.get('content-type')||'').includes('text/event-stream')){let message='The explanation could not start. Your original text is unchanged.';if((response.headers.get('content-type')||'').includes('application/json')){const value=await response.json();message=value.error||message;state.retry=value.allow_retry===true;}throw Error(message);}
   if(!response.body)throw Error('Streaming is unavailable in this browser.');const reader=response.body.getReader(),decoder=new TextDecoder();let buffer='';
   const event=block=>{const lines=block.split('\n'),type=lines.find(l=>l.startsWith('event:'))?.slice(6).trim(),value=JSON.parse(lines.filter(l=>l.startsWith('data:')).map(l=>l.slice(5).trim()).join('\n')||'{}');
    if(type==='delta'&&typeof value.text==='string'){state.text+=value.text;if(state.text.length>24000)throw Error('The explanation exceeded its limit.');}
    if(type==='error'){state.retry=value.allow_retry===true;throw Error(value.error||'Explanation stopped. No automatic retry was made.');}
    if(type==='done'){if(typeof value.definition!=='string'||!value.definition)throw Error('No complete explanation was returned.');state.text=value.definition;state.cached=value.cached===true;state.status='ready';complete=true;}
    if(current?.key===source.key)paint();
   };
   while(true){const chunk=await reader.read();buffer+=decoder.decode(chunk.value||new Uint8Array(),{stream:!chunk.done});buffer=buffer.replace(/\r\n/g,'\n');if(buffer.length>80000)throw Error('The explanation stream exceeded its limit.');let i;while((i=buffer.indexOf('\n\n'))!==-1){event(buffer.slice(0,i));buffer=buffer.slice(i+2);}if(chunk.done)break;}
   if(!complete){state.retry=true;throw Error('The stream ended before completion. No automatic retry was made.');}
  }catch(error){state.status='error';state.error=(state.text?'Unfinished rewrite. ':'')+error.message;if(error instanceof TypeError)state.retry=true;}
  if(current?.key===source.key)paint();if(cache.size>100)for(const [key,value]of cache)if(value.status!=='pending'&&key!==current?.key){cache.delete(key);break;}
 }
 const schedule=()=>{if(!queued){queued=true;requestAnimationFrame(draw);}};
 new MutationObserver(records=>{if(records.some(record=>{const target=record.target.nodeType===Node.ELEMENT_NODE?record.target:record.target.parentElement;return !target?.closest('[data-output-explanation],.term-help-panel');}))schedule();}).observe(document.body,{childList:true,subtree:true,attributes:true,attributeFilter:['data-term-node','data-term-version']});schedule();
})();
