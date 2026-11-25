# DOKTA-RDV — Plateforme multi-hôpitaux de prise de RDV

PHP 8.1+ / MySQL-MariaDB, sans framework. Structure prévue pour un hébergement mutualisé
(type InfinityFree) avec protection `.htaccess` des dossiers sensibles.

> **Version sécurisée.** Le détail des failles corrigées est dans `docs/SECURITY_CHANGES.md`.
> Lisez surtout la section « Mise à jour d'une installation existante » ci-dessous.

---

## 1. Installation (nouvelle installation)

### Étape 1 — Base de données
1. Créez une base MySQL/MariaDB (panneau de l'hébergeur ou phpMyAdmin).
2. Importez **`sql/install.sql`** (schéma uniquement, **aucun compte**).
3. Vérifiez la présence de 8 tables : `hopitaux`, `users`, `creneaux`, `rendezvous`,
   `notifications`, `config`, `rappels_log`, `rate_limits`.

### Étape 2 — Identifiants
Copiez `config/db_credentials.example.php` en `config/db_credentials.php` et renseignez
vos identifiants. Ne versionnez jamais ce fichier (déjà dans `.gitignore`).

### Étape 3 — Premier Super Administrateur
En ligne de commande (sur votre poste, avec `DB_*` pointant vers la base) :

```bash
php tools/create_superadmin.php
```
Le mot de passe est saisi par vous : 10 caractères minimum, avec lettres et chiffres.

### Étape 4 — Téléverser
Envoyez tout le contenu dans `htdocs/` (ou la racine web). Vérifiez que `.htaccess`
(fichier caché) est bien envoyé, ainsi que ceux de `includes/`, `config/`, `sql/`,
`tools/`, `docs/`, `logs/`, `cron/`.

### Étape 5 — HTTPS
Activez le certificat SSL de l'hébergeur, puis décommentez la redirection HTTPS dans `.htaccess`.
Le cookie de session passe automatiquement en `Secure` dès que le site est servi en HTTPS.

---

## 2. Mise à jour d'une installation existante

1. Sauvegardez la base et les fichiers.
2. Remplacez les fichiers par cette version (conservez votre `config/db_credentials.php` ;
   ajoutez-y la ligne `define('APP_ENV', 'production');` si elle manque).
3. Importez **`sql/security_update.sql`** (crée la table `rate_limits`).
4. **Changez immédiatement tous les mots de passe de démonstration** ou supprimez ces comptes :
   leurs mots de passe étaient publiés. Au minimum : `php tools/create_superadmin.php`
   pour réinitialiser un super admin, puis changez/supprimez les autres comptes.
5. Dans **Rappels SMS**, copiez la nouvelle clé du cron et mettez à jour votre tâche planifiée
   (l'ancienne clé `DOKTA-RDV_cron_2025` ne fonctionne plus).
6. Si vous aviez saisi des clés Twilio, envisagez de les régénérer (elles ont pu fuiter via l'ancien formulaire).

---

## 3. Mode démonstration (facultatif, jamais en production)

Pour une démo locale avec comptes préremplis :

1. Importez `sql/install.sql`, puis `sql/demo_data.sql` sur une base **de test**.
2. Dans `config/db_credentials.php`, mettez `define('APP_ENV', 'demo');`
   → les boutons « Comptes démo » apparaissent sur la page de connexion.

| Rôle | Email | Mot de passe |
|---|---|---|
| Super Admin | superadmin@DOKTA-RDV.fr | superadmin123 |
| Admin Hôpital | admin@hopital-central.fr | admin123 |
| Médecin | dr.dupont@hopital-central.fr | medecin123 |
| Patient | julie@email.fr | patient123 |

`APP_ENV = 'development'` affiche en plus les erreurs PHP à l'écran (poste local uniquement).

---

## 4. Rappels SMS automatiques

Voir `docs/RAPPELS_INSTALLATION.md`. Tant que Twilio n'est pas configuré, les SMS sont simulés.
Le cron se déclenche en CLI (`php cron/cron_rappels.php`) ou via une URL protégée par clé
(en-tête `X-Cron-Key`), toutes les 15 à 30 minutes.

---

## 5. Règles de sécurité à connaître

- **Rôles & accès** : chaque action de l'API vérifie la portée (un admin d'hôpital n'agit que
  sur son hôpital, un médecin sur ses RDV, un patient sur ses données). Le rôle et le statut du
  compte sont relus en base à chaque requête : suspendre un compte ou un hôpital coupe l'accès immédiatement.
- **Mots de passe** : 10 caractères minimum (72 max), au moins une lettre et un chiffre ; bcrypt coût 12.
  Plus aucun mot de passe par défaut.
- **Connexion** : jeton CSRF, 5 échecs / 15 min par e-mail et 20 / 15 min par IP, session régénérée,
  expiration après 2 h d'inactivité (12 h maximum).
- **Profil** : changer l'e-mail ou le mot de passe exige le mot de passe actuel.
- **Erreurs** : jamais affichées aux visiteurs (journal du serveur uniquement).
- **Limites connues** : le jeton Twilio est stocké en clair dans la table `config` ; la limitation
  de débit se base sur `REMOTE_ADDR` (derrière certains proxys, toutes les requêtes peuvent partager la même IP).
- Ce projet reste un outil de démonstration. Avec de vrais patients : audit externe, journalisation des accès
  aux données de santé, conformité RGPD et hébergement de données de santé (HDS) requis.

---

## 6. Arborescence

```
htdocs/
├── .htaccess, .gitignore
├── index.php, accueil.php, login.php, register.php, logout.php
├── admin/admin_hopital.php
├── superadmin/{superadmin.php, admin_rappels.php}
├── medecin/medecin.php
├── patient/{patient.php, hopital_espace.php}
├── api/api.php
├── assets/{app.js, style.css}
├── includes/{auth.php, db.php, security.php, rappels.php, sms.php, partials/}
├── config/{db_credentials.php, db_credentials.example.php}
├── cron/cron_rappels.php
├── tools/create_superadmin.php
├── logs/                (journal du cron, accès web interdit)
├── sql/{install.sql, demo_data.sql, security_update.sql}
└── docs/{README.md, RAPPELS_INSTALLATION.md, SECURITY_CHANGES.md}
```
