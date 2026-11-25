<?php
/**
 * cron_rappels.php — Script de rappel automatique (SMS J-1)
 * --------------------------------------------------
 * À exécuter toutes les 15-30 minutes :
 *   - en ligne de commande (recommandé) :  php cron/cron_rappels.php
 *   - ou via une URL appelée par un service de "cron web" (cron-job.org...) :
 *       https://votre-domaine/cron/cron_rappels.php
 *     en envoyant la clé dans l'EN-TÊTE HTTP  X-Cron-Key: <clé>  (méthode à privilégier)
 *     ou, à défaut, en paramètre ?key=<clé> (la clé apparaît alors dans les journaux du serveur).
 *
 * La clé n'est PLUS écrite dans le code : elle est générée aléatoirement et
 * stockée en base. Le Super Admin la consulte / la régénère dans
 * "Rappels SMS" (superadmin/admin_rappels.php). Sans clé configurée, seul
 * l'accès en ligne de commande est possible.
 */
require_once __DIR__ . '/../includes/rappels.php';

$isCli = (PHP_SAPI === 'cli');

if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');

    $ipBucket = 'cronkey:' . client_ip();
    if (throttle_count($ipBucket, 900) >= 10) { http_response_code(429); die("Trop de tentatives.\n"); }

    $provided = $_SERVER['HTTP_X_CRON_KEY'] ?? ($_POST['key'] ?? ($_GET['key'] ?? ''));
    $expected = getConfig('cron_key');
    // hash_equals : comparaison à temps constant ; la clé attendue n'est JAMAIS révélée.
    if ($expected === '' || !is_string($provided) || !hash_equals($expected, $provided)) {
        throttle_hit($ipBucket);
        http_response_code(403);
        die("Accès refusé.\n");
    }
}

$r = run_rappels();

if ($r['locked']) { echo "Une exécution est déjà en cours.\n"; exit; }
echo sprintf("[%s] Rappels traités : %d envoyés, %d échecs, %d RDV examinés\n", date('Y-m-d H:i:s'), $r['envoyes'], $r['echecs'], $r['examines']);
foreach ($r['lignes'] as $l) echo "  $l\n";
