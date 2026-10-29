function capture(){const token=new URLSearchParams(location.hash.slice(1)).get('token')??'';if(location.hash)history.replaceState(null,'',location.pathname);for(const input of document.querySelectorAll('input[name="token"]')){input.value=/^[a-f0-9]{64}$/.test(token)?token:'';input.form.querySelector('button').disabled=!input.value;}}
capture();addEventListener('hashchange',capture);
for(const form of document.querySelectorAll('form[data-account-action]')){
  form.addEventListener('submit',async event=>{
    event.preventDefault();const button=form.querySelector('button'),notice=document.querySelector('[data-account-notice]');button.disabled=true;
    try{const payload=Object.fromEntries(new FormData(form));const response=await fetch(form.dataset.accountAction,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':payload._token,Accept:'application/json'},body:JSON.stringify(payload)});const result=await response.json();if(!response.ok)throw new Error(result.error?.message??'Please try again.');notice.textContent=result.message;
      if(form.dataset.accountAction==='/auth/password/reset')location.assign('/login');
      else if(form.dataset.accountAction==='/auth/email/verify')location.assign('/dashboard');
      if(result.csrf)for(const input of document.querySelectorAll('input[name="_token"]'))input.value=result.csrf;
      const output=document.querySelector('[data-account-output]');if(output&&(result.setup||result.recovery_codes))output.textContent=JSON.stringify(result.setup??result.recovery_codes,null,2);
      if(result.authorization_url)location.assign(result.authorization_url);else if(result.redirect)location.assign(result.redirect);
    }catch(error){notice.textContent=error.message;notice.setAttribute('role','alert');}finally{button.disabled=false;}
  });
}
