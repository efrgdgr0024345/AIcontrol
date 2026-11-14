// Explicit, sequential system steps. Reloading never retries a dispatched step.
(() => {
  const area=document.querySelector('[data-system-run]');if(!area)return;
  const key='aicontrol-system-auto-'+area.dataset.systemRun;let busy=false,stepReload=false;
  const auto=area.querySelector('[data-system-auto]');
  const message=document.createElement('p');message.setAttribute('role','status');area.append(message);
  const disableAuto=()=>sessionStorage.removeItem(key);
  window.addEventListener('pagehide',()=>{if(!stepReload)disableAuto();});
  document.addEventListener('visibilitychange',()=>{if(document.hidden&&!stepReload)disableAuto();});
  const advance=async form=>{
    const stop=form.dataset.systemAction==='system_stop';if(busy&&!stop)return;
    if(stop)disableAuto();else busy=true;
    const button=form.querySelector('button');button.disabled=true;
    message.textContent=stop?'Stopping system task…':'Agent step running. Waiting for a confirmed result…';
    const controller=new AbortController(),timeout=setTimeout(()=>controller.abort(),65000);
    try {
      const url=new URL(form.getAttribute('action'),location.href);url.searchParams.set('systems_api','1');
      const response=await fetch(url,{method:'POST',body:new URLSearchParams(new FormData(form)),credentials:'same-origin',signal:controller.signal});
      if(!(response.headers.get('content-type')||'').includes('application/json'))throw Error('Session or progress could not be verified. Sign in again if needed.');
      const data=await response.json();if(!response.ok)throw Error(data.error||'System step failed.');
      if(data.run?.status!=='ready')disableAuto();
      const next=new URL(location.href);next.searchParams.set('system_run',area.dataset.systemRun);stepReload=true;location.assign(next);
    }catch(error){disableAuto();message.textContent=error.name==='AbortError'?'Progress is uncertain. No step will be retried automatically. Stop the task and inspect the server before starting another.':error.message;button.disabled=false;}
    finally{clearTimeout(timeout);busy=false;}
  };
  area.querySelectorAll('[data-system-action]').forEach(form=>form.addEventListener('submit',event=>{event.preventDefault();advance(form);}));
  if(auto){auto.addEventListener('click',()=>{sessionStorage.setItem(key,'1');auto.disabled=true;advance(area.querySelector('[data-system-action=system_step]'));});}
  if(area.dataset.systemStatus!=='ready'||document.hidden)disableAuto();
  else if(sessionStorage.getItem(key)==='1')setTimeout(()=>{if(!document.hidden&&sessionStorage.getItem(key)==='1')advance(area.querySelector('[data-system-action=system_step]'));},1500);
  area.querySelector('[data-system-download]')?.addEventListener('click',()=>{const blob=new Blob([document.getElementById('system-report').value],{type:'application/json'}),url=URL.createObjectURL(blob),a=document.createElement('a');a.href=url;a.download='system-task-'+area.dataset.systemRun+'.json';a.click();setTimeout(()=>URL.revokeObjectURL(url),1000);});
})();
