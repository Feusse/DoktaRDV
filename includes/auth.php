<?php
/**
 * includes/auth.php
 * ------------------------------------------------------------
 * Middleware d'authentification — inclure en haut de chaque page protégée.
 *
 * Points de sécurité :
 *  - session durcie (cookie HttpOnly + SameSite, expiration, mode strict)
 *  - le rôle, le statut du compte et l'hôpital sont RE-LUS EN BASE à chaque
 *    requête protégée : un compte suspendu, supprimé ou dont le rôle a changé
 *    perd immédiatement son accès (on ne se fie pas aveuglément à la session)
 *  - l'identifiant de session est régénéré à la connexion (anti fixation)
 *
 * NOTE SUR LES CHEMINS : les chemins de redirection sont calculés
 * dynamiquement (base_url()), ce qui permet un déploiement à la racine
 * d'un domaine comme dans un sous-dossier.
 * ------------------------------------------------------------
 */

require_once __DIR__.'/db.php';
start_secure_session();

/**
 * Calcule le chemin de base du site (ex: '/' ou '/reservation26/')
 * à partir du dossier où se trouve ce fichier (includes/).
 */
function base_url(): string {
    $docRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/');
    $root = dirname(__DIR__);
    $base = str_replace($docRoot, '', $root);
    $base = str_replace('\\', '/', $base);
    return rtrim($base, '/') . '/';
}

class Auth {
    private static ?array $validated = null;

    /** Colonnes publiques d'un utilisateur (JAMAIS le hash du mot de passe). */
    private const USER_COLS = 'u.id,u.hopital_id,u.nom,u.prenom,u.email,u.telephone,u.date_naissance,u.role,u.specialite,u.statut,u.created_at';

    public static function check(): bool { return isset($_SESSION['user_id']); }
    public static function role(): string { return $_SESSION['role'] ?? ''; }
    public static function hopitalId(): ?int {
        return isset($_SESSION['hopital_id']) ? (int)$_SESSION['hopital_id'] : null;
    }

    /**
     * Vérifie la session ET l'état réel du compte en base.
     * Retourne la ligne utilisateur (à jour) ou null (et nettoie la session).
     */
    public static function validate(): ?array {
        if (self::$validated !== null) return self::$validated;
        if (!self::check()) return null;

        $u = queryOne(
            'SELECT u.id,u.role,u.hopital_id,u.statut,h.statut AS hop_statut
             FROM users u LEFT JOIN hopitaux h ON h.id=u.hopital_id WHERE u.id=?',
            [(int)$_SESSION['user_id']]
        );
        $ok = $u && $u['statut'] === 'actif';
        // Admins et médecins d'un hôpital suspendu : accès coupé.
        if ($ok && in_array($u['role'], ['admin_hopital', 'medecin'], true) && $u['hop_statut'] !== 'actif') $ok = false;
        if (!$ok) { self::clear(); return null; }

        // Resynchronise la session avec la base (rôle/hôpital faisant foi).
        $_SESSION['role']       = $u['role'];
        $_SESSION['hopital_id'] = $u['hopital_id'];
        return self::$validated = $u;
    }

    /** Utilisateur courant (colonnes publiques + nom de l'hôpital), ou null. */
    public static function user(): ?array {
        if (!self::validate()) return null;
        return queryOne(
            'SELECT '.self::USER_COLS.', h.nom as hopital_nom
             FROM users u LEFT JOIN hopitaux h ON h.id=u.hopital_id WHERE u.id=?',
            [(int)$_SESSION['user_id']]
        );
    }

    /**
     * Exige une session valide et l'un des rôles donnés. Les rôles valides sont
     * EXACTEMENT ceux de users.role : 'super_admin','admin_hopital','medecin','patient'.
     */
    public static function require(string ...$roles): void {
        $base = base_url();
        $u = self::validate();
        if (!$u) { header('Location: ' . $base . 'login.php?error=expired'); exit; }
        if (!empty($roles) && !in_array($u['role'], $roles, true)) {
            header('Location: ' . $base . 'login.php?error=access'); exit;
        }
    }

    public static function login(array $user): void {
        session_regenerate_id(true);               // anti fixation de session
        unset($_SESSION['csrf']);                  // nouveau jeton CSRF après connexion
        $_SESSION['user_id']    = (int)$user['id'];
        $_SESSION['role']       = $user['role'];
        $_SESSION['nom']        = $user['prenom'].' '.$user['nom'];
        $_SESSION['hopital_id'] = $user['hopital_id'];
        $_SESSION['login_time'] = time();
        self::$validated = null;
    }

    /** Vide la session sans la détruire (garde un cookie valide pour la page de connexion). */
    public static function clear(): void {
        $_SESSION = [];
        session_regenerate_id(true);
        self::$validated = null;
    }

    /** Déconnexion complète : session détruite + cookie supprimé. */
    public static function logout(): void {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000, 'path' => $p['path'], 'domain' => $p['domain'],
                'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Lax',
            ]);
        }
        session_destroy();
        self::$validated = null;
    }

    public static function redirectByRole(): void {
        $base = base_url();
        $map = [
            'super_admin'   => $base . 'superadmin/superadmin.php',
            'admin_hopital' => $base . 'admin/admin_hopital.php',
            'medecin'       => $base . 'medecin/medecin.php',
            'patient'       => $base . 'patient/patient.php',
        ];
        $role = self::role();
        header('Location: '.($map[$role] ?? $base.'login.php'));
        exit;
    }
}
