<?php
/**
 * tools/create_superadmin.php
 * ------------------------------------------------------------
 * Crée (ou réinitialise le mot de passe de) un compte Super Administrateur.
 * EXÉCUTABLE UNIQUEMENT EN LIGNE DE COMMANDE :
 *
 *     php tools/create_superadmin.php
 *
 * Remplace l'ancien tools/generate_hash.php, qui réécrivait en base des mots de
 * passe de démonstration connus de tous. Ici, le mot de passe est saisi par vous,
 * jamais écrit dans un fichier, et doit respecter la politique de sécurité.
 * ------------------------------------------------------------
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Ce script ne peut être exécuté qu'en ligne de commande (CLI).\n");
}
require_once __DIR__ . '/../includes/db.php';

function ask(string $label, bool $hidden = false): string {
    echo $label;
    $isUnix = DIRECTORY_SEPARATOR === '/';
    if ($hidden && $isUnix) shell_exec('stty -echo');
    $v = trim((string)fgets(STDIN));
    if ($hidden && $isUnix) { shell_exec('stty echo'); echo "\n"; }
    return $v;
}

echo "\n=== DOKTA-RDV — Création d'un Super Administrateur ===\n\n";
$email  = ask('E-mail : ');
if (!valid_email($email)) exit("E-mail invalide.\n");
$prenom = ask('Prénom : ') ?: 'Super';
$nom    = ask('Nom : ')    ?: 'Admin';
$pwd    = ask('Mot de passe (10 car. min., lettres + chiffres) : ', true);
if ($err = password_policy_error($pwd)) exit($err . "\n");
if ($pwd !== ask('Confirmer le mot de passe : ', true)) exit("Les mots de passe ne correspondent pas.\n");

$hash = hash_password_secure($pwd);
$existing = queryOne('SELECT id, role FROM users WHERE email=?', [$email]);
if ($existing) {
    if ($existing['role'] !== 'super_admin') exit("Cet e-mail appartient déjà à un compte non super admin. Abandon.\n");
    execute('UPDATE users SET password=?, statut="actif" WHERE id=?', [$hash, $existing['id']]);
    echo "✓ Mot de passe du super admin existant mis à jour.\n";
} else {
    execute("INSERT INTO users (nom,prenom,email,password,role,statut) VALUES (?,?,?,?, 'super_admin','actif')", [$nom, $prenom, $email, $hash]);
    echo "✓ Super admin créé.\n";
}
