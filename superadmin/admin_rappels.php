<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rappels.php';
Auth::require('super_admin');

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $message = '✗ Formulaire expiré, veuillez réessayer.';
    } else {
        if (isset($_POST['save_config'])) {
            $newSid  = trim((string)($_POST['twilio_sid'] ?? ''));
            $newFrom = trim((string)($_POST['twilio_from'] ?? ''));
            $newTok  = trim((string)($_POST['twilio_token'] ?? ''));
            $heures  = (int)($_POST['heures_avant'] ?? 24);
            if (!in_array($heures, [12, 24, 48], true)) $heures = 24;
            if ($newSid !== '' && !preg_match('/^AC[0-9a-fA-F]{32}$/', $newSid)) {
                $message = '✗ Account SID invalide (format attendu : AC suivi de 32 caractères hexadécimaux).';
            } elseif ($newFrom !== '' && !preg_match('/^\+[0-9]{8,15}$/', $newFrom)) {
                $message = '✗ Numéro d\'envoi invalide (format international attendu, ex : +15551234567).';
            } else {
                setConfig('twilio_sid', $newSid);
                setConfig('twilio_from', $newFrom);
                // Le jeton n'est jamais renvoyé dans la page : champ vide = on conserve l'ancien.
                if ($newTok !== '') setConfig('twilio_token', $newTok);
                if ($newSid === '' ) setConfig('twilio_token', '');
                setConfig('rappel_heures_avant', (string)$heures);
                $message = '✓ Configuration enregistrée.';
            }
        }
        if (isset($_POST['test_sms'])) {
            $phone = trim((string)($_POST['test_phone'] ?? ''));
            if (!valid_phone($phone)) {
                $message = '✗ Numéro de téléphone invalide.';
            } elseif (throttle_count('smstest:' . (int)$_SESSION['user_id'], 3600) >= 10) {
                $message = '✗ Trop de tests d\'envoi, réessayez plus tard.';
            } else {
                throttle_hit('smstest:' . (int)$_SESSION['user_id']);
                $result = sendSMS($phone, 'Ceci est un SMS de test depuis DOKTA-RDV ✚');
                $message = $result['success']
                    ? ($result['simulated'] ? '✓ Test simulé (configurez Twilio pour un envoi réel).' : '✓ SMS de test envoyé avec succès !')
                    : '✗ Échec : ' . $result['error'];
            }
        }
        if (isset($_POST['run_now'])) {
            // Exécution directe (session super admin + jeton CSRF) : plus besoin d'exposer une clé dans un lien.
            $r = run_rappels();
            $message = $r['locked']
                ? '✗ Une exécution est déjà en cours.'
                : sprintf('✓ Rappels traités : %d envoyés, %d échecs, %d RDV examinés.', $r['envoyes'], $r['echecs'], $r['examines']);
        }
        if (isset($_POST['regen_cron_key'])) {
            setConfig('cron_key', bin2hex(random_bytes(24)));
            $message = '✓ Nouvelle clé du cron générée. Mettez à jour votre tâche planifiée.';
        }
    }
}

$sid   = getConfig('twilio_sid');
$tokenSet = getConfig('twilio_token') !== '';
$from  = getConfig('twilio_from');
$heuresAvant = getConfig('rappel_heures_avant') ?: '24';
$configured  = $sid && $tokenSet && $from;
$cronKey = getConfig('cron_key');
if ($cronKey === '') { $cronKey = bin2hex(random_bytes(24)); setConfig('cron_key', $cronKey); }

$logs = query(
    "SELECT rl.*, r.date_rdv, r.heure_rdv, u.prenom p_prenom, u.nom p_nom, m.prenom med_prenom, m.nom med_nom
     FROM rappels_log rl
     JOIN rendezvous r ON r.id = rl.rdv_id
     JOIN users u ON u.id = r.patient_id
     JOIN users m ON m.id = r.medecin_id
     ORDER BY rl.created_at DESC LIMIT 50"
);
$stats = [
    'envoyes' => queryOne("SELECT COUNT(*) n FROM rappels_log WHERE statut='envoye'")['n'],
    'echecs'  => queryOne("SELECT COUNT(*) n FROM rappels_log WHERE statut='echec'")['n'],
    'a_venir' => queryOne("SELECT COUNT(*) n FROM rendezvous WHERE statut='confirme' AND rappel_envoye=0 AND date_rdv >= CURDATE()")['n'],
];
?>
<!DOCTYPE html><html lang="fr"><head>
<meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>Rappels SMS — DOKTA-RDV</title>
<link rel="stylesheet" href="../assets/style.css"/>
</head><body>
<?php $me = Auth::user(); include __DIR__ . '/../includes/partials/navbar.php'; ?>
<div class="page-layout">
  <?php include __DIR__ . '/../includes/partials/sidebar_superadmin.php'; ?>
  <main class="main-content">
    <div class="page-header">
      <h1>📲 Rappels SMS automatiques</h1>
      <span class="statut-badge <?= $configured ? 'statut-confirme' : 'statut-en_attente' ?>">
        <?= $configured ? '✅ Twilio configuré' : '⚠️ Mode simulation (Twilio non configuré)' ?>
      </span>
    </div>

    <?php if ($message): ?>
      <div class="alert <?= str_starts_with($message,'✓') ? 'alert-success' : (str_starts_with($message,'✗') ? 'alert-error' : 'alert-success') ?>"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <div class="stats-grid">
      <div class="stat-card blue"><div class="stat-icon">📤</div><div class="stat-val"><?= $stats['envoyes'] ?></div><div class="stat-label">Rappels envoyés</div></div>
      <div class="stat-card orange"><div class="stat-icon">⚠️</div><div class="stat-val"><?= $stats['echecs'] ?></div><div class="stat-label">Échecs</div></div>
      <div class="stat-card green"><div class="stat-icon">⏳</div><div class="stat-val"><?= $stats['a_venir'] ?></div><div class="stat-label">RDV en attente de rappel</div></div>
    </div>

    <!-- CONFIGURATION TWILIO -->
    <div class="card mt2">
      <h2 class="card-title">⚙️ Configuration Twilio</h2>
      <p class="text-muted" style="margin-bottom:1rem">
        Créez un compte gratuit sur <a href="https://www.twilio.com" target="_blank" style="color:var(--primary)">twilio.com</a>,
        récupérez votre Account SID, Auth Token et numéro d'envoi, puis renseignez-les ci-dessous.
        Tant que ces champs sont vides, les rappels sont simulés (journalisés mais pas réellement envoyés).
      </p>
      <form method="POST">
        <?= csrf_field() ?>
        <div class="form-row">
          <div class="form-group"><label>Account SID</label><input type="text" name="twilio_sid" class="form-input" value="<?= htmlspecialchars($sid) ?>" placeholder="ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"/></div>
          <div class="form-group"><label>Auth Token</label><input type="password" name="twilio_token" class="form-input" value="" autocomplete="new-password" placeholder="<?= $tokenSet ? '•••••• (enregistré — laisser vide pour le conserver)' : 'Auth Token Twilio' ?>"/></div>
        </div>
        <div class="form-row">
          <div class="form-group"><label>Numéro d'envoi Twilio</label><input type="text" name="twilio_from" class="form-input" value="<?= htmlspecialchars($from) ?>" placeholder="+15551234567"/></div>
          <div class="form-group"><label>Heures avant le RDV</label>
            <select name="heures_avant" class="form-input">
              <option value="24" <?= $heuresAvant=='24'?'selected':'' ?>>24 heures avant</option>
              <option value="48" <?= $heuresAvant=='48'?'selected':'' ?>>48 heures avant</option>
              <option value="12" <?= $heuresAvant=='12'?'selected':'' ?>>12 heures avant</option>
            </select>
          </div>
        </div>
        <button type="submit" name="save_config" value="1" class="btn btn-primary">Enregistrer la configuration</button>
      </form>
    </div>

    <!-- TEST SMS -->
    <div class="card mt2">
      <h2 class="card-title">🧪 Tester l'envoi</h2>
      <form method="POST" style="display:flex;gap:.75rem;align-items:flex-end;flex-wrap:wrap">
        <?= csrf_field() ?>
        <div class="form-group" style="flex:1;min-width:200px;margin-bottom:0">
          <label>Numéro de téléphone de test</label>
          <input type="tel" name="test_phone" class="form-input" placeholder="06 12 34 56 78" required/>
        </div>
        <button type="submit" name="test_sms" value="1" class="btn btn-outline">📤 Envoyer un SMS test</button>
      </form>
    </div>

    <!-- DÉCLENCHEMENT MANUEL -->
    <div class="card mt2">
      <h2 class="card-title">▶️ Déclenchement manuel</h2>
      <p class="text-muted" style="margin-bottom:1rem">
        Le système est conçu pour tourner automatiquement via une tâche planifiée (voir <code>RAPPELS_INSTALLATION.md</code>).
        Vous pouvez aussi déclencher l'envoi manuellement à tout moment :
      </p>
      <form method="POST" style="display:inline">
        <?= csrf_field() ?>
        <button type="submit" name="run_now" value="1" class="btn btn-secondary">▶️ Exécuter les rappels maintenant</button>
      </form>
    </div>

    <!-- CLÉ DU CRON -->
    <div class="card mt2">
      <h2 class="card-title">🔑 Clé de la tâche planifiée (cron)</h2>
      <p class="text-muted" style="margin-bottom:1rem">
        Pour déclencher les rappels depuis un service de cron web, appelez <code>/cron/cron_rappels.php</code>
        en envoyant cette clé dans l'en-tête HTTP <code>X-Cron-Key</code>. Ne la partagez pas et ne la publiez jamais dans le code.
      </p>
      <input type="text" class="form-input" readonly value="<?= htmlspecialchars($cronKey) ?>" onclick="this.select()" style="font-family:monospace"/>
      <form method="POST" style="margin-top:.75rem" onsubmit="return confirm('Régénérer la clé ? L\'ancienne ne fonctionnera plus.')">
        <?= csrf_field() ?>
        <button type="submit" name="regen_cron_key" value="1" class="btn btn-outline btn-sm">♻️ Régénérer la clé</button>
      </form>
    </div>

    <!-- LOGS -->
    <div class="card mt2">
      <h2 class="card-title">📋 Historique des rappels (50 derniers)</h2>
      <?php if (empty($logs)): ?>
        <p class="text-muted">Aucun rappel envoyé pour le moment.</p>
      <?php else: ?>
      <div class="table-wrap">
      <table class="table">
        <thead><tr><th>Date</th><th>Patient</th><th>Médecin</th><th>RDV le</th><th>Téléphone</th><th>Statut</th></tr></thead>
        <tbody>
        <?php foreach ($logs as $l): ?>
          <tr>
            <td><?= date('d/m/Y H:i', strtotime($l['created_at'])) ?></td>
            <td><?= htmlspecialchars($l['p_prenom'].' '.$l['p_nom']) ?></td>
            <td>Dr. <?= htmlspecialchars($l['med_prenom'].' '.$l['med_nom']) ?></td>
            <td><?= date('d/m/Y', strtotime($l['date_rdv'])) ?> à <?= date('H:i', strtotime($l['heure_rdv'])) ?></td>
            <td><?= htmlspecialchars($l['telephone'] ?? '—') ?></td>
            <td>
              <?php if ($l['statut']==='envoye'): ?>
                <span class="statut-badge statut-confirme">✓ Envoyé</span>
              <?php else: ?>
                <span class="statut-badge statut-annule" title="<?= htmlspecialchars($l['erreur']??'') ?>">✗ Échec</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <?php endif; ?>
    </div>

  </main>
</div>
</body></html>
