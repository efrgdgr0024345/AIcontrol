(() => {
  const riskForm=document.querySelector('[data-system-risk-form]');
  riskForm?.querySelector('[data-system-risk]')?.addEventListener('change',()=>riskForm.requestSubmit());
  const terminal=document.querySelector('[data-system-terminal]');if(!terminal)return;
  const form=terminal.querySelector('[data-terminal-form]'),input=form.querySelector('[data-terminal-input]'),output=terminal.querySelector('[data-terminal-output]'),status=terminal.querySelector('[data-terminal-status]'),disconnect=terminal.querySelector('[data-terminal-disconnect]');
  let pending=false,generation=0,connected=true;
  const history=Array.from(output.textContent.matchAll(/^\$ (.*)$/gm),m=>m[1]);let index=history.length,draft='';
  const clean=text=>String(text||'').replace(/\x1b\[[0-?]*[ -\/]*[@-~]|[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/g,'');
  const append=text=>{output.textContent+='\n'+clean(text);if(output.textContent.length>200000)output.textContent=output.textContent.slice(-200000);output.scrollTop=output.scrollHeight;};
  const request=async target=>{
    const url=new URL(target.getAttribute('action'),location.href);url.searchParams.set('systems_api','1');
    const response=await fetch(url,{method:'POST',credentials:'same-origin',body:new URLSearchParams(new FormData(target))});
    if(!(response.headers.get('content-type')||'').includes('application/json'))throw Error('Session or SSH progress could not be verified. Sign in and reconnect before sending another command.');
    const data=await response.json();if(!response.ok)throw Error(data.error||'SSH request failed.');return data;
  };
  form.addEventListener('submit',async event=>{
    event.preventDefault();if(pending||!connected||input.disabled)return;
    const command=input.value.trim();if(!command)return;
    if(command==='clear'){output.textContent='';input.value='';return;}
    pending=true;const serial=generation;form.querySelector('button').disabled=true;status.textContent='Command running over SSH…';history.push(command);index=history.length;draft='';append('$ '+command);
    try {
      const data=await request(form);if(serial!==generation)return;
      const result=data.terminal_result;if(!result?.command)throw Error('The SSH result could not be verified. Reconnect before continuing.');
      const row=result.command;
      const stdout=clean(row.stdout),stderr=clean(row.stderr);
      const streams=[stdout,stderr?'[stderr]\n'+stderr:''].filter(Boolean);
      append((streams.join('\n')||'No output returned.')+'\n['+row.status+' · exit '+(row.exit_code??'unknown')+(row.truncated?' · output truncated':'')+']');
      terminal.querySelector('[data-terminal-directory]').textContent='Directory: '+(result.cwd||'server home');
      connected=result.connected;
      status.textContent=connected?(result.cwd_verified?'Command finished.':'Command finished; the previous confirmed directory was retained.'): 'SSH outcome uncertain. The console is disconnected. Review the result and reconnect before continuing.';
      input.value='';
    }catch(error){if(serial===generation){connected=false;status.textContent=error.message+' No command was retried automatically.';}}
    finally{pending=false;form.querySelector('button').disabled=!connected;input.disabled=!connected;if(connected)input.focus({preventScroll:true});}
  });
  disconnect.addEventListener('submit',async event=>{
    event.preventDefault();generation++;connected=false;input.disabled=true;form.querySelector('button').disabled=true;status.textContent='Disconnecting SSH console…';
    try{await request(disconnect);status.textContent='Console disconnected. A command already dispatched may have an uncertain outcome.';disconnect.querySelector('button').disabled=true;}
    catch(error){status.textContent=error.message+' Disconnect could not be confirmed; check the saved task report.';}
  });
  input.addEventListener('keydown',event=>{
    if(event.key==='ArrowUp'){event.preventDefault();if(index===history.length)draft=input.value;if(index>0)input.value=history[--index];}
    else if(event.key==='ArrowDown'){event.preventDefault();if(index<history.length){index++;input.value=index===history.length?draft:history[index];}}
  });
  if(location.hash==='#ssh-console')input.focus({preventScroll:true});
})();
