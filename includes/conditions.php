<?php
/**
 * « Conditions gagnantes » : prédire les bons créneaux à partir des données déjà collectées.
 *
 * Mode personnel : chaque heure des prochains jours est comparée aux meilleures sorties du
 *   pêcheur (distance pondérée entre conditions : pression, tendance, vent, marée, débit…).
 * Mode communautaire : répartition des prises de tous les membres par conditions, agrégée
 *   par espèce et par zone large (façade, département, cours d'eau, milieu). Aucune
 *   coordonnée n'est lue ; un résultat n'est montré qu'au-delà d'un seuil d'anonymat.
 */
require_once __DIR__ . '/geo.php';
require_once __DIR__ . '/maree.php';
require_once __DIR__ . '/hydro.php';

// Importance de chaque critère et écart à partir duquel deux valeurs sont jugées « différentes »
const CRITERES = [
    'pression' => ['poids' => 1.0, 'echelle' => 8.0],    // hPa
    'tend3'    => ['poids' => 1.5, 'echelle' => 2.0],    // hPa sur 3 h
    'tend24'   => ['poids' => 1.0, 'echelle' => 5.0],    // hPa sur 24 h
    'vent'     => ['poids' => 0.8, 'echelle' => 12.0],   // km/h
    'temp'     => ['poids' => 0.6, 'echelle' => 6.0],    // °C
    'coeff'    => ['poids' => 1.2, 'echelle' => 25.0],   // coefficient de marée
    'maree'    => ['poids' => 1.0, 'echelle' => 1.0],    // état (catégoriel)
    'debit'    => ['poids' => 1.3, 'echelle' => 0.4],    // débit / moyenne 30 j
    'heure'    => ['poids' => 0.7, 'echelle' => 3.0],    // heure de la journée (circulaire)
];
const NB_MEILLEURES_SORTIES = 5;
const SEUIL_SIMILAIRE = 70;             // % au-delà duquel une heure « ressemble » à une sortie
const COMMUNAUTE_MIN_PRISES = 10;       // seuils d'anonymat des statistiques communautaires
const COMMUNAUTE_MIN_PECHEURS = 3;

/** Conditions d'une session enregistrée, au format des critères. */
function conditions_session(array $s): array
{
    $n = fn($v) => ($v === null || $v === '') ? null : (float) $v;
    return [
        'pression' => $n($s['PRESSION_HPA'] ?? null),
        'tend3' => $n($s['TENDANCE_PRESSION_3H'] ?? null),
        'tend24' => $n($s['TENDANCE_PRESSION_24H'] ?? null),
        'vent' => $n($s['VITESSE_VENT'] ?? null),
        'temp' => $n($s['TEMPERATURE'] ?? null),
        'coeff' => $n($s['COEFF_MAREE'] ?? null),
        'maree' => in_array($s['DESC_MAREE'] ?? null, ['Montante', 'Haute', 'Descendante', 'Basse'], true) ? $s['DESC_MAREE'] : null,
        'debit' => $n($s['DEBIT_RATIO'] ?? null),
        'heure' => isset($s['DATE_DEBUT']) ? (float) date('G', strtotime($s['DATE_DEBUT'])) : null,
    ];
}

/**
 * Similarité (0 à 100) entre deux jeux de conditions : 100 = identiques.
 * Seuls les critères connus des deux côtés comptent ; null si moins de 2 critères communs.
 */
function similarite(array $a, array $b): ?float
{
    $somme = 0.0; $poids = 0.0; $communs = 0;
    foreach (CRITERES as $cle => $c) {
        if (!isset($a[$cle], $b[$cle])) continue;
        if ($cle === 'maree') {
            $d = $a[$cle] === $b[$cle] ? 0.0 : 1.0;
        } elseif ($cle === 'heure') {
            $ecart = abs($a[$cle] - $b[$cle]);
            $d = min(1.0, min($ecart, 24 - $ecart) / $c['echelle']);
        } else {
            $d = min(1.0, abs($a[$cle] - $b[$cle]) / $c['echelle']);
        }
        $somme += $c['poids'] * $d;
        $poids += $c['poids'];
        $communs++;
    }
    return $communs < 2 ? null : round(100 * (1 - $somme / $poids), 1);
}

/** Classe lisible de la tendance de pression. */
function sens_pression(?float $t3): ?string
{
    if ($t3 === null) return null;
    return $t3 <= -1 ? 'en baisse' : ($t3 >= 1 ? 'en hausse' : 'stable');
}
function classe_debit(?float $ratio): ?string
{
    if ($ratio === null) return null;
    return $ratio < 0.8 ? 'bas' : ($ratio <= 1.2 ? 'normal' : ($ratio <= 1.5 ? 'haut' : 'en crue'));
}

/**
 * Meilleures sorties du pêcheur (le plus de poissons, puis les plus gros), avec conditions connues.
 * On élargit si le spot ou l'espèce n'ont pas assez d'historique.
 */
function meilleures_sorties(PDO $pdo, int $id_user, ?int $id_spot, ?int $id_espece, ?string $milieu): array
{
    $tentatives = [];
    if ($id_spot) $tentatives[] = ['s.ID_SPOT = ?', $id_spot, 'sur ce spot'];
    if ($milieu) $tentatives[] = ['s.MILIEU = ?', $milieu, 'dans ce milieu'];
    $tentatives[] = ['1 = ?', 1, 'toutes tes sorties'];

    foreach ($tentatives as [$filtre, $valeur, $portee]) {
        $params = [$id_user, $valeur];
        $sql_espece = '';
        if ($id_espece) { $sql_espece = ' AND p.ID_ESPECE = ?'; $params[] = $id_espece; }
        $stmt = $pdo->prepare("
            SELECT s.ID_SESSION, s.DATE_DEBUT, s.PRESSION_HPA, s.TENDANCE_PRESSION_3H, s.TENDANCE_PRESSION_24H, s.VITESSE_VENT,
                   s.TEMPERATURE, s.COEFF_MAREE, s.DESC_MAREE, s.DEBIT_RATIO, sp.NOM_SPOT,
                   SUM(p.QUANTITE) AS NB, MAX(p.TAILLE_CM) AS TAILLE_MAX
            FROM session_p s
            JOIN prise p ON p.ID_SESSION = s.ID_SESSION
            LEFT JOIN spot sp ON sp.ID_SPOT = s.ID_SPOT
            WHERE s.ID_UTILISATEUR = ? AND $filtre AND (s.PRESSION_HPA IS NOT NULL OR s.COEFF_MAREE IS NOT NULL OR s.DEBIT_RATIO IS NOT NULL) $sql_espece
            GROUP BY s.ID_SESSION
            ORDER BY NB DESC, TAILLE_MAX DESC, s.DATE_DEBUT DESC
            LIMIT " . NB_MEILLEURES_SORTIES
        );
        $stmt->execute($params);
        $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($sessions) >= 3 || $filtre === '1 = ?') {
            return ['portee' => $portee, 'sessions' => array_map(fn($s) => [
                'id' => (int) $s['ID_SESSION'],
                'date' => $s['DATE_DEBUT'],
                'spot' => $s['NOM_SPOT'],
                'nb' => (int) $s['NB'],
                'taille_max' => $s['TAILLE_MAX'] !== null ? (int) $s['TAILLE_MAX'] : null,
                'conditions' => conditions_session($s),
            ], $sessions)];
        }
    }
    return ['portee' => 'toutes tes sorties', 'sessions' => []];
}

/** Prévisions horaires Open-Meteo (veille incluse pour les tendances), cache 1 h. */
function previsions_horaires(float $lat, float $lng): ?array
{
    $cle = sprintf('meteo:prev:%.2f,%.2f', $lat, $lng);
    if (($cache = cache_lire($cle)) !== null) return $cache;
    $d = http_json(API_OPEN_METEO . sprintf('/forecast?latitude=%.4F&longitude=%.4F', $lat, $lng)
        . '&hourly=temperature_2m,pressure_msl,wind_speed_10m,weathercode&past_days=1&forecast_days=3&timezone=Europe%2FParis', 10);
    if (!isset($d['hourly']['time']) || !is_array($d['hourly']['time'])) return null;
    $h = $d['hourly'];
    $resultat = ['time' => $h['time'], 'pression' => $h['pressure_msl'] ?? [], 'vent' => $h['wind_speed_10m'] ?? [], 'temp' => $h['temperature_2m'] ?? [], 'code' => $h['weathercode'] ?? []];
    cache_ecrire($cle, $resultat, 3600);
    return $resultat;
}

/**
 * Analyse personnelle pour un lieu : score de chaque heure (5 h - 22 h) des 3 prochains jours.
 * @param array $lieu ['lat', 'lng', 'milieu', 'id_spot', 'nom']
 */
function analyse_personnelle(PDO $pdo, int $id_user, array $lieu, ?int $id_espece): array
{
    $meilleures = meilleures_sorties($pdo, $id_user, $lieu['id_spot'] ?? null, $id_espece, $lieu['milieu'] ?? null);
    if (!$meilleures['sessions']) {
        return ['erreur' => "Pas encore de sortie avec prises et météo enregistrées" . ($id_espece ? ' pour cette espèce' : '') . ". Enregistre tes prochaines sessions avec la position sur la carte : l'analyse se construit avec ton historique."];
    }
    $prev = previsions_horaires($lieu['lat'], $lieu['lng']);
    if (!$prev) return ['erreur' => "Prévisions météo indisponibles pour le moment. Réessaie dans quelques minutes."];

    // Marée (mer) et débit actuel (eau douce) du lieu
    $marees = [];
    $en_mer = in_array($lieu['milieu'] ?? null, ['mer', 'estuaire'], true);
    $debit_ratio = null;
    if (!$en_mer && ($lieu['milieu'] ?? null) !== null) {
        $debit = debit_pour_session($lieu['lat'], $lieu['lng'], date('Y-m-d H:i:s'));
        $debit_ratio = $debit['ratio'] ?? null;
    }

    $heures = [];
    $aujourdhui = date('Y-m-d');
    foreach ($prev['time'] as $i => $t) {
        $jour = substr($t, 0, 10);
        $heure = (int) substr($t, 11, 2);
        if ($jour < $aujourdhui || $heure < 5 || $heure > 22) continue;
        if ($jour === $aujourdhui && $heure < (int) date('G')) continue;
        $p = $prev['pression'][$i] ?? null;
        $cond = [
            'pression' => $p,
            'tend3' => ($p !== null && isset($prev['pression'][$i - 3])) ? round($p - $prev['pression'][$i - 3], 1) : null,
            'tend24' => ($p !== null && isset($prev['pression'][$i - 24])) ? round($p - $prev['pression'][$i - 24], 1) : null,
            'vent' => $prev['vent'][$i] ?? null,
            'temp' => $prev['temp'][$i] ?? null,
            'coeff' => null, 'maree' => null,
            'debit' => $debit_ratio,
            'heure' => (float) $heure,
        ];
        if ($en_mer) {
            if (!array_key_exists($jour, $marees)) {
                try {
                    $m = maree_du_jour($pdo, $lieu['lat'], $lieu['lng'], $jour);
                    $marees[$jour] = maree_extrema($m['donnees']);
                } catch (Throwable $e) {
                    $marees[$jour] = null;
                }
            }
            if ($marees[$jour]) {
                $cond['coeff'] = $marees[$jour]['coef'];
                $cond['maree'] = etat_maree($marees[$jour]['extrema'], (float) $heure);
            }
        }

        $sims = [];
        foreach ($meilleures['sessions'] as $s) {
            $sim = similarite($cond, $s['conditions']);
            if ($sim !== null) $sims[] = $sim;
        }
        if (!$sims) continue;
        rsort($sims);
        $heures[] = [
            't' => $t,
            'score' => round(array_sum(array_slice($sims, 0, 3)) / min(3, count($sims))),
            'proches' => count(array_filter($sims, fn($v) => $v >= SEUIL_SIMILAIRE)),
            'conditions' => $cond,
        ];
    }
    if (!$heures) return ['erreur' => "Tes sorties enregistrées n'ont pas assez de conditions météo pour être comparées."];

    // Meilleur créneau : l'heure au score maximal, étendue aux heures voisines à 5 points près
    $max = max(array_column($heures, 'score'));
    $idx = array_search($max, array_column($heures, 'score'), true);
    $debut = $fin = $idx;
    while ($debut > 0 && $heures[$debut - 1]['score'] >= $max - 5 && substr($heures[$debut - 1]['t'], 0, 10) === substr($heures[$idx]['t'], 0, 10)) $debut--;
    while ($fin < count($heures) - 1 && $heures[$fin + 1]['score'] >= $max - 5 && substr($heures[$fin + 1]['t'], 0, 10) === substr($heures[$idx]['t'], 0, 10)) $fin++;

    $creneau = [
        'debut' => $heures[$debut]['t'],
        'fin' => date('Y-m-d\TH:i', strtotime($heures[$fin]['t']) + 3600),
        'score' => $max,
        'proches' => $heures[$idx]['proches'],
        'explications' => explications_creneau($heures[$idx]['conditions'], $meilleures['sessions']),
    ];
    $maintenant = $heures[0];

    return [
        'lieu' => $lieu['nom'],
        'portee' => $meilleures['portee'],
        'nb_reference' => count($meilleures['sessions']),
        'maintenant' => ['score' => $maintenant['score'], 'proches' => $maintenant['proches'], 'explications' => explications_creneau($maintenant['conditions'], $meilleures['sessions'])],
        'creneau' => $creneau,
        'heures' => array_map(fn($h) => ['t' => $h['t'], 'score' => $h['score']], $heures),
        'meilleures' => array_map(fn($s) => [
            'date' => date('d/m/Y', strtotime($s['date'])),
            'spot' => $s['spot'],
            'nb' => $s['nb'],
            'taille_max' => $s['taille_max'],
            'resume' => resume_conditions($s['conditions']),
        ], $meilleures['sessions']),
    ];
}

/** Phrases expliquant pourquoi un créneau ressemble (ou non) aux meilleures sorties. */
function explications_creneau(array $c, array $sessions): array
{
    $n = count($sessions);
    $phrases = [];
    $compte = fn(callable $test) => count(array_filter($sessions, fn($s) => $test($s['conditions'])));
    $de = fn(int $k) => $k === $n ? "de tes $n meilleures sorties" : "de $k de tes $n meilleures sorties";

    if ($sens = sens_pression($c['tend3'])) {
        $k = $compte(fn($s) => sens_pression($s['tend3']) === $sens);
        if ($k * 2 >= $n) {
            $phrases[] = ['poids' => $k, 'texte' => "Pression $sens, comme lors " . $de($k) . '.'];
        } else {
            // Contraste utile : la tendance qui revient le plus souvent dans les meilleures sorties
            $classes = array_count_values(array_filter(array_map(fn($s) => sens_pression($s['conditions']['tend3']), $sessions)));
            arsort($classes);
            $habituelle = array_key_first($classes);
            if ($habituelle) $phrases[] = ['poids' => $classes[$habituelle], 'texte' => "Pression $sens, alors que tes meilleures sorties se font plutôt avec une pression $habituelle ({$classes[$habituelle]} sur $n)."];
        }
    }
    if ($c['coeff'] !== null && ($k = $compte(fn($s) => $s['coeff'] !== null && abs($s['coeff'] - $c['coeff']) <= 15))) {
        $phrases[] = ['poids' => $k, 'texte' => "Coefficient {$c['coeff']} : proche " . $de($k) . '.'];
    }
    if ($c['maree'] !== null && ($k = $compte(fn($s) => $s['maree'] === $c['maree']))) {
        $phrases[] = ['poids' => $k, 'texte' => 'Marée ' . mb_strtolower($c['maree']) . ', comme lors ' . $de($k) . '.'];
    }
    if (($classe = classe_debit($c['debit'])) && ($k = $compte(fn($s) => classe_debit($s['debit']) === $classe))) {
        $phrases[] = ['poids' => $k, 'texte' => "Débit $classe pour la saison, comme lors " . $de($k) . '.'];
    }
    if ($c['vent'] !== null && ($k = $compte(fn($s) => $s['vent'] !== null && abs($s['vent'] - $c['vent']) <= 10))) {
        $phrases[] = ['poids' => $k, 'texte' => 'Vent ' . round($c['vent']) . ' km/h : proche ' . $de($k) . '.'];
    }
    usort($phrases, fn($a, $b) => $b['poids'] <=> $a['poids']);
    return array_column(array_slice($phrases, 0, 3), 'texte');
}

function resume_conditions(array $c): string
{
    $parties = [];
    if ($c['pression'] !== null) $parties[] = round($c['pression']) . ' hPa' . (($s = sens_pression($c['tend3'])) ? " ($s)" : '');
    if ($c['coeff'] !== null) $parties[] = 'coef. ' . (int) $c['coeff'] . ($c['maree'] ? ', ' . mb_strtolower($c['maree']) : '');
    if ($c['debit'] !== null) $parties[] = 'débit ' . classe_debit($c['debit']);
    if ($c['vent'] !== null) $parties[] = 'vent ' . round($c['vent']) . ' km/h';
    return implode(' · ', $parties) ?: 'conditions partielles';
}

// -------------------------------------------------------------------------
// MODE COMMUNAUTAIRE
// -------------------------------------------------------------------------

/** Zone d'agrégation validée → [colonne SQL, valeur, libellé] ou null (zones larges uniquement). */
function zone_communaute(string $zone): ?array
{
    if ($zone === 'tout') return ['1', 1, 'tout le site'];
    if (!preg_match('/^(facade|dep|milieu|cours):(.{1,100})$/u', $zone, $m)) return null;
    return match ($m[1]) {
        'facade' => isset(FACADES[$m[2]]) ? ['s.FACADE', $m[2], 'façade ' . FACADES[$m[2]]] : null,
        'dep' => preg_match('/^(\d{2,3}|2[AB])$/', $m[2]) ? ['s.CODE_DEPARTEMENT', $m[2], 'département ' . $m[2]] : null,
        'milieu' => isset(MILIEUX[$m[2]]) ? ['s.MILIEU', $m[2], mb_strtolower(MILIEUX[$m[2]])] : null,
        'cours' => ['s.COURS_EAU', $m[2], $m[2]],
        default => null,
    };
}

/**
 * Zones proposées pour une espèce : seulement celles où au moins COMMUNAUTE_MIN_PECHEURS membres ont des prises
 * (une zone avec un seul pêcheur révélerait ses habitudes).
 */
function zones_communaute(PDO $pdo, ?int $id_espece): array
{
    $zones = [['valeur' => 'tout', 'libelle' => 'Tout le site']];
    foreach (['facade' => 's.FACADE', 'milieu' => 's.MILIEU', 'dep' => 's.CODE_DEPARTEMENT', 'cours' => 's.COURS_EAU'] as $type => $col) {
        $params = [];
        $sql_esp = '';
        if ($id_espece) { $sql_esp = 'AND p.ID_ESPECE = ?'; $params[] = $id_espece; }
        $params[] = COMMUNAUTE_MIN_PECHEURS;
        $stmt = $pdo->prepare("
            SELECT $col AS V FROM prise p JOIN session_p s ON s.ID_SESSION = p.ID_SESSION
            JOIN utilisateur u ON u.ID_UTILISATEUR = s.ID_UTILISATEUR
            WHERE u.PARTAGE_COMMUNAUTE = 1 AND $col IS NOT NULL AND $col <> '' $sql_esp
            GROUP BY $col HAVING COUNT(DISTINCT s.ID_UTILISATEUR) >= ? ORDER BY COUNT(*) DESC LIMIT 30
        ");
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $v) {
            $z = zone_communaute("$type:$v");
            if ($z) $zones[] = ['valeur' => "$type:$v", 'libelle' => ucfirst($z[2])];
        }
    }
    return $zones;
}

/** Statistiques communautaires d'une espèce dans une zone (pourcentages de poissons par conditions). */
function statistiques_communaute(PDO $pdo, ?int $id_espece, string $zone): array
{
    $z = zone_communaute($zone);
    if (!$z) return ['erreur' => 'Zone inconnue.'];
    [$col, $valeur, $libelle] = $z;
    $params = [$valeur];
    $sql_esp = '';
    if ($id_espece) { $sql_esp = 'AND p.ID_ESPECE = ?'; $params[] = $id_espece; }
    $stmt = $pdo->prepare("
        SELECT p.QUANTITE, s.ID_UTILISATEUR, s.PRESSION_HPA, s.TENDANCE_PRESSION_3H, s.TENDANCE_PRESSION_24H, s.VITESSE_VENT,
               s.COEFF_MAREE, s.DESC_MAREE, s.DEBIT_RATIO, HOUR(COALESCE(p.DATE_HEURE, s.DATE_DEBUT)) AS H, MONTH(s.DATE_DEBUT) AS M
        FROM prise p JOIN session_p s ON s.ID_SESSION = p.ID_SESSION
        JOIN utilisateur u ON u.ID_UTILISATEUR = s.ID_UTILISATEUR
        WHERE u.PARTAGE_COMMUNAUTE = 1 AND $col = ? $sql_esp
    ");
    $stmt->execute($params);
    $lignes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $nb = array_sum(array_map(fn($l) => max(1, (int) $l['QUANTITE']), $lignes));
    $pecheurs = count(array_unique(array_column($lignes, 'ID_UTILISATEUR')));

    $base = ['zone' => $libelle, 'nb_prises' => $nb, 'nb_pecheurs' => $pecheurs, 'seuil_prises' => COMMUNAUTE_MIN_PRISES, 'seuil_pecheurs' => COMMUNAUTE_MIN_PECHEURS];
    if ($nb < COMMUNAUTE_MIN_PRISES || $pecheurs < COMMUNAUTE_MIN_PECHEURS) {
        return $base + ['dimensions' => [], 'message' => "Pas encore assez de données dans cette zone : il faut au moins " . COMMUNAUTE_MIN_PRISES . " prises de " . COMMUNAUTE_MIN_PECHEURS . " pêcheurs différents pour garantir l'anonymat."];
    }

    $dimensions = [
        'tend3' => ['titre' => 'Tendance de la pression (3 h)', 'classer' => fn($l) => $l['TENDANCE_PRESSION_3H'] === null ? null : sens_pression((float) $l['TENDANCE_PRESSION_3H']), 'phrase' => 'avec une pression %s'],
        'tend24' => ['titre' => 'Évolution sur 24 h', 'classer' => fn($l) => $l['TENDANCE_PRESSION_24H'] === null ? null : ((float) $l['TENDANCE_PRESSION_24H'] <= -3 ? 'en baisse' : ((float) $l['TENDANCE_PRESSION_24H'] >= 3 ? 'en hausse' : 'stable')), 'phrase' => 'avec une pression %s sur 24 h'],
        'pression' => ['titre' => 'Pression', 'classer' => fn($l) => $l['PRESSION_HPA'] === null ? null : ((float) $l['PRESSION_HPA'] < 1005 ? 'moins de 1005 hPa' : ((float) $l['PRESSION_HPA'] < 1015 ? '1005 à 1015 hPa' : ((float) $l['PRESSION_HPA'] < 1025 ? '1015 à 1025 hPa' : 'plus de 1025 hPa'))), 'phrase' => 'avec une pression de %s'],
        'debit' => ['titre' => 'Débit de la rivière', 'classer' => fn($l) => $l['DEBIT_RATIO'] === null ? null : classe_debit((float) $l['DEBIT_RATIO']), 'phrase' => 'avec un débit %s pour la saison'],
        'coeff' => ['titre' => 'Coefficient de marée', 'classer' => fn($l) => $l['COEFF_MAREE'] === null ? null : ((int) $l['COEFF_MAREE'] < 50 ? 'moins de 50' : ((int) $l['COEFF_MAREE'] < 70 ? '50 à 70' : ((int) $l['COEFF_MAREE'] < 90 ? '70 à 90' : 'plus de 90'))), 'phrase' => 'avec un coefficient de %s'],
        'maree' => ['titre' => 'État de la marée', 'classer' => fn($l) => in_array($l['DESC_MAREE'], ['Montante', 'Haute', 'Descendante', 'Basse'], true) ? mb_strtolower($l['DESC_MAREE']) : null, 'phrase' => 'à marée %s'],
        'vent' => ['titre' => 'Vent', 'classer' => fn($l) => $l['VITESSE_VENT'] === null ? null : ((int) $l['VITESSE_VENT'] < 10 ? 'moins de 10 km/h' : ((int) $l['VITESSE_VENT'] < 20 ? '10 à 20 km/h' : ((int) $l['VITESSE_VENT'] < 30 ? '20 à 30 km/h' : 'plus de 30 km/h'))), 'phrase' => 'avec un vent de %s'],
        'moment' => ['titre' => 'Moment de la journée', 'classer' => fn($l) => $l['H'] === null ? null : ((int) $l['H'] >= 5 && (int) $l['H'] < 9 ? 'au lever du jour (5 h-9 h)' : ((int) $l['H'] < 17 && (int) $l['H'] >= 9 ? 'en journée (9 h-17 h)' : ((int) $l['H'] >= 17 && (int) $l['H'] < 22 ? 'en soirée (17 h-22 h)' : 'de nuit'))), 'phrase' => '%s'],
        'saison' => ['titre' => 'Saison', 'classer' => fn($l) => [12 => 'en hiver', 1 => 'en hiver', 2 => 'en hiver', 3 => 'au printemps', 4 => 'au printemps', 5 => 'au printemps', 6 => 'en été', 7 => 'en été', 8 => 'en été', 9 => 'en automne', 10 => 'en automne', 11 => 'en automne'][(int) $l['M']] ?? null, 'phrase' => '%s'],
    ];

    $resultats = [];
    foreach ($dimensions as $cle => $d) {
        $classes = []; $users = [];
        foreach ($lignes as $l) {
            $c = $d['classer']($l);
            if ($c === null) continue;
            $classes[$c] = ($classes[$c] ?? 0) + max(1, (int) $l['QUANTITE']);
            $users[$l['ID_UTILISATEUR']] = true;
        }
        $total = array_sum($classes);
        if ($total < COMMUNAUTE_MIN_PRISES || count($users) < COMMUNAUTE_MIN_PECHEURS) continue; // pas assez de données sur ce critère
        arsort($classes);
        $repartition = [];
        foreach ($classes as $c => $v) $repartition[] = ['classe' => $c, 'pct' => (int) round(100 * $v / $total)];
        $top = $repartition[0];
        $resultats[] = [
            'cle' => $cle,
            'titre' => $d['titre'],
            'total' => $total,
            'repartition' => $repartition,
            'phrase' => $top['pct'] . ' % des prises ' . sprintf($d['phrase'], $top['classe']),
        ];
    }
    usort($resultats, fn($a, $b) => $b['repartition'][0]['pct'] <=> $a['repartition'][0]['pct']);
    return $base + ['dimensions' => $resultats, 'message' => $resultats ? null : "Les prises de cette zone n'ont pas encore assez de conditions enregistrées."];
}
