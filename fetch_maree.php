<?php
require_once './includes/auth_bdd.php';
require_once './cle/api-maree.php'; 

header('Content-Type: application/json');

$lat_user = isset($_GET['lat']) ? (float)$_GET['lat'] : 0;
$lng_user = isset($_GET['lng']) ? (float)$_GET['lng'] : 0;
$date = date('Y-m-d');

// Fonction cURL robuste
function callApiMaree($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    // Désactiver la vérification SSL locale (Indispensable sous XAMPP)
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'MonCarnetDePeche/1.0');
    
    // L'API veut la clé dans l'URL, donc on garde juste l'en-tête Accept JSON
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Accept: application/json"
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err = curl_error($ch);
    curl_close($ch);

    if ($http_code != 200 || !$response) {
        throw new Exception("API HTTP $http_code | Erreur cURL : $curl_err | Réponse : $response");
    }
    return $response;
}

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdo->exec("CREATE TABLE IF NOT EXISTS CACHE_MAREE (
        ID_PORT VARCHAR(100) NOT NULL,
        DATE_MAREE DATE NOT NULL,
        DONNEES_JSON MEDIUMTEXT NOT NULL,
        PRIMARY KEY (ID_PORT, DATE_MAREE)
    )");

    $stmtSites = $pdo->prepare("SELECT DONNEES_JSON FROM CACHE_MAREE WHERE ID_PORT = 'LISTE_SITES'");
    $stmtSites->execute();
    $cacheSites = $stmtSites->fetch(PDO::FETCH_ASSOC);

    if ($cacheSites) {
        $sites = json_decode($cacheSites['DONNEES_JSON'], true);
    } else {
        // ON A REMIS LA CLÉ DANS L'URL ICI
        $url_sites = "https://api-maree.fr/sites?key=" . CLE_API_MAREE;
        $sites_json = callApiMaree($url_sites);
        
        $stmtInsertSites = $pdo->prepare("INSERT INTO CACHE_MAREE (ID_PORT, DATE_MAREE, DONNEES_JSON) VALUES ('LISTE_SITES', '2099-12-31', ?)");
        $stmtInsertSites->execute([$sites_json]);
        $sites = json_decode($sites_json, true);
    }

    // Sécurité et purge du cache cassé
    if (isset($sites['error']) || isset($sites['message'])) {
        $pdo->exec("DELETE FROM CACHE_MAREE WHERE ID_PORT = 'LISTE_SITES'");
        throw new Exception("L'API a renvoyé une erreur (Cache vidé) : " . json_encode($sites));
    }

    $list = $sites['data'] ?? $sites['sites'] ?? $sites;
    
    if (!is_array($list) || empty($list)) {
        $pdo->exec("DELETE FROM CACHE_MAREE WHERE ID_PORT = 'LISTE_SITES'");
        throw new Exception("Format de la liste invalide ou vide. Réponse brute : " . substr(json_encode($sites), 0, 300));
    }

    function distanceKm($lat1, $lon1, $lat2, $lon2) {
        $earth_radius = 6371; 
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat/2) * sin($dLat/2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon/2) * sin($dLon/2);
        $c = 2 * asin(sqrt($a));
        return $earth_radius * $c;
    }

    $closest_site = null;
    $closest_site_key = null;
    $min_distance = 999999;
    
    foreach ($list as $key => $s) {
        $p_lat = $s['latitude'] ?? $s['lat'] ?? null;
        $p_lng = $s['longitude'] ?? $s['lng'] ?? $s['lon'] ?? null;

        if ($p_lat !== null && $p_lng !== null) {
            $dist = distanceKm($lat_user, $lng_user, (float)$p_lat, (float)$p_lng);
            if ($dist < $min_distance) {
                $min_distance = $dist;
                $closest_site = $s;
                $closest_site_key = $key;
            }
        }
    }

    if (!$closest_site) {
         $pdo->exec("DELETE FROM CACHE_MAREE WHERE ID_PORT = 'LISTE_SITES'");
         throw new Exception("Aucun port n'a été trouvé avec des coordonnées. Réponse API : " . substr(json_encode($list), 0, 300));
    }
    
    $id_port = $closest_site['id'] ?? $closest_site['site_id'] ?? $closest_site['slug'] ?? $closest_site_key ?? '';
    $nom_port = $closest_site['name'] ?? $closest_site['site_name'] ?? $closest_site['nom'] ?? 'Port inconnu';

    $stmt = $pdo->prepare("SELECT DONNEES_JSON FROM CACHE_MAREE WHERE ID_PORT = ? AND DATE_MAREE = ?");
    $stmt->execute([$id_port, $date]);
    $cachePort = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($cachePort) {
        $result = json_decode($cachePort['DONNEES_JSON'], true);
        $result['distance_km'] = round($min_distance, 1);
        $result['port_name_custom'] = $nom_port;
        echo json_encode($result);
        exit();
    }

    // ON A REMIS LA CLÉ DANS L'URL ICI AUSSI
    $url_extrema = "https://api-maree.fr/tide-extrema?site={$id_port}&from={$date}&to={$date}&tz=Europe/Paris&key=" . CLE_API_MAREE;
    $response = callApiMaree($url_extrema);
    
    $stmtInsert = $pdo->prepare("INSERT INTO CACHE_MAREE (ID_PORT, DATE_MAREE, DONNEES_JSON) VALUES (?, ?, ?)");
    $stmtInsert->execute([$id_port, $date, $response]);
    
    $result = json_decode($response, true);
    $result['distance_km'] = round($min_distance, 1);
    $result['port_name_custom'] = $nom_port;
    
    echo json_encode($result);

} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>