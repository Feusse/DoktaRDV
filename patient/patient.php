<?php
require_once dirname(__DIR__) . '/includes/auth.php';
Auth::require('patient');
$me = Auth::user();
$uid = (int)$_SESSION['user_id'];

// Hôpital présélectionné depuis accueil
$preselectedHopital = (int)($_GET["hopital"] ?? 0);

$mesRdv = query(
  "SELECT r.*, h.nom h_nom, m.nom med_nom, m.prenom med_prenom, m.specialite
   FROM rendezvous r JOIN hopitaux h ON h.id=r.hopital_id
   JOIN users m ON m.id=r.medecin_id
   WHERE r.patient_id=? ORDER BY r.date_rdv DESC, r.heure_rdv DESC",[$uid]);

$hopitaux = query("SELECT * FROM hopitaux WHERE statut='actif' ORDER BY nom");
$notifs   = query("SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 10",[$uid]);
$nonLus   = count(array_filter($notifs,fn($n)=>!$n['lu']));
execute("UPDATE notifications SET lu=1 WHERE user_id=?",[$uid]);
?>
<!DOCTYPE html><html lang="fr"><head>
<meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>Mon Espace — DOKTA-RDV </title><link rel="stylesheet" href="../assets/style.css"/>
</head><body>
<nav class="navbar">
  <div class="nav-brand">✚ DOKTA-RDV </div>
  <div class="nav-right">
    <button class="notif-btn" id="notifBtn">🔔<?php if($nonLus>0):?><span class="notif-badge"><?=$nonLus?></span><?php endif;?></button>
    <span class="nav-user">👤 <?=htmlspecialchars($me['prenom'].' '.$me['nom'])?></span>
    <a href="../logout.php" class="btn btn-outline btn-sm">Déconnexion</a>
  </div>
</nav>

<div class="notif-panel" id="notifPanel">
  <div class="notif-panel-header"><h3>Notifications</h3><button onclick="document.getElementById('notifPanel').classList.remove('open')">✕</button></div>
  <?php if(empty($notifs)):?><p class="notif-empty">Aucune notification</p><?php endif;?>
  <?php foreach($notifs as $n): ?>
    <div class="notif-item <?=$n['lu']?'lu':'non-lu'?>"><p><?=htmlspecialchars($n['message'])?></p><small><?=date('d/m/Y H:i',strtotime($n['created_at']))?></small></div>
  <?php endforeach; ?>
</div>

<div class="page-layout">
  <?php include dirname(__DIR__) . '/includes/partials/sidebar_patient.php'; ?>
  <main class="main-content">

    <!-- MES RDV -->
    <div id="tab-rdv" class="tab-content active">
      <div class="page-header"><h1>Mes rendez-vous</h1><button class="btn btn-primary" onclick="switchTab('prendre')">+ Prendre un RDV</button></div>
      <?php if(empty($mesRdv)): ?>
        <div class="empty-state"><div class="empty-icon">📅</div><p>Aucun rendez-vous pour le moment.</p><button class="btn btn-primary" onclick="switchTab('prendre')">Prendre mon premier RDV</button></div>
      <?php else: ?>
      <div class="rdv-grid">
      <?php foreach($mesRdv as $r): ?>
        <div class="rdv-card statut-<?=$r['statut']?>">
          <div class="rdv-card-top">
            <div class="rdv-date"><span class="rdv-day"><?=date('d',strtotime($r['date_rdv']))?></span><span class="rdv-month"><?=date('M',strtotime($r['date_rdv']))?></span></div>
            <div class="rdv-info">
              <h3>Dr. <?=htmlspecialchars($r['med_prenom'].' '.$r['med_nom'])?></h3>
              <p class="rdv-spec"><?=htmlspecialchars($r['specialite'])?></p>
              <p class="rdv-heure">🕐 <?=date('H:i',strtotime($r['heure_rdv']))?></p>
              <p class="rdv-heure">🏥 <?=htmlspecialchars($r['h_nom'])?></p>
            </div>
            <span class="statut-badge statut-<?=$r['statut']?>"><?=statutLabel($r['statut'])?></span>
          </div>
          <?php if($r['motif']): ?><p class="rdv-motif"><?=htmlspecialchars($r['motif'])?></p><?php endif; ?>
          <?php if(in_array($r['statut'],['en_attente','confirme'])): ?>
            <button class="btn btn-danger btn-sm" style="margin-top:.75rem" onclick="annulerRdv(<?=$r['id']?>)">Annuler</button>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- PRENDRE RDV -->
    <div id="tab-prendre" class="tab-content">
      <div class="page-header"><h1>Prendre un rendez-vous</h1></div>
      <div class="booking-steps">

        <div class="step" id="step1">
          <h2><span class="step-num">1</span> Choisir un hôpital</h2>
          <div class="hopital-grid">
            <?php foreach($hopitaux as $h): ?>
            <div class="hopital-card" data-id="<?=$h['id']?>" data-nom="<?=htmlspecialchars($h['nom'])?>" onclick="selectHopital(this)">
              <div class="hopital-icon">🏥</div>
              <div>
                <h3><?=htmlspecialchars($h['nom'])?></h3>
                <p><?=htmlspecialchars($h['ville']??'')?></p>
                <small><?=htmlspecialchars($h['telephone']??'')?></small>
              </div>
              <span class="check-icon">✓</span>
            </div>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="step" id="step2" style="display:none">
          <h2><span class="step-num">2</span> Choisir un médecin</h2>
          <div id="medecinListBooking" class="medecin-grid"></div>
        </div>

        <div class="step" id="step3" style="display:none">
          <h2><span class="step-num">3</span> Choisir un créneau</h2>
          <div class="creneau-filters">
            <input type="date" id="filterDate" min="<?=date('Y-m-d')?>" onchange="loadCreneaux()" class="form-input" style="max-width:200px"/>
          </div>
          <div id="creneauxList" class="creneaux-grid mt1"></div>
        </div>

        <div class="step" id="step4" style="display:none">
          <h2><span class="step-num">4</span> Confirmer</h2>
          <div class="confirm-box">
            <div class="confirm-summary" id="confirmSummary"></div>
            <div class="form-group"><label>Motif de consultation</label>
              <textarea id="motifRdv" class="form-input" rows="3" placeholder="Décrivez brièvement votre motif..."></textarea>
            </div>
            <div class="confirm-actions">
              <button class="btn btn-outline" onclick="resetBooking()">← Recommencer</button>
              <button class="btn btn-primary" onclick="confirmerRdv()">✓ Confirmer le RDV</button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- PROFIL -->
    <div id="tab-profil" class="tab-content">
      <div class="page-header"><h1>Mon profil</h1></div>
      <div class="profil-card">
        <form id="formProfil">
          <div class="form-row">
            <div class="form-group"><label>Prénom</label><input type="text" name="prenom" value="<?=htmlspecialchars($me['prenom'])?>" class="form-input"/></div>
            <div class="form-group"><label>Nom</label><input type="text" name="nom" value="<?=htmlspecialchars($me['nom'])?>" class="form-input"/></div>
          </div>
          <div class="form-group"><label>Email</label><input type="email" name="email" value="<?=htmlspecialchars($me['email'])?>" class="form-input"/></div>
          <div class="form-row">
            <div class="form-group"><label>Téléphone</label><input type="tel" name="telephone" value="<?=htmlspecialchars($me['telephone']??'')?>" class="form-input"/></div>
            <div class="form-group"><label>Date de naissance</label><input type="date" name="date_naissance" value="<?=htmlspecialchars($me['date_naissance']??'')?>" class="form-input"/></div>
          </div>
          <hr/><h3 style="margin:1rem 0 .5rem">Changer l'e-mail ou le mot de passe</h3>
          <div class="form-group"><label>Mot de passe actuel <small>(requis pour changer l'e-mail ou le mot de passe)</small></label><input type="password" name="current_password" class="form-input" autocomplete="current-password"/></div>
          <div class="form-row">
            <div class="form-group"><label>Nouveau mot de passe</label><input type="password" name="new_password" class="form-input" minlength="10" autocomplete="new-password" placeholder="10 car. min., lettres + chiffres (vide = inchangé)"/></div>
            <div class="form-group"><label>Confirmer</label><input type="password" name="confirm_password" class="form-input" autocomplete="new-password"/></div>
          </div>
          <button type="button" class="btn btn-primary" onclick="saveProfil()">Enregistrer</button>
        </form>
        <div id="profilMsg" class="alert" style="display:none;margin-top:1rem"></div>
      </div>
    </div>

  </main>
</div>
<div id="toast" class="toast"></div>
<script>const API_URL = '../api/api.php';</script>
<script src="../assets/app.js"></script>
<script>
const PATIENT_ID=<?=$uid?>;
const PRESELECTED_HOPITAL=<?=$preselectedHopital?>;
let booking={hopitalId:null,hopitalNom:null,medecinId:null,medecinNom:null,medecinSpec:null,creneauId:null,date:null,heure:null};

function selectHopital(card){
  document.querySelectorAll('.hopital-card').forEach(c=>c.classList.remove('selected'));
  card.classList.add('selected');
  booking.hopitalId=card.dataset.id; booking.hopitalNom=card.dataset.nom;
  loadMedecins();
}
async function loadMedecins(){
  const res=await fetch(`../api/api.php?action=get_medecins&hopital_id=${encodeURIComponent(booking.hopitalId)}`,{credentials:'same-origin'}).then(r=>r.json());
  const list=document.getElementById('medecinListBooking');
  list.innerHTML='';
  if(!Array.isArray(res)||!res.length){list.innerHTML='<p class="text-muted">Aucun médecin disponible.</p>';return;}
  res.forEach(m=>{
    // Toute donnée venant du serveur est échappée (esc) avant d'être insérée en HTML.
    list.innerHTML+=`<div class="medecin-card" data-id="${esc(m.id)}" data-nom="Dr. ${esc(m.prenom)} ${esc(m.nom)}" data-spec="${esc(m.specialite||'')}" onclick="selectMedecin(this)">
      <div class="med-avatar">${esc((m.prenom||'')[0])}${esc((m.nom||'')[0])}</div>
      <div class="med-info"><h3>Dr. ${esc(m.prenom)} ${esc(m.nom)}</h3><p>${esc(m.specialite||'')}</p></div>
      <span class="check-icon">✓</span></div>`;
  });
  document.getElementById('step2').style.display='block';
  document.getElementById('step2').scrollIntoView({behavior:'smooth',block:'start'});
}
function selectMedecin(card){
  document.querySelectorAll('#medecinListBooking .medecin-card').forEach(c=>c.classList.remove('selected'));
  card.classList.add('selected');
  booking.medecinId=card.dataset.id; booking.medecinNom=card.dataset.nom; booking.medecinSpec=card.dataset.spec;
  const tom=new Date(); tom.setDate(tom.getDate()+1);
  document.getElementById('filterDate').value=tom.toISOString().split('T')[0];
  document.getElementById('step3').style.display='block';
  loadCreneaux();
  document.getElementById('step3').scrollIntoView({behavior:'smooth',block:'start'});
}
async function loadCreneaux(){
  const date=document.getElementById('filterDate').value;
  if(!booking.medecinId||!date) return;
  booking.date=date;
  const creneaux=await fetch(`../api/api.php?action=get_creneaux&medecin_id=${encodeURIComponent(booking.medecinId)}&hopital_id=${encodeURIComponent(booking.hopitalId)}&date=${encodeURIComponent(date)}`,{credentials:'same-origin'}).then(r=>r.json());
  const list=document.getElementById('creneauxList');
  list.innerHTML='';
  if(!Array.isArray(creneaux)||!creneaux.length){list.innerHTML='<p class="creneau-empty">Aucun créneau disponible. Essayez une autre date.</p>';return;}
  creneaux.forEach(c=>{
    const btn=document.createElement('button'); btn.className='creneau-btn';
    btn.textContent=c.heure_debut.slice(0,5)+' – '+c.heure_fin.slice(0,5);
    btn.onclick=()=>{
      document.querySelectorAll('.creneau-btn').forEach(b=>b.classList.remove('active'));
      btn.classList.add('active'); booking.creneauId=c.id; booking.heure=c.heure_debut;
      showStep4();
    };
    list.appendChild(btn);
  });
}
function showStep4(){
  const d=new Date(booking.date+'T00:00:00');
  const ds=d.toLocaleDateString('fr-FR',{weekday:'long',year:'numeric',month:'long',day:'numeric'});
  document.getElementById('confirmSummary').innerHTML=`
    <div class="sum-row"><span class="sum-label">Hôpital</span><span class="sum-val">${esc(booking.hopitalNom)}</span></div>
    <div class="sum-row"><span class="sum-label">Médecin</span><span class="sum-val">${esc(booking.medecinNom)} — ${esc(booking.medecinSpec)}</span></div>
    <div class="sum-row"><span class="sum-label">Date</span><span class="sum-val">${ds}</span></div>
    <div class="sum-row"><span class="sum-label">Heure</span><span class="sum-val">${booking.heure?booking.heure.slice(0,5):''}</span></div>`;
  document.getElementById('step4').style.display='block';
  document.getElementById('step4').scrollIntoView({behavior:'smooth',block:'start'});
}
async function confirmerRdv(){
  if(!booking.creneauId){showToast('Sélectionnez un créneau','error');return;}
  const motif=document.getElementById('motifRdv').value;
  const r=await apiPost('book_rdv',{creneau_id:booking.creneauId,medecin_id:booking.medecinId,hopital_id:booking.hopitalId,motif});
  if(r.success){showToast('✅ RDV pris !');resetBooking();switchTab('rdv');setTimeout(()=>location.reload(),1500);}
  else showToast(r.error||'Erreur','error');
}
function resetBooking(){
  booking={hopitalId:null,hopitalNom:null,medecinId:null,medecinNom:null,medecinSpec:null,creneauId:null,date:null,heure:null};
  ['step2','step3','step4'].forEach(id=>document.getElementById(id).style.display='none');
  document.querySelectorAll('.hopital-card,.medecin-card,.creneau-btn').forEach(el=>el.classList.remove('selected','active'));
  document.getElementById('creneauxList').innerHTML='';
  document.getElementById('medecinListBooking').innerHTML='';
}
async function annulerRdv(id){
  if(!confirm('Annuler ce rendez-vous ?')) return;
  const r=await apiPost('cancel_rdv',{rdv_id:id});
  if(r.success){showToast('RDV annulé');setTimeout(()=>location.reload(),1000);}
  else showToast(r.error||'Erreur','error');
}
async function saveProfil(){
  const data=Object.fromEntries(new FormData(document.getElementById('formProfil')).entries());
  const r=await apiPost('update_profil',data);
  const msg=document.getElementById('profilMsg');
  msg.className='alert '+(r.success?'alert-success':'alert-error');
  msg.textContent=r.success?'✓ Profil mis à jour.':(r.error||'Erreur');
  msg.style.display='block';
  setTimeout(()=>msg.style.display='none',4000);
}
document.getElementById('notifBtn').addEventListener('click',()=>document.getElementById('notifPanel').classList.toggle('open'));
document.addEventListener('click',e=>{
  const p=document.getElementById('notifPanel');
  if(p&&!p.contains(e.target)&&!document.getElementById('notifBtn').contains(e.target)) p.classList.remove('open');
});
</script>
</body></html>
<script>
// Présélection hôpital si venu depuis accueil
if (PRESELECTED_HOPITAL) {
  switchTab('prendre');
  // Attendre que le DOM soit prêt puis cliquer sur la carte hôpital
  window.addEventListener('DOMContentLoaded', () => {
    const card = document.querySelector(`.hopital-card[data-id="${PRESELECTED_HOPITAL}"]`);
    if (card) {
      card.click();
      card.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
  });
}
</script>
