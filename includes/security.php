<?php
/**
 * includes/security.php
 * ------------------------------------------------------------
 * Briques de sécurité communes à toute l'application :
 *   - configuration des erreurs (jamais affichées en production)
 *   - en-têtes HTTP de sécurité
 *   - démarrage de session durci (cookie HttpOnly / SameSite / Secure)
 *   - jeton CSRF pour les formulaires HTML
 *   - fonctions d'échappement et de validation des entrées
 *   - limitation de débit (anti brute-force) basée sur la table rate_limits
 *
 * Ce fichier est chargé automatiquement par includes/db.php.
 * ------------------------------------------------------------
 */

if (!defined('APP_ENV')) define('APP_ENV', 'production'); // production | demo | development

/* ── 1. Gestion des erreurs ─────────────────────────────────────
 * Les erreurs PHP ne doivent JAMAIS être affichées aux visiteurs
 * (chemins, requêtes SQL, identifiants...). Elles vont dans le log
 * du serveur. En mode "development" uniquement, on les affiche. */
error_reporting(E_ALL);
ini_set('log_errors', '1');
ini_set('display_errors', APP_ENV === 'development' ? '1' : '0');
ini_set('display_startup_errors', APP_ENV === 'development' ? '1' : '0');

/* ── 2. Utilitaires de base ─────────────────────────────────── */
function is_https(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

/** Adresse IP du client. On ne fait volontairement PAS confiance à X-Forwarded-For (falsifiable). */
function client_ip(): string {
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

/** Échappement HTML (à utiliser pour TOUTE donnée affichée dans une page). */
function e($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** JSON sûr à placer dans un attribut HTML, ex: onclick="fn(<?= json_attr($row) ?>)". */
function json_attr($v): string {
    return htmlspecialchars(
        json_encode($v, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
        ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'
    );
}

/** JSON sûr à placer dans un bloc <script> (empêche de fermer la balise). */
function json_script($v): string {
    return json_encode($v, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
}

/* ── 3. En-têtes HTTP de sécurité ───────────────────────────── */
function send_security_headers(): void {
    if (PHP_SAPI === 'cli' || headers_sent()) return;
    header_remove('X-Powered-By');
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), camera=(), microphone=(), payment=()');
    header('Cross-Origin-Opener-Policy: same-origin');
    // CSP : les pages utilisent des scripts/styles inline, d'où 'unsafe-inline'. Elle bloque
    // malgré tout le chargement de scripts depuis des domaines non listés, les iframes, etc.
    header("Content-Security-Policy: default-src 'self'; "
         . "img-src 'self' data: https:; "
         . "style-src 'self' 'unsafe-inline'; "
         . "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; "
         . "font-src 'self' data:; connect-src 'self'; "
         . "object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
    if (is_https()) header('Strict-Transport-Security: max-age=15552000');
}
send_security_headers();

/* ── 4. Session durcie ──────────────────────────────────────── */
const SESSION_IDLE_SECONDS     = 7200;   // déconnexion après 2 h d'inactivité
const SESSION_ABSOLUTE_SECONDS = 43200;  // et au maximum 12 h après la connexion

function start_secure_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    ini_set('session.use_strict_mode', '1');   // refuse les identifiants de session inconnus
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    session_name('DOKTASESSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => is_https(),
        'httponly' => true,       // inaccessible au JavaScript (limite le vol de session via XSS)
        'samesite' => 'Lax',      // le cookie n'est pas envoyé sur les requêtes POST inter-sites (anti-CSRF)
    ]);
    session_start();

    $now = time();
    $expired = isset($_SESSION['last_activity']) && ($now - (int)$_SESSION['last_activity'] > SESSION_IDLE_SECONDS);
    if (!$expired && isset($_SESSION['login_time']) && ($now - (int)$_SESSION['login_time'] > SESSION_ABSOLUTE_SECONDS)) {
        $expired = true;
    }
    if ($expired) {
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['flash_expired'] = true;
    }
    $_SESSION['last_activity'] = $now;
}

/* ── 5. CSRF (formulaires HTML) ─────────────────────────────── */
function csrf_token(): string {
    start_secure_session();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '"/>';
}
function csrf_check(): bool {
    start_secure_session();
    $t = $_POST['csrf_token'] ?? '';
    return is_string($t) && $t !== '' && isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $t);
}

/** Vérifie que la requête provient bien de notre propre site (en-têtes Origin / Referer). */
function same_origin_request(): bool {
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    foreach (['HTTP_ORIGIN', 'HTTP_REFERER'] as $h) {
        if (!empty($_SERVER[$h])) {
            $p = parse_url((string)$_SERVER[$h]);
            if (!$p || empty($p['host'])) return false;
            $origin = strtolower($p['host'] . (isset($p['port']) ? ':' . $p['port'] : ''));
            return $origin === $host;
        }
    }
    return false; // ni Origin ni Referer : on refuse
}

/* ── 6. Validation des entrées ──────────────────────────────── */
function hash_password_secure(string $pwd): string {
    return password_hash($pwd, PASSWORD_BCRYPT, ['cost' => 12]);
}

/** Retourne un message d'erreur si le mot de passe est trop faible, sinon null. */
function password_policy_error(string $p): ?string {
    if (strlen($p) < 10)  return 'Le mot de passe doit contenir au moins 10 caractères.';
    if (strlen($p) > 72)  return 'Le mot de passe ne doit pas dépasser 72 caractères.';
    if (!preg_match('/[A-Za-z]/', $p) || !preg_match('/[0-9]/', $p)) {
        return 'Le mot de passe doit contenir au moins une lettre et un chiffre.';
    }
    return null;
}

function valid_email(string $s): bool {
    return strlen($s) <= 150 && filter_var($s, FILTER_VALIDATE_EMAIL) !== false;
}
function valid_phone(string $s): bool {
    return (bool)preg_match('/^\+?[0-9 .\-()]{6,20}$/', $s);
}
function valid_date(string $s): bool {
    $d = DateTime::createFromFormat('Y-m-d', $s);
    return $d && $d->format('Y-m-d') === $s;
}
/** Normalise "HH:MM" ou "HH:MM:SS" en "HH:MM:00" ; retourne null si invalide. */
function norm_time(string $s): ?string {
    if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?$/', $s, $m)) return null;
    return $m[1] . ':' . $m[2] . ':' . ($m[3] ?? '00');
}
/** Nettoie un texte libre : supprime les caractères de contrôle et espaces superflus. */
function clean_text(string $s): string {
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s) ?? '';
    return trim($s);
}
/** URL d'image autorisée : http(s) uniquement, sans caractère pouvant sortir d'un url('...') CSS. */
function safe_image_url($u): ?string {
    if (!is_string($u)) return null;
    $u = trim($u);
    if ($u === '' || strlen($u) > 500) return null;
    return preg_match('~^https?://[^\s\'"()<>\\\\]+$~i', $u) ? $u : null;
}
/** Couleur hexadécimale #RRGGBB, sinon valeur par défaut. */
function safe_color($c, string $default = '#2563eb'): string {
    return (is_string($c) && preg_match('/^#[0-9a-fA-F]{6}$/', $c)) ? $c : $default;
}

/* ── 7. Limitation de débit (anti brute-force) ──────────────── */
function _rl_key(string $bucket): string { return substr(hash('sha256', $bucket), 0, 40); }

/** Nombre d'événements dans la fenêtre donnée. En cas d'erreur BDD, on ne bloque pas (et on journalise). */
function throttle_count(string $bucket, int $windowSeconds): int {
    try {
        $r = queryOne('SELECT COUNT(*) n FROM rate_limits WHERE bucket=? AND created_at > (NOW() - INTERVAL ' . (int)$windowSeconds . ' SECOND)', [_rl_key($bucket)]);
        return (int)($r['n'] ?? 0);
    } catch (Throwable $ex) {
        error_log('[DOKTA] rate_limits indisponible (importer sql/security_update.sql) : ' . $ex->getMessage());
        return 0;
    }
}
function throttle_hit(string $bucket): void {
    try {
        execute('INSERT INTO rate_limits (bucket) VALUES (?)', [_rl_key($bucket)]);
        if (random_int(1, 100) === 1) execute('DELETE FROM rate_limits WHERE created_at < (NOW() - INTERVAL 1 DAY)');
    } catch (Throwable $ex) {
        error_log('[DOKTA] rate_limits indisponible : ' . $ex->getMessage());
    }
}
function throttle_clear(string $bucket): void {
    try { execute('DELETE FROM rate_limits WHERE bucket=?', [_rl_key($bucket)]); } catch (Throwable $ex) { /* ignoré */ }
}
