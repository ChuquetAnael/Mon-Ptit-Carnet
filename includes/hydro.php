<?php
/**
 * Hydrométrie : stations Hub'eau, débit au moment d'une session, détection des montées
 * d'eau brutales et niveau de vigilance Vigicrues.
 *
 * Hub'eau renvoie les débits en l/s et les hauteurs en mm : on convertit en m³/s et en m.
 * Les alertes sont indicatives et ne remplacent ni Vigicrues ni les consignes des autorités.
 */
require_once __DIR__ . '/geo.php';

// Seuils de détection d'une montée anormale (variation relative du débit, absolue de la hauteur)
const HAUSSE_DEBIT_1H = [1 => 0.15, 2 => 0.30, 3 => 0.60];   // +15 %, +30 %, +60 % en 1 h
const HAUSSE_DEBIT_3H = [1 => 0.30, 2 => 0.60, 3 => 1.00];   // +30 %, +60 %, x2 en 3 h
const HAUSSE_DEBIT_MIN_M3S = 0.5;                             // en dessous, simple bruit de mesure sur les petits ruisseaux
const HAUSSE_HAUTEUR_1H = [1 => 0.05, 2 => 0.10, 3 => 0.25];  // mètres
const HAUSSE_HAUTEUR_3H = [1 => 0.10, 2 => 0.20, 3 => 0.40];

const NIVEAUX_ALERTE = [
    0 => ['couleur' => 'vert', 'titre' => 'Pas de vigilance particulière'],
    1 => ['couleur' => 'jaune', 'titre' => 'Montée des eaux à surveiller'],
    2 => ['couleur' => 'orange', 'titre' => 'Montée rapide des eaux'],
    3 => ['couleur' => 'rouge', 'titre' => 'Danger : montée brutale des eaux'],
];

/** Code station Hub'eau/Vigicrues valide (lettre + 9 chiffres, ex. I362101001). */
function code_station_valide($code): ?string
{
    return (is_string($code) && preg_match('/^[A-Z][0-9A-Z]{7,11}$/', $code)) ? $code : null;
}

/** Stations hydrométriques en service à moins de $rayon km (cache 7 jours par zone de ~1 km). */
function stations_proches(float $lat, float $lng, int $rayon = 30): array
{
    $cle = sprintf('hydro:st:%.2f,%.2f,%d', $lat, $lng, $rayon);
    if (($cache = cache_lire($cle)) !== null) return $cache;
    $donnees = http_json(API_HUBEAU . sprintf('/referentiel/stations?latitude=%.5F&longitude=%.5F&distance=%d&size=20&en_service=true', $lat, $lng, $rayon), 15);
    if ($donnees === null) return [];
    $stations = [];
    foreach ($donnees['data'] ?? [] as $s) {
        $code = code_station_valide($s['code_station'] ?? null);
        $s_lat = $s['latitude_station'] ?? null;
        $s_lng = $s['longitude_station'] ?? null;
        if (!$code || !is_numeric($s_lat) || !is_numeric($s_lng)) continue;
        $stations[] = [
            'code' => $code,
            'libelle' => (string) ($s['libelle_station'] ?? $code),
            'cours_eau' => (string) ($s['libelle_cours_eau'] ?? ''),
            'distance' => round(distance_km($lat, $lng, (float) $s_lat, (float) $s_lng), 1),
        ];
    }
    usort($stations, fn($a, $b) => $a['distance'] <=> $b['distance']);
    cache_ecrire($cle, $stations, 7 * 86400);
    return $stations;
}

/** Observations temps réel (30 derniers jours max chez Hub'eau), triées de la plus récente à la plus ancienne. */
function observations_tr(array $codes, string $grandeur, int $taille = 200, ?string $debut = null, ?string $fin = null): array
{
    $url = API_HUBEAU . '/observations_tr?code_entite=' . rawurlencode(implode(',', $codes))
         . '&grandeur_hydro=' . ($grandeur === 'H' ? 'H' : 'Q') . '&size=' . $taille . '&sort=desc';
    if ($debut) $url .= '&date_debut_obs=' . rawurlencode($debut);
    if ($fin) $url .= '&date_fin_obs=' . rawurlencode($fin);
    $donnees = http_json($url, 10);
    $obs = [];
    foreach ($donnees['data'] ?? [] as $o) {
        if (!isset($o['resultat_obs'], $o['date_obs']) || !is_numeric($o['resultat_obs'])) continue;
        $obs[] = ['station' => $o['code_station'] ?? '', 'date' => strtotime($o['date_obs']), 'valeur' => (float) $o['resultat_obs'] / 1000];
    }
    usort($obs, fn($a, $b) => $b['date'] <=> $a['date']);
    return $obs;
}

/** Débits moyens journaliers (QmJ) entre deux dates, en m³/s, indexés par date. */
function debits_journaliers(string $code, string $du, string $au): array
{
    $url = API_HUBEAU . '/observations_elaborees?code_entite=' . rawurlencode($code)
         . '&grandeur_hydro_elab=QmJ&date_debut_obs_elab=' . $du . '&date_fin_obs_elab=' . $au . '&size=100';
    $donnees = http_json($url, 10);
    $resultat = [];
    foreach ($donnees['data'] ?? [] as $o) {
        if (isset($o['date_obs_elab'], $o['resultat_obs_elab']) && is_numeric($o['resultat_obs_elab'])) {
            $resultat[substr($o['date_obs_elab'], 0, 10)] = (float) $o['resultat_obs_elab'] / 1000;
        }
    }
    return $resultat;
}

/** Valeur mesurée la plus proche d'un instant (dans la liste triée), ou null si rien à moins de $tolerance secondes. */
function valeur_a_instant(array $obs, int $instant, int $tolerance = 5400): ?float
{
    $meilleure = null; $ecart = PHP_INT_MAX;
    foreach ($obs as $o) {
        $d = abs($o['date'] - $instant);
        if ($d < $ecart) { $ecart = $d; $meilleure = $o['valeur']; }
    }
    return $ecart <= $tolerance ? $meilleure : null;
}

/**
 * Débit au moment d'une session (pour l'analyse des conditions gagnantes).
 * @return array|null ['station', 'libelle', 'cours_eau', 'debit', 'ratio']
 */
function debit_pour_session(float $lat, float $lng, string $datetime): ?array
{
    $instant = strtotime($datetime);
    if (!$instant || $instant > time() + 3600) return null;
    $stations = array_slice(stations_proches($lat, $lng), 0, 5);
    if (!$stations) return null;
    $codes = array_column($stations, 'code');
    $jour = date('Y-m-d', $instant);

    $choisie = null; $debit = null;
    if ($instant > time() - 28 * 86400) {
        $obs = observations_tr($codes, 'Q', 1000, date('c', $instant - 7200), date('c', $instant + 7200));
        foreach ($stations as $s) {
            $v = valeur_a_instant(array_filter($obs, fn($o) => $o['station'] === $s['code']), $instant);
            if ($v !== null) { $choisie = $s; $debit = $v; break; }
        }
    }
    if ($choisie === null) { // session ancienne : débit moyen du jour
        foreach ($stations as $s) {
            $qmj = debits_journaliers($s['code'], $jour, $jour);
            if (isset($qmj[$jour])) { $choisie = $s; $debit = $qmj[$jour]; break; }
        }
    }
    if ($choisie === null) return null;

    $moyenne = debits_journaliers($choisie['code'], date('Y-m-d', $instant - 30 * 86400), date('Y-m-d', $instant - 86400));
    $ratio = ($moyenne && array_sum($moyenne) > 0) ? $debit / (array_sum($moyenne) / count($moyenne)) : null;
    return [
        'station' => $choisie['code'],
        'libelle' => $choisie['libelle'],
        'cours_eau' => $choisie['cours_eau'],
        'debit' => round($debit, 3),
        'ratio' => $ratio === null ? null : round($ratio, 2),
    ];
}

/**
 * Niveau d'anomalie d'une série récente : 0 (rien) à 3 (montée brutale).
 * @param array $obs observations triées de la plus récente à la plus ancienne
 */
function anomalie_niveau_eau(array $obs, string $grandeur): array
{
    if (!$obs) return ['niveau' => 0, 'fenetre' => null, 'variation_1h' => null, 'variation_3h' => null];
    $actuel = $obs[0];
    $v1 = valeur_a_instant($obs, $actuel['date'] - 3600, 1800);
    $v3 = valeur_a_instant($obs, $actuel['date'] - 3 * 3600, 2700);
    $niveau = 0;

    if ($grandeur === 'Q') {
        $var1 = ($v1 !== null && $v1 > 0) ? ($actuel['valeur'] - $v1) / $v1 : null;
        $var3 = ($v3 !== null && $v3 > 0) ? ($actuel['valeur'] - $v3) / $v3 : null;
        $delta = $actuel['valeur'] - ($v3 ?? $v1 ?? $actuel['valeur']);
        $fenetre = null;
        if ($delta >= HAUSSE_DEBIT_MIN_M3S) {
            foreach ([3, 2, 1] as $n) {
                if ($var1 !== null && $var1 >= HAUSSE_DEBIT_1H[$n]) { $niveau = $n; $fenetre = '1h'; break; }
                if ($var3 !== null && $var3 >= HAUSSE_DEBIT_3H[$n]) { $niveau = $n; $fenetre = '3h'; break; }
            }
        }
        return ['niveau' => $niveau, 'fenetre' => $fenetre, 'variation_1h' => $var1 === null ? null : (int) round($var1 * 100), 'variation_3h' => $var3 === null ? null : (int) round($var3 * 100)];
    }

    $d1 = $v1 !== null ? $actuel['valeur'] - $v1 : null;
    $d3 = $v3 !== null ? $actuel['valeur'] - $v3 : null;
    $fenetre = null;
    foreach ([3, 2, 1] as $n) {
        if ($d1 !== null && $d1 >= HAUSSE_HAUTEUR_1H[$n]) { $niveau = $n; $fenetre = '1h'; break; }
        if ($d3 !== null && $d3 >= HAUSSE_HAUTEUR_3H[$n]) { $niveau = $n; $fenetre = '3h'; break; }
    }
    return ['niveau' => $niveau, 'fenetre' => $fenetre, 'variation_1h' => $d1 === null ? null : (int) round($d1 * 100), 'variation_3h' => $d3 === null ? null : (int) round($d3 * 100)]; // en cm
}

/** Tronçon Vigicrues d'une station (cache 30 jours). */
function vigicrues_troncon(string $code): ?array
{
    $cle = 'vigi:troncon:' . $code;
    if (($cache = cache_lire($cle)) !== null) return $cache['troncon'] ? $cache : null;

    $station = http_json(API_VIGICRUES . '/station.json/index.php?CdStationHydro=' . rawurlencode($code), 8);
    if ($station === null) return null; // service indisponible : on réessaiera
    $territoire = $station['VigilanceCrues']['PereBoitEntVigiCru']['CdEntVigiCru'] ?? null;
    $resultat = ['troncon' => null, 'libelle' => null];
    if (is_string($territoire) && $territoire !== '') {
        $liste = http_json(API_VIGICRUES . '/StaEntVigiCru.json?TypEntVigiCru=5&CdEntVigiCru=' . rawurlencode($territoire), 8);
        foreach ($liste['ListEntVigiCru'] ?? [] as $t) {
            foreach ($t['aNMoinsUn'] ?? [] as $s) {
                if (strcasecmp((string) ($s['CdEntVigiCruInferieur'] ?? ''), $code) === 0) {
                    $resultat = ['troncon' => (string) $t['CdEntVigiCru'], 'libelle' => (string) ($t['LbEntVigiCru'] ?? '')];
                    break 2;
                }
            }
        }
    }
    cache_ecrire($cle, $resultat, 30 * 86400);
    return $resultat['troncon'] ? $resultat : null;
}

/** Niveau de vigilance de tous les tronçons (1 vert … 4 rouge), mis en cache 15 minutes. */
function vigicrues_niveaux(): array
{
    if (($cache = cache_lire('vigi:niveaux')) !== null) return $cache;
    $geo = http_json(API_VIGICRUES . '/InfoVigiCru.geojson', 20);
    if ($geo === null) return [];
    $niveaux = [];
    foreach ($geo['features'] ?? [] as $f) {
        $p = $f['properties'] ?? [];
        if (isset($p['CdEntCru'], $p['NivInfViCr']) && is_numeric($p['NivInfViCr'])) $niveaux[(string) $p['CdEntCru']] = (int) $p['NivInfViCr'];
    }
    if ($niveaux) cache_ecrire('vigi:niveaux', $niveaux, 900);
    return $niveaux;
}

/**
 * État de surveillance d'une station (appelé toutes les 10 min par la page ouverte).
 * @return array niveau global 0-3, message, mesures récentes, vigilance Vigicrues
 */
function surveillance_station(string $code): array
{
    $cle = 'hydro:surv:' . $code;
    if (($cache = cache_lire($cle)) !== null) return $cache;

    $grandeur = 'Q';
    $obs = observations_tr([$code], 'Q', 60);
    if (!$obs) { $grandeur = 'H'; $obs = observations_tr([$code], 'H', 60); }
    $anomalie = anomalie_niveau_eau($obs, $grandeur);

    $vigi = null;
    if ($troncon = vigicrues_troncon($code)) {
        $niv = vigicrues_niveaux()[$troncon['troncon']] ?? null;
        if ($niv !== null) $vigi = ['niveau' => $niv, 'couleur' => [1 => 'vert', 2 => 'jaune', 3 => 'orange', 4 => 'rouge'][$niv] ?? 'inconnu', 'troncon' => $troncon['libelle']];
    }
    $niveau = max($anomalie['niveau'], $vigi ? max(0, $vigi['niveau'] - 1) : 0);

    $message = [];
    if ($anomalie['niveau'] > 0) {
        $var = $anomalie['fenetre'] === '1h' ? $anomalie['variation_1h'] : $anomalie['variation_3h'];
        $duree = $anomalie['fenetre'] === '1h' ? '1 h' : '3 h';
        $message[] = $grandeur === 'Q' ? "Le débit a augmenté de $var % en $duree." : "Le niveau d'eau est monté de $var cm en $duree.";
        if ($anomalie['niveau'] >= 3) $message[] = 'Crue éclair ou lâcher de barrage possible : éloigne-toi du lit de la rivière.';
        elseif ($anomalie['niveau'] === 2) $message[] = "Reste sur la berge, garde un œil sur l'eau et repère ta sortie.";
    }
    if ($vigi && $vigi['niveau'] >= 2) $message[] = 'Vigicrues : vigilance ' . $vigi['couleur'] . ($vigi['troncon'] ? ' sur « ' . $vigi['troncon'] . ' »' : '') . '.';

    $resultat = [
        'station' => $code,
        'grandeur' => $grandeur,
        'unite' => $grandeur === 'Q' ? 'm³/s' : 'm',
        'valeur' => $obs ? round($obs[0]['valeur'], 2) : null,
        'date_mesure' => $obs ? date('c', $obs[0]['date']) : null,
        'variation_1h' => $anomalie['variation_1h'],
        'variation_3h' => $anomalie['variation_3h'],
        'anomalie' => $anomalie['niveau'],
        'vigicrues' => $vigi,
        'niveau' => $niveau,
        'couleur' => NIVEAUX_ALERTE[$niveau]['couleur'],
        'titre' => NIVEAUX_ALERTE[$niveau]['titre'],
        'message' => implode(' ', $message),
        'lien_vigicrues' => 'https://www.vigicrues.gouv.fr/',
    ];
    cache_ecrire($cle, $resultat, 300);
    return $resultat;
}
