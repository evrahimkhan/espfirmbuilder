let csrf='';
let mode='login';
const dialog=document.querySelector('#auth');
const form=document.querySelector('#auth-form');
const nameField=document.querySelector('.name-field');
const emailField=form.elements.email;
const passwordField=form.elements.password;
const modeButton=document.querySelector('.mode:not(.forgot):not(.resend)');
const forgotButton=document.querySelector('.forgot');
const resendButton=document.querySelector('.resend');
const errorBox=document.querySelector('.auth-error');
const resetToken=new URLSearchParams(location.search).get('reset_token');
const oauthError=new URLSearchParams(location.search).get('oauth_error');
const authMessage=new URLSearchParams(location.search).get('auth_message');

function setMode(next){
 mode=next;errorBox.textContent='';errorBox.style.color='';
 nameField.style.display=mode==='register'?'block':'none';
 emailField.style.display=mode==='reset'?'none':'';emailField.required=mode!=='reset';
 passwordField.style.display=['forgot','verify'].includes(mode)?'none':'';passwordField.required=!['forgot','verify'].includes(mode);
 modeButton.hidden=mode==='reset';forgotButton.hidden=mode==='register'||mode==='reset'||mode==='verify';resendButton.hidden=mode==='register'||mode==='reset'||mode==='forgot';
 modeButton.innerHTML=mode==='register'?'Already registered? <b>Sign in</b>':'New here? <b>Create an account</b>';
 form.querySelector('button[type=submit]').textContent=mode==='forgot'?'Send reset link →':mode==='verify'?'Send verification link →':mode==='reset'?'Set new password →':'Continue →';
 document.querySelector('.auth-head h2').textContent=mode==='forgot'?'Reset your password':mode==='verify'?'Verify your email':mode==='reset'?'Choose a new password':'Welcome to ESPForge';
}

document.querySelectorAll('[data-open-auth]').forEach(button=>button.onclick=()=>dialog.showModal());
document.querySelector('.close').onclick=()=>dialog.close();
dialog.addEventListener('click',event=>{const rect=dialog.getBoundingClientRect();if(event.clientX<rect.left||event.clientX>rect.right||event.clientY<rect.top||event.clientY>rect.bottom)dialog.close()});
modeButton.onclick=()=>setMode(mode==='register'?'login':'register');
forgotButton.onclick=()=>setMode('forgot');
resendButton.onclick=()=>setMode('verify');

fetch('api/auth.php?action=session').then(response=>response.json()).then(data=>{
 csrf=data.csrf;
 if(data.user)document.querySelectorAll('[data-open-auth]').forEach(button=>{button.textContent='Open dashboard →';button.onclick=()=>location.href='dashboard.html'});
}).catch(()=>{});

form.onsubmit=async event=>{
 event.preventDefault();errorBox.textContent='';
 const data=Object.fromEntries(new FormData(form));
 let action=mode==='register'?'register':mode==='forgot'?'request_password_reset':mode==='verify'?'resend_verification':mode==='reset'?'reset_password':'login';
 if(mode==='reset')data.token=resetToken;
 try{
  const response=await fetch(`api/auth.php?action=${action}`,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify(data)});
  const output=await response.json();if(!response.ok)throw new Error(output.error||'Request failed.');
  if(mode==='forgot'||mode==='verify'||mode==='reset'||output.verification_required){
   errorBox.textContent=output.message;errorBox.style.color='#19a878';
   history.replaceState(null,'',location.pathname);setTimeout(()=>{setMode('login');errorBox.style.color=''},1800);return;
  }
  location.href='dashboard.html';
 }catch(error){errorBox.style.color='';errorBox.textContent=error.message}
};

if(resetToken){history.replaceState(null,'',location.pathname);setMode('reset');dialog.showModal()}
if(authMessage){history.replaceState(null,'',location.pathname);setMode('login');dialog.showModal();queueMicrotask(()=>{errorBox.style.color=authMessage.startsWith('Email verified')?'#19a878':'';errorBox.textContent=authMessage})}
if(oauthError){history.replaceState(null,'',location.pathname);setMode('login');dialog.showModal();queueMicrotask(()=>errorBox.textContent=oauthError)}
