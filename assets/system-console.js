(() => {
  const riskForm=document.querySelector('[data-system-risk-form]');
  riskForm?.querySelector('[data-system-risk]')?.addEventListener('change',()=>riskForm.requestSubmit());
  const terminal=document.querySelector('#ssh-console');
  if(!terminal)return;
  const output=terminal.querySelector('[data-terminal-output]');
  const sources={ssh:'SSH',manual:'You',agent:'Agent',guided:'Guided action'};
  const rows=new Map([...output.children].map(node=>[node.dataset.terminalEntry,node]));
  const clean=text=>String(text||'').replace(/\x1b\[[0-?]*[ -\/]*[@-~]|[\x00-\x08\x0b-\x1f\x7f]/g,'');
  const scroll=()=>{output.scrollTop=output.scrollHeight;};
  const order=(a,b)=>Number(a.dataset.terminalTime)-Number(b.dataset.terminalTime)
    ||a.dataset.terminalEntry.localeCompare(b.dataset.terminalEntry,'en',{numeric:true});
  const put=(entries,force=false)=>{
    const follow=force||output.scrollHeight-output.clientHeight-output.scrollTop<32;
    for(const entry of entries){
      if(!Object.hasOwn(sources,entry.source)||typeof entry.text!=='string')continue;
      let row=rows.get(entry.key);
      if(row){
        if(row.lastElementChild.textContent!==entry.text)row.lastElementChild.textContent=entry.text;
        continue;
      }
      row=document.createElement('div');
      row.className='terminal-entry';
      row.dataset.terminalEntry=entry.key;
      row.dataset.terminalTime=entry.at;
      row.dataset.source=entry.source;
      const label=document.createElement('span');label.className='terminal-source';label.textContent=sources[entry.source];
      const body=document.createElement('div');body.className='terminal-text';body.textContent=entry.text;
      row.append(label,body);
      const next=[...output.children].find(existing=>order(existing,row)>0);
      output.insertBefore(row,next||null);rows.set(entry.key,row);
    }
    if(follow)scroll();
  };
  const remove=key=>{rows.get(key)?.remove();rows.delete(key);};
  document.addEventListener('aicontrol:terminal-records',event=>put(event.detail));
  scroll();

  const request=async target=>{
    const url=new URL(target.getAttribute('action'),location.href);url.searchParams.set('systems_api','1');
    const response=await fetch(url,{method:'POST',credentials:'same-origin',body:new URLSearchParams(new FormData(target))});
    if(!(response.headers.get('content-type')||'').includes('application/json'))throw Error('The request could not be verified. Sign in again before continuing.');
    const data=await response.json();if(!response.ok){
      const reference=/^[a-f0-9]{16}$/.test(data.request_id||'')?' Admin diagnostics reference '+data.request_id+'.':'';
      throw Error((data.error||'SSH request failed.')+reference);
    }return data;
  };

  const connect=terminal.querySelector('[data-terminal-connect]');
  connect?.addEventListener('submit',()=>{
    put([{key:'connecting',at:Date.now()/1000,source:'ssh',text:'Verifying the host and signing in with the saved SSH key…'}],true);
  });

  const direction=terminal.querySelector('[data-terminal-agent-form]');
  direction?.addEventListener('submit',async event=>{
    if(event.submitter?.value==='step')return;
    event.preventDefault();
    const controls=[...direction.querySelectorAll('button')],status=direction.querySelector('[data-agent-submit-status]');
    controls.forEach(button=>button.disabled=true);
    status.textContent='Starting the agent…';
    try{
      const data=await request(direction),run=data.run;
      if(!Number.isSafeInteger(run?.id)||run.status!=='ready')throw Error('The agent task could not be confirmed.');
      sessionStorage.setItem('aicontrol-agent-auto',String(run.id));
      const next=new URL(direction.getAttribute('action'),location.href);
      next.searchParams.set('system',direction.elements.system_id.value);
      next.searchParams.set('system_run',run.id);
      next.hash='ssh-console';
      location.assign(next);
    }catch(error){status.textContent=error.message;controls.forEach(button=>button.disabled=false);}
  });

  const form=terminal.querySelector('[data-terminal-form]');
  if(!form)return;
  const input=form.querySelector('[data-terminal-input]'),status=terminal.querySelector('[data-terminal-status]');
  const disconnect=terminal.querySelector('[data-terminal-disconnect]');
  let pending=false,generation=0,connected=true;
  const history=Array.from(output.querySelectorAll('[data-source=manual] .terminal-text'),row=>row.textContent.split('\n')[0].slice(2));let index=history.length,draft='';
  form.addEventListener('submit',async event=>{
    event.preventDefault();if(pending||!connected||input.disabled)return;
    const command=input.value.trim();if(!command)return;
    if(command==='clear'){output.replaceChildren();rows.clear();input.value='';return;}
    pending=true;const serial=generation;form.querySelector('button').disabled=true;
    status.textContent='Command running over SSH…';history.push(command);index=history.length;draft='';
    put([{key:'pending-manual',at:Date.now()/1000,source:'manual',text:'$ '+clean(command)+'\n[waiting for VPS output]'}],true);
    try {
      const data=await request(form);if(serial!==generation)return;
      const result=data.terminal_result;if(!result?.entry)throw Error('The SSH result could not be verified. Reconnect before continuing.');
      remove('pending-manual');put([result.entry],true);
      terminal.querySelector('[data-terminal-directory]').textContent='Directory: '+(result.cwd||'server home');
      connected=result.connected;
      status.textContent=connected?(result.cwd_verified?'Command finished.':'Command finished. The previous confirmed directory was retained.'):'SSH outcome uncertain. The console is disconnected. Review the result before reconnecting.';
      input.value='';
    }catch(error){
      if(serial===generation){
        connected=false;remove('pending-manual');
        put([{key:'manual-error',at:Date.now()/1000,source:'ssh',text:'[SSH error] '+error.message}],true);
        status.textContent=error.message+' Reopen the console before another command.';
      }
    }finally{pending=false;form.querySelector('button').disabled=!connected;input.disabled=!connected;if(connected)input.focus({preventScroll:true});}
  });
  disconnect.addEventListener('submit',async event=>{
    event.preventDefault();generation++;connected=false;input.disabled=true;form.querySelector('button').disabled=true;status.textContent='Disconnecting SSH console…';
    try{
      await request(disconnect);
      if(pending)put([{key:'ssh-disconnect',at:Date.now()/1000,source:'ssh',text:'Connection closed while a command was pending. Check its result before retrying.'}],true);
      status.textContent='Console disconnected.';disconnect.querySelector('button').disabled=true;
    }
    catch(error){status.textContent=error.message+' Disconnect could not be confirmed. Check the saved task report.';}
  });
  input.addEventListener('keydown',event=>{
    if(event.key==='ArrowUp'){event.preventDefault();if(index===history.length)draft=input.value;if(index>0)input.value=history[--index];}
    else if(event.key==='ArrowDown'){event.preventDefault();if(index<history.length){index++;input.value=index===history.length?draft:history[index];}}
  });
  if(location.hash==='#ssh-console'&&!input.disabled)input.focus({preventScroll:true});
})();
