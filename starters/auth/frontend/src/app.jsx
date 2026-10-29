import React, {useEffect, useState} from 'react';
import {createRoot} from 'react-dom/client';

function capture(){const value=new URLSearchParams(location.hash.slice(1)).get('token')??'';if(location.hash)history.replaceState(null,'',location.pathname);return /^[a-f0-9]{64}$/.test(value)?value:'';}
function AccountPanel({session}){
  const [token,setToken]=useState(capture),[error,setError]=useState(''),[notice,setNotice]=useState(''),[busy,setBusy]=useState(false);
  const page=location.pathname;
  useEffect(()=>{const changed=()=>setToken(capture());addEventListener('hashchange',changed);return()=>removeEventListener('hashchange',changed);},[]);
  async function send(event,operation){event.preventDefault();if(!session||busy)return;setBusy(true);setError('');try{
    const payload={...Object.fromEntries(new FormData(event.currentTarget)),token};
    const response=await fetch('/auth/'+operation,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':session.csrf,Accept:'application/json'},body:JSON.stringify(payload)});
    const result=await response.json();if(!response.ok)throw new Error(result.error?.message??'Please try again.');setNotice(result.message);
    if(operation==='password/reset')location.assign('/login');if(operation==='email/verify')location.assign('/dashboard');
  }catch(error){setError(error.message);}finally{setBusy(false);}}
  const field='mb-5 w-full rounded-lg border border-slate-700 bg-slate-950 px-4 py-3',button='w-full rounded-lg bg-cyan-400 px-4 py-3 font-semibold text-slate-950 disabled:opacity-50';
  return <main className="min-h-screen grid place-items-center px-6 py-16"><section className="w-full max-w-md rounded-2xl border border-slate-800 bg-slate-900 p-8 shadow-xl">
    <p className="mb-6 text-xs text-cyan-300">COREVIA / ACCOUNT ACCESS</p><h1 className="mb-6 text-3xl font-semibold">{page==='/forgot-password'?'Forgot your password?':page==='/reset-password'?'Choose a new password.':'Verify your email.'}</h1>
    {error&&<p role="alert" className="mb-4 text-red-200">{error}</p>}{notice&&<p role="status" className="mb-4">{notice}</p>}
    {page==='/forgot-password'?<form method="post" action="/auth/password/forgot" onSubmit={event=>send(event,'password/forgot')}><input type="hidden" name="_token" value={session?.csrf??''}/><label>Email address<input className={field} type="email" name="email" autoComplete="email" required/></label><button disabled={!session||busy} className={button}>Send recovery link</button></form>:
    page==='/reset-password'?<form method="post" action="/auth/password/reset" onSubmit={event=>send(event,'password/reset')}><input type="hidden" name="_token" value={session?.csrf??''}/><input type="hidden" name="token" value={token}/><label>New password<input className={field} type="password" name="password" autoComplete="new-password" minLength={12} required/></label><label>Confirm password<input className={field} type="password" name="password_confirmation" autoComplete="new-password" required/></label><button disabled={!session||busy||!token} className={button}>Update password</button></form>:
    !session?.user?<p>Sign in to the account that requested this link, then open the link again.</p>:
    session.email_verified?<p>Email verified. <a href="/dashboard">Open dashboard</a></p>:<><p className="mb-5">Confirm the link from your email, or request a new one.</p><form method="post" action="/auth/email/verify" className="mb-5" onSubmit={event=>send(event,'email/verify')}><input type="hidden" name="_token" value={session?.csrf??''}/><input type="hidden" name="token" value={token}/><button disabled={!token||busy} className={button}>Confirm email</button></form><form method="post" action="/auth/email/resend" onSubmit={event=>send(event,'email/resend')}><input type="hidden" name="_token" value={session?.csrf??''}/><button disabled={busy} className={button}>Send verification link</button></form></>}
    <p className="mt-6 text-cyan-300 underline"><a href="/login">Back to sign in</a></p>
  </section></main>;
}
function App(){
  const [session,setSession]=useState(null),[error,setError]=useState(''),[busy,setBusy]=useState(false);
  const register=location.pathname==='/register';
  async function providerStart(event){event.preventDefault();if(!session||busy)return;const action=event.currentTarget.action;setBusy(true);setError('');try{const response=await fetch(action,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':session.csrf,Accept:'application/json'},body:JSON.stringify({_token:session.csrf})});const result=await response.json();if(!response.ok)throw new Error(result.error?.message??'Provider login failed.');location.assign(result.authorization_url);}catch(error){setError(error.message);setBusy(false);}}
  useEffect(()=>{fetch('/auth/session',{credentials:'same-origin',headers:{Accept:'application/json'}}).then(r=>{if(!r.ok)throw new Error('Unable to open your session.');return r.json();}).then(setSession).catch(e=>setError(e.message));},[]);
  async function send(event){
    event.preventDefault();if(!session || busy)return;setBusy(true);setError('');
    try{
      const operation=session.user?'logout':(register?'register':'login');
      const payload=Object.fromEntries(new FormData(event.currentTarget));
      const response=await fetch('/auth/'+operation,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':session.csrf,Accept:'application/json'},body:JSON.stringify(payload)});
      const result=await response.json();if(!response.ok)throw new Error(result.error?.message??'Please try again.');
      location.assign(operation==='logout'?'/login':'/dashboard');
    }catch(e){setError(e.message);}finally{setBusy(false);}
  }
  const field='w-full rounded-lg border border-slate-700 bg-slate-950 px-4 py-3 focus:outline-none focus:ring-2 focus:ring-cyan-400';
  if(['/forgot-password','/reset-password','/verify-email'].includes(location.pathname))return <AccountPanel session={session}/>;
  return <main className="min-h-screen grid place-items-center px-6 py-16"><section className="w-full max-w-md rounded-2xl border border-slate-800 bg-slate-900 p-8 shadow-xl">
    <p className="mb-6 text-xs font-semibold uppercase tracking-widest text-cyan-300">COREVIA / YOUR WORKSPACE</p>
    <h1 className="mb-3 text-3xl font-semibold tracking-tight">{session?.user?`Welcome, ${session.user.name}`:register?'Make it yours.':'Welcome back.'}</h1>
    <p className="mb-8 text-sm leading-6 text-slate-400">{session?.user?`Signed in as ${session.user.email}`:register?'Create your account to open your workspace.':'Sign in to continue building.'}</p>
    {session?.user&&session.recovery&&<p className="mb-5">{session.email_verified?'Email verified.':<a className="text-cyan-300 underline" href="/verify-email">Verify email</a>}</p>}
    {session?.user&&<p className="mb-5"><a className="text-cyan-300 underline" href="/account">Account settings</a></p>}
    {error&&<p role="alert" className="mb-5 rounded-lg bg-red-950 p-4 text-red-200">{error}</p>}
    <form method="post" action={session?.user?'/logout':register?'/register':'/login'} onSubmit={send}><input type="hidden" name="_token" value={session?.csrf??''}/>
      {!session?.user&&<>{register&&<label className="mb-5 block text-sm">Your name<input className={field} name="name" autoComplete="name" maxLength={80} required/></label>}
      <label className="mb-5 block text-sm">Email address<input className={field} name="email" type="email" autoComplete="username" maxLength={254} required/></label>
      <label className="mb-5 block text-sm">Password<input className={field} name="password" type="password" autoComplete={register?'new-password':'current-password'} minLength={register?12:undefined} required/></label>
      {!register&&<><label className="mb-5 block text-sm">Authenticator or recovery code (if enabled)<input className={field} name="code" autoComplete="one-time-code" maxLength={32}/></label><label className="mb-5 block"><input type="checkbox" name="remember" value="1"/> Remember me for up to 30 days</label></>}
      {register&&<label className="mb-5 block text-sm">Confirm password<input className={field} name="password_confirmation" type="password" autoComplete="new-password" required/><span className="text-xs text-slate-400">At least 12 characters, at most 72 bytes.</span></label>}</>}
      <button disabled={!session||busy} className="w-full rounded-lg bg-cyan-400 px-4 py-3 font-semibold text-slate-950 hover:bg-cyan-300 disabled:opacity-50">{busy?'Please wait…':session?.user?'Sign out':register?'Create account':'Sign in'}</button>
    </form>
    {!session?.user&&!register&&session?.providers?.map(provider=><form key={provider} method="post" action={'/auth/oauth/'+provider+'/start'} onSubmit={providerStart} className="mt-4"><input type="hidden" name="_token" value={session.csrf}/><button disabled={busy}>Continue with {provider}</button></form>)}
    {!session?.user&&!register&&session?.recovery&&<p className="mt-4 text-cyan-300 underline"><a href="/forgot-password">Forgot password?</a></p>}
    {!session?.user&&<p className="mt-6 text-sm text-slate-400">{register?<a className="text-cyan-300 underline" href="/login">Already registered? Sign in</a>:session?.registration&&<a className="text-cyan-300 underline" href="/register">New here? Create an account</a>}</p>}
  </section></main>;
}
createRoot(document.getElementById('root')).render(<App/>);
