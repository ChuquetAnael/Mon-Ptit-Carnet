<?php
error_reporting(0); // Protège le JavaScript des erreurs HTML
header('Content-Type: application/json');

$lat_raw = isset($_GET['lat']) ? str_replace(',', '.', $_GET['lat']) : '0';
$lng_raw = isset($_GET['lng']) ? str_replace(',', '.', $_GET['lng']) : '0';

$lat_user = number_format(floatval($lat_raw), 6, '.', '');
$lng_user = number_format(floatval($lng_raw), 6, '.', '');

function fetchHubeau($url) {
    $options = [
        "http" => [
            "method" => "GET",
            "header" => "User-Agent: MonCarnetDePeche/2.0\r\nAccept: application/json\r\nAccept-Encoding: gzip\r\n",
            "timeout" => 8
        ],
        "ssl" => [
            "verify_peer" => false,
            "verify_peer_name" => false
        ]
    ];
    $context = stream_context_create($options);
    $result = @file_get_contents($url, false, $context);
    
    if ($result !== false && strlen($result) > 2 && substr($result, 0, 2) === "\x1f\x8b") {
        $result = gzdecode($result);
    }
    
    if ($result === false) return null;
    return json_decode($result, true);
}

function distanceKm($lat1, $lon1, $lat2, $lon2) {
    $earth_radius = 6371; 
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat/2) * sin($dLat/2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon/2) * sin($dLon/2);
    return $earth_radius * (2 * asin(sqrt($a)));
}

try {
    // ÉTAPE 1 : Chercher les stations proches
    $urlStations = "https://hubeau.eaufrance.fr/api/v2/hydrometrie/referentiel/stations?latitude={$lat_user}&longitude={$lng_user}&distance=25&size=20&en_service=true";
    $dataStations = fetchHubeau($urlStations);

    if (!$dataStations || empty($dataStations['data'])) {
        echo json_encode(['error' => "Aucune station hydrométrique à moins de 25 km."]);
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

    // On garde uniquement les 5 plus proches pour le "Tir groupé"
    $topStations = array_slice($stations, 0, 5);
    $codes = array_column($topStations, 'code_station');
    $codesStr = implode(',', $codes);

    // ÉTAPE 2 : Le Tir Groupé (Rapide)
    $urlTr = "https://hubeau.eaufrance.fr/api/v2/hydrometrie/observations_tr?code_entite={$codesStr}&size=1000&sort=desc";
    $dataTr = fetchHubeau($urlTr);
    
    $obsByStation = [];
    if ($dataTr && !empty($dataTr['data'])) {
        foreach ($dataTr['data'] as $o) {
            $c = $o['code_station'];
            if (!isset($obsByStation[$c])) $obsByStation[$c] = [];
            $obsByStation[$c][] = $o;
        }
    }

    $validStation = null;
    $obsTr = [];
    $typeMesure = 'Q';
    $labelMesure = 'Débit';
    $unite = 'm³/s';
    $grandeurElab = 'QmJ';

    foreach ($topStations as $station) {
        $c = $station['code_station'];
        if (isset($obsByStation[$c]) && count($obsByStation[$c]) > 0) {
            $validStation = $station;
            $obsTr = $obsByStation[$c];
            
            $typeMesure = $obsTr[0]['grandeur_hydro'] ?? 'Q';
            if ($typeMesure === 'H') {
                $grandeurElab = 'HmJ';
                $labelMesure = "Hauteur d'eau";
                $unite = 'm';
            }
            break; 
        }
    }

    if (!$validStation || empty($obsTr)) {
        echo json_encode(['error' => "Les stations proches sont en maintenance et ne transmettent aucune donnée."]);
        exit;
    }

    $currentValue = $obsTr[0]['resultat_obs'] / 1000; 

    // ÉTAPE 3 : Statistiques du mois
    $code = $validStation['code_station'];
    $urlElab = "https://hubeau.eaufrance.fr/api/v2/hydrometrie/observations_elaborees?code_entite={$code}&grandeur_hydro_elab={$grandeurElab}&size=30&sort=desc";
    $dataElab = fetchHubeau($urlElab);

    $chartLabels = [];
    $chartData = [];
    $sum = 0; $count = 0;

    if ($dataElab && isset($dataElab['data']) && count($dataElab['data']) > 0) {
        // PLAN A : L'API Statistique fonctionne, on récupère le mois complet instantanément
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
        // PLAN B (LE CORRECTIF) : L'API Statistique est vide pour cette station !
        // On télécharge spécifiquement 4500 relevés en temps réel (1 mois complet) pour la dessiner nous-mêmes.
        $urlFullTr = "https://hubeau.eaufrance.fr/api/v2/hydrometrie/observations_tr?code_entite={$code}&grandeur_hydro={$typeMesure}&size=4500&sort=desc";
        $dataFullTr = fetchHubeau($urlFullTr);
        
        if ($dataFullTr && isset($dataFullTr['data'])) {
            $lastDay = "";
            foreach ($dataFullTr['data'] as $o) {
                $val = $o['resultat_obs'] / 1000;
                $sum += $val;
                $count++;
                
                $timestamp = strtotime($o['date_obs']);
                $day = date('d/m', $timestamp);
                
                // On garde 1 seul point de repère par jour pour faire 30 jours au lieu de 24h !
                if ($day != $lastDay) {
                    array_unshift($chartLabels, $day);
                    array_unshift($chartData, number_format($val, 2, '.', ''));
                    $lastDay = $day;
                }
            }
        } else {
            // Sécurité absolue en cas de crash complet (ne devrait jamais arriver)
            $lastDay = "";
            foreach ($obsTr as $o) {
                $val = $o['resultat_obs'] / 1000;
                $sum += $val;
                $count++;
                $day = date('d/m', strtotime($o['date_obs']));
                if ($day != $lastDay) {
                    array_unshift($chartLabels, $day);
                    array_unshift($chartData, number_format($val, 2, '.', ''));
                    $lastDay = $day;
                }
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

    echo json_encode([
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

} catch (Exception $e) {
    echo json_encode(['error' => "Erreur Serveur : " . $e->getMessage()]);
}
?>