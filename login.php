<?php
require_once __DIR__ . '/includes/auth.php';

// Déjà connecté (et compte toujours valide) → on redirige
if (Auth::check() && Auth::validate()) {
    // Si patient avec hôpital en session → espace patient de cet hôpital
    if (Auth::role() === 'patient') {
        $hid = $_SESSION['hopital_id_actif'] ?? null;
        header('Location: ' . ($hid ? 'patient/hopital_espace.php' : 'patient/patient.php'));
    } else {
        Auth::redirectByRole();
    }
    exit;
}

$error = '';
$errorMessages = [
    'expired'   => 'Votre session a expiré ou est invalide. Veuillez vous reconnecter.',
    'access'    => "Vous n'avez pas accès à cette page.",
];
if (isset($_GET['error'], $errorMessages[$_GET['error']])) $error = $errorMessages[$_GET['error']];
if (!empty($_SESSION['flash_expired'])) { $error = $errorMessages['expired']; unset($_SESSION['flash_expired']); }

$hopitalId = (int)($_GET['hopital'] ?? 0);
$hopital   = null;
if ($hopitalId) {
    $hopital = queryOne('SELECT * FROM hopitaux WHERE id=? AND statut="actif"', [$hopitalId]);
}

// Hash factice : on vérifie toujours un mot de passe, même si l'e-mail n'existe pas,
// pour que le temps de réponse ne révèle pas quels comptes existent.
const DUMMY_HASH = '$2y$12$EwLlWhnQQPagyrouHriuhOusBj8FjA3wqy2iS5Kl5AxxeTWWyHss.';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $error = 'Formulaire expiré. Veuillez réessayer.';
    } else {
        $email       = trim((string)($_POST['email'] ?? ''));
        $pass        = (string)($_POST['password'] ?? '');
        $hopRedirect = (int)($_POST['hopital_id'] ?? 0);

        // Anti brute-force : 5 échecs / 15 min par e-mail, 20 échecs / 15 min par adresse IP
        $bEmail = 'login:' . strtolower($email);
        $bIp    = 'loginip:' . client_ip();
        if (throttle_count($bEmail, 900) >= 5 || throttle_count($bIp, 900) >= 20) {
            $error = 'Trop de tentatives de connexion. Réessayez dans 15 minutes.';
        } else {
            $user = queryOne('SELECT * FROM users WHERE email=? AND statut="actif"', [$email]);
            $ok   = password_verify($pass, $user['password'] ?? DUMMY_HASH) && $user;
            if ($ok) {
                throttle_clear($bEmail);
                if (password_needs_rehash($user['password'], PASSWORD_BCRYPT, ['cost' => 12])) {
                    execute('UPDATE users SET password=? WHERE id=?', [hash_password_secure($pass), $user['id']]);
                }
                Auth::login($user);
                if (!Auth::validate()) {
                    $error = 'Votre compte ou votre établissement est actuellement suspendu.';
                } else {
                    // Patient + hôpital choisi (et actif) → verrouiller l'hôpital en session
                    if ($user['role'] === 'patient' && $hopRedirect
                        && queryOne('SELECT id FROM hopitaux WHERE id=? AND statut="actif"', [$hopRedirect])) {
                        $_SESSION['hopital_id_actif'] = $hopRedirect;
                        header('Location: patient/hopital_espace.php');
                    } else {
                        Auth::redirectByRole();
                    }
                    exit;
                }
            } else {
                throttle_hit($bEmail);
                throttle_hit($bIp);
                $error = 'Email ou mot de passe incorrect.';
            }
        }
    }
}

// Couleur dynamique selon l'hôpital (validée : #RRGGBB uniquement)
$couleur = safe_color($hopital['couleur'] ?? null);
$hopitalImg = $hopital ? safe_image_url($hopital['image_url'] ?? '') : null;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title><?= $hopital ? htmlspecialchars($hopital['nom']) : 'DOKTA-RDV' ?> — Connexion</title>
  <link rel="stylesheet" href="assets/style.css"/>
  <style>
    :root {
      --muted: #64748b;
      --border: #e2e8f0;
    }
    .login-page { min-height:100vh; display:grid; grid-template-columns:1fr 1fr; }
    .login-left {
      background: linear-gradient(160deg, <?=$couleur?>dd, <?=$couleur?>);
      color:white; display:flex; flex-direction:column; justify-content:center;
      padding:4rem; position:relative; overflow:hidden;
    }
    .login-left::before {
      content:''; position:absolute; inset:0;
      background: <?= $hopitalImg ? "url('" . e($hopitalImg) . "') center/cover" : 'none' ?>;
      opacity:.12;
    }
    .login-left-content { position:relative; z-index:1; }
    .login-back { font-size:.82rem; opacity:.8; margin-bottom:2rem; display:inline-flex; align-items:center; gap:.4rem; }
    .login-back:hover { opacity:1; }
    .login-back, .login-back a { color:white; text-decoration:none; }
    .login-hopital-badge {
      display:inline-flex; align-items:center; gap:.5rem;
      background:rgba(255,255,255,.15); border:1px solid rgba(255,255,255,.25);
      backdrop-filter:blur(8px); border-radius:50px;
      padding:.4rem 1rem; font-size:.78rem; font-weight:600;
      letter-spacing:.04em; margin-bottom:1.5rem;
    }
    .login-left h1 { font-size:clamp(1.8rem,3vw,2.8rem); font-weight:800; line-height:1.15; margin-bottom:1rem; }
    .login-left p  { opacity:.85; font-size:.95rem; line-height:1.7; max-width:400px; margin-bottom:2rem; }
    .login-info-cards { display:flex; flex-direction:column; gap:.75rem; }
    .login-info-card {
      background:rgba(255,255,255,.12); border:1px solid rgba(255,255,255,.2);
      backdrop-filter:blur(8px); border-radius:10px; padding:.85rem 1.1rem;
      display:flex; align-items:center; gap:.75rem; font-size:.85rem;
    }
    .login-info-card .ic-icon { font-size:1.3rem; }
    .login-info-card .ic-label { opacity:.75; font-size:.72rem; }
    .login-info-card .ic-val   { font-weight:600; }

    .login-right { display:flex; align-items:center; justify-content:center; padding:3rem 2rem; background:#f8fafc; }
    .login-form-wrap { width:100%; max-width:400px; }
    .login-logo { font-size:1.8rem; margin-bottom:.5rem; text-align:center; color:#2563eb; }
    .login-form-wrap h2 { font-size:1.4rem; font-weight:800; text-align:center; margin-bottom:.35rem; color:#0f172a; }
    .login-form-wrap .sub { text-align:center; color:var(--muted); font-size:.85rem; margin-bottom:2rem; }
    .login-card { background:white; border-radius:20px; padding:2rem; border:1px solid var(--border); box-shadow:0 4px 24px rgba(0,0,0,.06); }
    .divider { display:flex; align-items:center; gap:.75rem; margin:1.25rem 0; }
    .divider span { color:var(--muted); font-size:.78rem; white-space:nowrap; }
    .divider::before,.divider::after { content:''; flex:1; height:1px; background:var(--border); }
    
    /* Boutons et formulaires */
    .btn { display:inline-block; padding:.7rem 1.2rem; border-radius:10px; text-decoration:none; font-weight:600; text-align:center; cursor:pointer; transition:all .2s; }
    .btn-primary { background:#2563eb; color:white; border:none; }
    .btn-primary:hover { background:#1e3a8a; }
    .btn-outline { background:white; color:#2563eb; border:1.5px solid #2563eb; }
    .btn-outline:hover { background:#eff6ff; }
    .btn-full { width:100%; }
    .btn-lg { padding:.9rem; font-size:1rem; }
    .alert-error { background:#fee2e2; color:#b91c1c; padding:.8rem; border-radius:10px; margin-bottom:1rem; font-size:.85rem; }
    .form-group { margin-bottom:1rem; }
    .form-group label { display:block; font-weight:600; font-size:.85rem; color:#334155; margin-bottom:.5rem; }
    .form-group input { width:100%; padding:.8rem 1rem; border:1.5px solid #e2e8f0; border-radius:10px; box-sizing:border-box; font-size:.9rem; }
    .form-group input:focus { outline:none; border-color:#2563eb; }
    .input-eye { position:relative; }
    .input-eye button { position:absolute; right:10px; top:50%; transform:translateY(-50%); background:none; border:none; cursor:pointer; font-size:1rem; }
    .demo-box .demo-title { font-weight:700; font-size:.8rem; margin-bottom:.5rem; color:#475569; }
    .demo-grid { display:grid; grid-template-columns:1fr 1fr; gap:.5rem; }
    .demo-btn { background:#f1f5f9; border:1px solid #e2e8f0; padding:.5rem; border-radius:8px; cursor:pointer; font-size:.75rem; }
    .demo-btn:hover { background:#e2e8f0; }
    .role-badge { padding:.2rem .5rem; border-radius:6px; font-size:.7rem; font-weight:600; }
    .role-patient { background:#dcfce7; color:#166534; }
    .role-admin { background:#dbeafe; color:#1e40af; }
    .role-super { background:#fce7f3; color:#9d174d; }
    .role-medecin { background:#ffedd5; color:#9a3412; }

    @media(max-width:768px){
      .login-page{grid-template-columns:1fr}
      .login-left{display:none}
    }
  </style>
</head>
<body>
<div class="login-page">

  <!-- GAUCHE : Présentation hôpital -->
  <div class="login-left">
    <div class="login-left-content">
      <a href="index.php" class="login-back">← Retour aux hôpitaux</a>

      <?php if ($hopital): ?>
        <div class="login-hopital-badge">🏥 Établissement partenaire DOKTA-RDV</div>
        <h1><?= htmlspecialchars($hopital['nom']) ?></h1>
        <p><?= htmlspecialchars($hopital['description'] ?? 'Prenez rendez-vous en ligne avec nos médecins disponibles.') ?></p>
        <div class="login-info-cards">
          <?php if (!empty($hopital['ville'])): ?>
          <div class="login-info-card">
            <span class="ic-icon">📍</span>
            <div><div class="ic-label">Localisation</div><div class="ic-val"><?= htmlspecialchars($hopital['ville']) ?></div></div>
          </div>
          <?php endif; ?>
          <?php if (!empty($hopital['horaires'])): ?>
          <div class="login-info-card">
            <span class="ic-icon">🕐</span>
            <div><div class="ic-label">Horaires</div><div class="ic-val"><?= htmlspecialchars($hopital['horaires']) ?></div></div>
          </div>
          <?php endif; ?>
          <?php if (!empty($hopital['telephone'])): ?>
          <div class="login-info-card">
            <span class="ic-icon">📞</span>
            <div><div class="ic-label">Téléphone</div><div class="ic-val"><?= htmlspecialchars($hopital['telephone']) ?></div></div>
          </div>
          <?php endif; ?>
          <?php
          $nbMed = queryOne("SELECT COUNT(*) n FROM users WHERE hopital_id=? AND role='medecin' AND statut='actif'", [$hopitalId])['n'];
          ?>
          <div class="login-info-card">
            <span class="ic-icon">👨‍⚕️</span>
            <div><div class="ic-label">Médecins disponibles</div><div class="ic-val"><?= $nbMed ?> spécialiste<?= $nbMed > 1 ? 's' : '' ?></div></div>
          </div>
        </div>
      <?php else: ?>
        <div class="login-hopital-badge">✚ DOKTA-RDV</div>
        <h1>Accédez à votre espace de santé</h1>
        <p>Connectez-vous pour gérer vos rendez-vous médicaux en ligne, rapidement et en toute sécurité.</p>
      <?php endif; ?>
    </div>
  </div>

  <!-- DROITE : Formulaire -->
  <div class="login-right">
    <div class="login-form-wrap">
      <div class="login-logo">✚</div>
      <h2><?= $hopital ? 'Accès patient' : 'Connexion' ?></h2>
      <p class="sub"><?= $hopital ? 'Connectez-vous pour prendre rendez-vous à<br><strong>' . htmlspecialchars($hopital['nom']) . '</strong>' : 'Bienvenue sur DOKTA-RDV' ?></p>

      <div class="login-card">
        <?php if ($error): ?>
          <div class="alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" autocomplete="on">
          <?= csrf_field() ?>
          <input type="hidden" name="hopital_id" value="<?= $hopitalId ?>"/>
          <div class="form-group">
            <label>Email</label>
            <input type="email" name="email" autocomplete="username" placeholder="votre@email.fr" required
                   value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"/>
          </div>
          <div class="form-group">
            <label>Mot de passe</label>
            <div class="input-eye">
              <input type="password" id="pwd" name="password" autocomplete="current-password" placeholder="••••" required/>
              <button type="button" onclick="document.getElementById('pwd').type=document.getElementById('pwd').type==='password'?'text':'password'">👁</button>
            </div>
          </div>
          <button class="btn btn-primary btn-full btn-lg" type="submit"
                  style="background:<?= $couleur ?>">
            Se connecter →
          </button>
        </form>

        <div class="divider"><span>Pas encore de compte ?</span></div>
        <a href="register.php?hopital=<?= $hopitalId ?>" class="btn btn-outline btn-full btn-lg">
          Créer un compte gratuit
        </a>
        <?php if (APP_ENV === 'demo'): /* comptes de démonstration : JAMAIS en production */ ?>
        <div class="demo-box" style="margin-top:1.25rem;padding-top:1.25rem;border-top:1px solid var(--border)">
          <p class="demo-title">Comptes démo</p>
          <div class="demo-grid">
            <button class="demo-btn" onclick="fill('julie@email.fr','patient123')"><span class="role-badge role-patient">Patient</span></button>
            <button class="demo-btn" onclick="fill('admin@hopital-central.fr','admin123')"><span class="role-badge role-admin">Admin</span></button>
            <button class="demo-btn" onclick="fill('superadmin@DOKTA-RDV.fr','superadmin123')"><span class="role-badge role-super">Super Admin</span></button>
            <button class="demo-btn" onclick="fill('dr.dupont@hopital-central.fr','medecin123')"><span class="role-badge role-medecin">Médecin</span></button>
          </div>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div> 
<?php if (APP_ENV === 'demo'): ?>
<script>
function fill(e,p){
  document.querySelector('[name=email]').value=e;
  document.getElementById('pwd').value=p;
}
</script>
<?php endif; ?>
</body>
</html>