import {streamAi} from './corevia-ai.js';
const settings=document.querySelector('[data-ai-ui]'),messages=JSON.parse(settings.dataset.messages),t=key=>messages[key]??key;
const form=document.querySelector('form'),status=document.querySelector('[data-status]'),output=document.querySelector('[data-output]'),empty=document.querySelector('[data-empty]'),badge=document.querySelector('[data-response-state]');
const send=form.querySelector('[type=submit]'),cancel=document.querySelector('[data-cancel]'),clear=document.querySelector('[data-clear]');let controller=null;
const feedback=(key,error=false)=>{status.textContent=t(key);status.setAttribute('role',error?'alert':'status');if(error){status.tabIndex=-1;status.focus();}};
cancel.addEventListener('click',()=>controller?.abort());
clear.addEventListener('click',()=>{if(controller)return;form.elements.message.value='';output.textContent='';output.hidden=true;empty.hidden=false;badge.hidden=true;feedback(settings.dataset.enabled==='true'?'Ready when you are.':'AI is not configured for this application yet.');form.elements.message.focus();});
form.addEventListener('submit',async event=>{
  event.preventDefault();if(controller||settings.dataset.enabled!=='true')return;
  controller=new AbortController();const active=controller;send.disabled=true;cancel.disabled=false;clear.disabled=true;form.setAttribute('aria-busy','true');
  output.textContent='';output.hidden=true;empty.hidden=false;badge.hidden=false;badge.textContent=t('Streaming');feedback('Generating…');const data=Object.fromEntries(new FormData(form));
  try{
    for await(const chunk of streamAi(new URL('/ai/chat',location.href),{message:data.message,csrf:data._token,signal:active.signal})){empty.hidden=true;output.hidden=false;output.textContent+=chunk;}
    feedback('Complete.');badge.textContent=t('Finished');
  }catch{
    feedback(active.signal.aborted?'Cancelled.':'Generation unavailable. Please try again.',!active.signal.aborted);badge.textContent=t(output.textContent?'Partial response':'Stopped');
  }finally{
    controller=null;send.disabled=false;cancel.disabled=true;clear.disabled=false;form.setAttribute('aria-busy','false');
  }
});
