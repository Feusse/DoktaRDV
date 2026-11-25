<?php
/**
 * Modèle de configuration : copiez ce fichier en db_credentials.php
 * puis renseignez VOS identifiants. db_credentials.php ne doit jamais être versionné (.gitignore).
 */
declare(strict_types=1);

define('DB_HOST', 'localhost');
define('DB_NAME', 'nom_de_la_base');
define('DB_USER', 'utilisateur');
define('DB_PASS', 'mot_de_passe');
define('DB_CHARSET', 'utf8mb4');

define('APP_ENV', 'production'); // production | demo | development
