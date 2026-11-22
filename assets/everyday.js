// Progressive reading only: every owner action remains a native authenticated POST.
(() => {
 'use strict';
 const root=document.querySelector('[data-everyday-workspace]');if(!root)return;
 const tabs=[...root.querySelectorAll('[data-everyday-tab]')],panels=[...root.querySelectorAll('[data-everyday-panel]')];
 function open(key,focus=false){
  if(!panels.some(p=>p.dataset.everydayPanel===key))return;
  for(const panel of panels)panel.hidden=panel.dataset.everydayPanel!==key;
  for(const tab of tabs){const selected=tab.dataset.everydayTab===key;tab.setAttribute('aria-selected',String(selected));tab.tabIndex=selected?0:-1;}
  if(focus)tabs.find(t=>t.dataset.everydayTab===key)?.focus({preventScroll:true});
 }
 root.querySelector('[data-everyday-tabs]')?.setAttribute('role','tablist');
 for(const panel of panels){panel.setAttribute('role','tabpanel');panel.setAttribute('aria-labelledby','everyday-tab-'+panel.dataset.everydayPanel);}
 for(const [index,tab] of tabs.entries()){
  tab.setAttribute('role','tab');tab.setAttribute('aria-controls','everyday-'+tab.dataset.everydayTab);
  tab.addEventListener('click',event=>{event.preventDefault();open(tab.dataset.everydayTab);history.replaceState(null,'','#everyday-'+tab.dataset.everydayTab);});
  tab.addEventListener('keydown',event=>{const next=event.key==='ArrowRight'?(index+1)%tabs.length:event.key==='ArrowLeft'?(index+tabs.length-1)%tabs.length:event.key==='Home'?0:event.key==='End'?tabs.length-1:null;if(next!==null){event.preventDefault();open(tabs[next].dataset.everydayTab,true);history.replaceState(null,'','#everyday-'+tabs[next].dataset.everydayTab);}});
 }
 for(const link of root.querySelectorAll('[data-everyday-open]'))link.addEventListener('click',event=>{event.preventDefault();open(link.dataset.everydayOpen,true);history.replaceState(null,'','#everyday-'+link.dataset.everydayOpen);});
 const initial=location.hash.startsWith('#everyday-')?location.hash.slice(10):'pathway';open(panels.some(p=>p.dataset.everydayPanel===initial)?initial:'pathway');
 let submitting=false;
 root.addEventListener('submit',event=>{
  const form=event.target;if(!form.matches('[data-everyday-form]')||form.elements.action.value==='nasa_export')return;
  if(submitting){event.preventDefault();return;}submitting=true;
  const status=root.querySelector('[data-everyday-operation]');if(status)status.textContent=form.hasAttribute('data-everyday-ai')?'Preparing one bounded proposal. Nothing is adopted or retried automatically.':'Saving the owner decision…';
  form.setAttribute('aria-busy','true');
 });
})();
