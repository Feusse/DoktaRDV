<?php
require_once __DIR__ . '/includes/auth.php';
Auth::logout();   // vide la session, supprime le cookie et détruit la session côté serveur
header('Location: index.php');
exit;
