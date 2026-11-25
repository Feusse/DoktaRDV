# Journal des correctifs de sécurité

Chaque correctif a été vérifié par des tests automatisés exécutés sur une vraie base MariaDB
(plus de 120 vérifications : accès croisés entre hôpitaux, CSRF, XSS, concurrence, etc.).

## Critique

| # | Faille | Correctif |
|---|---|---|
| 1 | **IDOR dans `api.php`** : un admin/médecin pouvait modifier des RDV d'un autre hôpital (`update_statut`), modifier ou supprimer des médecins d'un autre hôpital, créer des créneaux pour n'importe quel médecin | Contrôle de portée par ressource sur chaque action ; réponse 404 identique pour « inexistant » et « interdit » |
| 2 | **Prise de contrôle de compte** : `save_medecin` modifiait n'importe quel `users.id` (même le super admin) | Requêtes limitées à `role='medecin'` et à l'hôpital de l'admin |
| 3 | `delete_patient` : un admin pouvait supprimer n'importe quel patient de la plateforme | Autorisé seulement si le patient est suivi dans son hôpital et nulle part ailleurs |
| 4 | **Fuite de hachages de mots de passe** : `get_medecins` renvoyait `SELECT *`, et `json_encode($m)` plaçait toute la ligne utilisateur dans le HTML de l'admin | Colonnes explicites partout ; plus aucun hash dans les réponses ni dans les pages |
| 5 | **XSS stockés** : nom/prénom (saisis à l'inscription) injectés non échappés dans des attributs `data-*`, `onclick` (`json_encode` dans `'...'`), `innerHTML` côté JS, CSS (`image_url`, `couleur`) | `e()`, `json_attr()`, `json_script()`, `esc()` côté JS, validation stricte de l'URL d'image et de la couleur |
| 6 | **Comptes de démo publics** (mots de passe dans le README, boutons dans la page de connexion) ; le hash du super admin dans `install.sql` était le hash public de « password » | `install.sql` ne contient plus aucun compte ; démo isolée dans `demo_data.sql` ; boutons visibles seulement si `APP_ENV='demo'` ; `tools/create_superadmin.php` |
| 7 | **Clé du cron en dur et publique** (`DOKTA-RDV_cron_2025`, en lien dans l'admin) | Clé aléatoire en base, comparaison à temps constant, limitation d'essais ; déclenchement manuel par formulaire POST + CSRF |

## Élevé

| # | Faille | Correctif |
|---|---|---|
| 8 | Pas de CSRF | Formulaires HTML : jeton ; API : POST + `Content-Type: application/json` + contrôle `Origin`/`Referer` + cookie `SameSite=Lax` |
| 9 | Pas de protection contre la force brute | 5 échecs/15 min par e-mail, 20/15 min par IP, vérification de mot de passe à temps constant même si l'e-mail n'existe pas |
| 10 | Fixation de session ; sessions sans expiration ; rôle jamais revérifié | `session_regenerate_id` à la connexion, cookie HttpOnly/SameSite/Secure, mode strict, expiration 2 h/12 h, rôle et statut relus en base à chaque requête |
| 11 | `display_errors` activé dans 6 pages ; messages d'exception renvoyés par l'API | Erreurs jamais affichées (sauf `APP_ENV='development'`), journalisées ; messages génériques |
| 12 | Mots de passe : 6 caractères mini, défaut `medecin123`, aucun mot de passe actuel exigé pour changer e-mail/mot de passe | Politique 10–72 caractères + lettre + chiffre, bcrypt coût 12, plus de défaut, mot de passe actuel exigé |
| 13 | Double réservation possible d'un même créneau | Transaction + verrou `FOR UPDATE` + mise à jour conditionnelle ; médecin/hôpital déduits du créneau ; plafond de 5 RDV à venir par patient |
| 14 | `get_creneaux`/`get_medecins` exposaient des hôpitaux suspendus et des colonnes inutiles | Filtre sur hôpital/médecin actifs, colonnes minimales |

## Moyen / durcissement

- En-têtes : CSP, `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`, HSTS (en HTTPS).
- `.htaccess` (absent de l'archive d'origine alors que la doc le mentionnait) : `includes/`, `config/`, `sql/`, `tools/`, `docs/`, `logs/` fermés ; seul `cron_rappels.php` reste joignable.
- Suppression d'un hôpital : les comptes admin/médecins sont supprimés (ils restaient actifs et orphelins à cause de `ON DELETE SET NULL`).
- Suppression d'un patient : ses créneaux sont libérés ; suppression d'un médecin : ses patients sont notifiés.
- Admin d'hôpital : n'a plus accès à la liste de **tous** les patients de la plateforme.
- Jeton Twilio jamais ré-affiché ; numéros et messages SMS non journalisés ; vérification des formats (SID, numéros).
- Chart.js épinglé sur une version précise (au lieu de « latest »).
- `tools/generate_hash.php` (qui réécrivait des mots de passe connus en base) remplacé par `tools/create_superadmin.php`.
- Anciens scripts `medireserv.sql`, `update.sql`, `hopital_update.sql`, `rappels_update.sql` supprimés (obsolètes, comptes de démo publics).

## Bugs fonctionnels corrigés au passage

- Après « Modifier » un médecin ou un hôpital, le bouton « Ajouter » réutilisait l'ancien identifiant (écrasait au lieu de créer).
- Données de démo : les identifiants des créneaux/RDV ne correspondaient pas aux utilisateurs insérés (l'import échouait).
- Les blocs `?>` suivis de lignes vides avant `header()` provoquaient « headers already sent » sur certaines configurations.

## À faire de votre côté

1. Importer `sql/security_update.sql` (installation existante).
2. Changer/supprimer tous les anciens comptes de démo, régénérer la clé du cron, éventuellement les clés Twilio.
3. Activer HTTPS puis la redirection dans `.htaccess`.
4. Pour de vraies données de santé : audit externe, RGPD, hébergement HDS, chiffrement du jeton Twilio au repos, sauvegardes.
