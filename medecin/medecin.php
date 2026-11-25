<?php
require_once __DIR__ . '/../includes/auth.php';
Auth::require('medecin');
$me = Auth::user();
$mid = (int)$_SESSION['user_id'];
$hid = (int)Auth::hopitalId();
$hopital = queryOne('SELECT * FROM hopitaux WHERE id=?',[$hid]);

$stats=[
  'rdv_today'  => queryOne("SELECT COUNT(*) n FROM rendezvous WHERE medecin_id=? AND date_rdv=CURDATE()",[$mid])['n'],
  'en_attente' => queryOne("SELECT COUNT(*) n FROM rendezvous WHERE medecin_id=? AND statut='en_attente'",[$mid])['n'],
  'this_week'  => queryOne("SELECT COUNT(*) n FROM rendezvous WHERE medecin_id=? AND YEARWEEK(date_rdv)=YEARWEEK(CURDATE())",[$mid])['n'],
  'total'      => queryOne("SELECT COUNT(*) n FROM rendezvous WHERE medecin_id=?",[$mid])['n'],
];
$rdvs = query(
  "SELECT r.*, u.nom p_nom, u.prenom p_prenom, u.telephone p_tel, u.date_naissance p_ddn
   FROM rendezvous r JOIN users u ON u.id=r.patient_id
   WHERE r.medecin_id=? ORDER BY r.date_rdv DESC, r.heure_rdv",[$mid]);
$creneaux = query("SELECT * FROM creneaux WHERE medecin_id=? ORDER BY date_dispo DESC, heure_debut",[$mid]);
?>
<!DOCTYPE html><html lang="fr"><head>
<meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>Espace Médecin — Dr. <?=htmlspecialchars($me['nom']??'')?></title>
<link rel="stylesheet" href="../assets/style.css"/>
</head><body>
<?php include __DIR__ . '/../includes/partials/navbar.php'; ?>
<div class="page-layout">
  <?php include __DIR__ . '/../includes/partials/sidebar_medecin.php'; ?>
  <main class="main-content">

    <!-- DASHBOARD -->
    <div id="tab-dashboard" class="tab-content active">
      <div class="page-header">
        <div>
          <h1>Dr. <?=htmlspecialchars($me['prenom'].' '.$me['nom'])?></h1>
          <p class="text-muted"><?=htmlspecialchars($me['specialite']??'')?> — <?=htmlspecialchars($hopital['nom']??'')?></p>
        </div>
        <span class="role-badge role-medecin">Médecin</span>
      </div>
      <div class="stats-grid">
        <div class="stat-card green"><div class="stat-icon">📅</div><div class="stat-val"><?=$stats['rdv_today']?></div><div class="stat-label">Aujourd'hui</div></div>
        <div class="stat-card orange"><div class="stat-icon">⏳</div><div class="stat-val"><?=$stats['en_attente']?></div><div class="stat-label">En attente</div></div>
        <div class="stat-card blue"><div class="stat-icon">📆</div><div class="stat-val"><?=$stats['this_week']?></div><div class="stat-label">Cette semaine</div></div>
        <div class="stat-card purple"><div class="stat-icon">📋</div><div class="stat-val"><?=$stats['total']?></div><div class="stat-label">Total</div></div>
      </div>
      <!-- RDV du jour -->
      <div class="card mt2">
        <h2 class="card-title">Mes patients d'aujourd'hui</h2>
        <?php $today=array_filter($rdvs,fn($r)=>$r['date_rdv']===date('Y-m-d'));
        if(empty($today)): ?><p class="text-muted">Aucun RDV aujourd'hui. 🎉</p>
        <?php else: ?>
        <div class="rdv-grid">
        <?php foreach($today as $r): ?>
          <div class="rdv-card statut-<?=$r['statut']?>">
            <div class="rdv-card-top">
              <div class="rdv-date"><span class="rdv-day"><?=date('H:i',strtotime($r['heure_rdv']))?></span><span class="rdv-month">auj.</span></div>
              <div class="rdv-info">
                <h3><?=htmlspecialchars($r['p_prenom'].' '.$r['p_nom'])?></h3>
                <p class="rdv-spec"><?=htmlspecialchars($r['p_tel']??'')?></p>
                <?php if($r['p_ddn']): ?><p class="rdv-heure">Né(e) le <?=date('d/m/Y',strtotime($r['p_ddn']))?></p><?php endif; ?>
              </div>
              <span class="statut-badge statut-<?=$r['statut']?>"><?=statutLabel($r['statut'])?></span>
            </div>
            <?php if($r['motif']): ?><p class="rdv-motif"><?=htmlspecialchars($r['motif'])?></p><?php endif; ?>
            <?php if($r['statut']==='confirme'): ?>
              <button class="btn btn-secondary btn-sm mt1" onclick="updateRdv(<?=$r['id']?>,'termine')">✔ Marquer terminé</button>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- TOUS LES RDV -->
    <div id="tab-rdv" class="tab-content">
      <div class="page-header"><h1>Mes rendez-vous</h1></div>
      <div class="table-wrap">
      <table class="table">
        <thead><tr><th>Date</th><th>Heure</th><th>Patient</th><th>Motif</th><th>Statut</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach($rdvs as $r): ?>
          <tr>
            <td><?=date('d/m/Y',strtotime($r['date_rdv']))?></td>
            <td><?=date('H:i',strtotime($r['heure_rdv']))?></td>
            <td><?=htmlspecialchars($r['p_prenom'].' '.$r['p_nom'])?></td>
            <td><?=htmlspecialchars(substr($r['motif']??'—',0,50))?></td>
            <td><span class="statut-badge statut-<?=$r['statut']?>"><?=statutLabel($r['statut'])?></span></td>
            <td><?php if($r['statut']==='confirme'): ?><button class="btn btn-secondary btn-xs" onclick="updateRdv(<?=$r['id']?>,'termine')">✔</button><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    </div>

    <!-- CRÉNEAUX -->
    <div id="tab-creneaux" class="tab-content">
      <div class="page-header"><h1>Mes créneaux</h1><button class="btn btn-primary" onclick="openModal('modalCreneau')">+ Ajouter</button></div>
      <div class="table-wrap">
      <table class="table">
        <thead><tr><th>Date</th><th>Début</th><th>Fin</th><th>Statut</th></tr></thead>
        <tbody>
        <?php foreach($creneaux as $c): ?>
          <tr>
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

<div class="modal-overlay" id="modalCreneau">
  <div class="modal">
    <div class="modal-header"><h2>Ajouter un créneau</h2><button onclick="closeModal('modalCreneau')">✕</button></div>
    <form id="formCreneau">
      <div class="form-group"><label>Date *</label><input type="date" name="date_dispo" class="form-input" min="<?=date('Y-m-d')?>" required/></div>
      <div class="form-row">
        <div class="form-group"><label>Début *</label><input type="time" name="heure_debut" class="form-input" required/></div>
        <div class="form-group"><label>Fin *</label><input type="time" name="heure_fin" class="form-input" required/></div>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn btn-outline" onclick="closeModal('modalCreneau')">Annuler</button>
        <button type="button" class="btn btn-primary" onclick="saveMonCreneau()">Enregistrer</button>
      </div>
    </form>
  </div>
</div>
<div id="toast" class="toast"></div>
<script>const API_URL = '../api/api.php';</script>
<script src="../assets/app.js"></script>
<script>
const MEDECIN_ID=<?=$mid?>, HOPITAL_ID=<?=$hid?>;
function updateRdv(id,statut){
  apiPost('update_statut',{rdv_id:id,statut}).then(r=>{
    if(r.success){showToast('✓ Mis à jour');setTimeout(()=>location.reload(),900);}
    else showToast(r.error||'Erreur','error');
  });
}
function saveMonCreneau(){
  const data=Object.fromEntries(new FormData(document.getElementById('formCreneau')).entries());
  data.medecin_id=MEDECIN_ID; data.hopital_id=HOPITAL_ID;
  apiPost('save_creneau',data).then(r=>{
    if(r.success){closeModal('modalCreneau');showToast('✓ Créneau ajouté');setTimeout(()=>location.reload(),900);}
    else showToast(r.error||'Erreur','error');
  });
}
</script>
</body></html>
