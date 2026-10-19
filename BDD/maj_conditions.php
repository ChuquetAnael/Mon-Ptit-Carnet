<?php
/**
 * Complète les sessions existantes avec les nouvelles conditions de la V0.3 :
 * département, façade, tendance de pression (3 h / 24 h) et débit de la rivière.
 * Sans ces données, les anciennes sorties comptent moins dans « Conditions gagnantes ».
 *
 * Usage (ligne de commande, réseau nécessaire) : php BDD/maj_conditions.php
 * Le script peut être relancé : il ne traite que les sessions encore incomplètes.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit();
}
chdir(dirname(__DIR__));
require_once 'includes/bootstrap.php';
require_once 'includes/hydro.php';

$pdo = db();
$sessions = $pdo->query("
    SELECT s.ID_SESSION, s.DATE_DEBUT, s.MILIEU, s.CODE_DEPARTEMENT, s.FACADE, s.TENDANCE_PRESSION_3H, s.CODE_STATION_HYDRO,
           sp.LOCALISATION, sp.ID_TYPE_SPOT,
           (SELECT CONCAT(p.LATITUDE, ', ', p.LONGITUDE) FROM prise p WHERE p.ID_SESSION = s.ID_SESSION AND p.LATITUDE IS NOT NULL LIMIT 1) AS POSITION_PRISE
    FROM session_p s LEFT JOIN spot sp ON sp.ID_SPOT = s.ID_SPOT
    WHERE s.CODE_DEPARTEMENT IS NULL OR s.TENDANCE_PRESSION_3H IS NULL OR (s.MILIEU = 'eau_douce' AND s.CODE_STATION_HYDRO IS NULL)
    ORDER BY s.DATE_DEBUT DESC
")->fetchAll(PDO::FETCH_ASSOC);

$maj = $pdo->prepare("
    UPDATE session_p SET
        CODE_DEPARTEMENT = COALESCE(CODE_DEPARTEMENT, ?), FACADE = COALESCE(FACADE, ?),
        TENDANCE_PRESSION_3H = COALESCE(TENDANCE_PRESSION_3H, ?), TENDANCE_PRESSION_24H = COALESCE(TENDANCE_PRESSION_24H, ?),
        DEBIT_M3S = COALESCE(DEBIT_M3S, ?), DEBIT_RATIO = COALESCE(DEBIT_RATIO, ?), CODE_STATION_HYDRO = COALESCE(CODE_STATION_HYDRO, ?), COURS_EAU = COALESCE(COURS_EAU, ?)
    WHERE ID_SESSION = ?
");

echo count($sessions) . " session(s) à compléter.\n";
foreach ($sessions as $s) {
    $coords = lire_localisation($s['LOCALISATION']) ?? lire_localisation($s['POSITION_PRISE']);
    if (!$coords) { echo "#{$s['ID_SESSION']} : pas de position, ignorée.\n"; continue; }
    [$lat, $lng] = $coords;
    $milieu = $s['MILIEU'] ?? milieu_type_spot($s['ID_TYPE_SPOT'] ? (int) $s['ID_TYPE_SPOT'] : null);

    // Tendance de pression : archive Open-Meteo (pression au niveau de la mer, heure locale)
    $t3 = $t24 = null;
    $jour = substr($s['DATE_DEBUT'], 0, 10);
    $veille = date('Y-m-d', strtotime("$jour -1 day"));
    $base = strtotime($jour) < time() - 5 * 86400 ? 'https://archive-api.open-meteo.com/v1/archive' : API_OPEN_METEO . '/forecast';
    $meteo = http_json($base . sprintf('?latitude=%.4F&longitude=%.4F', $lat, $lng) . "&start_date=$veille&end_date=$jour&hourly=pressure_msl&timezone=Europe%2FParis", 15);
    if (isset($meteo['hourly']['time'])) {
        $i = array_search(str_replace(' ', 'T', substr($s['DATE_DEBUT'], 0, 13)) . ':00', $meteo['hourly']['time'], true);
        $p = $meteo['hourly']['pressure_msl'];
        if ($i !== false && isset($p[$i])) {
            if (isset($p[$i - 3])) $t3 = round($p[$i] - $p[$i - 3], 1);
            if (isset($p[$i - 24])) $t24 = round($p[$i] - $p[$i - 24], 1);
        }
    }

    $debit = $milieu === 'eau_douce' ? debit_pour_session($lat, $lng, $s['DATE_DEBUT']) : null;
    $maj->execute([
        departement_coords($lat, $lng),
        in_array($milieu, ['mer', 'estuaire'], true) ? facade_maritime($lat, $lng) : null,
        $t3, $t24,
        $debit['debit'] ?? null, $debit['ratio'] ?? null, $debit['station'] ?? null, $debit['cours_eau'] ?? null,
        $s['ID_SESSION'],
    ]);
    echo "#{$s['ID_SESSION']} du $jour : " . ($t3 !== null ? "pression $t3 hPa/3 h" : 'pression ?') . ($debit ? ", débit {$debit['debit']} m³/s" : '') . "\n";
    usleep(250000); // on ménage les API publiques
}

// Départements des spots
$spots = $pdo->query("SELECT ID_SPOT, LOCALISATION FROM spot WHERE CODE_DEPARTEMENT IS NULL AND LOCALISATION IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);
$majSpot = $pdo->prepare("UPDATE spot SET CODE_DEPARTEMENT = ? WHERE ID_SPOT = ?");
foreach ($spots as $sp) {
    if ($c = lire_localisation($sp['LOCALISATION'])) $majSpot->execute([departement_coords($c[0], $c[1]), $sp['ID_SPOT']]);
}
echo "Terminé.\n";
