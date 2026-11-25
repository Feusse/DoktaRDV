<?php
/**
 * includes/db.php
 * ------------------------------------------------------------
 * Connexion PDO MySQL/MariaDB + helpers de requêtes.
 * Les identifiants réels sont dans config/db_credentials.php
 * (fichier séparé, à adapter selon l'environnement).
 * ------------------------------------------------------------
 */

require_once __DIR__ . '/../config/db_credentials.php';
require_once __DIR__ . '/security.php';

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=".DB_CHARSET;
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $ex) {
            // On journalise le détail côté serveur, mais on n'affiche jamais
            // le message d'origine (il peut contenir l'hôte ou l'utilisateur BDD).
            error_log('[DOKTA] Connexion BDD impossible : ' . $ex->getMessage());
            if (PHP_SAPI !== 'cli') http_response_code(503);
            if (defined('API_REQUEST')) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['error' => 'Service momentanément indisponible.']);
            } else {
                echo 'Service momentanément indisponible. Veuillez réessayer plus tard.';
            }
            exit(1);
        }
    }
    return $pdo;
}
function query(string $sql, array $p=[]): array {
    $s = getDB()->prepare($sql); $s->execute($p); return $s->fetchAll();
}
function queryOne(string $sql, array $p=[]): ?array {
    $s = getDB()->prepare($sql); $s->execute($p); $r=$s->fetch(); return $r ?: null;
}
function execute(string $sql, array $p=[]): int {
    $s = getDB()->prepare($sql); $s->execute($p); return (int)getDB()->lastInsertId();
}
/** Comme execute(), mais retourne le NOMBRE DE LIGNES MODIFIÉES (utile pour les mises à jour conditionnelles). */
function executeCount(string $sql, array $p=[]): int {
    $s = getDB()->prepare($sql); $s->execute($p); return $s->rowCount();
}
function statutLabel(string $s): string {
    return match($s) {
        'en_attente' => '⏳ En attente',
        'confirme'   => '✅ Confirmé',
        'annule'     => '❌ Annulé',
        'termine'    => '✔ Terminé',
        'actif'      => '✅ Actif',
        'suspendu'   => '🚫 Suspendu',
        default      => $s,
    };
}
