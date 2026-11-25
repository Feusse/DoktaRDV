<?php
require_once __DIR__ . '/../includes/auth.php';
Auth::require('patient');

$uid = (int)$_SESSION['user_id'];
$hid = (int)($_SESSION['hopital_id_actif'] ?? 0);

// Sécurité : si pas d'hôpital en session → retour accueil
if (!$hid) { header('Location: ../index.php'); exit; }

$me      = Auth::user();
$hopital = queryOne('SELECT * FROM hopitaux WHERE id=? AND statut="actif"', [$hid]);
if (!$hopital) { header('Location: ../index.php'); exit; }

$couleur    = safe_color($hopital['couleur'] ?? null);   // #RRGGBB validé (évite l'injection CSS/JS)
$hopitalImg = safe_image_url($hopital['image_url'] ?? '');
$couleurRgb = sscanf($couleur, '#%02x%02x%02x');

// Données de l'hôpital
$medecins = query(
    "SELECT u.id,u.prenom,u.nom,u.specialite,u.telephone, COUNT(c.id) as nb_creneaux
     FROM users u
     LEFT JOIN creneaux c ON c.medecin_id=u.id AND c.hopital_id=? AND c.disponible=1 AND c.date_dispo >= CURDATE()
     WHERE u.hopital_id=? AND u.role='medecin' AND u.statut='actif'
     GROUP BY u.id,u.prenom,u.nom,u.specialite,u.telephone ORDER BY u.specialite, u.nom",
    [$hid, $hid]
);

$specialites = array_unique(array_column($medecins, 'specialite'));

$mesRdv = query(
    "SELECT r.*, m.nom med_nom, m.prenom med_prenom, m.specialite
     FROM rendezvous r
     JOIN users m ON m.id=r.medecin_id
     WHERE r.patient_id=? AND r.hopital_id=?
     ORDER BY r.date_rdv DESC, r.heure_rdv",
    [$uid, $hid]
);

$stats = [
    'rdv_actifs'  => queryOne("SELECT COUNT(*) n FROM rendezvous WHERE patient_id=? AND hopital_id=? AND statut IN ('en_attente','confirme')", [$uid, $hid])['n'],
    'rdv_total'   => queryOne("SELECT COUNT(*) n FROM rendezvous WHERE patient_id=? AND hopital_id=?", [$uid, $hid])['n'],
    'nb_medecins' => count($medecins),
];

$notifs  = query("SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 8", [$uid]);
$nonLus  = count(array_filter($notifs, fn($n) => !$n['lu']));
execute("UPDATE notifications SET lu=1 WHERE user_id=?", [$uid]);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title><?= htmlspecialchars($hopital['nom']) ?> — Mon Espace Patient</title>
  <link rel="stylesheet" href="../assets/style.css"/>
  <style>
    :root {
      --hp: <?= $couleur ?>;
      --hp-light: <?= $couleur ?>22;
      --hp-mid:   <?= $couleur ?>66;
    }

    /* ── NAVBAR BRANDÉE ── */
    .hp-navbar {
      position:sticky; top:0; z-index:50;
      background:var(--hp); color:white;
      padding:0 2rem; height:60px;
      display:flex; align-items:center; justify-content:space-between;
      box-shadow:0 2px 12px rgba(0,0,0,.15);
    }
    .hp-nav-brand { font-family:'Space Grotesk',sans-serif; font-weight:800; font-size:1rem; display:flex; align-items:center; gap:.6rem; }
    .hp-nav-brand span { opacity:.7; font-weight:400; font-size:.8rem; }
    .hp-nav-right { display:flex; align-items:center; gap:1rem; }
    .hp-nav-user { font-size:.82rem; opacity:.9; }
    .hp-notif { background:none; border:none; color:white; font-size:1.1rem; cursor:pointer; position:relative; padding:.3rem; }
    .hp-notif-badge { position:absolute; top:-2px; right:-2px; background:#ef4444; color:white; border-radius:50%; width:16px; height:16px; font-size:.6rem; display:flex; align-items:center; justify-content:center; }
    .hp-btn-out { background:rgba(255,255,255,.2); border:1px solid rgba(255,255,255,.3); color:white; padding:.35rem .9rem; border-radius:8px; font-size:.78rem; font-weight:600; cursor:pointer; text-decoration:none; transition:.15s; }
    .hp-btn-out:hover { background:rgba(255,255,255,.3); }

    /* ── HERO HÔPITAL ── */
    .hp-hero {
      background:var(--hp);
      color:white; padding:2.5rem 2rem;
      position:relative; overflow:hidden;
    }
    .hp-hero::before {
      content:''; position:absolute; inset:0;
      background:<?= $hopitalImg ? "url('" . e($hopitalImg) . "') center/cover" : 'none' ?>;
      opacity:.08;
    }
    .hp-hero::after {
      content:''; position:absolute; bottom:0; left:0; right:0; height:60px;
      background:linear-gradient(to bottom, transparent, #f8fafc);
    }
    .hp-hero-inner { max-width:1200px; margin:0 auto; position:relative; z-index:1; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:1.5rem; }
    .hp-hero-left h1 { font-family:'Space Grotesk',sans-serif; font-size:clamp(1.4rem,3vw,2rem); font-weight:800; margin-bottom:.35rem; }
    .hp-hero-left p  { opacity:.85; font-size:.9rem; }
    .hp-hero-info { display:flex; gap:1.5rem; flex-wrap:wrap; }
    .hp-info-chip { background:rgba(255,255,255,.15); border:1px solid rgba(255,255,255,.25); backdrop-filter:blur(8px); border-radius:8px; padding:.4rem .85rem; font-size:.78rem; display:flex; align-items:center; gap:.4rem; }

    /* ── LAYOUT ── */
    .hp-layout { display:flex; max-width:1200px; margin:0 auto; gap:0; min-height:calc(100vh - 60px); }
    .hp-sidebar {
      width:200px; flex-shrink:0; background:white;
      border-right:1px solid var(--border);
      padding:1.5rem 0; position:sticky; top:60px;
      height:calc(100vh - 60px); overflow-y:auto;
    }
    .hp-side-link {
      display:flex; align-items:center; gap:.6rem;
      padding:.65rem 1.25rem; font-size:.85rem; color:var(--muted);
      cursor:pointer; border-left:3px solid transparent; transition:all .15s;
    }
    .hp-side-link:hover { color:var(--text); background:#f8fafc; }
    .hp-side-link.active { color:var(--hp); border-left-color:var(--hp); background:var(--hp-light); font-weight:600; }
    .hp-main { flex:1; padding:2rem; overflow-x:auto; }

    .tab-content { display:none; }
    .tab-content.active { display:block; }

    /* ── STATS ── */
    .hp-stats { display:grid; grid-template-columns:repeat(3,1fr); gap:1rem; margin-bottom:2rem; }
    .hp-stat { background:white; border-radius:14px; padding:1.25rem; border:1px solid var(--border); box-shadow:var(--shadow); display:flex; align-items:center; gap:1rem; }
    .hp-stat-icon { width:48px; height:48px; border-radius:12px; background:var(--hp-light); display:flex; align-items:center; justify-content:center; font-size:1.4rem; flex-shrink:0; }
    .hp-stat-val { font-family:'Space Grotesk',sans-serif; font-size:1.6rem; font-weight:800; color:var(--hp); line-height:1; }
    .hp-stat-label { font-size:.75rem; color:var(--muted); margin-top:.15rem; }

    /* ── MÉDECINS ── */
    .spec-filter { display:flex; flex-wrap:wrap; gap:.5rem; margin-bottom:1.5rem; }
    .spec-btn { padding:.35rem .9rem; border-radius:20px; border:1.5px solid var(--border); background:white; font-size:.78rem; cursor:pointer; transition:all .15s; font-weight:500; }
    .spec-btn:hover, .spec-btn.active { background:var(--hp); color:white; border-color:var(--hp); }

    .medecins-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(260px,1fr)); gap:1.25rem; }
    .med-card {
      background:white; border-radius:16px; border:1.5px solid var(--border);
      overflow:hidden; transition:all .25s; cursor:pointer;
    }
    .med-card:hover { border-color:var(--hp); box-shadow:0 4px 20px rgba(0,0,0,.08); transform:translateY(-3px); }
    .med-card-top { background:var(--hp-light); padding:1.5rem; display:flex; align-items:center; gap:1rem; }
    .med-avatar-lg { width:56px; height:56px; border-radius:50%; background:var(--hp); color:white; font-size:1.2rem; font-weight:800; display:flex; align-items:center; justify-content:center; flex-shrink:0; border:3px solid white; box-shadow:0 2px 8px rgba(0,0,0,.15); }
    .med-card-name { font-weight:700; font-size:.95rem; }
    .med-card-spec { font-size:.78rem; color:var(--hp); font-weight:600; }
    .med-card-body { padding:1rem 1.25rem; }
    .med-card-dispo { font-size:.78rem; color:var(--muted); margin-bottom:1rem; display:flex; align-items:center; gap:.35rem; }
    .dispo-dot { width:8px; height:8px; border-radius:50%; flex-shrink:0; }
    .dispo-dot.green { background:#22c55e; }
    .dispo-dot.red   { background:#ef4444; }
    .med-card-btn { width:100%; padding:.55rem; background:var(--hp); color:white; border:none; border-radius:8px; font-size:.82rem; font-weight:600; cursor:pointer; transition:.15s; }
    .med-card-btn:hover { filter:brightness(1.1); }
    .med-card-btn:disabled { background:var(--border); color:var(--muted); cursor:not-allowed; }

    /* ── BOOKING MODAL ── */
    .booking-modal { background:white; border-radius:20px; padding:0; width:560px; max-width:95vw; overflow:hidden; }
    .booking-modal-header { background:var(--hp); color:white; padding:1.5rem 2rem; display:flex; justify-content:space-between; align-items:center; }
    .booking-modal-header h2 { font-size:1rem; font-weight:700; }
    .booking-modal-header button { background:rgba(255,255,255,.2); border:none; color:white; width:28px; height:28px; border-radius:50%; cursor:pointer; font-size:.9rem; }
    .booking-modal-body { padding:1.75rem 2rem; }
    .booking-steps-indicator { display:flex; gap:0; margin-bottom:1.75rem; }
    .bsi { flex:1; display:flex; flex-direction:column; align-items:center; gap:.3rem; position:relative; }
    .bsi::after { content:''; position:absolute; top:14px; left:50%; width:100%; height:2px; background:var(--border); z-index:0; }
    .bsi:last-child::after { display:none; }
    .bsi-dot { width:28px; height:28px; border-radius:50%; background:var(--border); color:var(--muted); font-size:.75rem; font-weight:700; display:flex; align-items:center; justify-content:center; position:relative; z-index:1; transition:.2s; }
    .bsi.active .bsi-dot, .bsi.done .bsi-dot { background:var(--hp); color:white; }
    .bsi-label { font-size:.65rem; color:var(--muted); font-weight:600; text-transform:uppercase; letter-spacing:.05em; }
    .bsi.active .bsi-label { color:var(--hp); }

    .booking-step { display:none; }
    .booking-step.active { display:block; }

    .creneaux-date-nav { display:flex; align-items:center; gap:.75rem; margin-bottom:1rem; }
    .date-nav-btn { background:none; border:1.5px solid var(--border); border-radius:8px; padding:.4rem .7rem; cursor:pointer; font-size:1rem; transition:.15s; }
    .date-nav-btn:hover { border-color:var(--hp); }
    .creneaux-date-input { flex:1; padding:.5rem; border:1.5px solid var(--border); border-radius:8px; font-size:.85rem; outline:none; transition:.2s; }
    .creneaux-date-input:focus { border-color:var(--hp); }
    .creneaux-slots { display:flex; flex-wrap:wrap; gap:.5rem; min-height:60px; }
    .slot-btn { padding:.5rem 1.1rem; border:1.5px solid var(--border); border-radius:8px; background:white; cursor:pointer; font-size:.85rem; font-weight:500; transition:all .15s; }
    .slot-btn:hover  { border-color:var(--hp); color:var(--hp); }
    .slot-btn.active { background:var(--hp); color:white; border-color:var(--hp); }
    .slots-empty { font-size:.85rem; color:var(--muted); padding:.5rem 0; }

    .confirm-recap { background:var(--hp-light); border-radius:12px; padding:1.25rem; margin-bottom:1.25rem; border:1px solid var(--hp-mid); }
    .recap-row { display:flex; gap:.75rem; margin-bottom:.5rem; font-size:.87rem; align-items:center; }
    .recap-row:last-child { margin-bottom:0; }
    .recap-icon { font-size:1rem; flex-shrink:0; }
    .recap-label { color:var(--muted); font-size:.78rem; min-width:70px; }
    .recap-val { font-weight:600; color:var(--text); }

    .booking-nav { display:flex; gap:.75rem; justify-content:flex-end; margin-top:1.5rem; }

    /* ── RDV CARDS ── */
    .rdv-list { display:flex; flex-direction:column; gap:1rem; }
    .rdv-item {
      background:white; border-radius:14px; border:1.5px solid var(--border);
      padding:1.25rem; display:flex; align-items:center; gap:1.25rem;
      border-left:4px solid var(--border); transition:border-color .2s;
    }
    .rdv-item.statut-confirme   { border-left-color:#22c55e; }
    .rdv-item.statut-en_attente { border-left-color:#f59e0b; }
    .rdv-item.statut-annule     { border-left-color:#ef4444; opacity:.65; }
    .rdv-item.statut-termine    { border-left-color:#94a3b8; }
    .rdv-date-block { background:var(--hp-light); border-radius:10px; padding:.6rem .9rem; text-align:center; min-width:52px; flex-shrink:0; }
    .rdv-date-d { font-family:'Space Grotesk',sans-serif; font-size:1.5rem; font-weight:800; color:var(--hp); line-height:1; }
    .rdv-date-m { font-size:.65rem; text-transform:uppercase; color:var(--hp); font-weight:700; }
    .rdv-info { flex:1; }
    .rdv-med { font-weight:700; font-size:.92rem; }
    .rdv-spec { font-size:.78rem; color:var(--hp); font-weight:600; margin-bottom:.15rem; }
    .rdv-heure { font-size:.8rem; color:var(--muted); display:flex; align-items:center; gap:.3rem; }
    .rdv-motif { font-size:.78rem; color:var(--muted); margin-top:.2rem; font-style:italic; }
    .rdv-actions { display:flex; flex-direction:column; gap:.4rem; }

    .statut-b { font-size:.72rem; padding:.2rem .55rem; border-radius:20px; font-weight:600; white-space:nowrap; }
    .statut-b.en_attente { background:#fef9c3; color:#854d0e; }
    .statut-b.confirme   { background:#dcfce7; color:#166534; }
    .statut-b.annule     { background:#fee2e2; color:#991b1b; }
    .statut-b.termine    { background:#f1f5f9; color:#64748b; }

    /* ── NOTIFS PANEL ── */
    .notif-panel { position:fixed; top:60px; right:0; width:320px; max-height:calc(100vh - 60px); background:white; border-left:1px solid var(--border); box-shadow:-4px 0 20px rgba(0,0,0,.1); z-index:40; overflow-y:auto; transform:translateX(100%); transition:transform .25s; }
    .notif-panel.open { transform:none; }
    .notif-panel-header { display:flex; justify-content:space-between; align-items:center; padding:1rem 1.25rem; border-bottom:1px solid var(--border); background:var(--hp); color:white; position:sticky; top:0; }
    .notif-panel-header h3 { font-size:.9rem; font-weight:700; }
    .notif-panel-header button { background:none; border:none; color:white; cursor:pointer; font-size:1rem; }
    .notif-item { padding:.85rem 1.25rem; border-bottom:1px solid var(--border); }
    .notif-item p { font-size:.82rem; line-height:1.5; }
    .notif-item small { font-size:.7rem; color:var(--muted); }
    .notif-item.non-lu { background:#f0fdf4; border-left:3px solid var(--hp); }
    .notif-empty { padding:2rem; text-align:center; color:var(--muted); font-size:.85rem; }

    /* ── PROFIL ── */
    .profil-section { max-width:560px; }
    .profil-card { background:white; border-radius:16px; padding:2rem; border:1px solid var(--border); }
    .profil-card h3 { font-size:.9rem; font-weight:700; margin-bottom:1.25rem; color:var(--hp); display:flex; align-items:center; gap:.5rem; }

    /* ── EMPTY ── */
    .empty-rdv { text-align:center; padding:3rem; background:white; border-radius:16px; border:1px dashed var(--border); }
    .empty-rdv .ei { font-size:3rem; margin-bottom:1rem; }
    .empty-rdv p { color:var(--muted); margin-bottom:1.25rem; }

    .toast { position:fixed; bottom:1.5rem; right:1.5rem; background:#0f172a; color:white; padding:.75rem 1.25rem; border-radius:10px; font-size:.85rem; z-index:200; transform:translateY(20px); opacity:0; transition:all .3s; pointer-events:none; }
    .toast.show { transform:none; opacity:1; }

    @media(max-width:768px){
      .hp-stats{grid-template-columns:1fr}
      .hp-layout{flex-direction:column}
      .hp-sidebar{width:100%;position:static;height:auto}
      .hp-side-link{border-left:none;border-bottom:3px solid transparent;display:inline-flex;padding:.5rem 1rem}
      .hp-sidebar nav{display:flex;overflow-x:auto;padding:.5rem}
      .medecins-grid{grid-template-columns:1fr}
      .booking-modal{width:100%}
    }
  </style>
</head>
<body>

<!-- NAVBAR BRANDÉE -->
<nav class="hp-navbar">
  <div class="hp-nav-brand">
    ✚ <?= htmlspecialchars($hopital['nom']) ?>
    <span>/ Espace patient</span>
  </div>
  <div class="hp-nav-right">
    <button class="hp-notif" id="notifBtn" onclick="document.getElementById('notifPanel').classList.toggle('open')">
      🔔<?php if ($nonLus > 0): ?><span class="hp-notif-badge"><?= $nonLus ?></span><?php endif; ?>
    </button>
    <span class="hp-nav-user">👤 <?= htmlspecialchars($me['prenom']) ?></span>
    <a href="../logout.php" class="hp-btn-out">Quitter / Changer d'hôpital</a>
  </div>
</nav>

<!-- PANEL NOTIFICATIONS -->
<div class="notif-panel" id="notifPanel">
  <div class="notif-panel-header">
    <h3>🔔 Notifications</h3>
    <button onclick="document.getElementById('notifPanel').classList.remove('open')">✕</button>
  </div>
  <?php if (empty($notifs)): ?>
    <div class="notif-empty">Aucune notification</div>
  <?php else: ?>
    <?php foreach ($notifs as $n): ?>
      <div class="notif-item <?= $n['lu'] ? '' : 'non-lu' ?>">
        <p><?= htmlspecialchars($n['message']) ?></p>
        <small><?= date('d/m/Y H:i', strtotime($n['created_at'])) ?></small>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<!-- HERO -->
<div class="hp-hero">
  <div class="hp-hero-inner">
    <div class="hp-hero-left">
      <h1>Bonjour <?= htmlspecialchars($me['prenom']) ?> 👋</h1>
      <p><?= htmlspecialchars($hopital['nom']) ?> · <?= htmlspecialchars($hopital['ville'] ?? '') ?></p>
    </div>
    <div class="hp-hero-info">
      <div class="hp-info-chip">👨‍⚕️ <?= count($medecins) ?> médecin<?= count($medecins) > 1 ? 's' : '' ?></div>
      <div class="hp-info-chip">📅 <?= $stats['rdv_actifs'] ?> RDV actif<?= $stats['rdv_actifs'] > 1 ? 's' : '' ?></div>
      <?php if ($hopital['horaires']): ?>
      <div class="hp-info-chip">🕐 <?= htmlspecialchars($hopital['horaires']) ?></div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- LAYOUT PRINCIPAL -->
<div class="hp-layout">

  <!-- SIDEBAR -->
  <aside class="hp-sidebar">
    <nav>
      <div class="hp-side-link active" data-tab="medecins">👨‍⚕️ Médecins</div>
      <div class="hp-side-link" data-tab="rdv">📅 Mes RDV</div>
      <div class="hp-side-link" data-tab="profil">👤 Profil</div>
    </nav>
  </aside>

  <!-- CONTENU -->
  <main class="hp-main">

    <!-- ─── MÉDECINS ─── -->
    <div id="tab-medecins" class="tab-content active">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem;flex-wrap:wrap;gap:1rem">
        <div>
          <h1 style="font-family:'Space Grotesk',sans-serif;font-size:1.3rem;font-weight:800">Nos médecins</h1>
          <p style="color:var(--muted);font-size:.85rem">Sélectionnez un médecin pour prendre rendez-vous</p>
        </div>
      </div>

      <!-- Filtre par spécialité -->
      <div class="spec-filter">
        <button class="spec-btn active" onclick="filterSpec('', this)">Tous</button>
        <?php foreach ($specialites as $spec): ?>
          <button class="spec-btn" onclick="filterSpec('<?= htmlspecialchars($spec) ?>', this)"><?= htmlspecialchars($spec) ?></button>
        <?php endforeach; ?>
      </div>

      <!-- Grille médecins -->
      <?php if (empty($medecins)): ?>
        <div class="empty-rdv">
          <div class="ei">👨‍⚕️</div>
          <p>Aucun médecin disponible pour le moment.</p>
        </div>
      <?php else: ?>
      <div class="medecins-grid" id="medGrid">
        <?php foreach ($medecins as $med): ?>
          <div class="med-card" data-spec="<?= htmlspecialchars($med['specialite'] ?? '') ?>">
            <div class="med-card-top">
              <div class="med-avatar-lg"><?= strtoupper(substr($med['prenom'], 0, 1) . substr($med['nom'], 0, 1)) ?></div>
              <div>
                <div class="med-card-name">Dr. <?= htmlspecialchars($med['prenom'] . ' ' . $med['nom']) ?></div>
                <div class="med-card-spec"><?= htmlspecialchars($med['specialite'] ?? '—') ?></div>
              </div>
            </div>
            <div class="med-card-body">
              <div class="med-card-dispo">
                <span class="dispo-dot <?= $med['nb_creneaux'] > 0 ? 'green' : 'red' ?>"></span>
                <?= $med['nb_creneaux'] > 0
                    ? $med['nb_creneaux'] . ' créneau' . ($med['nb_creneaux'] > 1 ? 'x' : '') . ' disponible' . ($med['nb_creneaux'] > 1 ? 's' : '')
                    : 'Aucun créneau disponible' ?>
              </div>
              <?php if ($med['telephone']): ?>
                <div style="font-size:.78rem;color:var(--muted);margin-bottom:.75rem">📞 <?= htmlspecialchars($med['telephone']) ?></div>
              <?php endif; ?>
              <button class="med-card-btn"
                <?= $med['nb_creneaux'] == 0 ? 'disabled' : '' ?>
                data-nom="<?= e('Dr. ' . $med['prenom'] . ' ' . $med['nom']) ?>" data-spec="<?= e($med['specialite'] ?? '') ?>"
                onclick="openBooking(<?= (int)$med['id'] ?>, this.dataset.nom, this.dataset.spec)">
                <?= $med['nb_creneaux'] > 0 ? '📅 Prendre rendez-vous' : '❌ Indisponible' ?>
              </button>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- ─── MES RDV ─── -->
    <div id="tab-rdv" class="tab-content">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem">
        <h1 style="font-family:'Space Grotesk',sans-serif;font-size:1.3rem;font-weight:800">Mes rendez-vous</h1>
        <button class="btn btn-primary btn-sm" style="background:var(--hp)" onclick="switchTab('medecins')">+ Nouveau RDV</button>
      </div>

      <!-- Stats rapides -->
      <div class="hp-stats" style="margin-bottom:1.5rem">
        <div class="hp-stat">
          <div class="hp-stat-icon">📅</div>
          <div><div class="hp-stat-val"><?= $stats['rdv_actifs'] ?></div><div class="hp-stat-label">RDV à venir</div></div>
        </div>
        <div class="hp-stat">
          <div class="hp-stat-icon">✅</div>
          <div><div class="hp-stat-val"><?= $stats['rdv_total'] ?></div><div class="hp-stat-label">Total RDV</div></div>
        </div>
        <div class="hp-stat">
          <div class="hp-stat-icon">🏥</div>
          <div><div class="hp-stat-val"><?= $stats['nb_medecins'] ?></div><div class="hp-stat-label">Médecins dispo</div></div>
        </div>
      </div>

      <?php if (empty($mesRdv)): ?>
        <div class="empty-rdv">
          <div class="ei">📭</div>
          <p>Vous n'avez aucun rendez-vous dans cet hôpital.</p>
          <button class="btn btn-primary" style="background:var(--hp)" onclick="switchTab('medecins')">Prendre mon premier RDV</button>
        </div>
      <?php else: ?>
        <div class="rdv-list">
          <?php foreach ($mesRdv as $r): ?>
            <div class="rdv-item statut-<?= $r['statut'] ?>">
              <div class="rdv-date-block">
                <div class="rdv-date-d"><?= date('d', strtotime($r['date_rdv'])) ?></div>
                <div class="rdv-date-m"><?= date('M', strtotime($r['date_rdv'])) ?></div>
              </div>
              <div class="rdv-info">
                <div class="rdv-med">Dr. <?= htmlspecialchars($r['med_prenom'] . ' ' . $r['med_nom']) ?></div>
                <div class="rdv-spec"><?= htmlspecialchars($r['specialite']) ?></div>
                <div class="rdv-heure">🕐 <?= date('H:i', strtotime($r['heure_rdv'])) ?></div>
                <?php if ($r['motif']): ?>
                  <div class="rdv-motif"><?= htmlspecialchars(substr($r['motif'], 0, 80)) ?></div>
                <?php endif; ?>
              </div>
              <div class="rdv-actions">
                <span class="statut-b <?= $r['statut'] ?>"><?= statutLabel($r['statut']) ?></span>
                <?php if (in_array($r['statut'], ['en_attente', 'confirme'])): ?>
                  <button class="btn btn-danger btn-xs" onclick="annulerRdv(<?= $r['id'] ?>)">Annuler</button>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- ─── PROFIL ─── -->
    <div id="tab-profil" class="tab-content">
      <h1 style="font-family:'Space Grotesk',sans-serif;font-size:1.3rem;font-weight:800;margin-bottom:1.5rem">Mon profil</h1>
      <div class="profil-section">
        <div class="profil-card">
          <h3>📋 Informations personnelles</h3>
          <form id="profilForm">
            <div class="form-row">
              <div class="form-group"><label>Prénom</label><input type="text" name="prenom" value="<?= htmlspecialchars($me['prenom']) ?>" class="form-input"/></div>
              <div class="form-group"><label>Nom</label><input type="text" name="nom" value="<?= htmlspecialchars($me['nom']) ?>" class="form-input"/></div>
            </div>
            <div class="form-group"><label>Email</label><input type="email" name="email" value="<?= htmlspecialchars($me['email']) ?>" class="form-input"/></div>
            <div class="form-row">
              <div class="form-group"><label>Téléphone</label><input type="tel" name="telephone" value="<?= htmlspecialchars($me['telephone'] ?? '') ?>" class="form-input"/></div>
              <div class="form-group"><label>Date de naissance</label><input type="date" name="date_naissance" value="<?= htmlspecialchars($me['date_naissance'] ?? '') ?>" class="form-input"/></div>
            </div>
            <hr/>
            <h3 style="margin-bottom:1rem">🔒 Changer l'e-mail ou le mot de passe</h3>
            <div class="form-group"><label>Mot de passe actuel <small>(requis pour changer l'e-mail ou le mot de passe)</small></label><input type="password" name="current_password" class="form-input" autocomplete="current-password"/></div>
            <div class="form-row">
              <div class="form-group"><label>Nouveau mot de passe</label><input type="password" name="new_password" class="form-input" minlength="10" autocomplete="new-password" placeholder="10 car. min., lettres + chiffres (vide = inchangé)"/></div>
              <div class="form-group"><label>Confirmer</label><input type="password" name="confirm_password" class="form-input" autocomplete="new-password"/></div>
            </div>
            <button type="button" class="btn btn-primary" style="background:var(--hp)" onclick="saveProfil()">Enregistrer les modifications</button>
          </form>
          <div id="profilMsg" class="alert" style="display:none;margin-top:1rem"></div>
        </div>
      </div>
    </div>

  </main>
</div>

<!-- ─── MODAL BOOKING ─── -->
<div class="modal-overlay" id="bookingModal">
  <div class="booking-modal">
    <div class="booking-modal-header">
      <h2 id="bookingTitle">Prendre rendez-vous</h2>
      <button onclick="closeBooking()">✕</button>
    </div>
    <div class="booking-modal-body">

      <!-- Indicateur d'étapes -->
      <div class="booking-steps-indicator">
        <div class="bsi active" id="bsi1"><div class="bsi-dot">1</div><div class="bsi-label">Créneau</div></div>
        <div class="bsi" id="bsi2"><div class="bsi-dot">2</div><div class="bsi-label">Motif</div></div>
        <div class="bsi" id="bsi3"><div class="bsi-dot">3</div><div class="bsi-label">Confirmation</div></div>
      </div>

      <!-- STEP 1 : Choisir créneau -->
      <div class="booking-step active" id="bs1">
        <p style="font-size:.85rem;color:var(--muted);margin-bottom:1rem">Sélectionnez une date et un créneau disponible</p>
        <div class="creneaux-date-nav">
          <button class="date-nav-btn" onclick="changeDate(-1)">◀</button>
          <input type="date" class="creneaux-date-input" id="bookDate" min="<?= date('Y-m-d') ?>" onchange="loadSlots()"/>
          <button class="date-nav-btn" onclick="changeDate(1)">▶</button>
        </div>
        <div class="creneaux-slots" id="slotsContainer">
          <p class="slots-empty">Sélectionnez une date pour voir les créneaux</p>
        </div>
      </div>

      <!-- STEP 2 : Motif -->
      <div class="booking-step" id="bs2">
        <p style="font-size:.85rem;color:var(--muted);margin-bottom:1rem">Décrivez brièvement le motif de votre consultation</p>
        <div class="form-group">
          <label>Motif de consultation</label>
          <textarea id="motifInput" class="form-input" rows="4" placeholder="Ex: Douleurs abdominales, suivi tension, renouvellement ordonnance..."></textarea>
        </div>
        <div style="display:flex;flex-wrap:wrap;gap:.4rem;margin-top:.75rem">
          <?php foreach(['Consultation de routine','Renouvellement ordonnance','Douleurs','Fièvre','Suivi maladie chronique','Bilan de santé'] as $m): ?>
            <button class="spec-btn" style="font-size:.75rem" onclick="document.getElementById('motifInput').value='<?= $m ?>'"><?= $m ?></button>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- STEP 3 : Confirmation -->
      <div class="booking-step" id="bs3">
        <div class="confirm-recap" id="bookingRecap"></div>
        <p style="font-size:.82rem;color:var(--muted)">Votre rendez-vous sera en attente de confirmation par l'équipe médicale.</p>
      </div>

      <!-- Navigation -->
      <div class="booking-nav">
        <button class="btn btn-outline" id="btnBack" style="display:none" onclick="bookingPrev()">← Retour</button>
        <button class="btn btn-primary" id="btnNext" style="background:var(--hp)" onclick="bookingNext()" disabled>Suivant →</button>
      </div>

    </div>
  </div>
</div>

<div id="toast" class="toast"></div>

<script>
const HOPITAL_ID  = <?= (int)$hid ?>;
const HOPITAL_NOM = <?= json_script($hopital['nom']) ?>;
const COULEUR     = <?= json_script($couleur) ?>;

// Échappement HTML : toute donnée insérée via innerHTML doit passer par esc() (anti-XSS)
function esc(v){return String(v ?? '').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));}

// ── TABS
function switchTab(id) {
  document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
  document.querySelectorAll('.hp-side-link').forEach(l => l.classList.remove('active'));
  document.getElementById('tab-'+id)?.classList.add('active');
  document.querySelector(`[data-tab="${id}"]`)?.classList.add('active');
}
document.querySelectorAll('.hp-side-link[data-tab]').forEach(l => {
  l.addEventListener('click', () => switchTab(l.dataset.tab));
});

// ── FILTRE SPÉCIALITÉ
function filterSpec(spec, btn) {
  document.querySelectorAll('.spec-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  document.querySelectorAll('.med-card').forEach(card => {
    card.style.display = (!spec || card.dataset.spec === spec) ? '' : 'none';
  });
}

// ── TOAST
function showToast(msg, type='success') {
  const t = document.getElementById('toast');
  t.textContent = msg; t.style.background = type==='error'?'#dc2626':'#0f172a';
  t.classList.add('show'); setTimeout(()=>t.classList.remove('show'),3500);
}

// ── API
async function apiPost(action, data) {
  try {
    const r = await fetch('../api/api.php?action='+encodeURIComponent(action),{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify(data)});
    if (r.status === 401) { location.href = '../login.php?error=expired'; return {error:'Session expirée'}; }
    return await r.json();
  } catch (e) { return {error:'Erreur réseau ou réponse invalide. Réessayez.'}; }
}

// ── BOOKING
let booking = { medecinId:null, medecinNom:'', medecinSpec:'', creneauId:null, date:'', heure:'', step:1 };

function openBooking(id, nom, spec) {
  booking = { medecinId:id, medecinNom:nom, medecinSpec:spec, creneauId:null, date:'', heure:'', step:1 };
  document.getElementById('bookingTitle').textContent = 'RDV avec ' + nom;
  // Date par défaut = demain
  const tom = new Date(); tom.setDate(tom.getDate()+1);
  document.getElementById('bookDate').value = tom.toISOString().split('T')[0];
  setStep(1);
  document.getElementById('bookingModal').classList.add('open');
  loadSlots();
}
function closeBooking() { document.getElementById('bookingModal').classList.remove('open'); }

function setStep(n) {
  booking.step = n;
  [1,2,3].forEach(i => {
    document.getElementById('bs'+i).classList.toggle('active', i===n);
    const bsi = document.getElementById('bsi'+i);
    bsi.classList.toggle('active', i===n);
    bsi.classList.toggle('done', i<n);
  });
  document.getElementById('btnBack').style.display = n>1?'':'none';
  const next = document.getElementById('btnNext');
  if (n===3) { next.textContent='✓ Confirmer le RDV'; next.disabled=false; }
  else        { next.textContent='Suivant →'; }
  if (n===1) updateNextBtn();
}

function updateNextBtn() {
  document.getElementById('btnNext').disabled = !booking.creneauId;
}

function bookingNext() {
  if (booking.step===1 && !booking.creneauId) { showToast('Sélectionnez un créneau','error'); return; }
  if (booking.step===2) { buildRecap(); setStep(3); return; }
  if (booking.step===3) { confirmerRdv(); return; }
  setStep(booking.step+1);
}
function bookingPrev() { if(booking.step>1) setStep(booking.step-1); }

async function loadSlots() {
  const date = document.getElementById('bookDate').value;
  if (!date || !booking.medecinId) return;
  booking.date = date; booking.creneauId = null; updateNextBtn();
  const container = document.getElementById('slotsContainer');
  container.innerHTML = '<p class="slots-empty">Chargement...</p>';
  const slots = await fetch(`../api/api.php?action=get_creneaux&medecin_id=${encodeURIComponent(booking.medecinId)}&hopital_id=${encodeURIComponent(HOPITAL_ID)}&date=${encodeURIComponent(date)}`,{credentials:'same-origin'}).then(r=>r.json()).catch(()=>[]);
  if (!Array.isArray(slots) || !slots.length) { container.innerHTML='<p class="slots-empty">Aucun créneau disponible pour cette date.</p>'; return; }
  container.innerHTML='';
  slots.forEach(s => {
    const btn = document.createElement('button');
    btn.className='slot-btn'; btn.textContent=s.heure_debut.slice(0,5)+' – '+s.heure_fin.slice(0,5);
    btn.onclick=()=>{
      document.querySelectorAll('.slot-btn').forEach(b=>b.classList.remove('active'));
      btn.classList.add('active'); booking.creneauId=s.id; booking.heure=s.heure_debut; updateNextBtn();
    };
    container.appendChild(btn);
  });
}

function changeDate(delta) {
  const input = document.getElementById('bookDate');
  const d = new Date(input.value || new Date()); d.setDate(d.getDate()+delta);
  const min = new Date(); min.setDate(min.getDate()); // aujourd'hui minimum
  if (d >= min) { input.value = d.toISOString().split('T')[0]; loadSlots(); }
}

function buildRecap() {
  const dateStr = new Date(booking.date+'T00:00:00').toLocaleDateString('fr-FR',{weekday:'long',year:'numeric',month:'long',day:'numeric'});
  document.getElementById('bookingRecap').innerHTML=`
    <div class="recap-row"><span class="recap-icon">👨‍⚕️</span><span class="recap-label">Médecin</span><span class="recap-val">${esc(booking.medecinNom)}</span></div>
    <div class="recap-row"><span class="recap-icon">🩺</span><span class="recap-label">Spécialité</span><span class="recap-val">${esc(booking.medecinSpec)}</span></div>
    <div class="recap-row"><span class="recap-icon">📅</span><span class="recap-label">Date</span><span class="recap-val">${dateStr}</span></div>
    <div class="recap-row"><span class="recap-icon">🕐</span><span class="recap-label">Heure</span><span class="recap-val">${booking.heure.slice(0,5)}</span></div>
    <div class="recap-row"><span class="recap-icon">🏥</span><span class="recap-label">Hôpital</span><span class="recap-val">${esc(HOPITAL_NOM)}</span></div>
  `;
}

async function confirmerRdv() {
  const motif = document.getElementById('motifInput').value;
  const r = await apiPost('book_rdv',{creneau_id:booking.creneauId,medecin_id:booking.medecinId,hopital_id:HOPITAL_ID,motif});
  if(r.success) { closeBooking(); showToast('✅ RDV demandé ! Vous recevrez une confirmation.'); setTimeout(()=>location.reload(),1800); }
  else showToast(r.error||'Erreur lors de la réservation','error');
}

async function annulerRdv(id) {
  if(!confirm('Annuler ce rendez-vous ?')) return;
  const r = await apiPost('cancel_rdv',{rdv_id:id});
  if(r.success){showToast('RDV annulé');setTimeout(()=>location.reload(),1200);}
  else showToast(r.error||'Erreur','error');
}

async function saveProfil() {
  const data = Object.fromEntries(new FormData(document.getElementById('profilForm')).entries());
  const r = await apiPost('update_profil',data);
  const msg = document.getElementById('profilMsg');
  msg.className='alert '+(r.success?'alert-success':'alert-error');
  msg.textContent = r.success?'✓ Profil mis à jour.':r.error||'Erreur';
  msg.style.display='block'; setTimeout(()=>msg.style.display='none',4000);
}

// Fermer modal overlay
document.getElementById('bookingModal').addEventListener('click', e => {
  if(e.target===document.getElementById('bookingModal')) closeBooking();
});

// Fermer notifs au clic extérieur
document.addEventListener('click', e => {
  const panel=document.getElementById('notifPanel');
  const btn=document.getElementById('notifBtn');
  if(panel&&!panel.contains(e.target)&&!btn.contains(e.target)) panel.classList.remove('open');
});
</script>
</body>
</html>
