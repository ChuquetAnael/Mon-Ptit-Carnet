<?php
// Proxy JSON vers api-maree.fr (la clé API reste côté serveur) avec cache en base.
require_once './includes/bootstrap.php';

if (!utilisateur_id()) {
    json_out(['error' => 'Session expirée, reconnecte-toi.'], 401);
}
session_write_close(); // libère la session : les autres pages ne restent pas bloquées pendant l'appel API

if (!is_file(__DIR__ . '/cle/api-maree.php')) {
    json_out(['error' => "Clé API marée absente (fichier cle/api-maree.php)."], 503);
}
require_once __DIR__ . '/cle/api-maree.php';

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

function distanceKm($lat1, $lon1, $lat2, $lon2)
{
    $earth_radius = 6371;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) * sin($dLat / 2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) * sin($dLon / 2);
    return $earth_radius * 2 * asin(sqrt($a));
}

try {
    $pdo = db();

    // 1. Liste des ports (mise en cache sans expiration)
    $stmtSites = $pdo->prepare("SELECT DONNEES_JSON FROM cache_maree WHERE ID_PORT = 'LISTE_SITES'");
    $stmtSites->execute();
    $sites_json = $stmtSites->fetchColumn();

    if (!$sites_json) {
        $sites_json = http_get("https://api-maree.fr/sites?key=" . urlencode(CLE_API_MAREE));
        $sites = $sites_json ? json_decode($sites_json, true) : null;
        if (!is_array($sites) || isset($sites['error']) || isset($sites['message'])) {
            json_out(['error' => "Le service de marées ne répond pas pour le moment."], 502);
        }
        $pdo->prepare("REPLACE INTO cache_maree (ID_PORT, DATE_MAREE, DONNEES_JSON) VALUES ('LISTE_SITES', '2099-12-31', ?)")
            ->execute([$sites_json]);
    }
    $sites = json_decode($sites_json, true);

    $list = $sites['data'] ?? $sites['sites'] ?? $sites;
    if (!is_array($list) || empty($list)) {
        $pdo->exec("DELETE FROM cache_maree WHERE ID_PORT = 'LISTE_SITES'");
        json_out(['error' => "Liste des ports indisponible."], 502);
    }

    // 2. Port le plus proche
    $closest_site = null;
    $closest_site_key = null;
    $min_distance = PHP_FLOAT_MAX;
    foreach ($list as $key => $s) {
        $p_lat = $s['latitude'] ?? $s['lat'] ?? null;
        $p_lng = $s['longitude'] ?? $s['lng'] ?? $s['lon'] ?? null;
        if ($p_lat === null || $p_lng === null) continue;
        $dist = distanceKm($lat_user, $lng_user, (float) $p_lat, (float) $p_lng);
        if ($dist < $min_distance) {
            $min_distance = $dist;
            $closest_site = $s;
            $closest_site_key = $key;
        }
    }
    if (!$closest_site) {
        $pdo->exec("DELETE FROM cache_maree WHERE ID_PORT = 'LISTE_SITES'");
        json_out(['error' => "Aucun port de référence trouvé."], 502);
    }

    $id_port = (string) ($closest_site['id'] ?? $closest_site['site_id'] ?? $closest_site['slug'] ?? $closest_site_key ?? '');
    $nom_port = $closest_site['name'] ?? $closest_site['site_name'] ?? $closest_site['nom'] ?? 'Port inconnu';

    // 3. Marée du jour demandé (cache par port et par date)
    $stmt = $pdo->prepare("SELECT DONNEES_JSON FROM cache_maree WHERE ID_PORT = ? AND DATE_MAREE = ?");
    $stmt->execute([$id_port, $date]);
    $response = $stmt->fetchColumn();

    if (!$response) {
        $url = "https://api-maree.fr/tide-extrema?site=" . urlencode($id_port) . "&from={$date}&to={$date}&tz=Europe/Paris&key=" . urlencode(CLE_API_MAREE);
        $response = http_get($url);
        $decode = $response ? json_decode($response, true) : null;
        if (!is_array($decode) || isset($decode['error'])) {
            json_out(['error' => "Horaires de marée indisponibles pour ce port."], 502);
        }
        $pdo->prepare("REPLACE INTO cache_maree (ID_PORT, DATE_MAREE, DONNEES_JSON) VALUES (?, ?, ?)")
            ->execute([$id_port, $date, $response]);

        // Purge occasionnelle du cache (les marées anciennes ne servent plus)
        if (random_int(1, 50) === 1) {
            $pdo->exec("DELETE FROM cache_maree WHERE ID_PORT <> 'LISTE_SITES' AND DATE_MAREE < (CURDATE() - INTERVAL 400 DAY)");
        }
    }

    $result = json_decode($response, true);
    $result['distance_km'] = round($min_distance, 1);
    $result['port_name_custom'] = $nom_port;
    json_out($result);
} catch (Throwable $e) {
    error_log('[MonPtitCarnet] fetch_maree : ' . $e->getMessage());
    json_out(['error' => "Erreur serveur lors du calcul de la marée."], 500);
}
