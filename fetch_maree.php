<?php
// Proxy JSON vers api-maree.fr (la clé API reste côté serveur) avec cache en base.
require_once './includes/bootstrap.php';
require_once './includes/maree.php';

if (!utilisateur_id()) {
    json_out(['error' => 'Session expirée, reconnecte-toi.'], 401);
}
session_write_close(); // libère la session : les autres pages ne restent pas bloquées pendant l'appel API

if (!maree_disponible()) {
    json_out(['error' => "Clé API marée absente (fichier cle/api-maree.php)."], 503);
}

$lat_user = nombre_ou_null($_GET['lat'] ?? null, -90, 90);
$lng_user = nombre_ou_null($_GET['lng'] ?? null, -180, 180);
if ($lat_user === null || $lng_user === null) {
    json_out(['error' => 'Coordonnées invalides.'], 400);
}

// Date demandée (session passée) ou aujourd'hui
$date = date('Y-m-d');
if (!empty($_GET['date'])) {
    $d = DateTime::createFromFormat('Y-m-d', (string) $_GET['date']);
    if (!$d || $d->format('Y-m-d') !== $_GET['date']) json_out(['error' => 'Date invalide.'], 400);
    $date = $_GET['date'];
}

try {
    $maree = maree_du_jour(db(), $lat_user, $lng_user, $date);
    $result = $maree['donnees'];
    $result['distance_km'] = $maree['distance_km'];
    $result['port_name_custom'] = $maree['port'];
    json_out($result);
} catch (RuntimeException $e) {
    json_out(['error' => $e->getMessage()], 502);
} catch (Throwable $e) {
    error_log('[MonPtitCarnet] fetch_maree : ' . $e->getMessage());
    json_out(['error' => "Erreur serveur lors du calcul de la marée."], 500);
}
