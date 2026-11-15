const messages=JSON.parse(document.getElementById('corevia-ui')?.dataset.messages??'{}');
export function t(message,parameters={}){let result=messages[message]??message;for(const [key,value] of Object.entries(parameters))result=result.replaceAll(`:${key}`,()=>String(value));return result;}
export function errorText(error){return messages[error?.code]??t(error?.message??'Please try again.');}
export function initializePresentation(){
  const root=document.documentElement,button=document.querySelector('[data-auth-theme]');let theme='system';
  try{const saved=localStorage.getItem('corevia-theme');if(['system','light','dark'].includes(saved))theme=saved;}catch{}
  function apply(){if(theme==='system')delete root.dataset.theme;else root.dataset.theme=theme;if(button){button.textContent=t(`Theme: ${theme}`);button.setAttribute('aria-label',button.textContent+'. '+t('Change color theme'));}}
  apply();button?.addEventListener('click',()=>{theme=theme==='system'?'light':theme==='light'?'dark':'system';try{localStorage.setItem('corevia-theme',theme);}catch{}apply();});
  document.querySelector('[data-auth-locale]')?.addEventListener('change',event=>{const locale=event.target.value;if(!['en','ur','ar'].includes(locale))return;document.cookie=`corevia_locale=${locale}; Path=/; SameSite=Lax; Max-Age=31536000${location.protocol==='https:'?'; Secure':''}`;location.reload();});
  document.querySelector('[data-auth-error]')?.focus();
}
