<?php
// Proxy JSON vers l'API Hub'eau (hydrométrie) et Vigicrues : réservé aux membres connectés.
//   ?lat&lng                      : état de la rivière la plus proche (page Météo)
//   ?action=surveillance&station  : niveau d'alerte d'une station surveillée (appelé toutes les 10 min)
//   ?action=session&lat&lng&date  : débit au début d'une session (conditions gagnantes)
require_once './includes/bootstrap.php';
require_once './includes/hydro.php';

if (!utilisateur_id()) {
    json_out(['error' => 'Session expirée, reconnecte-toi.'], 401);
}
session_write_close();
header('Content-Type: application/json; charset=UTF-8');

$action = $_GET['action'] ?? '';
if ($action === 'surveillance') {
    $code = code_station_valide($_GET['station'] ?? null);
    if (!$code) json_out(['error' => 'Station invalide.'], 400);
    try {
        json_out(surveillance_station($code));
    } catch (Throwable $e) {
        error_log('[MonPtitCarnet] surveillance : ' . $e->getMessage());
        json_out(['error' => 'Surveillance indisponible pour le moment.'], 502);
    }
}

$lat_num = nombre_ou_null($_GET['lat'] ?? null, -90, 90);
$lng_num = nombre_ou_null($_GET['lng'] ?? null, -180, 180);
if ($lat_num === null || $lng_num === null) {
    json_out(['error' => 'Coordonnées invalides.'], 400);
}
$lat_user = number_format($lat_num, 6, '.', '');
$lng_user = number_format($lng_num, 6, '.', '');

if ($action === 'session') {
    $dt = datetime_ou_null($_GET['date'] ?? '');
    if (!$dt) json_out(['error' => 'Date invalide.'], 400);
    try {
        $debit = debit_pour_session($lat_num, $lng_num, $dt);
        if (!$debit) json_out(['error' => 'Aucun débit mesuré à proximité pour cette date.']);
        // Session en cours : on vérifie aussi qu'aucune montée d'eau n'est en cours
        if (abs(strtotime($dt) - time()) < 3 * 3600) $debit['alerte'] = surveillance_station($debit['station']);
        json_out($debit);
    } catch (Throwable $e) {
        error_log('[MonPtitCarnet] debit session : ' . $e->getMessage());
        json_out(['error' => 'Débit indisponible.'], 502);
    }
}

/** Appel Hub'eau (TLS vérifié, gzip géré par cURL). Retourne le JSON décodé ou null. */
function fetchHubeau($url, $timeout = 10) {
    $result = http_get($url, $timeout);
    return $result === null ? null : json_decode($result, true);
}

function distanceKm($lat1, $lon1, $lat2, $lon2) {
    return distance_km((float) $lat1, (float) $lon1, (float) $lat2, (float) $lon2);
}

try {
    // ÉTAPE 1 : Chercher les stations proches (On repasse à 30 km et on laisse 15 secondes au serveur)
    $urlStations = API_HUBEAU . "/referentiel/stations?latitude={$lat_user}&longitude={$lng_user}&distance=30&size=20&en_service=true";
    $dataStations = fetchHubeau($urlStations, 15);

    if (!$dataStations || empty($dataStations['data'])) {
        echo json_encode(['error' => "Aucune station hydrométrique en service trouvée à moins de 30 km. (Vérifiez votre connexion ou réessayez)"]);
        exit;
    }

    $stations = $dataStations['data'];
    foreach ($stations as &$s) {
        $s_lat = $s['latitude_station'] ?? $s['coordonnee_y_station'] ?? $lat_user;
        $s_lng = $s['longitude_station'] ?? $s['coordonnee_x_station'] ?? $lng_user;
        $s['distance_calculee'] = distanceKm($lat_user, $lng_user, $s_lat, $s_lng);
    }

    usort($stations, function($a, $b) {
        return $a['distance_calculee'] <=> $b['distance_calculee'];
    });

    // Tir groupé sur les 5 plus proches
    $topStations = array_slice($stations, 0, 5);
    $codes = array_column($topStations, 'code_station');
    $codesStr = implode(',', $codes);

    $validStation = null;
    $obsTr = [];
    $typeMesure = 'Q';
    $labelMesure = 'Débit';
    $unite = 'm³/s';
    $grandeurElab = 'QmJ';

    // ÉTAPE 2 : PLAN A - On force la recherche stricte sur le DÉBIT (Q)
    $urlTrQ = API_HUBEAU . "/observations_tr?code_entite={$codesStr}&grandeur_hydro=Q&size=1500&sort=desc";
    $dataTrQ = fetchHubeau($urlTrQ, 8);
    
    if ($dataTrQ && !empty($dataTrQ['data'])) {
        $obsByStation = [];
        foreach ($dataTrQ['data'] as $o) {
            $c = $o['code_station'];
            if (!isset($obsByStation[$c])) $obsByStation[$c] = [];
            $obsByStation[$c][] = $o;
        }
        
        foreach ($topStations as $station) {
            $c = $station['code_station'];
            if (isset($obsByStation[$c]) && count($obsByStation[$c]) > 0) {
                $validStation = $station;
                $obsTr = $obsByStation[$c];
                break; 
            }
        }
    }

    // ÉTAPE 3 : PLAN B - Si vraiment aucun débit, on se rabat sur la HAUTEUR (H)
    if (!$validStation) {
        $urlTrH = API_HUBEAU . "/observations_tr?code_entite={$codesStr}&grandeur_hydro=H&size=1500&sort=desc";
        $dataTrH = fetchHubeau($urlTrH, 8);

        if ($dataTrH && !empty($dataTrH['data'])) {
            $obsByStation = [];
            foreach ($dataTrH['data'] as $o) {
                $c = $o['code_station'];
                if (!isset($obsByStation[$c])) $obsByStation[$c] = [];
                $obsByStation[$c][] = $o;
            }
            
            foreach ($topStations as $station) {
                $c = $station['code_station'];
                if (isset($obsByStation[$c]) && count($obsByStation[$c]) > 0) {
                    $validStation = $station;
                    $obsTr = $obsByStation[$c];
                    $typeMesure = 'H';
                    $grandeurElab = 'HmJ';
                    $labelMesure = "Hauteur d'eau";
                    $unite = 'm';
                    break; 
                }
            }
        }
    }

    if (!$validStation || empty($obsTr)) {
        echo json_encode(['error' => "Les stations proches sont en maintenance et ne transmettent aucune donnée actuellement."]);
        exit;
    }

    $currentValue = (float) ($obsTr[0]['resultat_obs'] ?? 0) / 1000;

    // ÉTAPE 4 : Statistiques (Élaborées)
    $code = $validStation['code_station'];
    $urlElab = API_HUBEAU . "/observations_elaborees?code_entite={$code}&grandeur_hydro_elab={$grandeurElab}&size=30&sort=desc";
    
    $dataElab = fetchHubeau($urlElab, 5);

    $chartLabels = [];
    $chartData = [];
    $sum = 0; $count = 0;

    if ($dataElab && isset($dataElab['data']) && count($dataElab['data']) > 0) {
        foreach ($dataElab['data'] as $o) {
            $val = ($o['resultat_obs_elab'] ?? 0) / 1000;
            $sum += $val;
            $count++;
            $day = date('d/m', strtotime($o['date_obs_elab']));
            array_unshift($chartLabels, $day);
            array_unshift($chartData, number_format($val, 2, '.', ''));
        }
        array_push($chartLabels, "Actuel");
        array_push($chartData, number_format($currentValue, 2, '.', ''));
    } else {
        // Si l'API élaborée est vide, on télécharge un historique raisonnable (1000 relevés = ~5 jours)
        // Cela empêche l'API de crasher, tout en gardant une belle courbe dans ton interface !
        $urlFullTr = API_HUBEAU . "/observations_tr?code_entite={$code}&grandeur_hydro={$typeMesure}&size=1000&sort=desc";
        $dataFullTr = fetchHubeau($urlFullTr, 6);
        
        $sourceObs = ($dataFullTr && isset($dataFullTr['data'])) ? $dataFullTr['data'] : $obsTr;
        
        $lastDay = "";
        foreach ($sourceObs as $o) {
            if (!isset($o['resultat_obs'])) continue;
            $val = $o['resultat_obs'] / 1000;
            $sum += $val;
            $count++;
            $timestamp = strtotime($o['date_obs']);
            $day = date('d/m', $timestamp);
            
            if ($day != $lastDay) {
                array_unshift($chartLabels, $day);
                array_unshift($chartData, number_format($val, 2, '.', ''));
                $lastDay = $day;
            }
        }
    }

    $avgMonth = $count > 0 ? $sum / $count : $currentValue;

    // DIAGNOSTIC
    $etatRiviere = "Niveau de saison";
    $etatColor = "text-success";
    if ($currentValue > ($avgMonth * 1.5)) {
        $etatRiviere = "En Crue (Eau piquée)";
        $etatColor = "text-danger";
    } elseif ($currentValue > ($avgMonth * 1.2)) {
        $etatRiviere = "Rivière gonflée";
        $etatColor = "text-info";
    } elseif ($currentValue < ($avgMonth * 0.6)) {
        $etatRiviere = "Étiage (Très bas/Clair)";
        $etatColor = "text-warning";
    }

    // TENDANCE 24H
    $trend = "Stable"; $trendIcon = "horizontal_rule"; $trendColor = "text-secondary";
    $twentyFourHoursAgo = strtotime('-24 hours', strtotime($obsTr[0]['date_obs']));
    $oldValue = $currentValue;
    
    foreach ($obsTr as $o) {
        if (strtotime($o['date_obs']) <= $twentyFourHoursAgo) {
            $oldValue = $o['resultat_obs'] / 1000;
            break;
        }
    }
    
    $diffPercent = $oldValue > 0 ? (($currentValue - $oldValue) / $oldValue) * 100 : 0;
    if ($diffPercent > 10) { 
        $trend = "En Hausse"; $trendIcon = "trending_up"; $trendColor = "text-danger"; 
    } elseif ($diffPercent < -10) { 
        $trend = "En Décrue"; $trendIcon = "trending_down"; $trendColor = "text-success"; 
    }

    // Alerte crue (variation récente + Vigicrues) pour la station retenue
    $alerte = null;
    try {
        if ($code_valide = code_station_valide($code)) $alerte = surveillance_station($code_valide);
    } catch (Throwable $e) {
        error_log('[MonPtitCarnet] alerte riviere : ' . $e->getMessage());
    }

    echo json_encode([
        'code_station' => $code,
        'cours_eau' => $validStation['libelle_cours_eau'] ?? '',
        'alerte' => $alerte,
        'station_name' => $validStation['libelle_station'] ?? 'Inconnue',
        'distance' => round($validStation['distance_calculee'], 1),
        'current_flow' => round($currentValue, 2),
        'avg_month' => round($avgMonth, 2),
        'etat_riviere' => $etatRiviere,
        'etat_color' => $etatColor,
        'trend_text' => $trend,
        'trend_icon' => $trendIcon,
        'trend_color' => $trendColor,
        'chart_labels' => $chartLabels,
        'chart_data' => $chartData,
        'mesure_label' => $labelMesure, 
        'mesure_unit' => $unite       
    ]);

} catch (Throwable $e) {
    error_log('[MonPtitCarnet] fetch_riviere : ' . $e->getMessage());
    echo json_encode(['error' => "Erreur serveur lors de la lecture des données hydrométriques."]);
}