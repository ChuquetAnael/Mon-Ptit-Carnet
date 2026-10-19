<?php
/**
 * Marées (api-maree.fr) : port de référence le plus proche, horaires du jour en cache,
 * coefficient et état de la marée à une heure donnée.
 * Utilisé par fetch_maree.php (page Météo, saisie de session) et par les conditions gagnantes.
 */
require_once __DIR__ . '/geo.php';

/** Clé API présente ? (fichier cle/api-maree.php, ignoré par Git) */
function maree_disponible(): bool
{
    if (!defined('CLE_API_MAREE') && is_file(__DIR__ . '/../cle/api-maree.php')) require_once __DIR__ . '/../cle/api-maree.php';
    return defined('CLE_API_MAREE');
}

/**
 * Horaires de marée d'une journée pour le port le plus proche.
 * @return array ['donnees' => réponse api-maree décodée, 'port' => nom, 'distance_km' => float]
 * @throws RuntimeException message lisible si le service ne répond pas
 */
function maree_du_jour(PDO $pdo, float $lat, float $lng, string $date): array
{
    if (!maree_disponible()) throw new RuntimeException("Clé API marée absente (fichier cle/api-maree.php).");

    // 1. Liste des ports (mise en cache sans expiration)
    $stmt = $pdo->prepare("SELECT DONNEES_JSON FROM cache_maree WHERE ID_PORT = 'LISTE_SITES'");
    $stmt->execute();
    $sites_json = $stmt->fetchColumn();
    if (!$sites_json) {
        $sites_json = http_get(API_MAREE . "/sites?key=" . urlencode(CLE_API_MAREE));
        $sites = $sites_json ? json_decode($sites_json, true) : null;
        if (!is_array($sites) || isset($sites['error']) || isset($sites['message'])) {
            throw new RuntimeException("Le service de marées ne répond pas pour le moment.");
        }
        $pdo->prepare("REPLACE INTO cache_maree (ID_PORT, DATE_MAREE, DONNEES_JSON) VALUES ('LISTE_SITES', '2099-12-31', ?)")->execute([$sites_json]);
    }
    $sites = json_decode($sites_json, true);
    $liste = $sites['data'] ?? $sites['sites'] ?? $sites;
    if (!is_array($liste) || empty($liste)) {
        $pdo->exec("DELETE FROM cache_maree WHERE ID_PORT = 'LISTE_SITES'");
        throw new RuntimeException("Liste des ports indisponible.");
    }

    // 2. Port le plus proche
    $port = null; $cle_port = null; $min = PHP_FLOAT_MAX;
    foreach ($liste as $cle => $s) {
        $p_lat = $s['latitude'] ?? $s['lat'] ?? null;
        $p_lng = $s['longitude'] ?? $s['lng'] ?? $s['lon'] ?? null;
        if (!is_numeric($p_lat) || !is_numeric($p_lng)) continue;
        $d = distance_km($lat, $lng, (float) $p_lat, (float) $p_lng);
        if ($d < $min) { $min = $d; $port = $s; $cle_port = $cle; }
    }
    if (!$port) {
        $pdo->exec("DELETE FROM cache_maree WHERE ID_PORT = 'LISTE_SITES'");
        throw new RuntimeException("Aucun port de référence trouvé.");
    }
    $id_port = (string) ($port['id'] ?? $port['site_id'] ?? $port['slug'] ?? $cle_port ?? '');
    $nom_port = $port['name'] ?? $port['site_name'] ?? $port['nom'] ?? 'Port inconnu';

    // 3. Marée du jour (cache par port et par date)
    $stmt = $pdo->prepare("SELECT DONNEES_JSON FROM cache_maree WHERE ID_PORT = ? AND DATE_MAREE = ?");
    $stmt->execute([$id_port, $date]);
    $reponse = $stmt->fetchColumn();
    if (!$reponse) {
        $reponse = http_get(API_MAREE . "/tide-extrema?site=" . urlencode($id_port) . "&from={$date}&to={$date}&tz=Europe/Paris&key=" . urlencode(CLE_API_MAREE));
        $decode = $reponse ? json_decode($reponse, true) : null;
        if (!is_array($decode) || isset($decode['error'])) throw new RuntimeException("Horaires de marée indisponibles pour ce port.");
        $pdo->prepare("REPLACE INTO cache_maree (ID_PORT, DATE_MAREE, DONNEES_JSON) VALUES (?, ?, ?)")->execute([$id_port, $date, $reponse]);
        if (random_int(1, 50) === 1) {
            $pdo->exec("DELETE FROM cache_maree WHERE ID_PORT <> 'LISTE_SITES' AND DATE_MAREE < (CURDATE() - INTERVAL 400 DAY)");
        }
    }
    return ['donnees' => json_decode($reponse, true) ?: [], 'port' => $nom_port, 'distance_km' => round($min, 1)];
}

/** Pleines et basses mers [['h' => heure décimale, 'pm' => bool]] et coefficient maximum du jour. */
function maree_extrema(array $donnees): array
{
    $liste = $donnees['data'][0]['extrema'] ?? $donnees['extrema'] ?? [];
    $extrema = []; $coef = null;
    foreach (is_array($liste) ? $liste : [] as $e) {
        $c = $e['coef'] ?? $e['coeff'] ?? null;
        if (is_numeric($c) && (int) $c > 0) $coef = max($coef ?? 0, (int) $c);
        if (preg_match('/([0-2]\d):([0-5]\d)/', (string) ($e['datetime'] ?? $e['time'] ?? $e['heure'] ?? ''), $m)) {
            $type = strtoupper((string) ($e['type'] ?? ''));
            $extrema[] = ['h' => (int) $m[1] + (int) $m[2] / 60, 'pm' => in_array($type, ['PM', 'HIGH', 'PLEINE_MER'], true)];
        }
    }
    usort($extrema, fn($a, $b) => $a['h'] <=> $b['h']);
    return ['extrema' => $extrema, 'coef' => $coef];
}

/** État de la marée à une heure (même règle que script/meteo_map.js : « Haute »/« Basse » à ±45 min). */
function etat_maree(array $extrema, float $heure): ?string
{
    if (!$extrema) return null;
    $premier = $extrema[0]; $dernier = $extrema[count($extrema) - 1];
    if ($heure <= $premier['h']) {
        if ($premier['h'] - $heure < 0.75) return $premier['pm'] ? 'Haute' : 'Basse';
        return $premier['pm'] ? 'Montante' : 'Descendante';
    }
    if ($heure >= $dernier['h']) {
        if ($heure - $dernier['h'] < 0.75) return $dernier['pm'] ? 'Haute' : 'Basse';
        return $dernier['pm'] ? 'Descendante' : 'Montante';
    }
    for ($i = 0; $i < count($extrema) - 1; $i++) {
        $a = $extrema[$i]; $b = $extrema[$i + 1];
        if ($heure >= $a['h'] && $heure <= $b['h']) {
            if ($heure - $a['h'] < 0.75) return $a['pm'] ? 'Haute' : 'Basse';
            if ($b['h'] - $heure < 0.75) return $b['pm'] ? 'Haute' : 'Basse';
            return $a['pm'] ? 'Descendante' : 'Montante';
        }
    }
    return null;
}
