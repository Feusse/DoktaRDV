<?php
/**
 * includes/sms.php — Passerelle d'envoi de SMS via Twilio
 * --------------------------------------------------
 * Pour activer réellement l'envoi de SMS :
 * 1. Créez un compte sur https://www.twilio.com (offre d'essai gratuite disponible)
 * 2. Récupérez : Account SID, Auth Token, et un numéro Twilio (From)
 * 3. Renseignez ces 3 valeurs dans la table `config` (ou via superadmin/admin_rappels.php)
 *
 * Tant que ces valeurs ne sont pas renseignées, les SMS sont seulement
 * journalisés (mode simulation) — rien n'est réellement envoyé, mais
 * tout le système de rappel automatique fonctionne et est testable.
 *
 * NOTE : ce fichier ne définit que des fonctions (pas d'endpoint web).
 * Il est inclus par admin_rappels.php et cron_rappels.php, jamais
 * appelé directement depuis une URL.
 */
require_once __DIR__.'/db.php';

function getConfig(string $cle): string {
    $row = queryOne('SELECT valeur FROM config WHERE cle=?', [$cle]);
    return $row['valeur'] ?? '';
}

function setConfig(string $cle, string $valeur): void {
    execute('INSERT INTO config (cle,valeur) VALUES (?,?) ON DUPLICATE KEY UPDATE valeur=?', [$cle, $valeur, $valeur]);
}

/**
 * Envoie un SMS. Retourne ['success'=>bool, 'simulated'=>bool, 'error'=>string|null]
 */
function sendSMS(string $to, string $message): array {
    $sid   = getConfig('twilio_sid');
    $token = getConfig('twilio_token');
    $from  = getConfig('twilio_from');

    // Nettoyer le numéro (garder uniquement chiffres et +)
    $to = preg_replace('/[^0-9+]/', '', $to);
    if (!$to || !preg_match('/^\+?[0-9]{8,15}$/', $to)) {
        return ['success' => false, 'simulated' => false, 'error' => 'Numéro de téléphone invalide ou manquant'];
    }

    // Mode simulation si pas de clés Twilio configurées
    if (!$sid || !$token || !$from) {
        error_log('[SMS SIMULÉ] À: ...' . substr($to, -3) . ' (contenu non journalisé)');
        return ['success' => true, 'simulated' => true, 'error' => null];
    }

    // Convertir au format international si numéro français local (06... → +336...)
    if (preg_match('/^0[67]\d{8}$/', $to)) {
        $to = '+33' . substr($to, 1);
    }

    $url = "https://api.twilio.com/2010-04-01/Accounts/$sid/Messages.json";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER       => true,
        CURLOPT_POST                 => true,
        CURLOPT_USERPWD              => "$sid:$token",
        CURLOPT_POSTFIELDS           => http_build_query([
            'To'   => $to,
            'From' => $from,
            'Body' => $message,
        ]),
        CURLOPT_TIMEOUT              => 30,
        CURLOPT_CONNECTTIMEOUT       => 15,
        // Vérification SSL activée : ne jamais désactiver en production,
        // même sur un hébergement gratuit — ces échanges contiennent des
        // données patients (numéro, message de rappel de RDV).
        // Si vous développez en local et rencontrez une erreur de
        // certificat (cURL error 60), corrigez plutôt votre configuration
        // cacert.pem PHP locale plutôt que de désactiver cette option.
        CURLOPT_SSL_VERIFYPEER       => true,
        CURLOPT_SSL_VERIFYHOST       => 2,
        CURLOPT_DNS_USE_GLOBAL_CACHE => false,
        CURLOPT_IPRESOLVE            => CURL_IPRESOLVE_V4,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        return ['success' => false, 'simulated' => false, 'error' => 'Erreur réseau : ' . $curlErr];
    }
    if ($httpCode >= 200 && $httpCode < 300) {
        return ['success' => true, 'simulated' => false, 'error' => null];
    }
    $data = json_decode($response, true);
    return ['success' => false, 'simulated' => false, 'error' => $data['message'] ?? "Erreur Twilio (HTTP $httpCode)"];
}
