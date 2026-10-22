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
 * Port de référence le plus proche (liste des ports mise en cache sans expiration).
 * @return array ['id' => string, 'nom' => string, 'distance_km' => float]
 * @throws RuntimeException message lisible si le service ne répond pas
 */
function maree_port_proche(PDO $pdo, float $lat, float $lng): array
{
    if (!maree_disponible()) throw new RuntimeException("Clé API marée absente (fichier cle/api-maree.php).");

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
    return [
        'id' => (string) ($port['id'] ?? $port['site_id'] ?? $port['slug'] ?? $cle_port ?? ''),
        'nom' => $port['name'] ?? $port['site_name'] ?? $port['nom'] ?? 'Port inconnu',
        'distance_km' => round($min, 1),
    ];
}

/** Liste des pleines / basses mers d'une réponse api-maree (une ou plusieurs journées). */
function maree_liste_extrema(array $donnees): array
{
    $liste = [];
    if (isset($donnees['data']) && is_array($donnees['data'])) {
        foreach ($donnees['data'] as $jour) {
            foreach ((array) ($jour['extrema'] ?? []) as $e) {
                if (is_array($e)) $liste[] = $e + (isset($jour['date']) ? ['_jour' => (string) $jour['date']] : []);
            }
        }
    }
    foreach ((array) ($donnees['extrema'] ?? []) as $e) if (is_array($e)) $liste[] = $e;
    return $liste;
}

/**
 * Marées de plusieurs jours pour le port le plus proche, en ménageant l'API (quota limité) :
 * les jours déjà connus viennent du cache (les prédictions ne changent pas) ;
 * les jours manquants sont demandés en UN seul appel (du premier au dernier jour manquant),
 * puis rangés jour par jour dans cache_maree pour tous les membres.
 * @return array ['port' => string, 'distance_km' => float, 'jours' => [date => réponse décodée ['data' => [['extrema' => …]]]]]
 */
function maree_jours(PDO $pdo, float $lat, float $lng, string $debut, int $nb_jours): array
{
    $port = maree_port_proche($pdo, $lat, $lng);
    $nb_jours = max(1, min(14, $nb_jours));
    $dates = [];
    for ($i = 0; $i < $nb_jours; $i++) $dates[] = date('Y-m-d', strtotime($debut . " +$i day"));

    $stmt = $pdo->prepare("SELECT DATE_MAREE, DONNEES_JSON FROM cache_maree WHERE ID_PORT = ? AND DATE_MAREE BETWEEN ? AND ?");
    $stmt->execute([$port['id'], $dates[0], end($dates)]);
    $jours = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $d = json_decode($r['DONNEES_JSON'], true);
        if (is_array($d)) $jours[$r['DATE_MAREE']] = $d;
    }

    $manquants = array_values(array_filter($dates, fn($d) => !isset($jours[$d])));
    if ($manquants) {
        $de = $manquants[0]; $a = end($manquants);
        $reponse = http_get(API_MAREE . "/tide-extrema?site=" . urlencode($port['id']) . "&from={$de}&to={$a}&tz=Europe/Paris&key=" . urlencode(CLE_API_MAREE), 15);
        $decode = $reponse ? json_decode($reponse, true) : null;
        if (!is_array($decode) || isset($decode['error'])) {
            if (!$jours) throw new RuntimeException("Horaires de marée indisponibles pour ce port.");
        } else {
            // Rangement par jour (date tirée de l'horaire « 2026-10-22T05:12… », ou du jour de la réponse)
            $par_jour = [];
            foreach (maree_liste_extrema($decode) as $e) {
                $date = null;
                if (preg_match('/^(\d{4}-\d{2}-\d{2})/', (string) ($e['datetime'] ?? $e['date'] ?? ''), $m)) $date = $m[1];
                elseif (!empty($e['_jour']) && preg_match('/^\d{4}-\d{2}-\d{2}/', $e['_jour'])) $date = substr($e['_jour'], 0, 10);
                elseif ($de === $a) $date = $de;
                unset($e['_jour']);
                if ($date && in_array($date, $manquants, true)) $par_jour[$date][] = $e;
            }
            $ecrire = $pdo->prepare("REPLACE INTO cache_maree (ID_PORT, DATE_MAREE, DONNEES_JSON) VALUES (?, ?, ?)");
            foreach ($par_jour as $date => $extrema) {
                $donnees = ['data' => [['extrema' => $extrema]]];
                $ecrire->execute([$port['id'], $date, json_encode($donnees, JSON_UNESCAPED_UNICODE)]);
                $jours[$date] = $donnees;
            }
            if (random_int(1, 50) === 1) {
                $pdo->exec("DELETE FROM cache_maree WHERE ID_PORT <> 'LISTE_SITES' AND DATE_MAREE < (CURDATE() - INTERVAL 400 DAY)");
            }
        }
    }
    ksort($jours);
    return ['port' => $port['nom'], 'distance_km' => $port['distance_km'], 'jours' => $jours];
}

/**
 * Horaires de marée d'une journée pour le port le plus proche.
 * @return array ['donnees' => réponse api-maree décodée, 'port' => nom, 'distance_km' => float]
 * @throws RuntimeException message lisible si le service ne répond pas
 */
function maree_du_jour(PDO $pdo, float $lat, float $lng, string $date): array
{
    $m = maree_jours($pdo, $lat, $lng, $date, 1);
    if (!isset($m['jours'][$date])) throw new RuntimeException("Horaires de marée indisponibles pour ce port.");
    return ['donnees' => $m['jours'][$date], 'port' => $m['port'], 'distance_km' => $m['distance_km']];
}

/** Pleines et basses mers [['h' => heure décimale, 'pm' => bool]] et coefficient maximum du jour. */
function maree_extrema(array $donnees): array
{
    $liste = maree_liste_extrema($donnees);
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
