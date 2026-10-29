import React, {useEffect, useState} from 'react';
import {createRoot} from 'react-dom/client';

function App(){
  const [session,setSession]=useState(null),[error,setError]=useState(''),[busy,setBusy]=useState(false);
  const register=location.pathname==='/register';
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
  return <main className="min-h-screen grid place-items-center px-6 py-16"><section className="w-full max-w-md rounded-2xl border border-slate-800 bg-slate-900 p-8 shadow-xl">
    <p className="mb-6 text-xs font-semibold uppercase tracking-widest text-cyan-300">COREVIA / YOUR WORKSPACE</p>
    <h1 className="mb-3 text-3xl font-semibold tracking-tight">{session?.user?`Welcome, ${session.user.name}`:register?'Make it yours.':'Welcome back.'}</h1>
    <p className="mb-8 text-sm leading-6 text-slate-400">{session?.user?`Signed in as ${session.user.email}`:register?'Create your account to open your workspace.':'Sign in to continue building.'}</p>
    {error&&<p role="alert" className="mb-5 rounded-lg bg-red-950 p-4 text-red-200">{error}</p>}
    <form onSubmit={send}>
      {!session?.user&&<>{register&&<label className="mb-5 block text-sm">Your name<input className={field} name="name" autoComplete="name" maxLength={80} required/></label>}
      <label className="mb-5 block text-sm">Email address<input className={field} name="email" type="email" autoComplete="username" maxLength={254} required/></label>
      <label className="mb-5 block text-sm">Password<input className={field} name="password" type="password" autoComplete={register?'new-password':'current-password'} minLength={register?12:undefined} required/></label>
      {register&&<label className="mb-5 block text-sm">Confirm password<input className={field} name="password_confirmation" type="password" autoComplete="new-password" required/><span className="text-xs text-slate-400">At least 12 characters, at most 72 bytes.</span></label>}</>}
      <button disabled={!session||busy} className="w-full rounded-lg bg-cyan-400 px-4 py-3 font-semibold text-slate-950 hover:bg-cyan-300 disabled:opacity-50">{busy?'Please wait…':session?.user?'Sign out':register?'Create account':'Sign in'}</button>
    </form>
    {!session?.user&&<p className="mt-6 text-sm text-slate-400">{register?<a className="text-cyan-300 underline" href="/login">Already registered? Sign in</a>:session?.registration&&<a className="text-cyan-300 underline" href="/register">New here? Create an account</a>}</p>}
  </section></main>;
}
createRoot(document.getElementById('root')).render(<App/>);
