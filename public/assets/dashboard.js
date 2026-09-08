let csrf='',repos=[],loader=null,transport=null;
const $=s=>document.querySelector(s), $$=s=>document.querySelectorAll(s);
function tab(name){$$('.tab').forEach(x=>x.classList.toggle('active',x.id===name));$$('aside nav button').forEach(x=>x.classList.toggle('active',x.dataset.tab===name));$('#title').textContent=({overview:'Workspace overview',repos:'Repositories',builds:'Build history',flash:'Web Flasher',settings:'Settings'})[name]||'ESPForge'}
$$('[data-tab]').forEach(b=>b.onclick=()=>tab(b.dataset.tab)); $('#new-project').onclick=()=>tab('repos');
async function api(url,opt={}){opt.headers={...(opt.headers||{}),'Content-Type':'application/json','X-CSRF-Token':csrf};const r=await fetch(url,opt),d=await r.json();if(r.status===401){location.href='index.html';throw Error('Please sign in')}if(!r.ok)throw Error(d.error||'Request failed');return d}
function escapeHtml(s){const e=document.createElement('div');e.textContent=s??'';return e.innerHTML}
async function load(){
 const session=await api('api/auth.php?action=session');csrf=session.csrf;if(!session.user){location.href='index.html';return}
 $('#username').textContent=session.user.name;$('#email').textContent=session.user.email;$('#avatar').textContent=session.user.name[0].toUpperCase();
 const [p,b,s]=await Promise.all([api('api/projects.php'),api('api/builds.php?refresh=1'),api('api/settings.php')]); repos=p.projects;renderRepos();
 $('#build-count').textContent=b.builds.length; if(b.builds.length)$('#build-list').innerHTML=b.builds.map(x=>`<p><span>${escapeHtml(x.conclusion||x.status)}</span> ${escapeHtml(x.full_name)} — build #${x.id}${x.artifact_url?` · <a href="${escapeHtml(x.artifact_url)}" target="_blank" rel="noopener">Open run</a>`:''}</p>`).join('');
 $('#github-status').textContent=s.settings.github_connected?'✓ GitHub connected':'GitHub is not connected'; $('#github-status').className=s.settings.github_connected?'connected':'';
 if(s.settings.ai_provider)$('#settings-form [name=ai_provider]').value=s.settings.ai_provider;
}
function renderRepos(){
 $('#repo-count').textContent=repos.length;const html=repos.map(r=>`<div class="repo-row"><i>⌘</i><div><b>${escapeHtml(r.full_name)}</b><small>${escapeHtml(r.framework||'Awaiting analysis')} · ${escapeHtml(r.status)}</small></div><button class="primary" onclick="build(${r.id})">Build</button></div>`).join('');
 $('#repos-list').innerHTML=html;$('#project-list').className=repos.length?'':'empty';$('#project-list').innerHTML=html||'<b>No repositories yet</b><span>Connect a GitHub repository to start building.</span>';
}
$('#repo-form').onsubmit=async e=>{e.preventDefault();const err=$('#repos .error'),button=e.submitter;err.textContent='';button.disabled=true;button.textContent='Analyzing & deploying…';try{await api('api/projects.php',{method:'POST',body:JSON.stringify(Object.fromEntries(new FormData(e.target)))});e.target.reset();await load()}catch(x){err.textContent=x.message}finally{button.disabled=false;button.textContent='Connect repository'}};
window.build=async id=>{try{await api('api/builds.php',{method:'POST',body:JSON.stringify({repo_id:id})});tab('builds');await load()}catch(x){alert(x.message)}};
$('#settings-form').onsubmit=async e=>{e.preventDefault();const err=e.target.querySelector('.error');err.textContent='';try{await api('api/settings.php',{method:'POST',body:JSON.stringify(Object.fromEntries(new FormData(e.target)))});e.target.ai_api_key.value='';err.style.color='#19a878';err.textContent='Settings saved securely.';await load()}catch(x){err.style.color='';err.textContent=x.message}};
$('#binary').onchange=e=>{const f=e.target.files[0];if(f){e.target.closest('label').querySelector('b').textContent=f.name;e.target.closest('label').querySelector('span').textContent=`${(f.size/1024).toFixed(1)} KB · ready to flash`}};
$('#connect-device').onclick=async()=>{
 const log=$('#flash-log'),button=$('#connect-device'),file=$('#binary').files[0];
 if(!file){log.textContent='Choose a .bin firmware file first.';return} if(file.size>16*1024*1024){log.textContent='Firmware exceeds the 16 MB safety limit.';return} if(!('serial'in navigator)){log.textContent='Web Serial is unavailable. Use Chrome or Edge over HTTPS.';return}
 try{
  button.disabled=true;button.textContent='Connecting…';const {Transport,ESPLoader}=await import('https://cdn.jsdelivr.net/npm/esptool-js@0.5.4/+esm');const port=await navigator.serial.requestPort();transport=new Transport(port,true);
  const terminal={clean:()=>log.textContent='',writeLine:s=>{log.textContent+=s+'\n';log.scrollTop=log.scrollHeight},write:s=>{log.textContent+=s;log.scrollTop=log.scrollHeight}};
  loader=new ESPLoader({transport,baudrate:Number($('#baud').value||460800),terminal});const chip=await loader.main();terminal.writeLine(`Connected to ${chip}. Preparing flash…`);
  const bytes=new Uint8Array(await file.arrayBuffer());let binary='';for(let i=0;i<bytes.length;i+=8192)binary+=String.fromCharCode(...bytes.subarray(i,i+8192));const address=parseInt($('#offset').value,16);
  await loader.writeFlash({fileArray:[{data:binary,address}],flashSize:'keep',eraseAll:false,compress:true,reportProgress:(i,w,t)=>{button.textContent=`Flashing ${Math.round(w/t*100)}%`}});terminal.writeLine('Flash complete. Resetting device…');await transport.setDTR(false);await transport.setRTS(true);await new Promise(r=>setTimeout(r,100));await transport.setRTS(false);button.textContent='Flash complete ✓';
 }catch(e){log.textContent+='\nFlash failed: '+e.message;button.textContent='Try again'}finally{button.disabled=false}
};
load().catch(e=>console.error(e));
