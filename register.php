<?php
require_once __DIR__ . '/includes/auth.php';
if (Auth::check() && Auth::validate()) Auth::redirectByRole();
$error = $success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nom    = clean_text((string)($_POST['nom'] ?? ''));
    $prenom = clean_text((string)($_POST['prenom'] ?? ''));
    $email  = trim((string)($_POST['email'] ?? ''));
    $tel    = clean_text((string)($_POST['telephone'] ?? ''));
    $ddn    = trim((string)($_POST['date_naissance'] ?? ''));
    $pass   = (string)($_POST['password'] ?? '');
    $conf   = (string)($_POST['confirm'] ?? '');
    $bIp    = 'register:' . client_ip();

    if (!csrf_check())                                   $error = 'Formulaire expiré. Veuillez réessayer.';
    elseif (throttle_count($bIp, 3600) >= 10)            $error = "Trop d'inscriptions depuis cette adresse. Réessayez plus tard.";
    elseif (!$nom || !$prenom || !$email || !$pass)      $error = 'Champs obligatoires manquants.';
    elseif (mb_strlen($nom) > 100 || mb_strlen($prenom) > 100) $error = 'Nom ou prénom trop long.';
    elseif (!valid_email($email))                        $error = 'Adresse e-mail invalide.';
    elseif ($tel !== '' && !valid_phone($tel))           $error = 'Numéro de téléphone invalide.';
    elseif ($ddn !== '' && (!valid_date($ddn) || $ddn > date('Y-m-d') || $ddn < '1900-01-01')) $error = 'Date de naissance invalide.';
    elseif ($pass !== $conf)                             $error = 'Mots de passe différents.';
    elseif ($perr = password_policy_error($pass))        $error = $perr;
    elseif (queryOne('SELECT id FROM users WHERE email=?', [$email])) { throttle_hit($bIp); $error = 'Email déjà utilisé.'; }
    else {
        throttle_hit($bIp);
        $id = execute('INSERT INTO users (nom,prenom,email,password,telephone,date_naissance,role) VALUES (?,?,?,?,?,?,?)',
            [$nom, $prenom, $email, hash_password_secure($pass), $tel, $ddn ?: null, 'patient']);
        execute('INSERT INTO notifications (user_id,message) VALUES (?,?)', [$id, "Bienvenue $prenom ! Votre compte DOKTA-RDV est créé."]);
        $success = 'Compte créé ! Vous pouvez vous connecter.';
    }
}
?>
<!DOCTYPE html><html lang="fr"><head>
<meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>DOKTA-RDV  — Inscription</title><link rel="stylesheet" href="assets/style.css"/>
</head><body class="auth-page">
<div class="auth-wrap">
  <div class="auth-hero">
    <div class="hero-logo">✚</div><h1>DOKTA-RDV </h1>
    <p>Rejoignez des milliers de patients qui gèrent leurs RDV en ligne.</p>
  </div>
  <div class="auth-form-side">
    <div class="auth-card">
      <h2>Créer un compte patient</h2>
      <?php if($error):?><div class="alert alert-error"><?=htmlspecialchars($error)?></div><?php endif;?>
      <?php if($success):?><div class="alert alert-success"><?=htmlspecialchars($success)?> <a href="login.php">Se connecter →</a></div><?php endif;?>
      <form method="POST">
        <?= csrf_field() ?>
        <div class="form-row">
          <div class="form-group"><label>Prénom *</label><input type="text" name="prenom" value="<?=htmlspecialchars($_POST['prenom']??'')?>" required/></div>
          <div class="form-group"><label>Nom *</label><input type="text" name="nom" value="<?=htmlspecialchars($_POST['nom']??'')?>" required/></div>
        </div>
        <div class="form-group"><label>Email *</label><input type="email" name="email" value="<?=htmlspecialchars($_POST['email']??'')?>" required/></div>
        <div class="form-row">
          <div class="form-group"><label>Téléphone</label><input type="tel" name="telephone" value="<?=htmlspecialchars($_POST['telephone']??'')?>"/></div>
          <div class="form-group"><label>Date de naissance</label><input type="date" name="date_naissance" value="<?=htmlspecialchars($_POST['date_naissance']??'')?>"/></div>
        </div>
        <div class="form-row">
          <div class="form-group"><label>Mot de passe *</label><input type="password" name="password" minlength="10" autocomplete="new-password" placeholder="10 caractères min., lettres + chiffres" required/></div>
          <div class="form-group"><label>Confirmer *</label><input type="password" name="confirm" minlength="10" autocomplete="new-password" required/></div>
        </div>
        <button class="btn btn-primary btn-full" type="submit">Créer mon compte</button>
      </form>
      <div class="auth-sep"></div>
      <p style="text-align:center;font-size:.85rem">Déjà inscrit ? <a href="login.php">Se connecter</a></p>
    </div>
  </div>
</div>
</body></html>
