<?php
/**
 * api/api.php — API JSON de DOKTA-RDV
 * ------------------------------------------------------------
 * Règles de sécurité appliquées à CHAQUE requête :
 *  1. Session valide + compte actif (revérifié en base).
 *  2. Les actions qui modifient des données exigent : méthode POST,
 *     Content-Type: application/json et requête issue du même site
 *     (Origin/Referer) — en complément du cookie SameSite, c'est la
 *     protection CSRF de l'API.
 *  3. Autorisation PAR RESSOURCE : un admin d'hôpital ne peut agir que
 *     sur SON hôpital, un médecin que sur SES rendez-vous, un patient
 *     que sur SES données. Les identifiants envoyés par le client
 *     (medecin_id, hopital_id...) ne sont jamais crus sans vérification.
 *  4. Aucune erreur interne n'est renvoyée au client (journalisées seulement).
 * ------------------------------------------------------------
 */
define('API_REQUEST', true);
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

const MAX_RDV_ACTIFS_PAR_PATIENT = 5;   // limite anti-abus : un patient ne peut pas bloquer tous les créneaux

class ApiError extends RuntimeException {
    public function __construct(string $message, public int $status = 400) { parent::__construct($message); }
}
function fail(string $msg, int $status = 400) { throw new ApiError($msg, $status); }
function out($data, int $status = 200) {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
    exit;
}

/** Corps JSON de la requête (tableau associatif). */
function body(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false || strlen($raw) > 65536) fail('Requête invalide.', 413);
    $d = json_decode($raw, true);
    if (!is_array($d)) fail('Requête invalide.');
    return $d;
}
/** Lit un champ texte : trim, nettoyage, longueur max. */
function str_in(array $d, string $k, int $max, bool $required = false, string $label = ''): string {
    $v = $d[$k] ?? '';
    if (is_array($v) || is_object($v)) fail('Champ invalide.');
    $v = clean_text((string)$v);
    $label = $label ?: $k;
    if ($required && $v === '') fail("Champ obligatoire manquant : $label.");
    if (mb_strlen($v) > $max) fail("Champ trop long : $label (max $max caractères).");
    return $v;
}
function require_role(array $roles, string $role): void {
    if (!in_array($role, $roles, true)) fail('Accès refusé.', 403);
}
function email_taken(string $email, int $exceptId = 0): bool {
    return (bool)queryOne('SELECT id FROM users WHERE email=? AND id<>?', [$email, $exceptId]);
}

try {
    // ── 1. Authentification (session + compte actif en base) ─────────────
    $user = Auth::validate();
    if (!$user) fail('Non authentifié.', 401);
    $uid  = (int)$user['id'];
    $role = $user['role'];
    $hid  = (int)($user['hopital_id'] ?? 0);

    $action   = (string)($_GET['action'] ?? '');
    $readOnly = ['get_medecins', 'get_creneaux'];

    // ── 2. Protection CSRF / méthode pour toute action qui écrit ─────────
    if (!in_array($action, $readOnly, true)) {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Méthode non autorisée.', 405);
        if (stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== 0) fail('Type de contenu invalide.', 415);
        if (!same_origin_request()) fail('Origine de la requête refusée.', 403);
        // Anti-emballement : 120 écritures / minute / utilisateur
        if (throttle_count('api:' . $uid, 60) >= 120) fail('Trop de requêtes, patientez un instant.', 429);
        throttle_hit('api:' . $uid);
    }

    $pdo = getDB();

    switch ($action) {

    // ───────────────────────── LECTURES (publiques pour tout compte connecté) ─────────────────────────
    case 'get_medecins': {
        $hId = (int)($_GET['hopital_id'] ?? 0);
        // Colonnes strictement nécessaires : on n'expose NI e-mail, NI téléphone, NI hash de mot de passe.
        out(query(
            "SELECT m.id, m.prenom, m.nom, m.specialite
             FROM users m JOIN hopitaux h ON h.id=m.hopital_id AND h.statut='actif'
             WHERE m.hopital_id=? AND m.role='medecin' AND m.statut='actif' ORDER BY m.nom", [$hId]));
    }

    case 'get_creneaux': {
        $mId  = (int)($_GET['medecin_id'] ?? 0);
        $hId  = (int)($_GET['hopital_id'] ?? 0);
        $date = (string)($_GET['date'] ?? '');
        if (!valid_date($date)) out([]);
        out(query(
            "SELECT c.id, c.heure_debut, c.heure_fin
             FROM creneaux c
             JOIN users m    ON m.id=c.medecin_id AND m.role='medecin' AND m.statut='actif' AND m.hopital_id=c.hopital_id
             JOIN hopitaux h ON h.id=c.hopital_id AND h.statut='actif'
             WHERE c.medecin_id=? AND c.hopital_id=? AND c.date_dispo=? AND c.disponible=1
               AND TIMESTAMP(c.date_dispo, c.heure_debut) > NOW()
             ORDER BY c.heure_debut", [$mId, $hId, $date]));
    }

    // ───────────────────────── PATIENT : prendre / annuler un RDV ─────────────────────────
    case 'book_rdv': {
        require_role(['patient'], $role);
        $d     = body();
        $cId   = (int)($d['creneau_id'] ?? 0);
        $motif = str_in($d, 'motif', 500, false, 'motif');

        $pdo->beginTransaction();
        // Verrou sur le créneau : deux patients ne peuvent plus réserver le même créneau en même temps.
        $c = queryOne('SELECT * FROM creneaux WHERE id=? FOR UPDATE', [$cId]);
        if (!$c || (int)$c['disponible'] !== 1) fail('Créneau non disponible.', 409);
        if (strtotime($c['date_dispo'] . ' ' . $c['heure_debut']) <= time()) fail('Ce créneau est déjà passé.');

        // Médecin et hôpital sont DÉDUITS du créneau (jamais pris dans la requête du client).
        $med = queryOne("SELECT id,prenom,nom FROM users WHERE id=? AND role='medecin' AND statut='actif' AND hopital_id=?", [$c['medecin_id'], $c['hopital_id']]);
        $hop = queryOne("SELECT id,nom FROM hopitaux WHERE id=? AND statut='actif'", [$c['hopital_id']]);
        if (!$med || !$hop) fail('Créneau non disponible.', 409);

        $actifs = queryOne("SELECT COUNT(*) n FROM rendezvous WHERE patient_id=? AND statut IN ('en_attente','confirme') AND TIMESTAMP(date_rdv,heure_rdv) > NOW()", [$uid]);
        if ((int)$actifs['n'] >= MAX_RDV_ACTIFS_PAR_PATIENT) fail('Vous avez déjà ' . MAX_RDV_ACTIFS_PAR_PATIENT . ' rendez-vous à venir. Annulez-en un pour en prendre un nouveau.');
        $meme = queryOne("SELECT id FROM rendezvous WHERE patient_id=? AND date_rdv=? AND heure_rdv=? AND statut IN ('en_attente','confirme')", [$uid, $c['date_dispo'], $c['heure_debut']]);
        if ($meme) fail('Vous avez déjà un rendez-vous à cette date et cette heure.');

        // Mise à jour conditionnelle : si quelqu'un a pris le créneau entre-temps, 0 ligne modifiée.
        if (executeCount('UPDATE creneaux SET disponible=0 WHERE id=? AND disponible=1', [$cId]) !== 1) fail('Créneau non disponible.', 409);
        $rdvId = execute(
            'INSERT INTO rendezvous (patient_id,medecin_id,hopital_id,creneau_id,date_rdv,heure_rdv,motif) VALUES (?,?,?,?,?,?,?)',
            [$uid, $c['medecin_id'], $c['hopital_id'], $cId, $c['date_dispo'], $c['heure_debut'], $motif]);
        execute('INSERT INTO notifications (user_id,message) VALUES (?,?)', [$uid,
            "RDV demandé : Dr. {$med['prenom']} {$med['nom']} à {$hop['nom']} le " . date('d/m/Y', strtotime($c['date_dispo'])) . ' à ' . date('H:i', strtotime($c['heure_debut'])) . '. En attente de confirmation.']);
        $pdo->commit();
        out(['success' => true, 'id' => $rdvId]);
    }

    case 'cancel_rdv': {
        require_role(['patient'], $role);
        $d     = body();
        $rdvId = (int)($d['rdv_id'] ?? 0);
        $pdo->beginTransaction();
        $rdv = queryOne('SELECT * FROM rendezvous WHERE id=? AND patient_id=? FOR UPDATE', [$rdvId, $uid]);
        if (!$rdv) fail('Rendez-vous introuvable.', 404);
        if (!in_array($rdv['statut'], ['en_attente', 'confirme'], true)) fail('Ce rendez-vous ne peut plus être annulé.');
        execute("UPDATE rendezvous SET statut='annule' WHERE id=?", [$rdvId]);
        execute('UPDATE creneaux SET disponible=1 WHERE id=?', [$rdv['creneau_id']]);
        execute('INSERT INTO notifications (user_id,message) VALUES (?,?)', [$uid, 'Votre RDV du ' . date('d/m/Y', strtotime($rdv['date_rdv'])) . ' a été annulé.']);
        $pdo->commit();
        out(['success' => true]);
    }

    // ───────────────────────── PERSONNEL : changer le statut d'un RDV ─────────────────────────
    case 'update_statut': {
        require_role(['admin_hopital', 'medecin', 'super_admin'], $role);
        $d      = body();
        $rdvId  = (int)($d['rdv_id'] ?? 0);
        $statut = (string)($d['statut'] ?? '');
        if (!in_array($statut, ['en_attente', 'confirme', 'annule', 'termine'], true)) fail('Statut invalide.');

        $pdo->beginTransaction();
        $rdv = queryOne('SELECT * FROM rendezvous WHERE id=? FOR UPDATE', [$rdvId]);
        // Portée : admin → son hôpital ; médecin → ses propres RDV ; super admin → tous.
        $inScope = $rdv && match ($role) {
            'super_admin'   => true,
            'admin_hopital' => (int)$rdv['hopital_id'] === $hid,
            'medecin'       => (int)$rdv['medecin_id'] === $uid,
            default         => false,
        };
        if (!$inScope) fail('Rendez-vous introuvable.', 404);   // même réponse que "n'existe pas" : pas d'énumération
        if (in_array($rdv['statut'], ['annule', 'termine'], true)) fail('Ce rendez-vous est clôturé : modification impossible.');
        if ($rdv['statut'] === $statut) { $pdo->commit(); out(['success' => true]); }

        execute('UPDATE rendezvous SET statut=? WHERE id=?', [$statut, $rdvId]);
        $msgs = ['confirme' => '✅ Votre RDV a été confirmé.', 'annule' => '❌ Votre RDV a été annulé.', 'termine' => '✔ Votre consultation est terminée.'];
        if (isset($msgs[$statut])) execute('INSERT INTO notifications (user_id,message) VALUES (?,?)', [$rdv['patient_id'], $msgs[$statut]]);
        if ($statut === 'annule') execute('UPDATE creneaux SET disponible=1 WHERE id=?', [$rdv['creneau_id']]);
        $pdo->commit();
        out(['success' => true]);
    }

    // ───────────────────────── SUPER ADMIN : hôpitaux ─────────────────────────
    case 'save_hopital': {
        require_role(['super_admin'], $role);
        $d    = body();
        $id   = (int)($d['id'] ?? 0);
        $nom  = str_in($d, 'nom', 200, true, 'nom');
        $ville = str_in($d, 'ville', 100);
        $tel  = str_in($d, 'telephone', 20);
        $mail = str_in($d, 'email', 150);
        $adr  = str_in($d, 'adresse', 500);
        $imgRaw = str_in($d, 'image_url', 500);
        if ($tel !== '' && !valid_phone($tel)) fail('Numéro de téléphone invalide.');
        if ($mail !== '' && !valid_email($mail)) fail('Adresse e-mail de l\'hôpital invalide.');
        $img = null;
        if ($imgRaw !== '') { $img = safe_image_url($imgRaw); if ($img === null) fail('URL d\'image invalide (http/https uniquement, sans espaces ni guillemets).'); }

        if ($id) {
            if (!queryOne('SELECT id FROM hopitaux WHERE id=?', [$id])) fail('Hôpital introuvable.', 404);
            execute('UPDATE hopitaux SET nom=?,ville=?,telephone=?,email=?,adresse=?,image_url=? WHERE id=?', [$nom, $ville, $tel, $mail, $adr, $img, $id]);
            out(['success' => true]);
        }
        $aMail = str_in($d, 'admin_email', 150, true, 'e-mail admin');
        $aPass = (string)($d['admin_password'] ?? '');
        if (!valid_email($aMail)) fail('E-mail administrateur invalide.');
        if ($err = password_policy_error($aPass)) fail($err);
        if (email_taken($aMail)) fail('Email admin déjà utilisé.', 409);
        $aNom = str_in($d, 'admin_nom', 100) ?: 'Admin';
        $aPre = str_in($d, 'admin_prenom', 100);

        $pdo->beginTransaction();
        $hId = execute('INSERT INTO hopitaux (nom,ville,telephone,email,adresse,image_url,statut) VALUES (?,?,?,?,?,?,?)', [$nom, $ville, $tel, $mail, $adr, $img, 'actif']);
        execute('INSERT INTO users (hopital_id,nom,prenom,email,password,role,statut) VALUES (?,?,?,?,?,?,?)', [$hId, $aNom, $aPre, $aMail, hash_password_secure($aPass), 'admin_hopital', 'actif']);
        $pdo->commit();
        out(['success' => true, 'id' => $hId]);
    }

    case 'hopital_action': {
        require_role(['super_admin'], $role);
        $d  = body();
        $id = (int)($d['id'] ?? 0);
        $statut = match ((string)($d['action'] ?? '')) { 'activer' => 'actif', 'suspendre' => 'suspendu', default => null };
        if (!$statut) fail('Action invalide.');
        if (executeCount('UPDATE hopitaux SET statut=? WHERE id=?', [$statut, $id]) === 0 && !queryOne('SELECT id FROM hopitaux WHERE id=?', [$id])) fail('Hôpital introuvable.', 404);
        out(['success' => true]);
    }

    case 'delete_hopital': {
        require_role(['super_admin'], $role);
        $d  = body();
        $id = (int)($d['id'] ?? 0);
        if (!queryOne('SELECT id FROM hopitaux WHERE id=?', [$id])) fail('Hôpital introuvable.', 404);
        $pdo->beginTransaction();
        // users.hopital_id est en "ON DELETE SET NULL" : sans ceci, les comptes admin/médecin
        // resteraient actifs mais orphelins. On les supprime explicitement (RDV/créneaux en cascade).
        execute("DELETE FROM users WHERE hopital_id=? AND role IN ('admin_hopital','medecin')", [$id]);
        execute('DELETE FROM hopitaux WHERE id=?', [$id]);
        $pdo->commit();
        out(['success' => true]);
    }

    // ───────────────────────── ADMIN : médecins ─────────────────────────
    case 'save_medecin': {
        require_role(['admin_hopital', 'super_admin'], $role);
        $d  = body();
        $id = (int)($d['id'] ?? 0);
        $prenom = str_in($d, 'prenom', 100, true, 'prénom');
        $nom    = str_in($d, 'nom', 100, true, 'nom');
        $spec   = str_in($d, 'specialite', 100);
        $mail   = str_in($d, 'email', 150, true, 'e-mail');
        $tel    = str_in($d, 'telephone', 20);
        if (!valid_email($mail)) fail('Adresse e-mail invalide.');
        if ($tel !== '' && !valid_phone($tel)) fail('Numéro de téléphone invalide.');

        // Hôpital cible : celui de l'admin connecté (le client ne choisit pas). Le super admin doit le préciser.
        $targetHid = $role === 'admin_hopital' ? $hid : (int)($d['hopital_id'] ?? 0);
        if ($id) {
            // On ne peut modifier QUE un compte de rôle médecin, et (pour un admin) de SON hôpital.
            $m = queryOne("SELECT id,hopital_id FROM users WHERE id=? AND role='medecin'", [$id]);
            if (!$m || ($role === 'admin_hopital' && (int)$m['hopital_id'] !== $hid)) fail('Médecin introuvable.', 404);
            if (email_taken($mail, $id)) fail('Email déjà utilisé.', 409);
            execute("UPDATE users SET nom=?,prenom=?,specialite=?,email=?,telephone=? WHERE id=? AND role='medecin'", [$nom, $prenom, $spec, $mail, $tel, $id]);
            out(['success' => true]);
        }
        if (!$targetHid || !queryOne('SELECT id FROM hopitaux WHERE id=?', [$targetHid])) fail('Hôpital invalide.');
        $pass = (string)($d['password'] ?? '');
        if ($err = password_policy_error($pass)) fail($err);   // plus de mot de passe par défaut "medecin123"
        if (email_taken($mail)) fail('Email déjà utilisé.', 409);
        $newId = execute('INSERT INTO users (hopital_id,nom,prenom,email,password,telephone,role,specialite,statut) VALUES (?,?,?,?,?,?,?,?,?)',
            [$targetHid, $nom, $prenom, $mail, hash_password_secure($pass), $tel, 'medecin', $spec, 'actif']);
        out(['success' => true, 'id' => $newId]);
    }

    case 'delete_medecin': {
        require_role(['admin_hopital', 'super_admin'], $role);
        $d  = body();
        $id = (int)($d['id'] ?? 0);
        $m = queryOne("SELECT id,hopital_id FROM users WHERE id=? AND role='medecin'", [$id]);
        if (!$m || ($role === 'admin_hopital' && (int)$m['hopital_id'] !== $hid)) fail('Médecin introuvable.', 404);
        $pdo->beginTransaction();
        // Prévenir les patients dont le RDV à venir va disparaître avec le médecin (suppression en cascade).
        foreach (query("SELECT patient_id,date_rdv,heure_rdv FROM rendezvous WHERE medecin_id=? AND statut IN ('en_attente','confirme') AND TIMESTAMP(date_rdv,heure_rdv) > NOW()", [$id]) as $r) {
            execute('INSERT INTO notifications (user_id,message) VALUES (?,?)', [$r['patient_id'],
                '❌ Votre RDV du ' . date('d/m/Y', strtotime($r['date_rdv'])) . ' à ' . date('H:i', strtotime($r['heure_rdv'])) . ' a été annulé (médecin indisponible).']);
        }
        execute("DELETE FROM users WHERE id=? AND role='medecin'", [$id]);
        $pdo->commit();
        out(['success' => true]);
    }

    // ───────────────────────── ADMIN : patients ─────────────────────────
    case 'save_patient': {
        require_role(['admin_hopital', 'super_admin'], $role);
        $d = body();
        $prenom = str_in($d, 'prenom', 100, true, 'prénom');
        $nom    = str_in($d, 'nom', 100, true, 'nom');
        $mail   = str_in($d, 'email', 150, true, 'e-mail');
        $tel    = str_in($d, 'telephone', 20);
        $ddn    = str_in($d, 'date_naissance', 10);
        $pass   = (string)($d['password'] ?? '');
        if (!valid_email($mail)) fail('Adresse e-mail invalide.');
        if ($tel !== '' && !valid_phone($tel)) fail('Numéro de téléphone invalide.');
        if ($ddn !== '' && (!valid_date($ddn) || $ddn > date('Y-m-d') || $ddn < '1900-01-01')) fail('Date de naissance invalide.');
        if ($err = password_policy_error($pass)) fail($err);
        if (email_taken($mail)) fail('Email déjà utilisé.', 409);
        $pdo->beginTransaction();
        $pId = execute('INSERT INTO users (nom,prenom,email,password,telephone,date_naissance,role,statut) VALUES (?,?,?,?,?,?,?,?)',
            [$nom, $prenom, $mail, hash_password_secure($pass), $tel, $ddn ?: null, 'patient', 'actif']);
        execute('INSERT INTO notifications (user_id,message) VALUES (?,?)', [$pId, "Bienvenue $prenom ! Votre compte DOKTA-RDV a été créé."]);
        $pdo->commit();
        out(['success' => true, 'id' => $pId]);
    }

    case 'delete_patient': {
        require_role(['admin_hopital', 'super_admin'], $role);
        $d   = body();
        $pid = (int)($d['id'] ?? 0);
        if (!queryOne("SELECT id FROM users WHERE id=? AND role='patient'", [$pid])) fail('Patient introuvable.', 404);
        if ($role === 'admin_hopital') {
            // Un patient est un compte GLOBAL (multi-hôpitaux). Un admin ne peut le supprimer que s'il
            // est suivi dans SON hôpital ET n'a aucun rendez-vous dans un autre établissement.
            $ici    = queryOne('SELECT COUNT(*) n FROM rendezvous WHERE patient_id=? AND hopital_id=?',  [$pid, $hid]);
            $ailleurs = queryOne('SELECT COUNT(*) n FROM rendezvous WHERE patient_id=? AND hopital_id<>?', [$pid, $hid]);
            if ((int)$ici['n'] === 0 || (int)$ailleurs['n'] > 0) fail('Suppression non autorisée : ce patient est aussi suivi dans un autre établissement.', 403);
        }
        $pdo->beginTransaction();
        // Libère les créneaux réservés par ce patient (sinon ils resteraient bloqués à jamais).
        execute('UPDATE creneaux c JOIN rendezvous r ON r.creneau_id=c.id SET c.disponible=1 WHERE r.patient_id=?', [$pid]);
        execute("DELETE FROM users WHERE id=? AND role='patient'", [$pid]);   // RDV + notifications supprimés en cascade
        $pdo->commit();
        out(['success' => true]);
    }

    // ───────────────────────── MÉDECIN / ADMIN : créneaux ─────────────────────────
    case 'save_creneau': {
        require_role(['admin_hopital', 'medecin'], $role);
        $d     = body();
        $date  = str_in($d, 'date_dispo', 10, true, 'date');
        $debut = norm_time(str_in($d, 'heure_debut', 8, true, 'heure de début') );
        $fin   = norm_time(str_in($d, 'heure_fin', 8, true, 'heure de fin'));
        if (!valid_date($date) || $date < date('Y-m-d')) fail('Date invalide (le passé n\'est pas autorisé).');
        if (!$debut || !$fin || $fin <= $debut) fail('Horaires invalides (la fin doit suivre le début).');

        if ($role === 'medecin') {
            $mId = $uid;                                   // un médecin ne crée que SES créneaux
        } else {
            $mId = (int)($d['medecin_id'] ?? 0);           // un admin : uniquement un médecin actif de SON hôpital
            if (!queryOne("SELECT id FROM users WHERE id=? AND role='medecin' AND statut='actif' AND hopital_id=?", [$mId, $hid])) fail('Médecin introuvable dans votre établissement.', 404);
        }
        // L'hôpital est toujours celui du compte connecté (jamais celui envoyé par le client).
        if (queryOne('SELECT id FROM creneaux WHERE medecin_id=? AND date_dispo=? AND heure_debut < ? AND heure_fin > ?', [$mId, $date, $fin, $debut])) {
            fail('Ce créneau chevauche un créneau existant.', 409);
        }
        execute('INSERT INTO creneaux (medecin_id,hopital_id,date_dispo,heure_debut,heure_fin) VALUES (?,?,?,?,?)', [$mId, $hid, $date, $debut, $fin]);
        out(['success' => true]);
    }

    // ───────────────────────── Profil (tout compte connecté, UNIQUEMENT le sien) ─────────────────────────
    case 'update_profil': {
        $d      = body();
        $prenom = str_in($d, 'prenom', 100, true, 'prénom');
        $nom    = str_in($d, 'nom', 100, true, 'nom');
        $mail   = str_in($d, 'email', 150, true, 'e-mail');
        $tel    = str_in($d, 'telephone', 20);
        $ddn    = str_in($d, 'date_naissance', 10);
        $newPwd = (string)($d['new_password'] ?? '');
        $curPwd = (string)($d['current_password'] ?? '');
        if (!valid_email($mail)) fail('Adresse e-mail invalide.');
        if ($tel !== '' && !valid_phone($tel)) fail('Numéro de téléphone invalide.');
        if ($ddn !== '' && (!valid_date($ddn) || $ddn > date('Y-m-d') || $ddn < '1900-01-01')) fail('Date de naissance invalide.');
        if (email_taken($mail, $uid)) fail('Email déjà utilisé.', 409);

        $row = queryOne('SELECT email,password FROM users WHERE id=?', [$uid]);
        $emailChange = strcasecmp($row['email'], $mail) !== 0;
        if ($newPwd !== '') {
            if ($err = password_policy_error($newPwd)) fail($err);
            if (isset($d['confirm_password']) && (string)$d['confirm_password'] !== $newPwd) fail('La confirmation du mot de passe ne correspond pas.');
        }
        // Changer l'e-mail ou le mot de passe exige le mot de passe ACTUEL (protège d'une session volée).
        if ($emailChange || $newPwd !== '') {
            if (throttle_count('pwcheck:' . $uid, 900) >= 5) fail('Trop de tentatives. Réessayez dans 15 minutes.', 429);
            if ($curPwd === '' || !password_verify($curPwd, $row['password'])) {
                throttle_hit('pwcheck:' . $uid);
                fail('Mot de passe actuel requis et incorrect pour modifier l\'e-mail ou le mot de passe.', 403);
            }
            throttle_clear('pwcheck:' . $uid);
        }
        execute('UPDATE users SET nom=?,prenom=?,email=?,telephone=?,date_naissance=? WHERE id=?', [$nom, $prenom, $mail, $tel, $ddn ?: null, $uid]);
        if ($newPwd !== '') {
            execute('UPDATE users SET password=? WHERE id=?', [hash_password_secure($newPwd), $uid]);
            session_regenerate_id(true);   // l'ancienne session ne doit plus être réutilisable
        }
        out(['success' => true]);
    }

    default:
        fail('Action inconnue.', 404);
    }

} catch (ApiError $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    out(['error' => $e->getMessage()], $e->status);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('[DOKTA API] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    out(['error' => 'Erreur serveur. Veuillez réessayer.'], 500);   // jamais le détail de l'exception
}
