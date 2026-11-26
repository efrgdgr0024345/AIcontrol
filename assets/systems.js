// Advance only one confirmed step at a time. An interrupted request is never retried.
(() => {
  const area=document.querySelector('[data-system-run]');
  if(!area)return;
  document.querySelector('[data-system-download]')?.addEventListener('click',()=>{
    const blob=new Blob([document.getElementById('system-report').value],{type:'application/json'});
    const url=URL.createObjectURL(blob),link=document.createElement('a');
    link.href=url;link.download='system-task-'+area.dataset.systemRun+'.json';link.click();
    setTimeout(()=>URL.revokeObjectURL(url),1000);
  });

  const step=area.querySelector('[data-system-action=system_step]');
  if(!step)return;
  const stop=area.querySelector('[data-system-action=system_stop]');
  const auto=area.querySelector('[data-system-auto]');
  const status=area.querySelector('[data-agent-status]');
  const message=area.querySelector('[data-agent-message]');
  const updates=area.querySelector('[data-agent-updates]');
  const output=area.querySelector('[data-agent-output]');
  let busy=false,stopping=false,automatic=false,nextTimer,progressTimer,progress;

  function pauseAuto(){
    automatic=false;
    clearTimeout(nextTimer);
    if(auto)auto.disabled=busy||stopping;
  }
  document.addEventListener('visibilitychange',()=>{if(document.hidden)pauseAuto();});

  async function request(form,action,signal){
    const url=new URL(form.getAttribute('action'),location.href);
    url.searchParams.set('systems_api','1');
    const body=new URLSearchParams(new FormData(form));
    body.set('action',action);
    const response=await fetch(url,{method:'POST',body,credentials:'same-origin',signal});
    if(!(response.headers.get('content-type')||'').includes('application/json'))throw Error('Session or progress could not be verified. Sign in again if needed.');
    const data=await response.json();
    if(!response.ok){
      const reference=/^[a-f0-9]{16}$/.test(data.request_id||'')?' Admin diagnostics reference '+data.request_id+'.':'';
      throw Error((data.error||'System step failed.')+reference);
    }
    return data;
  }

  function show(activity){
    area.dataset.systemStatus=activity.status;
    status.textContent=activity.status+' · '+activity.rounds+' / 8 decisions';
    if(activity.output&&output.textContent!==activity.output){
      output.textContent=activity.output;
      output.scrollTop=output.scrollHeight;
    }
    if(updates.children.length!==activity.updates.length){
      const items=activity.updates.map(note=>{
        const item=document.createElement('li');item.textContent=note;return item;
      });
      updates.replaceChildren(...items);
      updates.scrollTop=updates.scrollHeight;
    }
  }

  function reopen(){
    const next=new URL(location.href);
    next.searchParams.set('system_run',area.dataset.systemRun);
    next.hash='ssh-console';
    history.replaceState(history.state,'',next);
    location.reload();
  }

  async function watch(signal){
    try{
      const activity=await request(step,'system_task_activity',signal);
      if(signal.aborted)return;
      show(activity);
      if(activity.status==='working'){
        message.textContent=activity.output.includes('[waiting for VPS output]')?'Command running. Waiting for VPS output…':'Agent is reviewing the task and confirmed results…';
      }
      if(!busy&&activity.status!=='working'){reopen();return;}
    }catch(error){
      if(signal.aborted)return;
      pauseAuto();
      message.textContent=error.message;
      return;
    }
    if(!signal.aborted)progressTimer=setTimeout(()=>watch(signal),1500);
  }

  function endWatch(){
    clearTimeout(progressTimer);
    progress?.abort();
  }

  async function advance(){
    if(busy||stopping||area.dataset.systemStatus!=='ready')return;
    clearTimeout(nextTimer);
    busy=true;
    step.querySelector('button').disabled=true;
    if(auto)auto.disabled=true;
    message.textContent='Agent is reviewing the task and confirmed results…';
    progress=new AbortController();
    progressTimer=setTimeout(()=>watch(progress.signal),500);
    const controller=new AbortController(),timeout=setTimeout(()=>controller.abort(),65000);
    try{
      const data=await request(step,'system_step',controller.signal);
      endWatch();
      if(stopping)return;
      if(data.run?.status!=='ready'){pauseAuto();reopen();return;}
      show(await request(step,'system_task_activity',controller.signal));
      if(stopping)return;
      if(area.dataset.systemStatus!=='ready'||!automatic){pauseAuto();reopen();return;}
      message.textContent='Result confirmed. Preparing the next step…';
      step.querySelector('button').disabled=false;
      if(automatic&&!document.hidden)nextTimer=setTimeout(advance,1500);
      else pauseAuto();
    }catch(error){
      if(stopping)return;
      automatic=false;
      message.textContent=(error.name==='AbortError'?'Progress is uncertain.':error.message)+' Stop the task and inspect the server before starting another.';
    }finally{
      clearTimeout(timeout);endWatch();busy=false;
      if(auto&&!automatic&&!stopping&&!step.querySelector('button').disabled)auto.disabled=false;
    }
  }

  step.addEventListener('submit',event=>{event.preventDefault();advance();});
  auto?.addEventListener('click',()=>{automatic=true;advance();});
  stop.addEventListener('submit',async event=>{
    event.preventDefault();
    if(stopping)return;
    stopping=true;pauseAuto();endWatch();
    stop.querySelector('button').disabled=true;
    step.querySelector('button').disabled=true;
    message.textContent='Stopping system task…';
    try{await request(stop,'system_stop',AbortSignal.timeout(15000));reopen();}
    catch(error){message.textContent=error.message;stopping=false;stop.querySelector('button').disabled=false;}
  });
  if(area.dataset.systemStatus==='working'){
    progress=new AbortController();
    setTimeout(()=>{
      if(busy||stopping)return;
      endWatch();
      message.textContent='The last step is still unconfirmed. Stop the task and inspect the server before continuing.';
    },65000);
    watch(progress.signal);
  }
})();
