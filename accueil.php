<?php
require_once __DIR__ . '/includes/auth.php';

// Déjà connecté (compte toujours valide) → rediriger selon le rôle
if (Auth::check() && Auth::validate()) Auth::redirectByRole();

$hopitaux = query("SELECT * FROM hopitaux WHERE statut='actif' ORDER BY nom");

// Images de fond par défaut (Unsplash, libres de droits)
$images = [
    'https://images.unsplash.com/photo-1586773860418-d37222d8fce3?w=800&q=80',
    'https://images.unsplash.com/photo-1519494026892-80bbd2d6fd0d?w=800&q=80',
    'https://images.unsplash.com/photo-1538108149393-fbbd81895907?w=800&q=80',
    'https://images.unsplash.com/photo-1551190822-a9333d879b1f?w=800&q=80',
    'https://images.unsplash.com/photo-1576091160550-2173dba999ef?w=800&q=80',
    'https://images.unsplash.com/photo-1504439468489-c8920d796a29?w=800&q=80',
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title>DOKTA-RDV  — Plateforme de rendez-vous médicaux</title>
  <link rel="stylesheet" href="assets/style.css"/>
  <style>
    /* ── HERO ── */
    .landing-hero {
      background: linear-gradient(135deg, #0f172a 0%, #1e3a8a 60%, #2563eb 100%);
      color: white; padding: 5rem 2rem 4rem; text-align: center; position: relative; overflow: hidden;
    }
    .landing-hero::before {
      content: ''; position: absolute; inset: 0;
      background: url('https://images.unsplash.com/photo-1579684385127-1ef15d508118?w=1600&q=60') center/cover no-repeat;
      opacity: .08;
    }
    .hero-content { position: relative; z-index: 1; max-width: 700px; margin: 0 auto; }
    .hero-logo { font-size: 3rem; margin-bottom: 1rem; }
    .hero-title { font-size: clamp(2rem, 5vw, 3.2rem); font-weight: 800; letter-spacing: -.02em; margin-bottom: 1rem; }
    .hero-title span { color: #60a5fa; }
    .hero-sub { font-size: 1.05rem; opacity: .85; max-width: 540px; margin: 0 auto 2rem; line-height: 1.7; }
    .hero-actions { display: flex; gap: 1rem; justify-content: center; flex-wrap: wrap; }
    .btn-hero-primary { background: white; color: #1e3a8a; font-weight: 700; padding: .8rem 2rem; border-radius: 50px; text-decoration: none; font-size: .95rem; transition: all .2s; border: none; cursor: pointer; }
    .btn-hero-primary:hover { background: #e0e7ff; transform: translateY(-1px); }
    .btn-hero-outline { background: transparent; color: white; font-weight: 600; padding: .8rem 2rem; border-radius: 50px; text-decoration: none; font-size: .95rem; border: 2px solid rgba(255,255,255,.5); transition: all .2s; }
    .btn-hero-outline:hover { background: rgba(255,255,255,.1); border-color: white; }

    /* ── STATS BAND ── */
    .stats-band { background: white; border-bottom: 1px solid #e2e8f0; padding: 1.5rem 2rem; }
    .stats-band-inner { max-width: 900px; margin: 0 auto; display: flex; justify-content: center; gap: 4rem; flex-wrap: wrap; }
    .band-stat { text-align: center; }
    .band-num { font-size: 2rem; font-weight: 800; color: #2563eb; }
    .band-label { font-size: .8rem; color: #64748b; margin-top: .1rem; }

    /* ── SECTION ── */
    .section { padding: 4rem 2rem; }
    .section-alt { background: #f8fafc; }
    .section-inner { max-width: 1200px; margin: 0 auto; }
    .section-header { text-align: center; margin-bottom: 3rem; }
    .section-tag { font-size: .72rem; text-transform: uppercase; letter-spacing: .12em; color: #2563eb; font-weight: 700; margin-bottom: .5rem; }
    .section-title { font-size: clamp(1.6rem, 3vw, 2.2rem); font-weight: 800; color: #0f172a; margin-bottom: .75rem; }
    .section-sub { color: #64748b; max-width: 520px; margin: 0 auto; }

    /* ── FILTRE ── */
    .filter-bar { display: flex; gap: .6rem; flex-wrap: wrap; justify-content: center; margin-bottom: 2rem; }
    .filter-btn { padding: .45rem 1.1rem; border-radius: 50px; border: 1.5px solid #e2e8f0; background: white; font-size: .82rem; color: #64748b; cursor: pointer; transition: all .15s; font-weight: 500; }
    .filter-btn:hover, .filter-btn.active { background: #2563eb; color: white; border-color: #2563eb; }
    .search-input { padding: .55rem 1.1rem; border-radius: 50px; border: 1.5px solid #e2e8f0; background: white; font-size: .88rem; outline: none; transition: border-color .2s; min-width: 260px; }
    .search-input:focus { border-color: #2563eb; }

    /* ── CARTES HÔPITAUX ── */
    .hopitaux-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 1.5rem; }
    .hopital-hero-card {
      border-radius: 16px; overflow: hidden; cursor: pointer;
      box-shadow: 0 4px 20px rgba(0,0,0,.1); transition: all .3s;
      position: relative; height: 260px;
      text-decoration: none; display: block;
    }
    .hopital-hero-card:hover { transform: translateY(-6px); box-shadow: 0 12px 40px rgba(0,0,0,.18); }
    .hopital-hero-card:hover .hcard-overlay { background: linear-gradient(to top, rgba(0,0,0,.85) 0%, rgba(0,0,0,.3) 60%, transparent 100%); }
    .hcard-bg { position: absolute; inset: 0; background-size: cover; background-position: center; transition: transform .4s; }
    .hopital-hero-card:hover .hcard-bg { transform: scale(1.05); }
    .hcard-overlay { position: absolute; inset: 0; background: linear-gradient(to top, rgba(0,0,0,.75) 0%, rgba(0,0,0,.2) 60%, transparent 100%); transition: background .3s; }
    .hcard-badge { position: absolute; top: 1rem; right: 1rem; background: rgba(255,255,255,.15); backdrop-filter: blur(8px); border: 1px solid rgba(255,255,255,.3); color: white; font-size: .65rem; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; padding: .25rem .65rem; border-radius: 20px; }
    .hcard-content { position: absolute; bottom: 0; left: 0; right: 0; padding: 1.5rem; color: white; }
    .hcard-ville { font-size: .72rem; text-transform: uppercase; letter-spacing: .1em; opacity: .8; margin-bottom: .3rem; display: flex; align-items: center; gap: .35rem; }
    .hcard-nom { font-size: 1.15rem; font-weight: 700; line-height: 1.25; margin-bottom: .6rem; }
    .hcard-meta { display: flex; gap: 1rem; }
    .hcard-stat { font-size: .75rem; opacity: .85; display: flex; align-items: center; gap: .3rem; }
    .hcard-btn { position: absolute; bottom: 1.5rem; right: 1.5rem; background: white; color: #1e3a8a; font-size: .72rem; font-weight: 700; padding: .4rem 1rem; border-radius: 20px; opacity: 0; transform: translateY(4px); transition: all .2s; }
    .hopital-hero-card:hover .hcard-btn { opacity: 1; transform: none; }

    /* ── COMMENT ÇA MARCHE ── */
    .steps-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 2rem; }
    .step-item { text-align: center; }
    .step-circle { width: 60px; height: 60px; border-radius: 50%; background: linear-gradient(135deg, #2563eb, #60a5fa); color: white; font-size: 1.4rem; display: flex; align-items: center; justify-content: center; margin: 0 auto 1rem; box-shadow: 0 4px 14px rgba(37,99,235,.3); }
    .step-title { font-size: .95rem; font-weight: 700; color: #0f172a; margin-bottom: .4rem; }
    .step-desc { font-size: .83rem; color: #64748b; line-height: 1.6; }

    /* ── CTA FINAL ── */
    .cta-section { background: linear-gradient(135deg, #1e3a8a, #2563eb); color: white; text-align: center; padding: 4rem 2rem; }
    .cta-section h2 { font-size: clamp(1.6rem, 3vw, 2rem); font-weight: 800; margin-bottom: .75rem; }
    .cta-section p { opacity: .85; margin-bottom: 2rem; }

    /* ── NAVBAR LANDING ── */
    .landing-nav { position: sticky; top: 0; z-index: 50; background: white; border-bottom: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: space-between; padding: 1rem 2rem; box-shadow: 0 1px 3px rgba(0,0,0,.06); }
    .landing-nav-brand { font-weight: 800; font-size: 1.1rem; color: #2563eb; display: flex; align-items: center; gap: .4rem; }
    .landing-nav-links { display: flex; align-items: center; gap: 1rem; }
    .nav-link-plain { font-size: .85rem; color: #64748b; text-decoration: none; font-weight: 500; transition: color .15s; }
    .nav-link-plain:hover { color: #2563eb; }
    .empty-hopitaux { text-align: center; padding: 3rem; color: #64748b; grid-column: 1/-1; }

    @media(max-width:640px){
      .stats-band-inner { gap: 2rem; }
      .hopitaux-grid { grid-template-columns: 1fr; }
      .hero-actions { flex-direction: column; align-items: center; }
    }
  </style>
</head>
<body>

<!-- NAVBAR -->
<nav class="landing-nav">
  <div class="landing-nav-brand">✚ DOKTA-RDV</div>
  <div class="landing-nav-links">
    <a href="#hopitaux" class="nav-link-plain">Hôpitaux</a>
    <a href="#comment" class="nav-link-plain">Comment ça marche</a>
    <a href="login.php" class="btn" style="background:#eff6ff;color:#2563eb;padding:.4rem 1rem;border-radius:8px;font-size:.85rem;font-weight:600;text-decoration:none">Connexion</a>
    <a href="register.php" class="btn" style="background:#2563eb;color:white;padding:.4rem 1rem;border-radius:8px;font-size:.85rem;font-weight:600;text-decoration:none">S'inscrire</a>
  </div>
</nav>

<!-- HERO -->
<section class="landing-hero">
  <div class="hero-content">
    <div class="hero-logo">✚</div>
    <h1 class="hero-title">La santé à portée de <span>clic</span></h1>
    <p class="hero-sub">Prenez rendez-vous avec les meilleurs médecins dans les hôpitaux et cliniques partenaires. Rapide, simple, sécurisé.</p>
    <div class="hero-actions">
      <a href="#hopitaux" class="btn-hero-primary">🏥 Voir les hôpitaux</a>
      <a href="register.php" class="btn-hero-outline">Créer un compte gratuit</a>
    </div>
  </div>
</section>

<!-- STATS BAND -->
<div class="stats-band">
  <div class="stats-band-inner">
    <?php
    $nbHop = queryOne("SELECT COUNT(*) n FROM hopitaux WHERE statut='actif'")['n'];
    $nbMed = queryOne("SELECT COUNT(*) n FROM users WHERE role='medecin' AND statut='actif'")['n'];
    $nbRdv = queryOne("SELECT COUNT(*) n FROM rendezvous WHERE statut='confirme'")['n'];
    $nbPat = queryOne("SELECT COUNT(*) n FROM users WHERE role='patient'")['n'];
    ?>
    <div class="band-stat"><div class="band-num"><?=$nbHop?>+</div><div class="band-label">Hôpitaux partenaires</div></div>
    <div class="band-stat"><div class="band-num"><?=$nbMed?>+</div><div class="band-label">Médecins disponibles</div></div>
    <div class="band-stat"><div class="band-num"><?=$nbRdv?>+</div><div class="band-label">RDV confirmés</div></div>
    <div class="band-stat"><div class="band-num"><?=$nbPat?>+</div><div class="band-label">Patients inscrits</div></div>
  </div>
</div>

<!-- HÔPITAUX -->
<section class="section" id="hopitaux">
  <div class="section-inner">
    <div class="section-header">
      <p class="section-tag">Nos établissements partenaires</p>
      <h2 class="section-title">Choisissez votre hôpital</h2>
      <p class="section-sub">Sélectionnez un établissement pour consulter ses médecins disponibles et prendre rendez-vous.</p>
    </div>

    <!-- Recherche -->
    <div class="filter-bar">
      <input type="text" class="search-input" id="searchHopital" placeholder="🔍 Rechercher un hôpital ou une ville..." oninput="filterHopitaux()"/>
    </div>

    <div class="hopitaux-grid" id="hopitauxGrid">
      <?php if(empty($hopitaux)): ?>
        <div class="empty-hopitaux">
          <p style="font-size:2rem">🏥</p>
          <p>Aucun hôpital disponible pour le moment.</p>
        </div>
      <?php endif; ?>

      <?php foreach($hopitaux as $i => $h): ?>
        <?php
        $img = safe_image_url($h['image_url'] ?? '') ?? $images[$i % count($images)];
        $nbMedH = queryOne("SELECT COUNT(*) n FROM users WHERE hopital_id=? AND role='medecin' AND statut='actif'",[$h['id']])['n'];
        $nbRdvH = queryOne("SELECT COUNT(*) n FROM rendezvous WHERE hopital_id=? AND statut='confirme'",[$h['id']])['n'];
        ?>
        <a href="patient/patient.php?hopital=<?=$h['id']?>"
           class="hopital-hero-card"
           data-nom="<?=e(mb_strtolower($h['nom']))?>"
           data-ville="<?=e(mb_strtolower($h['ville']??''))?>">
          <div class="hcard-bg" style="background-image:url('<?=e($img)?>')"></div>
          <div class="hcard-overlay"></div>
          <div class="hcard-badge">Partenaire</div>
          <div class="hcard-content">
            <div class="hcard-ville">📍 <?=htmlspecialchars($h['ville']??'France')?></div>
            <div class="hcard-nom"><?=htmlspecialchars($h['nom'])?></div>
            <div class="hcard-meta">
              <div class="hcard-stat">👨‍⚕️ <?=$nbMedH?> médecin<?=$nbMedH>1?'s':''?></div>
              <div class="hcard-stat">📅 <?=$nbRdvH?> RDV confirmés</div>
            </div>
          </div>
          <div class="hcard-btn">Prendre RDV →</div>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- COMMENT ÇA MARCHE -->
<section class="section section-alt" id="comment">
  <div class="section-inner">
    <div class="section-header">
      <p class="section-tag">Simple & rapide</p>
      <h2 class="section-title">Comment ça marche ?</h2>
    </div>
    <div class="steps-grid">
      <div class="step-item">
        <div class="step-circle">🏥</div>
        <div class="step-title">1. Choisissez un hôpital</div>
        <div class="step-desc">Parcourez nos établissements partenaires et sélectionnez celui qui vous convient.</div>
      </div>
      <div class="step-item">
        <div class="step-circle">👨‍⚕️</div>
        <div class="step-title">2. Choisissez un médecin</div>
        <div class="step-desc">Consultez les spécialistes disponibles et choisissez selon votre besoin.</div>
      </div>
      <div class="step-item">
        <div class="step-circle">📅</div>
        <div class="step-title">3. Sélectionnez un créneau</div>
        <div class="step-desc">Choisissez la date et l'heure qui vous conviennent parmi les disponibilités.</div>
      </div>
      <div class="step-item">
        <div class="step-circle">✅</div>
        <div class="step-title">4. Confirmé !</div>
        <div class="step-desc">Vous recevez une confirmation et un rappel avant votre rendez-vous.</div>
      </div>
    </div>
  </div>
</section>

<!-- CTA -->
<section class="cta-section">
  <h2>Prêt à prendre soin de vous ?</h2>
  <p>Créez votre compte gratuitement et prenez rendez-vous en moins de 2 minutes.</p>
  <div style="display:flex;gap:1rem;justify-content:center;flex-wrap:wrap">
    <a href="register.php" class="btn-hero-primary">Créer mon compte</a>
    <a href="login.php" class="btn-hero-outline">Se connecter</a>
  </div>
</section>

<footer style="background:#0f172a;color:#94a3b8;text-align:center;padding:1.5rem;font-size:.82rem">
  © 2025 DOKTA-RDV — Plateforme nationale de rendez-vous médicaux
</footer>

<script>
function filterHopitaux() {
  const q = document.getElementById('searchHopital').value.toLowerCase();
  document.querySelectorAll('.hopital-hero-card').forEach(card => {
    const match = card.dataset.nom.includes(q) || card.dataset.ville.includes(q);
    card.style.display = match ? '' : 'none';
  });
}

// Clic sur carte → vérifier si connecté
document.querySelectorAll('.hopital-hero-card').forEach(card => {
  card.addEventListener('click', function(e) {
    e.preventDefault();
    const hopitalId = new URL(this.href).searchParams.get('hopital');
    // Rediriger vers login avec paramètre hopital, ou patient.php si déjà connecté
    window.location.href = `login.php?hopital=${hopitalId}`;
  });
});
</script>
</body>
</html>
