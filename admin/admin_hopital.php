<?php
require_once __DIR__ . '/../includes/auth.php';
Auth::require('admin_hopital');
$me = Auth::user();
$hid = (int)Auth::hopitalId();
$hopital = queryOne('SELECT * FROM hopitaux WHERE id=?',[$hid]);

$stats=[
  'medecins'    => queryOne("SELECT COUNT(*) n FROM users WHERE hopital_id=? AND role='medecin'",[$hid])['n'],
  'patients_rdv'=> queryOne("SELECT COUNT(DISTINCT patient_id) n FROM rendezvous WHERE hopital_id=?",[$hid])['n'],
  'rdv_total'   => queryOne("SELECT COUNT(*) n FROM rendezvous WHERE hopital_id=?",[$hid])['n'],
  'en_attente'  => queryOne("SELECT COUNT(*) n FROM rendezvous WHERE hopital_id=? AND statut='en_attente'",[$hid])['n'],
  'rdv_today'   => queryOne("SELECT COUNT(*) n FROM rendezvous WHERE hopital_id=? AND date_rdv=CURDATE()",[$hid])['n'],
  'confirmes'   => queryOne("SELECT COUNT(*) n FROM rendezvous WHERE hopital_id=? AND statut='confirme'",[$hid])['n'],
];
$medecins = query("SELECT id,prenom,nom,email,telephone,specialite,statut FROM users WHERE hopital_id=? AND role='medecin' ORDER BY nom",[$hid]);
// Colonnes strictement nécessaires (jamais le hash du mot de passe) ; seuls les patients suivis dans CET hôpital.
$patients = query(
  "SELECT u.id,u.prenom,u.nom,u.email,u.telephone, COUNT(r.id) nb_rdv FROM users u
   JOIN rendezvous r ON r.patient_id=u.id WHERE r.hopital_id=?
   GROUP BY u.id,u.prenom,u.nom,u.email,u.telephone ORDER BY u.nom",[$hid]);
$rdvs = query(
  "SELECT r.*, u.nom p_nom, u.prenom p_prenom, u.telephone p_tel,
          m.nom med_nom, m.prenom med_prenom, m.specialite
   FROM rendezvous r JOIN users u ON u.id=r.patient_id JOIN users m ON m.id=r.medecin_id
   WHERE r.hopital_id=? ORDER BY r.date_rdv DESC, r.heure_rdv DESC",[$hid]);
$creneaux = query(
  "SELECT c.*, u.nom med_nom, u.prenom med_prenom FROM creneaux c JOIN users u ON u.id=c.medecin_id
   WHERE c.hopital_id=? ORDER BY c.date_dispo DESC, c.heure_debut",[$hid]);
?>
<!DOCTYPE html><html lang="fr"><head>
<meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>Admin Hôpital — <?=htmlspecialchars($hopital['nom']??'')?></title>
<link rel="stylesheet" href="../assets/style.css"/>
</head><body>
<?php include __DIR__ . '/../includes/partials/navbar.php'; ?>
<div class="page-layout">
  <?php include __DIR__ . '/../includes/partials/sidebar_admin.php'; ?>
  <main class="main-content">

    <!-- DASHBOARD -->
    <div id="tab-dashboard" class="tab-content active">
      <div class="page-header"><h1><?=htmlspecialchars($hopital['nom']??'')?></h1><span class="role-badge role-admin">Admin Hôpital</span></div>
      <div class="stats-grid">
        <div class="stat-card blue"><div class="stat-icon">👨‍⚕️</div><div class="stat-val"><?=$stats['medecins']?></div><div class="stat-label">Médecins</div></div>
        <div class="stat-card green"><div class="stat-icon">📅</div><div class="stat-val"><?=$stats['rdv_today']?></div><div class="stat-label">RDV aujourd'hui</div></div>
        <div class="stat-card orange"><div class="stat-icon">⏳</div><div class="stat-val"><?=$stats['en_attente']?></div><div class="stat-label">En attente</div></div>
        <div class="stat-card purple"><div class="stat-icon">✅</div><div class="stat-val"><?=$stats['confirmes']?></div><div class="stat-label">Confirmés</div></div>
        <div class="stat-card teal"><div class="stat-icon">👥</div><div class="stat-val"><?=$stats['patients_rdv']?></div><div class="stat-label">Patients suivis</div></div>
        <div class="stat-card indigo"><div class="stat-icon">📋</div><div class="stat-val"><?=$stats['rdv_total']?></div><div class="stat-label">Total RDV</div></div>
      </div>
      <!-- RDV du jour -->
      <div class="card mt2">
        <h2 class="card-title">RDV d'aujourd'hui</h2>
        <?php $today=array_filter($rdvs,fn($r)=>$r['date_rdv']===date('Y-m-d'));
        if(empty($today)): ?><p class="text-muted">Aucun RDV aujourd'hui.</p>
        <?php else: ?>
        <table class="table">
          <thead><tr><th>Heure</th><th>Patient</th><th>Médecin</th><th>Statut</th><th>Actions</th></tr></thead>
          <tbody>
          <?php foreach($today as $r): ?>
            <tr>
              <td><strong><?=date('H:i',strtotime($r['heure_rdv']))?></strong></td>
              <td><?=htmlspecialchars($r['p_prenom'].' '.$r['p_nom'])?></td>
              <td>Dr. <?=htmlspecialchars($r['med_prenom'].' '.$r['med_nom'])?> <small>(<?=htmlspecialchars($r['specialite'])?>)</small></td>
              <td><span class="statut-badge statut-<?=$r['statut']?>"><?=statutLabel($r['statut'])?></span></td>
              <td class="actions-td">
                <?php if($r['statut']==='en_attente'): ?>
                  <button class="btn btn-success btn-xs" onclick="updateRdv(<?=$r['id']?>,'confirme')">✓ Confirmer</button>
                  <button class="btn btn-danger btn-xs" onclick="updateRdv(<?=$r['id']?>,'annule')">✕</button>
                <?php elseif($r['statut']==='confirme'): ?>
                  <button class="btn btn-secondary btn-xs" onclick="updateRdv(<?=$r['id']?>,'termine')">✔ Terminé</button>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
    </div>

    <!-- MÉDECINS -->
    <div id="tab-medecins" class="tab-content">
      <div class="page-header"><h1>Médecins</h1><button class="btn btn-primary" onclick="newMedecin()">+ Ajouter un médecin</button></div>
      <div class="medecin-grid">
        <?php foreach($medecins as $m): ?>
        <div class="medecin-card static">
          <div class="med-avatar"><?=strtoupper(substr($m['prenom'],0,1).substr($m['nom'],0,1))?></div>
          <div class="med-info">
            <h3>Dr. <?=htmlspecialchars($m['prenom'].' '.$m['nom'])?></h3>
            <p><?=htmlspecialchars($m['specialite']??'—')?></p>
            <small><?=htmlspecialchars($m['email'])?></small>
          </div>
          <span class="statut-badge statut-<?=$m['statut']?>"><?=statutLabel($m['statut'])?></span>
          <div class="med-actions">
            <button class="btn btn-outline btn-xs" onclick="editMedecin(<?=json_attr($m)?>)">✏ Modifier</button>
            <button class="btn btn-danger btn-xs" onclick="deleteMedecin(<?=$m['id']?>)">🗑</button>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- PATIENTS -->
    <div id="tab-patients" class="tab-content">
      <div class="page-header"><h1>Patients</h1>
        <button class="btn btn-primary" onclick="openModal('modalPatient')">+ Ajouter un patient</button>
      </div>
      <div class="table-wrap">
      <table class="table">
        <thead><tr><th>Nom</th><th>Email</th><th>Tél.</th><th>RDV dans cet hôpital</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach($patients as $p): ?>
          <tr>
            <td><?=htmlspecialchars($p['prenom'].' '.$p['nom'])?></td>
            <td><?=htmlspecialchars($p['email'])?></td>
            <td><?=htmlspecialchars($p['telephone']??'-')?></td>
            <td><span class="badge-count"><?=$p["nb_rdv"]?></span></td>
            <td class="actions-td"><button class="btn btn-danger btn-xs" data-nom="<?=e($p['prenom'].' '.$p['nom'])?>" onclick="deletePatient(<?=(int)$p['id']?>,this.dataset.nom)">🗑 Supprimer</button></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    </div>

    <!-- RDV -->
    <div id="tab-rdv" class="tab-content">
      <div class="page-header"><h1>Rendez-vous</h1>
        <div class="filters">
          <select id="fStatut" class="form-input form-input-sm" onchange="filterRdvTable()">
            <option value="">Tous</option><option value="en_attente">En attente</option>
            <option value="confirme">Confirmé</option><option value="annule">Annulé</option><option value="termine">Terminé</option>
          </select>
          <input type="date" id="fDate" class="form-input form-input-sm" onchange="filterRdvTable()"/>
          <input type="text" id="fSearch" class="form-input form-input-sm" placeholder="Patient..." oninput="filterRdvTable()"/>
        </div>
      </div>
      <div class="table-wrap">
      <table class="table" id="rdvTableAdmin">
        <thead><tr><th>Date</th><th>Heure</th><th>Patient</th><th>Médecin</th><th>Motif</th><th>Statut</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach($rdvs as $r): ?>
          <tr data-statut="<?=$r['statut']?>" data-date="<?=$r['date_rdv']?>" data-patient="<?=e(mb_strtolower($r['p_prenom'].' '.$r['p_nom']))?>">
            <td><?=date('d/m/Y',strtotime($r['date_rdv']))?></td>
            <td><?=date('H:i',strtotime($r['heure_rdv']))?></td>
            <td><?=htmlspecialchars($r['p_prenom'].' '.$r['p_nom'])?><br><small><?=htmlspecialchars($r['p_tel']??'')?></small></td>
            <td>Dr. <?=htmlspecialchars($r['med_prenom'].' '.$r['med_nom'])?></td>
            <td><?=htmlspecialchars(substr($r['motif']??'—',0,40))?></td>
            <td><span class="statut-badge statut-<?=$r['statut']?>"><?=statutLabel($r['statut'])?></span></td>
            <td class="actions-td">
              <?php if($r['statut']==='en_attente'): ?>
                <button class="btn btn-success btn-xs" onclick="updateRdv(<?=$r['id']?>,'confirme')">✓</button>
                <button class="btn btn-danger btn-xs" onclick="updateRdv(<?=$r['id']?>,'annule')">✕</button>
              <?php elseif($r['statut']==='confirme'): ?>
                <button class="btn btn-secondary btn-xs" onclick="updateRdv(<?=$r['id']?>,'termine')">✔</button>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    </div>

    <!-- CRÉNEAUX -->
    <div id="tab-creneaux" class="tab-content">
      <div class="page-header"><h1>Créneaux disponibles</h1><button class="btn btn-primary" onclick="openModal('modalCreneau')">+ Ajouter créneau</button></div>
      <div class="table-wrap">
      <table class="table">
        <thead><tr><th>Médecin</th><th>Date</th><th>Début</th><th>Fin</th><th>Statut</th></tr></thead>
        <tbody>
        <?php foreach($creneaux as $c): ?>
          <tr>
            <td>Dr. <?=htmlspecialchars($c['med_prenom'].' '.$c['med_nom'])?></td>
            <td><?=date('d/m/Y',strtotime($c['date_dispo']))?></td>
            <td><?=date('H:i',strtotime($c['heure_debut']))?></td>
            <td><?=date('H:i',strtotime($c['heure_fin']))?></td>
            <td><?=$c['disponible']?'<span class="statut-badge statut-confirme">Disponible</span>':'<span class="statut-badge statut-annule">Pris</span>'?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    </div>

  </main>
</div>

<!-- Modal médecin -->
<div class="modal-overlay" id="modalMedecin">
  <div class="modal">
    <div class="modal-header"><h2 id="titleMedecin">Ajouter un médecin</h2><button onclick="closeModal('modalMedecin')">✕</button></div>
    <form id="formMedecin">
      <input type="hidden" id="med_id" name="id"/>
      <div class="form-row">
        <div class="form-group"><label>Prénom *</label><input type="text" name="prenom" id="med_prenom" class="form-input" required/></div>
        <div class="form-group"><label>Nom *</label><input type="text" name="nom" id="med_nom" class="form-input" required/></div>
      </div>
      <div class="form-group"><label>Spécialité *</label>
        <select name="specialite" id="med_spec" class="form-input">
          <option>Médecine générale</option><option>Cardiologie</option><option>Dermatologie</option>
          <option>Pédiatrie</option><option>Gynécologie</option><option>Neurologie</option>
          <option>Ophtalmologie</option><option>Orthopédie</option><option>Psychiatrie</option><option>Radiologie</option>
        </select>
      </div>
      <div class="form-row">
        <div class="form-group"><label>Email *</label><input type="email" name="email" id="med_email" class="form-input" required/></div>
        <div class="form-group"><label>Téléphone</label><input type="tel" name="telephone" id="med_tel" class="form-input"/></div>
      </div>
      <div class="form-group" id="pwdGroup"><label>Mot de passe * <small>(10 car. min., lettres + chiffres)</small></label><input type="password" name="password" id="med_pwd" class="form-input" minlength="10" autocomplete="new-password"/></div>
      <div class="modal-actions">
        <button type="button" class="btn btn-outline" onclick="closeModal('modalMedecin')">Annuler</button>
        <button type="button" class="btn btn-primary" onclick="saveMedecin()">Enregistrer</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal patient -->
<div class="modal-overlay" id="modalPatient">
  <div class="modal">
    <div class="modal-header"><h2>Ajouter un patient</h2><button onclick="closeModal('modalPatient')">✕</button></div>
    <form id="formPatient">
      <div class="form-row">
        <div class="form-group"><label>Prénom *</label><input type="text" name="prenom" class="form-input" required/></div>
        <div class="form-group"><label>Nom *</label><input type="text" name="nom" class="form-input" required/></div>
      </div>
      <div class="form-group"><label>Email *</label><input type="email" name="email" class="form-input" required/></div>
      <div class="form-row">
        <div class="form-group"><label>Téléphone</label><input type="tel" name="telephone" class="form-input"/></div>
        <div class="form-group"><label>Date naissance</label><input type="date" name="date_naissance" class="form-input"/></div>
      </div>
      <div class="form-group"><label>Mot de passe *</label><input type="password" name="password" class="form-input" placeholder="Min. 10 caractères, lettres + chiffres" minlength="10" autocomplete="new-password" required/></div>
      <div class="modal-actions">
        <button type="button" class="btn btn-outline" onclick="closeModal('modalPatient')">Annuler</button>
        <button type="button" class="btn btn-primary" onclick="savePatient()">Enregistrer</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal créneau -->
<div class="modal-overlay" id="modalCreneau">
  <div class="modal">
    <div class="modal-header"><h2>Ajouter un créneau</h2><button onclick="closeModal('modalCreneau')">✕</button></div>
    <form id="formCreneau">
      <div class="form-group"><label>Médecin *</label>
        <select name="medecin_id" class="form-input" required>
          <?php foreach($medecins as $m): ?>
            <option value="<?=$m['id']?>">Dr. <?=htmlspecialchars($m['prenom'].' '.$m['nom'])?> (<?=htmlspecialchars($m['specialite']??'')?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group"><label>Date *</label><input type="date" name="date_dispo" class="form-input" min="<?=date('Y-m-d')?>" required/></div>
      <div class="form-row">
        <div class="form-group"><label>Heure début *</label><input type="time" name="heure_debut" class="form-input" required/></div>
        <div class="form-group"><label>Heure fin *</label><input type="time" name="heure_fin" class="form-input" required/></div>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn btn-outline" onclick="closeModal('modalCreneau')">Annuler</button>
        <button type="button" class="btn btn-primary" onclick="saveCreneau()">Enregistrer</button>
      </div>
    </form>
  </div>
</div>

<div id="toast" class="toast"></div>
<script>const API_URL = '../api/api.php';</script>
<script src="../assets/app.js"></script>
<script>
const HOPITAL_ID = <?=$hid?>;
function updateRdv(id,statut){
  if(!confirm('Confirmer ?')) return;
  apiPost('update_statut',{rdv_id:id,statut}).then(r=>{
    if(r.success){showToast('✓ Statut mis à jour');setTimeout(()=>location.reload(),900);}
    else showToast(r.error||'Erreur','error');
  });
}
function filterRdvTable(){
  const s=document.getElementById('fStatut').value;
  const d=document.getElementById('fDate').value;
  const q=document.getElementById('fSearch').value.toLowerCase();
  document.querySelectorAll('#rdvTableAdmin tbody tr').forEach(r=>{
    const ms=!s||r.dataset.statut===s;
    const md=!d||r.dataset.date===d;
    const mq=!q||r.dataset.patient.includes(q);
    r.style.display=ms&&md&&mq?'':'none';
  });
}
function newMedecin(){
  // Réinitialise le formulaire : sans cela, après une modification, "Ajouter" réutilisait l'ancien id.
  document.getElementById('formMedecin').reset();
  document.getElementById('med_id').value='';
  document.getElementById('titleMedecin').textContent='Ajouter un médecin';
  document.getElementById('pwdGroup').style.display='';
  openModal('modalMedecin');
}
function editMedecin(m){
  document.getElementById('titleMedecin').textContent='Modifier le médecin';
  document.getElementById('med_id').value=m.id;
  document.getElementById('med_prenom').value=m.prenom;
  document.getElementById('med_nom').value=m.nom;
  document.getElementById('med_spec').value=m.specialite||'Médecine générale';
  document.getElementById('med_email').value=m.email;
  document.getElementById('med_tel').value=m.telephone||'';
  document.getElementById('pwdGroup').style.display='none';
  openModal('modalMedecin');
}
function saveMedecin(){
  const data=Object.fromEntries(new FormData(document.getElementById('formMedecin')).entries());
  apiPost('save_medecin',data).then(r=>{
    if(r.success){closeModal('modalMedecin');showToast('✓ Médecin enregistré');setTimeout(()=>location.reload(),900);}
    else showToast(r.error||'Erreur','error');
  });
}
function deleteMedecin(id){
  if(!confirm('Supprimer ce médecin ?')) return;
  apiPost('delete_medecin',{id}).then(r=>{
    if(r.success){showToast('Supprimé');setTimeout(()=>location.reload(),900);}
    else showToast(r.error||'Erreur','error');
  });
}
function savePatient(){
  const data=Object.fromEntries(new FormData(document.getElementById('formPatient')).entries());
  apiPost('save_patient',data).then(r=>{
    if(r.success){closeModal('modalPatient');showToast('✓ Patient ajouté');setTimeout(()=>location.reload(),900);}
    else showToast(r.error||'Erreur','error');
  });
}
function deletePatient(id, nom){
  if(!confirm(`Supprimer le patient "${nom}" ? Tous ses RDV seront supprimés.`)) return;
  apiPost("delete_patient",{id}).then(r=>{
    if(r.success){showToast("Patient supprimé");setTimeout(()=>location.reload(),900);}
    else showToast(r.error||"Erreur","error");
  });
}
function saveCreneau(){
  const data=Object.fromEntries(new FormData(document.getElementById('formCreneau')).entries());
  data.hopital_id=HOPITAL_ID;
  apiPost('save_creneau',data).then(r=>{
    if(r.success){closeModal('modalCreneau');showToast('✓ Créneau ajouté');setTimeout(()=>location.reload(),900);}
    else showToast(r.error||'Erreur','error');
  });
}
</script>
</body></html>
