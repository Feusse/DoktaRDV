<?php
/**
 * includes/rappels.php — Logique des rappels SMS (partagée par le cron et la page Super Admin)
 * ------------------------------------------------------------
 * Cherche les RDV confirmés dont l'heure approche (fenêtre de 30 min,
 * "rappel_heures_avant" avant le RDV), envoie un SMS au patient, marque le
 * RDV comme "rappelé" et journalise chaque tentative dans rappels_log.
 *
 * Un verrou MySQL (GET_LOCK) empêche deux exécutions simultanées (cron +
 * clic manuel) d'envoyer deux fois le même SMS.
 * ------------------------------------------------------------
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/sms.php';

function rappels_log_file(string $line): void {
    $dir = __DIR__ . '/../logs';
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    // Le dossier logs/ est protégé par son propre .htaccess (accès web interdit).
    @file_put_contents($dir . '/cron_rappels.log', $line, FILE_APPEND | LOCK_EX);
}

/** @return array{locked:bool,envoyes:int,echecs:int,examines:int,lignes:string[]} */
function run_rappels(): array {
    $res = ['locked' => false, 'envoyes' => 0, 'echecs' => 0, 'examines' => 0, 'lignes' => []];

    $lock = queryOne("SELECT GET_LOCK('dokta_rappels', 0) AS l");
    if (!$lock || (int)$lock['l'] !== 1) { $res['locked'] = true; return $res; }

    try {
        $heuresAvant = (int)getConfig('rappel_heures_avant');
        if (!in_array($heuresAvant, [12, 24, 48], true)) $heuresAvant = 24;

        $cibleDebut = date('Y-m-d H:i:s', strtotime("+{$heuresAvant} hours"));
        $cibleFin   = date('Y-m-d H:i:s', strtotime("+{$heuresAvant} hours +30 minutes"));

        $rdvs = query(
            "SELECT r.*, u.prenom p_prenom, u.nom p_nom, u.telephone p_tel,
                    m.prenom med_prenom, m.nom med_nom, h.nom hopital_nom
             FROM rendezvous r
             JOIN users u ON u.id = r.patient_id
             JOIN users m ON m.id = r.medecin_id
             JOIN hopitaux h ON h.id = r.hopital_id
             WHERE r.statut = 'confirme'
               AND r.rappel_envoye = 0
               AND CONCAT(r.date_rdv, ' ', r.heure_rdv) BETWEEN ? AND ?",
            [$cibleDebut, $cibleFin]
        );
        $res['examines'] = count($rdvs);

        foreach ($rdvs as $rdv) {
            $dateStr  = date('d/m/Y', strtotime($rdv['date_rdv']));
            $heureStr = date('H:i', strtotime($rdv['heure_rdv']));
            $message = sprintf(
                "Rappel %s : RDV demain %s à %s avec Dr. %s %s. En cas d'empêchement, annulez depuis votre espace patient.",
                $rdv['hopital_nom'], $dateStr, $heureStr, $rdv['med_prenom'], $rdv['med_nom']
            );

            $result = sendSMS((string)$rdv['p_tel'], $message);

            execute(
                'INSERT INTO rappels_log (rdv_id, telephone, statut, message, erreur) VALUES (?,?,?,?,?)',
                [$rdv['id'], $rdv['p_tel'], $result['success'] ? 'envoye' : 'echec', $message, $result['error']]
            );

            if ($result['success']) {
                execute('UPDATE rendezvous SET rappel_envoye=1 WHERE id=?', [$rdv['id']]);
                execute('INSERT INTO notifications (user_id,message) VALUES (?,?)', [
                    $rdv['patient_id'],
                    "🔔 Rappel : vous avez RDV demain à $heureStr avec Dr. {$rdv['med_prenom']} {$rdv['med_nom']}."
                ]);
                $res['envoyes']++;
                $res['lignes'][] = "✓ RDV #{$rdv['id']} — " . ($result['simulated'] ? 'SIMULÉ' : 'SMS réel');
            } else {
                $res['echecs']++;
                $res['lignes'][] = "✗ RDV #{$rdv['id']} — Erreur d'envoi";
            }
        }
    } finally {
        queryOne("SELECT RELEASE_LOCK('dokta_rappels') AS r");
    }

    rappels_log_file(sprintf("[%s] Rappels traités : %d envoyés, %d échecs, %d RDV examinés\n",
        date('Y-m-d H:i:s'), $res['envoyes'], $res['echecs'], $res['examines']));
    return $res;
}
