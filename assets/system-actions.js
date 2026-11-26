// Secrets are fetched only by an explicit reveal and never placed in browser storage.
(() => {
 const privacy=document.querySelector('[data-system-privacy]');if(!privacy)return;
 const lockedURL=()=>{const u=new URL(location.href);for(const k of ['terminal','enrollment','system_run','operation'])u.searchParams.delete(k);return u;};
 const until=Number(privacy.dataset.unlockUntil||0);if(until){const expire=()=>location.replace(lockedURL());setTimeout(expire,Math.max(0,until*1000-Date.now()));window.addEventListener('storage',e=>{if(e.key!=='aicontrol-systems-locked')return;document.querySelectorAll('[data-protected-output]').forEach(n=>{n.textContent='';n.hidden=true;});const f=privacy.querySelector('form');if(!f){expire();return;}const u=new URL(f.getAttribute('action'),location.href);u.searchParams.set('systems_api','1');fetch(u,{method:'POST',credentials:'same-origin',body:new FormData(f)}).finally(expire);});privacy.querySelector('[data-systems-lock]')?.addEventListener('click',()=>localStorage.setItem('aicontrol-systems-locked',String(Date.now())));}
 const request=async form=>{
  const u=new URL(form.getAttribute('action')||location.pathname,location.href);
  u.searchParams.set('systems_api','1');
  const r=await fetch(u,{method:'POST',credentials:'same-origin',body:new FormData(form)});
  if(!(r.headers.get('content-type')||'').includes('application/json'))throw Error('Session verification expired. Unlock Systems again.');
  const v=await r.json();
  if(!r.ok){
   const reference=/^[a-f0-9]{16}$/.test(v.request_id||'')?' Admin diagnostics reference '+v.request_id+'.':'';
   throw Error((v.error||'Server action could not be verified.')+reference);
  }
  return v;
 };
 document.querySelectorAll('[data-protected-reveal]').forEach(button=>{const form=button.closest('form'),output=form.querySelector('[data-protected-output]'),status=form.querySelector('[data-protected-status]');let timer;const hide=()=>{output.textContent='';output.hidden=true;button.textContent='Reveal protected values and full observations';status.textContent='Protected values hidden.';};form.addEventListener('submit',async e=>{e.preventDefault();if(!output.hidden){hide();return;}button.disabled=true;try{const v=await request(form);output.textContent=JSON.stringify(v.protected_record,null,2);output.hidden=false;button.textContent='Hide protected values';status.textContent='Shown for one minute. These values are not copied or saved in browser storage.';clearTimeout(timer);timer=setTimeout(hide,60000);}catch(error){status.textContent=error.message;}finally{button.disabled=false;}});document.addEventListener('visibilitychange',()=>{if(document.hidden)hide();});});
 const progress=document.querySelector('[data-guided-progress]');
 const pendingMessages={
  system_playbook_prepare:'Checking the VPS over SSH…',
  system_playbook_ai_prepare:'AI is choosing an action. Waiting for the recommendation…',
  system_playbook_apply:'Sending the approved action to the VPS…',
  system_playbook_stop:'Sending the stop request to the VPS…',
 };
 if(progress)document.querySelector('[data-system-control]')?.addEventListener('submit',event=>{
  const message=pendingMessages[event.target.elements?.action?.value];
  if(message){
   progress.textContent=message;
   progress.scrollIntoView({block:'nearest'});
  }
 });
 const op=document.querySelector('[data-playbook]');
 if(!op||op.dataset.playbookActive!=='1')return;
 const poll=op.querySelector('[data-playbook-poll]')?.closest('form');
 const status=op.querySelector('[data-playbook-status]');
 let busy=false,timer;
 let auto=op.dataset.playbookState!=='uncertain';
 function schedule(delay){
  clearTimeout(timer);
  if(auto&&!document.hidden)timer=setTimeout(check,delay);
 }
 async function check(){
  if(busy||document.hidden||!poll)return;
  clearTimeout(timer);busy=true;
  try{
   const data=await request(poll);
   const playbook=data.playbook;
   if(playbook?.id!==op.dataset.playbook||!Array.isArray(data.entries))throw Error('The operation result could not be verified.');
   document.dispatchEvent(new CustomEvent('aicontrol:terminal-records',{detail:data.entries}));
   if(!playbook.blocking||playbook.status==='uncertain'){
    auto=false;
    const url=new URL(location.href);
    url.searchParams.set('operation',playbook.id);
    url.hash='ssh-console';
    history.replaceState(history.state,'',url);
    location.reload();
    return;
   }
   auto=true;
   status.textContent=playbook.status;
   if(progress)progress.textContent='Waiting for the next result from the VPS.';
   schedule(5000);
  }catch(error){
   auto=false;
   const message=error.message+' Use Check progress to reconcile this operation.';
   status.textContent=message;
   if(progress)progress.textContent=message;
  }finally{busy=false;}
 }
 poll?.addEventListener('submit',e=>{e.preventDefault();check();});
 document.addEventListener('visibilitychange',()=>schedule(2500));
 schedule(2500);
})();
