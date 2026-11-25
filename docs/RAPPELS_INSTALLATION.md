# 📲 Installation des rappels SMS automatiques

## Comment ça fonctionne

Un script PHP (`cron/cron_rappels.php`) doit être exécuté automatiquement
à intervalles réguliers. Il recherche les RDV confirmés dont l'heure
approche (24h avant par défaut) et envoie un SMS au patient via Twilio.

---

## Étape 1 — Importer les tables SQL nécessaires

Si vous avez utilisé `sql/install.sql`, cette étape est déjà faite (les
tables `config` et `rappels_log` y sont incluses). Sinon, importez
`sql/rappels_update.sql` séparément dans phpMyAdmin.

---

## Étape 2 — Configurer Twilio (envoi réel de SMS)

1. Créez un compte gratuit sur https://www.twilio.com
2. Dans la console Twilio, récupérez :
   - **Account SID**
   - **Auth Token**
   - Un **numéro de téléphone Twilio** (offert lors de l'essai gratuit)
3. Connectez-vous en Super Admin → menu **📲 Rappels SMS**
   (`superadmin/admin_rappels.php`)
4. Renseignez ces 3 informations et enregistrez

> Tant que Twilio n'est pas configuré, le système fonctionne quand même :
> les rappels sont "simulés" (journalisés) sans réel envoi de SMS. Cela
> permet de tout tester avant de configurer un vrai service SMS.

---

## Étape 3 — Automatiser l'exécution

### Sur InfinityFree (ou hébergement mutualisé similaire)

1. Connectez-vous en Super Admin → **Rappels SMS** → section **Clé de la tâche planifiée** :
   copiez la clé (générée aléatoirement, stockée en base, régénérable en un clic).
2. Dans le panneau (vPanel), section **Cron Jobs**, ou dans un service de cron web
   (ex : cron-job.org), appelez `https://votredomaine.infinityfreeapp.com/cron/cron_rappels.php`
   en ajoutant l'**en-tête HTTP** `X-Cron-Key: VOTRE_CLE` (méthode recommandée : la clé
   n'apparaît pas dans les journaux du serveur).
   À défaut d'en-têtes personnalisés, `?key=VOTRE_CLE` fonctionne aussi, mais la clé sera
   alors visible dans les journaux d'accès.
3. **Fréquence : toutes les 15 à 30 minutes.** Le script cherche les RDV qui tombent dans
   une fenêtre de 30 minutes situées « heures avant » (24 h par défaut) plus tard : un
   cron exécuté une seule fois par jour manquerait la plupart des rendez-vous.

> Si votre hébergement ne propose pas de Cron Jobs dans son panneau,
> utilisez un service externe gratuit (ex : cron-job.org), qui permet
> d'ajouter l'en-tête `X-Cron-Key`.

### En local (XAMPP/MAMP/WAMP, pour développement)

**Sur macOS/Linux**, utilisez `crontab` :
```bash
crontab -e
```
Ajoutez :
```
*/15 * * * * /usr/bin/php /chemin/vers/htdocs/cron/cron_rappels.php
```

**Sur Windows**, utilisez le Planificateur de tâches :
1. Ouvrez le **Planificateur de tâches** (menu Démarrer)
2. **Créer une tâche de base**, nom : `DOKTA-RDV - Rappels SMS`
3. Déclencheur : **Quotidien**, répété toutes les 15 minutes
4. Action : **Démarrer un programme**
   - Programme/script : `C:\xampp\php\php.exe`
   - Arguments : `C:\xampp\htdocs\hopital-rdv\cron\cron_rappels.php`

### Alternative : déclenchement manuel

Dans l'espace Super Admin → **Rappels SMS**, un bouton **"Exécuter les
rappels maintenant"** permet de déclencher l'envoi à la demande, en
attendant de configurer la tâche planifiée.

---

## Étape 4 — Vérifier

- Créez un RDV confirmé avec une date/heure dans 24h
- Cliquez sur **Exécuter les rappels maintenant** (page Rappels SMS) ou lancez `php cron/cron_rappels.php`
- Vérifiez dans **Rappels SMS → Historique** que le rappel apparaît
- Si Twilio est configuré, le patient reçoit un vrai SMS

---

## Sécurité

- Le script `cron/cron_rappels.php` n'est joignable depuis le web qu'avec la clé secrète
  (comparée à temps constant, 10 essais ratés / 15 min / IP au maximum). En ligne de commande
  (CLI), aucune clé n'est nécessaire.
- Il n'existe **plus aucune clé écrite dans le code** : l'ancienne clé statique
  `DOKTA-RDV_cron_2025` était publique (README + code) et doit être considérée comme compromise.
  Elle est rejetée par cette version.
- L'Auth Token Twilio n'est jamais ré-affiché dans l'interface (champ vide = conservé).
- Les SMS contiennent le nom du médecin et de l'établissement : n'y ajoutez pas de motif de consultation.
