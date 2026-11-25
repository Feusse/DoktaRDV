// ── TABS
function switchTab(id){
  document.querySelectorAll('.tab-content').forEach(t=>t.classList.remove('active'));
  document.querySelectorAll('.side-link').forEach(l=>l.classList.remove('active'));
  const tab=document.getElementById('tab-'+id);
  if(tab) tab.classList.add('active');
  document.querySelectorAll(`[data-tab="${id}"]`).forEach(l=>l.classList.add('active'));
}
document.querySelectorAll('.side-link[data-tab]').forEach(l=>{
  l.addEventListener('click',e=>{e.preventDefault();switchTab(l.dataset.tab);});
});

// ── ÉCHAPPEMENT HTML
// À utiliser pour TOUTE donnée venant du serveur avant de l'insérer via innerHTML
// (noms, spécialités...) : évite l'injection de code (XSS).
function esc(v){
  return String(v ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

// ── API
// API_URL doit être défini par la page HTML avant de charger ce script
// (ex: <script>const API_URL = '../api/api.php';</script>), car le chemin
// relatif vers api.php dépend de la profondeur du dossier de la page
// appelante (admin/, medecin/, superadmin/, patient/...).
async function apiPost(action,data={}){
  const url = (typeof API_URL !== 'undefined' ? API_URL : 'api/api.php');
  try {
    const r = await fetch(url+'?action='+encodeURIComponent(action),{
      method:'POST',
      credentials:'same-origin',
      headers:{'Content-Type':'application/json'},
      body:JSON.stringify(data)
    });
    if (r.status === 401) { location.href = (typeof API_URL !== 'undefined' ? API_URL.replace(/api\/api\.php$/,'') : '') + 'login.php?error=expired'; return {error:'Session expirée'}; }
    return await r.json();
  } catch (e) {
    return {error:'Erreur réseau ou réponse invalide. Réessayez.'};
  }
}

// ── TOAST
function showToast(msg,type='success'){
  const t=document.getElementById('toast');
  if(!t) return;
  t.textContent=msg;
  t.style.background=type==='error'?'#dc2626':'#1e293b';
  t.classList.add('show');
  setTimeout(()=>t.classList.remove('show'),3500);
}

// ── MODALS
function openModal(id){ document.getElementById(id)?.classList.add('open'); }
function closeModal(id){ document.getElementById(id)?.classList.remove('open'); }
document.querySelectorAll('.modal-overlay').forEach(o=>{
  o.addEventListener('click',e=>{ if(e.target===o) o.classList.remove('open'); });
});

// ── TABLE FILTER
function filterTable(tableId,inputId,attr){
  const q=document.getElementById(inputId)?.value.toLowerCase()||'';
  document.querySelectorAll(`#${tableId} tbody tr`).forEach(r=>{
    r.style.display=(r.getAttribute(attr)||'').toLowerCase().includes(q)?'':'none';
  });
}
