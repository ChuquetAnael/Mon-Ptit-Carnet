<?php
/**
 * Outils géographiques : distance, façade maritime, département, milieu, polygones.
 * Rien ici n'expose de coordonnées : les fonctions servent à classer un point
 * (département, façade, réserve) côté serveur.
 */

/** Types de spot (table type_spot) : 1 à 8 eau douce, 9 estuaire, 10 à 18 mer. */
const TYPES_SPOT_EAU_DOUCE_MAX = 8;
const TYPE_SPOT_ESTUAIRE = 9;
/** Types de session (table type_session) qui se pratiquent en mer. */
const TYPES_SESSION_MER = [6, 7, 8, 9, 10, 11, 12, 16];
/** Types de session qui se pratiquent en eau douce (coup, feeder). */
const TYPES_SESSION_EAU_DOUCE = [3, 14];

const FACADES = [
    'atlantique_manche' => 'Manche, mer du Nord et Atlantique',
    'mediterranee' => 'Méditerranée',
];
const MILIEUX = ['mer' => 'Mer', 'eau_douce' => 'Eau douce', 'estuaire' => 'Estuaire'];

/** Départements français (code INSEE => nom), pour l'affichage (profil, fil d'actualité, réglementation). */
const DEPARTEMENTS = [
    '01' => 'Ain', '02' => 'Aisne', '03' => 'Allier', '04' => 'Alpes-de-Haute-Provence', '05' => 'Hautes-Alpes',
    '06' => 'Alpes-Maritimes', '07' => 'Ardèche', '08' => 'Ardennes', '09' => 'Ariège', '10' => 'Aube',
    '11' => 'Aude', '12' => 'Aveyron', '13' => 'Bouches-du-Rhône', '14' => 'Calvados', '15' => 'Cantal',
    '16' => 'Charente', '17' => 'Charente-Maritime', '18' => 'Cher', '19' => 'Corrèze', '2A' => 'Corse-du-Sud',
    '2B' => 'Haute-Corse', '21' => "Côte-d'Or", '22' => "Côtes-d'Armor", '23' => 'Creuse', '24' => 'Dordogne',
    '25' => 'Doubs', '26' => 'Drôme', '27' => 'Eure', '28' => 'Eure-et-Loir', '29' => 'Finistère',
    '30' => 'Gard', '31' => 'Haute-Garonne', '32' => 'Gers', '33' => 'Gironde', '34' => 'Hérault',
    '35' => 'Ille-et-Vilaine', '36' => 'Indre', '37' => 'Indre-et-Loire', '38' => 'Isère', '39' => 'Jura',
    '40' => 'Landes', '41' => 'Loir-et-Cher', '42' => 'Loire', '43' => 'Haute-Loire', '44' => 'Loire-Atlantique',
    '45' => 'Loiret', '46' => 'Lot', '47' => 'Lot-et-Garonne', '48' => 'Lozère', '49' => 'Maine-et-Loire',
    '50' => 'Manche', '51' => 'Marne', '52' => 'Haute-Marne', '53' => 'Mayenne', '54' => 'Meurthe-et-Moselle',
    '55' => 'Meuse', '56' => 'Morbihan', '57' => 'Moselle', '58' => 'Nièvre', '59' => 'Nord',
    '60' => 'Oise', '61' => 'Orne', '62' => 'Pas-de-Calais', '63' => 'Puy-de-Dôme', '64' => 'Pyrénées-Atlantiques',
    '65' => 'Hautes-Pyrénées', '66' => 'Pyrénées-Orientales', '67' => 'Bas-Rhin', '68' => 'Haut-Rhin', '69' => 'Rhône',
    '70' => 'Haute-Saône', '71' => 'Saône-et-Loire', '72' => 'Sarthe', '73' => 'Savoie', '74' => 'Haute-Savoie',
    '75' => 'Paris', '76' => 'Seine-Maritime', '77' => 'Seine-et-Marne', '78' => 'Yvelines', '79' => 'Deux-Sèvres',
    '80' => 'Somme', '81' => 'Tarn', '82' => 'Tarn-et-Garonne', '83' => 'Var', '84' => 'Vaucluse',
    '85' => 'Vendée', '86' => 'Vienne', '87' => 'Haute-Vienne', '88' => 'Vosges', '89' => 'Yonne',
    '90' => 'Territoire de Belfort', '91' => 'Essonne', '92' => 'Hauts-de-Seine', '93' => 'Seine-Saint-Denis',
    '94' => 'Val-de-Marne', '95' => "Val-d'Oise", '971' => 'Guadeloupe', '972' => 'Martinique', '973' => 'Guyane',
    '974' => 'La Réunion', '976' => 'Mayotte',
];

/** « Calvados (14) », ou le code seul s'il est inconnu. */
function nom_departement(?string $code): ?string
{
    if ($code === null || $code === '') return null;
    return isset(DEPARTEMENTS[$code]) ? DEPARTEMENTS[$code] . ' (' . $code . ')' : 'Département ' . $code;
}

/** Libellé large d'un lieu de pêche, sans jamais rien de précis : « Mer · Manche (50) ». */
function lieu_large(?string $milieu, ?string $departement, ?string $facade = null): ?string
{
    $parts = array_filter([
        $milieu ? (MILIEUX[$milieu] ?? null) : null,
        nom_departement($departement) ?? ($facade ? (FACADES[$facade] ?? null) : null),
    ]);
    return $parts ? implode(' · ', $parts) : null;
}

function distance_km(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return 6371 * 2 * asin(min(1, sqrt($a)));
}

/**
 * Façade maritime d'un point de la côte française (calcul local, sans API).
 * La Méditerranée commence au cap Cerbère (3,17° E) ; la Corse y est incluse.
 */
function facade_maritime(?float $lat, ?float $lng): ?string
{
    if ($lat === null || $lng === null) return null;
    if ($lat < 43.95 && $lng > 2.95) return 'mediterranee';
    return 'atlantique_manche';
}

/** Milieu d'un spot d'après son type ('eau_douce', 'estuaire', 'mer') ou null. */
function milieu_type_spot(?int $id_type_spot): ?string
{
    if (!$id_type_spot) return null;
    if ($id_type_spot <= TYPES_SPOT_EAU_DOUCE_MAX) return 'eau_douce';
    return $id_type_spot === TYPE_SPOT_ESTUAIRE ? 'estuaire' : 'mer';
}

/** Milieu déduit du type de session quand le spot est inconnu (null si ambigu : leurre, mouche…). */
function milieu_type_session(?int $id_type_session): ?string
{
    if (in_array($id_type_session, TYPES_SESSION_MER, true)) return 'mer';
    if (in_array($id_type_session, TYPES_SESSION_EAU_DOUCE, true)) return 'eau_douce';
    return null;
}

/**
 * Code département (« 50 », « 2A », « 974 ») d'un point, via geo.api.gouv.fr.
 * Le résultat est mis en cache 1 an par cellule d'environ 100 m.
 * Seul le département est conservé : la commune n'est jamais stockée.
 */
function departement_coords(?float $lat, ?float $lng): ?string
{
    if ($lat === null || $lng === null) return null;
    $cle = sprintf('geo:dep:%.3f,%.3f', $lat, $lng);
    $cache = cache_lire($cle);
    if ($cache !== null) return $cache['dep'] ?? null;

    $url = API_GEO . '/communes?lat=' . rawurlencode(sprintf('%.5F', $lat)) . '&lon=' . rawurlencode(sprintf('%.5F', $lng))
         . '&fields=codeDepartement&format=json';
    $reponse = http_json($url, 4);
    if ($reponse === null) return null; // réseau indisponible : on réessaiera plus tard
    $dep = $reponse[0]['codeDepartement'] ?? null;
    $dep = (is_string($dep) && preg_match('/^(\d{2,3}|2[AB])$/', $dep)) ? $dep : null;
    cache_ecrire($cle, ['dep' => $dep], 365 * 86400); // en mer, aucune commune : on mémorise aussi l'absence
    return $dep;
}

/** Point dans un anneau [[lng, lat], ...] (algorithme du lancer de rayon). */
function point_dans_anneau(float $lat, float $lng, array $anneau): bool
{
    $dedans = false;
    $n = count($anneau);
    for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
        $xi = (float) $anneau[$i][0]; $yi = (float) $anneau[$i][1];
        $xj = (float) $anneau[$j][0]; $yj = (float) $anneau[$j][1];
        if ((($yi > $lat) !== ($yj > $lat)) && ($lng < ($xj - $xi) * ($lat - $yi) / (($yj - $yi) ?: 1e-12) + $xi)) {
            $dedans = !$dedans;
        }
    }
    return $dedans;
}

/** Point dans une géométrie GeoJSON Polygon / MultiPolygon (les trous sont respectés). */
function point_dans_geojson(float $lat, float $lng, array $geometrie): bool
{
    $polygones = match ($geometrie['type'] ?? '') {
        'Polygon' => [$geometrie['coordinates'] ?? []],
        'MultiPolygon' => $geometrie['coordinates'] ?? [],
        default => [],
    };
    foreach ($polygones as $anneaux) {
        if (empty($anneaux[0]) || !point_dans_anneau($lat, $lng, $anneaux[0])) continue;
        $dans_un_trou = false;
        foreach (array_slice($anneaux, 1) as $trou) {
            if (point_dans_anneau($lat, $lng, $trou)) { $dans_un_trou = true; break; }
        }
        if (!$dans_un_trou) return true;
    }
    return false;
}

/**
 * Valide une géométrie GeoJSON saisie par l'administrateur.
 * Accepte un objet Geometry, Feature ou FeatureCollection (1re entité).
 * @return array|null ['geometrie' => array, 'bbox' => [latMin, latMax, lngMin, lngMax]]
 */
function geojson_valide(string $texte): ?array
{
    $g = json_decode($texte, true);
    if (!is_array($g)) return null;
    if (($g['type'] ?? '') === 'FeatureCollection') $g = $g['features'][0] ?? [];
    if (($g['type'] ?? '') === 'Feature') $g = $g['geometry'] ?? [];
    $type = $g['type'] ?? '';
    if (!in_array($type, ['Polygon', 'MultiPolygon'], true) || !is_array($g['coordinates'] ?? null)) return null;

    $polygones = $type === 'Polygon' ? [$g['coordinates']] : $g['coordinates'];
    $latMin = 90; $latMax = -90; $lngMin = 180; $lngMax = -180; $points = 0;
    foreach ($polygones as $anneaux) {
        if (!is_array($anneaux)) return null;
        foreach ($anneaux as $anneau) {
            if (!is_array($anneau) || count($anneau) < 4) return null;
            foreach ($anneau as $p) {
                if (!is_array($p) || count($p) < 2 || !is_numeric($p[0]) || !is_numeric($p[1])) return null;
                [$lng, $lat] = [(float) $p[0], (float) $p[1]];
                if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) return null;
                $latMin = min($latMin, $lat); $latMax = max($latMax, $lat);
                $lngMin = min($lngMin, $lng); $lngMax = max($lngMax, $lng);
                if (++$points > 20000) return null;
            }
        }
    }
    // On ne garde que les clés utiles (aucun champ arbitraire n'est réenregistré)
    return ['geometrie' => ['type' => $type, 'coordinates' => $g['coordinates']], 'bbox' => [$latMin, $latMax, $lngMin, $lngMax]];
}

/** Réserves actives contenant le point (nom + règle), pour les alertes. */
function reserves_au_point(PDO $pdo, ?float $lat, ?float $lng, ?string $date = null): array
{
    if ($lat === null || $lng === null) return [];
    try {
        $stmt = $pdo->prepare("
            SELECT ID_RESERVE, NOM, TYPE_ZONE, REGLE, GEOJSON FROM zone_reserve
            WHERE ACTIF = 1 AND ? BETWEEN LAT_MIN AND LAT_MAX AND ? BETWEEN LNG_MIN AND LNG_MAX
              AND (DATE_FIN IS NULL OR DATE_FIN >= ?)
        ");
        $stmt->execute([$lat, $lng, $date ?? date('Y-m-d')]);
    } catch (PDOException $e) {
        return [];
    }
    $resultat = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $geo = json_decode($r['GEOJSON'], true);
        if (is_array($geo) && point_dans_geojson($lat, $lng, $geo)) {
            $resultat[] = ['id' => (int) $r['ID_RESERVE'], 'nom' => $r['NOM'], 'type' => $r['TYPE_ZONE'], 'regle' => $r['REGLE']];
        }
    }
    return $resultat;
}

/** Réserves actives au format léger pour la carte Leaflet (données publiques). */
function reserves_pour_carte(PDO $pdo): array
{
    try {
        $lignes = $pdo->query("SELECT ID_RESERVE, NOM, TYPE_ZONE, REGLE, GEOJSON FROM zone_reserve WHERE ACTIF = 1 AND (DATE_FIN IS NULL OR DATE_FIN >= CURDATE())")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
    return array_map(fn($r) => [
        'id' => (int) $r['ID_RESERVE'],
        'nom' => $r['NOM'],
        'type' => $r['TYPE_ZONE'],
        'regle' => $r['REGLE'],
        'geometrie' => json_decode($r['GEOJSON'], true),
    ], $lignes);
}
