<?php
// =========================================================================
// CONFIGURATION (valeurs par défaut = poste de développement XAMPP)
// Pour un serveur de production, créer BDD/env.local.php (ignoré par Git)
// et y redéfinir ces variables/constantes (mot de passe BDD, APP_DEBUG = false).
// =========================================================================
$host = 'localhost';
$dbname = 'mlr1';   // en minuscules : les noms de BDD sont sensibles à la casse sous Linux
$user = 'root';
$pass = '';

if (is_file(__DIR__ . '/env.local.php')) {
    require __DIR__ . '/env.local.php';
}

// Affiche le détail technique des erreurs (à désactiver en production)
if (!defined('APP_DEBUG')) define('APP_DEBUG', true);
