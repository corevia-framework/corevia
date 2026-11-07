const root=document.documentElement;
const storageKey='corevia-theme';
let theme='system';
try{const saved=localStorage.getItem(storageKey);if(['light','dark','system'].includes(saved))theme=saved;}catch{}
const button=document.querySelector('[data-theme-toggle]');
function apply(){
  if(theme==='system')delete root.dataset.theme;else root.dataset.theme=theme;
  if(button)button.textContent=`Theme: ${theme}`;
}
apply();
button?.addEventListener('click',()=>{theme=theme==='system'?'light':theme==='light'?'dark':'system';try{localStorage.setItem(storageKey,theme);}catch{}apply();});
for(const copy of document.querySelectorAll('[data-copy]'))copy.addEventListener('click',async()=>{
  const text=document.getElementById(copy.dataset.copy)?.textContent;
  const status=document.querySelector('[data-announcement]');
  try{if(!navigator.clipboard||text===undefined)throw new Error('unavailable');await navigator.clipboard.writeText(text);if(status)status.textContent='Command copied.';}
  catch{if(status)status.textContent='Select the command text to copy it.';}
});
