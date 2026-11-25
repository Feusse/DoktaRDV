<?php
require_once __DIR__ . '/../includes/auth.php';
Auth::require('super_admin');
$me = Auth::user();

// Stats globales
$stats = [
  'hopitaux'       => queryOne('SELECT COUNT(*) n FROM hopitaux')['n'],
  'actifs'         => queryOne("SELECT COUNT(*) n FROM hopitaux WHERE statut='actif'")['n'],
  'en_attente_h'   => queryOne("SELECT COUNT(*) n FROM hopitaux WHERE statut='en_attente'")['n'],
  'medecins'       => queryOne("SELECT COUNT(*) n FROM users WHERE role='medecin'")['n'],
  'patients'       => queryOne("SELECT COUNT(*) n FROM users WHERE role='patient'")['n'],
  'total_rdv'      => queryOne('SELECT COUNT(*) n FROM rendezvous')['n'],
  'rdv_ce_mois'    => queryOne("SELECT COUNT(*) n FROM rendezvous WHERE MONTH(date_rdv)=MONTH(CURDATE()) AND YEAR(date_rdv)=YEAR(CURDATE())")['n'],
  'admins'         => queryOne("SELECT COUNT(*) n FROM users WHERE role='admin_hopital'")['n'],
];

$hopitaux = query(
  'SELECT h.*, COUNT(DISTINCT u_m.id) nb_med, COUNT(DISTINCT u_p.id) nb_patients,
          COUNT(DISTINCT r.id) nb_rdv,
          ua.prenom admin_prenom, ua.nom admin_nom, ua.email admin_email
   FROM hopitaux h
   LEFT JOIN users u_m ON u_m.hopital_id=h.id AND u_m.role="medecin"
   LEFT JOIN users u_p ON u_p.hopital_id=h.id AND u_p.role="patient"
   LEFT JOIN rendezvous r ON r.hopital_id=h.id
   LEFT JOIN users ua ON ua.hopital_id=h.id AND ua.role="admin_hopital"
   GROUP BY h.id ORDER BY h.created_at DESC'
);

$rdvParMois = query(
  "SELECT DATE_FORMAT(date_rdv,'%Y-%m') mois, COUNT(*) total,
          SUM(statut='confirme') confirmes, SUM(statut='annule') annules
   FROM rendezvous WHERE date_rdv >= DATE_SUB(CURDATE(),INTERVAL 6 MONTH)
   GROUP BY mois ORDER BY mois"
);
?>
<!DOCTYPE html><html lang="fr"><head>
<meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>Super Admin — DOKTA-RDV</title>
<link rel="stylesheet" href="../assets/style.css"/>
</head><body>
<?php include __DIR__ . '/../includes/partials/navbar.php'; ?>
<div class="page-layout">
  <?php include __DIR__ . '/../includes/partials/sidebar_superadmin.php'; ?>
  <main class="main-content">

    <!-- TAB: DASHBOARD -->
    <div id="tab-dashboard" class="tab-content active">
      <div class="page-header"><h1>Vue globale de la plateforme</h1><span class="role-badge role-super">Super Admin</span></div>
      <div class="stats-grid">
        <div class="stat-card blue"><div class="stat-icon">🏥</div><div class="stat-val"><?=$stats['hopitaux']?></div><div class="stat-label">Hôpitaux total</div></div>
        <div class="stat-card green"><div class="stat-icon">✅</div><div class="stat-val"><?=$stats['actifs']?></div><div class="stat-label">Hôpitaux actifs</div></div>
        <div class="stat-card orange"><div class="stat-icon">⏳</div><div class="stat-val"><?=$stats['en_attente_h']?></div><div class="stat-label">En attente</div></div>
        <div class="stat-card purple"><div class="stat-icon">👨‍⚕️</div><div class="stat-val"><?=$stats['medecins']?></div><div class="stat-label">Médecins</div></div>
        <div class="stat-card teal"><div class="stat-icon">👥</div><div class="stat-val"><?=$stats['patients']?></div><div class="stat-label">Patients</div></div>
        <div class="stat-card indigo"><div class="stat-icon">📅</div><div class="stat-val"><?=$stats['total_rdv']?></div><div class="stat-label">RDV total</div></div>
      </div>
      <div class="card mt2">
        <h2 class="card-title">Évolution des RDV (6 derniers mois)</h2>
        <canvas id="chartRdv" height="80"></canvas>
      </div>
    </div>

    <!-- TAB: HÔPITAUX -->
    <div id="tab-hopitaux" class="tab-content">
      <div class="page-header">
        <h1>Gestion des hôpitaux</h1>
        <button class="btn btn-primary" onclick="newHopital()">+ Enregistrer un hôpital</button>
      </div>
      <div class="table-wrap">
      <table class="table">
        <thead><tr><th>Hôpital</th><th>Ville</th><th>Admin</th><th>Médecins</th><th>RDV</th><th>Statut</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach($hopitaux as $h): ?>
          <tr>
            <td><strong><?=htmlspecialchars($h['nom'])?></strong><br><small><?=htmlspecialchars($h['email']??'')?></small></td>
            <td><?=htmlspecialchars($h['ville']??'-')?></td>
            <td><?=$h['admin_prenom']?htmlspecialchars($h['admin_prenom'].' '.$h['admin_nom']):'<span class="text-muted">Aucun</span>'?><br><small><?=htmlspecialchars($h['admin_email']??'')?></small></td>
            <td><span class="badge-count"><?=$h['nb_med']?></span></td>
            <td><span class="badge-count"><?=$h['nb_rdv']?></span></td>
            <td><span class="statut-badge statut-<?=$h['statut']?>"><?=statutLabel($h['statut'])?></span></td>
            <td class="actions-td">
              <?php if($h['statut']==='en_attente'): ?>
                <button class="btn btn-success btn-xs" onclick="hopitalAction(<?=$h['id']?>,'activer')">✓ Activer</button>
              <?php elseif($h['statut']==='actif'): ?>
                <button class="btn btn-warning btn-xs" onclick="hopitalAction(<?=$h['id']?>,'suspendre')">⏸ Suspendre</button>
              <?php else: ?>
                <button class="btn btn-success btn-xs" onclick="hopitalAction(<?=$h['id']?>,'activer')">▶ Réactiver</button>
              <?php endif; ?>
              <button class="btn btn-outline btn-xs" onclick="editHopital(<?=json_attr(['id'=>$h['id'],'nom'=>$h['nom'],'ville'=>$h['ville'],'telephone'=>$h['telephone'],'email'=>$h['email'],'adresse'=>$h['adresse'],'image_url'=>$h['image_url']])?>)">✏</button>
              <button class="btn btn-danger btn-xs" onclick="deleteHopital(<?=$h['id']?>)">🗑</button>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    </div>

    <!-- TAB: TOUS LES UTILISATEURS -->
    <div id="tab-users" class="tab-content">
      <div class="page-header"><h1>Tous les utilisateurs</h1>
        <input type="text" id="searchUser" class="form-input form-input-sm" placeholder="Rechercher..." oninput="filterTable('userTable','searchUser','data-name')"/>
      </div>
      <div class="table-wrap">
      <table class="table" id="userTable">
        <thead><tr><th>Nom</th><th>Email</th><th>Rôle</th><th>Hôpital</th><th>Statut</th><th>Inscrit le</th></tr></thead>
        <tbody>
        <?php
        $users = query('SELECT u.id,u.prenom,u.nom,u.email,u.role,u.statut,u.created_at, h.nom hopital_nom FROM users u LEFT JOIN hopitaux h ON h.id=u.hopital_id ORDER BY u.role, u.created_at DESC');
        foreach($users as $u): ?>
          <tr data-name="<?=e(mb_strtolower($u['prenom'].' '.$u['nom'].' '.$u['email']))?>">
            <td><?=htmlspecialchars($u['prenom'].' '.$u['nom'])?></td>
            <td><?=htmlspecialchars($u['email'])?></td>
            <td><span class="role-badge role-<?=str_replace('_','-',$u['role'])?>"><?=roleLabel($u['role'])?></span></td>
            <td><?=htmlspecialchars($u['hopital_nom']??'—')?></td>
            <td><span class="statut-badge statut-<?=$u['statut']?>"><?=statutLabel($u['statut'])?></span></td>
            <td><?=date('d/m/Y',strtotime($u['created_at']))?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    </div>

  </main>
</div>

<!-- Modal: Ajouter hôpital -->
<div class="modal-overlay" id="modalHopital">
  <div class="modal modal-lg">
    <div class="modal-header"><h2 id="modalHopitalTitle">Enregistrer un hôpital</h2><button onclick="closeModal('modalHopital')">✕</button></div>
    <form id="formHopital">
      <input type="hidden" id="h_id" name="id"/>
      <div class="form-section-title">Informations hôpital</div>
      <div class="form-row">
        <div class="form-group full"><label>Nom de l'hôpital *</label><input type="text" name="nom" id="h_nom" class="form-input" required/></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label>Ville</label><input type="text" name="ville" id="h_ville" class="form-input"/></div>
        <div class="form-group"><label>Téléphone</label><input type="tel" name="telephone" id="h_tel" class="form-input"/></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label>Email</label><input type="email" name="email" id="h_email" class="form-input"/></div>
        <div class="form-group"><label>Adresse</label><input type="text" name="adresse" id="h_adresse" class="form-input"/></div>
      <div class="form-group full"><label>Image de couverture (URL)</label><input type="url" name="image_url" id="h_image_url" class="form-input" placeholder="https://...jpg ou laisser vide pour image automatique"/></div>
      </div>
      <div class="form-section-title" id="adminSection">Compte administrateur de l'hôpital</div>
      <div id="adminFields">
        <div class="form-row">
          <div class="form-group"><label>Prénom admin *</label><input type="text" name="admin_prenom" id="h_admin_prenom" class="form-input"/></div>
          <div class="form-group"><label>Nom admin *</label><input type="text" name="admin_nom" id="h_admin_nom" class="form-input"/></div>
        </div>
        <div class="form-row">
          <div class="form-group"><label>Email admin *</label><input type="email" name="admin_email" id="h_admin_email" class="form-input"/></div>
          <div class="form-group"><label>Mot de passe *</label><input type="password" name="admin_password" id="h_admin_pwd" class="form-input" placeholder="Min. 10 caractères, lettres + chiffres" minlength="10" autocomplete="new-password"/></div>
        </div>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn btn-outline" onclick="closeModal('modalHopital')">Annuler</button>
        <button type="button" class="btn btn-primary" onclick="saveHopital()">Enregistrer</button>
      </div>
    </form>
  </div>
</div>

<div id="toast" class="toast"></div>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>const API_URL = '../api/api.php';</script>
<script src="../assets/app.js"></script>
<script>
const chartData = <?=json_script($rdvParMois)?>;
if(document.getElementById('chartRdv')) {
  new Chart(document.getElementById('chartRdv'),{
    type:'bar',
    data:{
      labels:chartData.map(d=>d.mois),
      datasets:[
        {label:'Total',data:chartData.map(d=>+d.total),backgroundColor:'rgba(37,99,235,.5)',borderColor:'#2563eb',borderWidth:1},
        {label:'Confirmés',data:chartData.map(d=>+d.confirmes),backgroundColor:'rgba(22,163,74,.5)',borderColor:'#16a34a',borderWidth:1},
        {label:'Annulés',data:chartData.map(d=>+d.annules),backgroundColor:'rgba(220,38,38,.4)',borderColor:'#dc2626',borderWidth:1},
      ]
    },
    options:{responsive:true,plugins:{legend:{position:'top'}},scales:{y:{beginAtZero:true}}}
  });
}
function hopitalAction(id,action){
  if(!confirm('Confirmer cette action ?')) return;
  apiPost('hopital_action',{id,action}).then(r=>{
    if(r.success){showToast('✓ Mis à jour');setTimeout(()=>location.reload(),900);}
    else showToast(r.error||'Erreur','error');
  });
}
function newHopital(){
  // Réinitialise le formulaire : sans cela, après une modification, "Enregistrer" réutilisait l'ancien id.
  document.getElementById('formHopital').reset();
  document.getElementById('h_id').value='';
  document.getElementById('modalHopitalTitle').textContent='Enregistrer un hôpital';
  document.getElementById('adminSection').style.display='';
  document.getElementById('adminFields').style.display='';
  openModal('modalHopital');
}
function editHopital(h){
  document.getElementById('modalHopitalTitle').textContent='Modifier l\'hôpital';
  document.getElementById('h_id').value=h.id;
  document.getElementById('h_nom').value=h.nom;
  document.getElementById('h_ville').value=h.ville||'';
  document.getElementById('h_tel').value=h.telephone||'';
  document.getElementById('h_email').value=h.email||'';
  document.getElementById('h_adresse').value=h.adresse||'';
  document.getElementById('h_image_url').value=h.image_url||'';
  document.getElementById('adminSection').style.display='none';
  document.getElementById('adminFields').style.display='none';
  openModal('modalHopital');
}
function saveHopital(){
  const f=document.getElementById('formHopital');
  const data=Object.fromEntries(new FormData(f).entries());
  apiPost('save_hopital',data).then(r=>{
    if(r.success){closeModal('modalHopital');showToast('✓ Hôpital enregistré');setTimeout(()=>location.reload(),1000);}
    else showToast(r.error||'Erreur','error');
  });
}
function deleteHopital(id){
  if(!confirm('Supprimer cet hôpital ? Toutes ses données seront perdues.')) return;
  apiPost('delete_hopital',{id}).then(r=>{
    if(r.success){showToast('Supprimé');setTimeout(()=>location.reload(),900);}
    else showToast(r.error||'Erreur','error');
  });
}
</script>
</body></html>
<?php function roleLabel($r){return match($r){'super_admin'=>'Super Admin','admin_hopital'=>'Admin Hôpital','medecin'=>'Médecin','patient'=>'Patient',default=>$r};}?>
