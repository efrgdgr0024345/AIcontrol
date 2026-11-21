// Progressive enhancement only; all planning actions also work as ordinary POST forms.
(() => {
  'use strict';
  const root=document.querySelector('[data-nasa-workspace]');if(!root)return;
  const density=root.querySelector('[data-nasa-density-choice]');
  const setDensity=value=>{root.dataset.nasaDensity=value==='practitioner'?'practitioner':'beginner';if(density)density.value=root.dataset.nasaDensity;};
  if(!new URL(location.href).searchParams.has('density')){try{setDensity(localStorage.getItem('aicontrol-nasa-density')||root.dataset.nasaDensity);}catch{}}
  density?.addEventListener('change',()=>{setDensity(density.value);try{localStorage.setItem('aicontrol-nasa-density',density.value);}catch{}});
  const tabs=[...root.querySelectorAll('[data-nasa-tab]')],panels=[...root.querySelectorAll('[data-nasa-panel]')];
  function openPanel(key,focus=false){
    if(!panels.some(p=>p.dataset.nasaPanel===key))return;
    for(const p of panels)p.hidden=p.dataset.nasaPanel!==key;
    for(const t of tabs){const on=t.dataset.nasaTab===key;t.setAttribute('aria-selected',String(on));t.tabIndex=on?0:-1;}
    if(focus)tabs.find(t=>t.dataset.nasaTab===key)?.focus();
  }
  root.querySelector('[data-nasa-tabs]')?.setAttribute('role','tablist');
  for(const tab of tabs){tab.setAttribute('role','tab');tab.setAttribute('aria-controls','nasa-'+tab.dataset.nasaTab);tab.addEventListener('click',event=>{event.preventDefault();openPanel(tab.dataset.nasaTab);history.replaceState(null,'','#nasa-'+tab.dataset.nasaTab);});tab.addEventListener('keydown',event=>{const index=tabs.indexOf(tab),next=event.key==='ArrowRight'?tabs[(index+1)%tabs.length]:event.key==='ArrowLeft'?tabs[(index-1+tabs.length)%tabs.length]:event.key==='Home'?tabs[0]:event.key==='End'?tabs.at(-1):null;if(next){event.preventDefault();openPanel(next.dataset.nasaTab,true);}});}
  for(const panel of panels){panel.setAttribute('role','tabpanel');panel.setAttribute('aria-labelledby','nasa-tab-'+panel.dataset.nasaPanel);}
  openPanel(location.hash.replace('#nasa-','')||'overview');
  const editType=new URLSearchParams(location.search).get('edit_artifact'),editor=editType?document.getElementById('nasa-artifact-'+editType):null;
  if(editor&&!editor.closest('[data-nasa-panel]')?.hidden)editor.querySelector('textarea')?.focus({preventScroll:true});
  for(const link of root.querySelectorAll('[data-nasa-open],[data-nasa-next]'))link.addEventListener('click',()=>openPanel(link.dataset.nasaOpen||'overview'));
  const beginner=root.querySelector('[data-nasa-beginner]');
  function guidance(){for(const note of root.querySelectorAll('.nasa-beginner-note'))note.hidden=!beginner.checked;try{localStorage.setItem('aicontrol-nasa-beginner',String(beginner.checked));}catch{}}
  if(beginner){try{beginner.checked=localStorage.getItem('aicontrol-nasa-beginner')!=='false';}catch{}beginner.addEventListener('change',guidance);guidance();}
  const inspector=root.querySelector('[data-nasa-inspector]'),content=root.querySelector('[data-nasa-inspector-content]');let helpTrigger;
  const close=()=>{if(!inspector)return;inspector.hidden=true;helpTrigger?.focus({preventScroll:true});};
  root.querySelector('[data-nasa-help-close]')?.addEventListener('click',close);
  root.addEventListener('keydown',event=>{if(event.key==='Escape'&&inspector&&!inspector.hidden){event.preventDefault();close();}});
  for(const term of root.querySelectorAll('[data-nasa-term]'))term.addEventListener('click',event=>{
    const source=root.querySelector(`[data-nasa-help-content="${term.dataset.nasaTerm}"]`);if(!source||!inspector)return;event.preventDefault();helpTrigger=term;content.replaceChildren();const copy=source.cloneNode(true);copy.removeAttribute('id');for(const node of copy.querySelectorAll('[id]'))node.removeAttribute('id');content.append(copy);inspector.hidden=false;const heading=content.querySelector('h4');heading.tabIndex=-1;heading.focus({preventScroll:true});
  });
  root.querySelector('[data-nasa-help-search]')?.addEventListener('input',event=>{const term=event.target.value.trim().toLocaleLowerCase();for(const item of root.querySelectorAll('[data-nasa-glossary]'))item.hidden=!!term&&!item.textContent.toLocaleLowerCase().includes(term);});
  for(const cell of root.querySelectorAll('[data-nasa-risk-cell]'))cell.addEventListener('click',()=>{for(const card of root.querySelectorAll('[data-nasa-risk]'))card.hidden=card.dataset.nasaRisk!==cell.dataset.nasaRiskCell;});
  root.querySelector('[data-nasa-risk-reset]')?.addEventListener('click',()=>{for(const card of root.querySelectorAll('[data-nasa-risk]'))card.hidden=false;});
  root.querySelector('[data-nasa-print]')?.addEventListener('click',()=>{const hidden=panels.filter(p=>p.hidden),closed=[...root.querySelectorAll('details:not([open])')];for(const p of hidden)p.hidden=false;for(const d of closed)d.open=true;window.print();for(const p of hidden)p.hidden=true;for(const d of closed)d.open=false;});
  let busy=false,uncertain=false;const status=root.querySelector('[data-nasa-operation]');
  root.addEventListener('submit',async event=>{
    const form=event.target;if(!form.matches('[data-nasa-form]'))return;event.preventDefault();if(busy||uncertain&&form.hasAttribute('data-nasa-ai'))return;
    const submitter=event.submitter;const disabled=[...form.querySelectorAll('button:not(:disabled)')];const body=new FormData(form);if(submitter?.name)body.set(submitter.name,submitter.value);
    busy=true;form.setAttribute('aria-busy','true');for(const b of disabled)b.disabled=true;
    if(status)status.textContent=form.hasAttribute('data-nasa-ai')?'Preparing one bounded AI request. Drafts need your review; no automatic retry.':'Saving planning records…';
    try{
      const url=new URL(form.getAttribute('action')||location.href,location.href);url.searchParams.set('nasa_api','1');const response=await fetch(url,{method:'POST',body,credentials:'same-origin',headers:{Accept:'application/json'}});
      if(!(response.headers.get('content-type')||'').includes('application/json'))throw Error('Session or outcome could not be verified. Refresh NASA Managed before trying again.');
      const result=await response.json();if(!response.ok)throw Error(result.error||'Planning action failed. Saved records were retained.');
      const next=new URL(result.location,location.href);if(next.origin!==location.origin)throw Error('Unexpected redirect. Refresh the workspace.');
      const panel=form.closest('[data-nasa-panel]')?.dataset.nasaPanel,action=form.elements.action.value;let destination=action.includes('review')?'reviews':['nasa_generate','nasa_template','nasa_promote_research'].includes(action)?'artifacts':panel||'overview';if(action==='nasa_collaborate'||action.startsWith('nasa_proposal_')||action==='nasa_generate'&&form.elements.artifact?.value==='missing')destination='overview';if(next.searchParams.get('view')==='nasa')next.hash='nasa-'+destination;
      if(next.pathname===location.pathname&&next.search===location.search){history.replaceState(null,'',next);location.reload();}else location.assign(next);
    }catch(error){uncertain=form.hasAttribute('data-nasa-ai');if(status){status.textContent=error.message+(uncertain?' Inspect AI request history before sending another request.':'');status.setAttribute('role','alert');}if(uncertain)for(const button of root.querySelectorAll('[data-nasa-ai] button'))button.disabled=true;}
    finally{busy=false;form.removeAttribute('aria-busy');if(!uncertain)for(const b of disabled)b.disabled=false;}
  });
})();
