<?php
    // 1. KPI : Total des sessions
    $stmtSess = $pdo->prepare("SELECT COUNT(*) as total_sessions FROM session_p WHERE ID_UTILISATEUR = :id_user");
    $stmtSess->execute(['id_user' => $id_user]);
    $total_sessions = $stmtSess->fetchColumn();

    // 2. KPI : Total des prises et No-Kill
    $stmtPrises = $pdo->prepare("
        SELECT 
            SUM(COALESCE(p.QUANTITE, 1)) as total_poissons,
            SUM(CASE WHEN p.RELACHE = 1 THEN COALESCE(p.QUANTITE, 1) ELSE 0 END) as relaches
        FROM prise p
        JOIN session_p s ON p.ID_SESSION = s.ID_SESSION
        WHERE s.ID_UTILISATEUR = :id_user
    ");
    $stmtPrises->execute(['id_user' => $id_user]);
    $kpi_prises = $stmtPrises->fetch(PDO::FETCH_ASSOC);
    
    $total_poissons = $kpi_prises['total_poissons'] ?? 0;
    $taux_nokill = ($total_poissons > 0) ? round(($kpi_prises['relaches'] / $total_poissons) * 100) : 0;

    // 3. KPI : Plus gros poisson (Record Personnel / PB)
    $stmtRecord = $pdo->prepare("
        SELECT p.TAILLE_CM as record_taille, e.NOM_COM 
        FROM prise p 
        JOIN espece e ON p.ID_ESPECE = e.ID_ESPECE 
        JOIN session_p s ON p.ID_SESSION = s.ID_SESSION 
        WHERE s.ID_UTILISATEUR = :id_user AND p.TAILLE_CM IS NOT NULL
        ORDER BY p.TAILLE_CM DESC LIMIT 1
    ");
    $stmtRecord->execute(['id_user' => $id_user]);
    $record = $stmtRecord->fetch(PDO::FETCH_ASSOC);

    // 4. Liste détaillée des espèces
    $stmtEspeces = $pdo->prepare("
        SELECT e.ID_ESPECE, e.NOM_COM, e.ICONE_CHEMIN, 
               SUM(COALESCE(p.QUANTITE, 1)) as nb,
               MAX(p.TAILLE_CM) as pb_cm
        FROM prise p 
        JOIN espece e ON p.ID_ESPECE = e.ID_ESPECE 
        JOIN session_p s ON p.ID_SESSION = s.ID_SESSION 
        WHERE s.ID_UTILISATEUR = :id_user 
        GROUP BY e.ID_ESPECE, e.NOM_COM, e.ICONE_CHEMIN
        ORDER BY nb DESC
    ");
    $stmtEspeces->execute(['id_user' => $id_user]);
    $liste_especes = $stmtEspeces->fetchAll(PDO::FETCH_ASSOC);

    // 5. Statistiques par Spot (Classé par Ratio Prises/Session)
    $stmtSpots = $pdo->prepare("
        SELECT sp.NOM_SPOT, 
               COUNT(s.ID_SESSION) as nb_sessions,
               SUM(COALESCE(sub.nb_poissons, 0)) as nb_prises,
               (SUM(COALESCE(sub.nb_poissons, 0)) / COUNT(s.ID_SESSION)) as ratio
        FROM session_p s
        JOIN spot sp ON s.ID_SPOT = sp.ID_SPOT
        LEFT JOIN (
            SELECT ID_SESSION, SUM(COALESCE(QUANTITE, 1)) as nb_poissons
            FROM prise
            GROUP BY ID_SESSION
        ) sub ON s.ID_SESSION = sub.ID_SESSION
        WHERE s.ID_UTILISATEUR = :id_user
        GROUP BY sp.ID_SPOT, sp.NOM_SPOT
        ORDER BY ratio DESC LIMIT 5
    ");
    $stmtSpots->execute(['id_user' => $id_user]);
    $top_spots = $stmtSpots->fetchAll(PDO::FETCH_ASSOC);

    // 6. Top Techniques & Leurres
    $stmtTech = $pdo->prepare("
        SELECT t.NOM_TECHNIQUE, SUM(COALESCE(p.QUANTITE, 1)) as nb 
        FROM prise p 
        JOIN technique t ON p.ID_TECHNIQUE = t.ID_TECHNIQUE 
        JOIN session_p s ON p.ID_SESSION = s.ID_SESSION 
        WHERE s.ID_UTILISATEUR = :id_user 
        GROUP BY t.ID_TECHNIQUE, t.NOM_TECHNIQUE ORDER BY nb DESC LIMIT 3
    ");
    $stmtTech->execute(['id_user' => $id_user]);
    $top_techs = $stmtTech->fetchAll(PDO::FETCH_ASSOC);

    $stmtLeurre = $pdo->prepare("
        SELECT l.NOM_LEURRE, SUM(COALESCE(p.QUANTITE, 1)) as nb 
        FROM prise p 
        JOIN leurre l ON p.ID_LEURRE = l.ID_LEURRE 
        JOIN session_p s ON p.ID_SESSION = s.ID_SESSION 
        WHERE s.ID_UTILISATEUR = :id_user 
        GROUP BY l.ID_LEURRE, l.NOM_LEURRE ORDER BY nb DESC LIMIT 3
    ");
    $stmtLeurre->execute(['id_user' => $id_user]);
    $top_leurres = $stmtLeurre->fetchAll(PDO::FETCH_ASSOC);

    // 7. Graphique : Effort de pêche mensuel (les 12 DERNIERS mois actifs ;
    //    avant, ORDER BY ASC LIMIT 12 affichait les 12 premiers et figeait le graphique)
    $stmtMois = $pdo->prepare("
        SELECT * FROM (
            SELECT 
                DATE_FORMAT(s.DATE_DEBUT, '%m') as mois_num, 
                DATE_FORMAT(s.DATE_DEBUT, '%Y') as annee,
                SUM(COALESCE(sub.nb_poissons, 0)) as nb_prises,
                SUM(CASE WHEN s.DATE_FIN > s.DATE_DEBUT THEN TIMESTAMPDIFF(MINUTE, s.DATE_DEBUT, s.DATE_FIN) ELSE 0 END) as minutes_peche
            FROM session_p s 
            LEFT JOIN (
                SELECT ID_SESSION, SUM(COALESCE(QUANTITE, 1)) as nb_poissons
                FROM prise
                GROUP BY ID_SESSION
            ) sub ON s.ID_SESSION = sub.ID_SESSION
            WHERE s.ID_UTILISATEUR = :id_user 
            GROUP BY annee, mois_num 
            ORDER BY annee DESC, mois_num DESC LIMIT 12
        ) derniers ORDER BY annee ASC, mois_num ASC
    ");
    $stmtMois->execute(['id_user' => $id_user]);
    $data_mois = $stmtMois->fetchAll(PDO::FETCH_ASSOC);
    
    $mois_fr = ['01'=>'Jan', '02'=>'Fév', '03'=>'Mar', '04'=>'Avr', '05'=>'Mai', '06'=>'Juin', '07'=>'Juil', '08'=>'Aoû', '09'=>'Sep', '10'=>'Oct', '11'=>'Nov', '12'=>'Déc'];
    $labels_mois = []; $valeurs_prises = []; $valeurs_heures_peche = [];
    foreach($data_mois as $m) {
        $labels_mois[] = $mois_fr[$m['mois_num']] . ' ' . substr($m['annee'], 2);
        $valeurs_prises[] = (int)$m['nb_prises'];
        $valeurs_heures_peche[] = round(($m['minutes_peche'] ?? 0) / 60, 1);
    }

    // =========================================================================
    // NOUVELLES STATISTIQUES EN RATIO (Prises / Session)
    // =========================================================================

    // 8. Heures d'activité : répartition des prises et rendement réel par heure de la journée.
    //    - répartition : part de tes poissons pris à chaque heure (le total fait 100 %) ;
    //    - rendement : poissons pris à cette heure ÷ heures réellement passées au bord de l'eau à cette heure-là.
    //    Le temps de pêche vient du début et de la fin des sessions (à défaut : jusqu'à la dernière prise, 1 h minimum).
    //    Avant (V0.2/V0.3), on divisait par le nombre total de sessions : 22 h affichait « 0,09 prise/session »
    //    même si tu n'avais jamais pêché à 22 h.
    $stmtH = $pdo->prepare("
        SELECT HOUR(p.DATE_HEURE) AS heure, SUM(COALESCE(p.QUANTITE, 1)) AS poissons
        FROM prise p JOIN session_p s ON p.ID_SESSION = s.ID_SESSION
        WHERE s.ID_UTILISATEUR = :id_user AND p.DATE_HEURE IS NOT NULL
        GROUP BY HOUR(p.DATE_HEURE)
    ");
    $stmtH->execute(['id_user' => $id_user]);
    $poissons_heure = array_fill(0, 24, 0);
    foreach ($stmtH->fetchAll(PDO::FETCH_ASSOC) as $h) $poissons_heure[(int) $h['heure']] = (int) $h['poissons'];

    $stmtE = $pdo->prepare("
        SELECT s.DATE_DEBUT, s.DATE_FIN, MAX(p.DATE_HEURE) AS derniere_prise
        FROM session_p s LEFT JOIN prise p ON p.ID_SESSION = s.ID_SESSION
        WHERE s.ID_UTILISATEUR = :id_user
        GROUP BY s.ID_SESSION, s.DATE_DEBUT, s.DATE_FIN
    ");
    $stmtE->execute(['id_user' => $id_user]);
    $minutes_heure = array_fill(0, 24, 0);
    foreach ($stmtE->fetchAll(PDO::FETCH_ASSOC) as $se) {
        $a = strtotime($se['DATE_DEBUT']);
        $b = $se['DATE_FIN'] ? strtotime($se['DATE_FIN']) : null;
        if (!$b || $b <= $a) {
            $b = $se['derniere_prise'] ? strtotime($se['derniere_prise']) : $a;
            $b = max($b, $a + 3600); // durée inconnue : au moins une heure
        }
        $b = min($b, $a + 72 * 3600);
        for ($t = $a; $t < $b;) {
            $fin_heure = strtotime(date('Y-m-d H:00:00', $t)) + 3600; // fuseau local (heures d'été / d'hiver)
            $morceau = min($b, $fin_heure) - $t;
            $minutes_heure[(int) date('G', $t)] += $morceau / 60;
            $t += max(60, $morceau);
        }
    }
    $total_heures_poissons = array_sum($poissons_heure);
    $labels_heures = [];
    $repartition_heures = [];
    $rendement_heures = [];
    $meilleure_heure = null;
    $heure_plus_de_poissons = null;
    for ($h = 0; $h < 24; $h++) {
        $labels_heures[] = $h . 'h';
        $repartition_heures[] = $total_heures_poissons ? round($poissons_heure[$h] / $total_heures_poissons * 100, 1) : 0;
        $heures_passees = $minutes_heure[$h] / 60;
        // Moins d'une heure passée à ce créneau : pas assez de recul pour un rendement
        $rendement_heures[] = $heures_passees >= 1 ? round($poissons_heure[$h] / $heures_passees, 2) : null;
        if ($heures_passees >= 2 && $poissons_heure[$h] > 0 && ($meilleure_heure === null || $poissons_heure[$h] / $heures_passees > $meilleure_heure['rendement'])) {
            $meilleure_heure = ['heure' => $h, 'rendement' => $poissons_heure[$h] / $heures_passees, 'heures' => $heures_passees, 'poissons' => $poissons_heure[$h]];
        }
        if ($poissons_heure[$h] > 0 && ($heure_plus_de_poissons === null || $poissons_heure[$h] > $poissons_heure[$heure_plus_de_poissons])) $heure_plus_de_poissons = $h;
    }

    // 8 bis. Détail par espèce (fenêtre ouverte en touchant une espèce du bilan)
    $date_prise = "COALESCE(p.DATE_HEURE, s.DATE_DEBUT)";
    $stmtD = $pdo->prepare("
        SELECT p.ID_ESPECE, COUNT(*) AS lignes,
               SUM(COALESCE(p.QUANTITE, 1)) AS poissons,
               SUM(CASE WHEN p.RELACHE = 1 THEN COALESCE(p.QUANTITE, 1) ELSE 0 END) AS relaches,
               SUM(CASE WHEN p.TAILLE_CM > 0 THEN p.TAILLE_CM * COALESCE(p.QUANTITE, 1) END) / SUM(CASE WHEN p.TAILLE_CM > 0 THEN COALESCE(p.QUANTITE, 1) END) AS taille_moy,
               SUM(CASE WHEN p.TAILLE_CM > 0 THEN COALESCE(p.QUANTITE, 1) END) AS nb_mesures,
               SUM(CASE WHEN p.POIDS_KG > 0 THEN p.POIDS_KG * COALESCE(p.QUANTITE, 1) END) / SUM(CASE WHEN p.POIDS_KG > 0 THEN COALESCE(p.QUANTITE, 1) END) AS poids_moy,
               SUM(CASE WHEN p.POIDS_KG > 0 THEN COALESCE(p.QUANTITE, 1) END) AS nb_pesees,
               MAX(p.POIDS_KG) AS pb_kg, MIN($date_prise) AS premiere, MAX($date_prise) AS derniere
        FROM prise p JOIN session_p s ON p.ID_SESSION = s.ID_SESSION
        WHERE s.ID_UTILISATEUR = :id_user
        GROUP BY p.ID_ESPECE
    ");
    $stmtD->execute(['id_user' => $id_user]);
    $stats_especes = [];
    foreach ($stmtD->fetchAll(PDO::FETCH_ASSOC) as $d) {
        $stats_especes[(int) $d['ID_ESPECE']] = [
            'poissons' => (int) $d['poissons'], 'lignes' => (int) $d['lignes'],
            'nokill' => $d['poissons'] > 0 ? (int) round($d['relaches'] / $d['poissons'] * 100) : 0,
            'taille_moy' => $d['taille_moy'] !== null ? round((float) $d['taille_moy'], 1) : null, 'nb_mesures' => (int) $d['nb_mesures'],
            'poids_moy' => $d['poids_moy'] !== null ? round((float) $d['poids_moy'], 2) : null, 'nb_pesees' => (int) $d['nb_pesees'],
            'pb_kg' => $d['pb_kg'] !== null && (float) $d['pb_kg'] > 0 ? (float) $d['pb_kg'] : null,
            'premiere' => $d['premiere'] ? date('d/m/Y', strtotime($d['premiere'])) : null,
            'derniere' => $d['derniere'] ? date('d/m/Y', strtotime($d['derniere'])) : null,
            'mois' => array_fill(0, 12, 0), 'heures' => array_fill(0, 24, 0),
            'leurre' => null, 'appat' => null, 'technique' => null, 'pb' => null,
        ];
    }
    // Matériel et technique les plus efficaces (nombre de poissons)
    foreach ([
        'leurre' => "SELECT p.ID_ESPECE, l.NOM_LEURRE AS nom, SUM(COALESCE(p.QUANTITE, 1)) AS nb FROM prise p JOIN session_p s ON p.ID_SESSION = s.ID_SESSION JOIN leurre l ON l.ID_LEURRE = p.ID_LEURRE WHERE s.ID_UTILISATEUR = :id_user GROUP BY p.ID_ESPECE, l.ID_LEURRE, l.NOM_LEURRE",
        'appat' => "SELECT p.ID_ESPECE, a.NOM_APPAT AS nom, SUM(COALESCE(p.QUANTITE, 1)) AS nb FROM prise p JOIN session_p s ON p.ID_SESSION = s.ID_SESSION JOIN appat a ON a.ID_APPAT = p.ID_APPAT WHERE s.ID_UTILISATEUR = :id_user GROUP BY p.ID_ESPECE, a.ID_APPAT, a.NOM_APPAT",
        'technique' => "SELECT p.ID_ESPECE, t.NOM_TECHNIQUE AS nom, SUM(COALESCE(p.QUANTITE, 1)) AS nb FROM prise p JOIN session_p s ON p.ID_SESSION = s.ID_SESSION JOIN technique t ON t.ID_TECHNIQUE = p.ID_TECHNIQUE WHERE s.ID_UTILISATEUR = :id_user GROUP BY p.ID_ESPECE, t.ID_TECHNIQUE, t.NOM_TECHNIQUE",
    ] as $cle => $sql) {
        $st = $pdo->prepare($sql);
        $st->execute(['id_user' => $id_user]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $id = (int) $r['ID_ESPECE'];
            if (isset($stats_especes[$id]) && (!$stats_especes[$id][$cle] || $r['nb'] > $stats_especes[$id][$cle]['nb'])) {
                $stats_especes[$id][$cle] = ['nom' => $r['nom'], 'nb' => (int) $r['nb']];
            }
        }
    }
    // Saisonnalité (mois) et heures
    $st = $pdo->prepare("SELECT p.ID_ESPECE, MONTH($date_prise) AS m, HOUR(p.DATE_HEURE) AS h, SUM(COALESCE(p.QUANTITE, 1)) AS nb FROM prise p JOIN session_p s ON p.ID_SESSION = s.ID_SESSION WHERE s.ID_UTILISATEUR = :id_user GROUP BY p.ID_ESPECE, MONTH($date_prise), HOUR(p.DATE_HEURE)");
    $st->execute(['id_user' => $id_user]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $id = (int) $r['ID_ESPECE'];
        if (!isset($stats_especes[$id])) continue;
        $stats_especes[$id]['mois'][(int) $r['m'] - 1] += (int) $r['nb'];
        if ($r['h'] !== null) $stats_especes[$id]['heures'][(int) $r['h']] += (int) $r['nb'];
    }
    // Record personnel (plus grande taille) avec sa date et ses photos
    $st = $pdo->prepare("
        SELECT x.* FROM (
            SELECT p.ID_PRISE, p.ID_ESPECE, p.TAILLE_CM, p.POIDS_KG, s.DATE_DEBUT,
                   ROW_NUMBER() OVER (PARTITION BY p.ID_ESPECE ORDER BY p.TAILLE_CM DESC, p.POIDS_KG DESC, s.DATE_DEBUT) AS rang
            FROM prise p JOIN session_p s ON p.ID_SESSION = s.ID_SESSION
            WHERE s.ID_UTILISATEUR = :id_user AND p.TAILLE_CM > 0
        ) x WHERE x.rang = 1
    ");
    $st->execute(['id_user' => $id_user]);
    $pb_prises = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $id = (int) $r['ID_ESPECE'];
        if (!isset($stats_especes[$id])) continue;
        $stats_especes[$id]['pb'] = ['taille' => (int) $r['TAILLE_CM'], 'poids' => $r['POIDS_KG'] !== null && (float) $r['POIDS_KG'] > 0 ? (float) $r['POIDS_KG'] : null,
            'date' => date('d/m/Y', strtotime($r['DATE_DEBUT'])), 'photos' => []];
        $pb_prises[(int) $r['ID_PRISE']] = $id;
    }
    if ($pb_prises) {
        $st = $pdo->prepare("SELECT ID_PRISE, CHEMIN FROM photo_prise WHERE ID_PRISE IN (" . implode(',', array_fill(0, count($pb_prises), '?')) . ") ORDER BY ORDRE, ID_PHOTO");
        $st->execute(array_keys($pb_prises));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $ph) $stats_especes[$pb_prises[(int) $ph['ID_PRISE']]]['pb']['photos'][] = $ph['CHEMIN'];
    }

    // 9. Météo (Ciel) - Ratio Prises/Session
    $stmtCiel = $pdo->prepare("
        SELECT s.DESCRIP_CIEL, 
               (SUM(COALESCE(sub.nb_poissons, 0)) / COUNT(s.ID_SESSION)) as ratio
        FROM session_p s
        LEFT JOIN (
            SELECT ID_SESSION, SUM(COALESCE(QUANTITE, 1)) as nb_poissons
            FROM prise
            GROUP BY ID_SESSION
        ) sub ON s.ID_SESSION = sub.ID_SESSION
        WHERE s.ID_UTILISATEUR = :id_user AND s.DESCRIP_CIEL IS NOT NULL AND s.DESCRIP_CIEL != ''
        GROUP BY s.DESCRIP_CIEL ORDER BY ratio DESC
    ");
    $stmtCiel->execute(['id_user' => $id_user]);
    $data_ciel = $stmtCiel->fetchAll(PDO::FETCH_ASSOC);
    
    $labels_ciel = js(array_column($data_ciel, 'DESCRIP_CIEL'));
    $valeurs_ciel = js(array_map(function($val) { return round((float)$val, 2); }, array_column($data_ciel, 'ratio')));

    // 10. Température - Ratio par tranches fixes de 5°C (de -15°C à 45°C)
    $stmtTemp = $pdo->prepare("
        SELECT 
            FLOOR(s.TEMPERATURE / 5) * 5 AS tranche_debut,
            (SUM(COALESCE(sub.nb_poissons, 0)) / COUNT(s.ID_SESSION)) as ratio
        FROM session_p s
        LEFT JOIN (
            SELECT ID_SESSION, SUM(COALESCE(QUANTITE, 1)) as nb_poissons
            FROM prise
            GROUP BY ID_SESSION
        ) sub ON s.ID_SESSION = sub.ID_SESSION
        WHERE s.ID_UTILISATEUR = :id_user AND s.TEMPERATURE IS NOT NULL 
        GROUP BY tranche_debut
    ");
    $stmtTemp->execute(['id_user' => $id_user]);
    $data_temp_raw = $stmtTemp->fetchAll(PDO::FETCH_ASSOC);
    
    // Initialisation du tableau avec toutes les tranches requises
    $temp_intervals = [];
    for ($i = -15; $i <= 45; $i += 5) {
        $temp_intervals[$i] = 0;
    }

    foreach($data_temp_raw as $t) {
        $tranche = (int)$t['tranche_debut'];
        if (isset($temp_intervals[$tranche])) {
            $temp_intervals[$tranche] = round((float)$t['ratio'], 2);
        }
    }

    $labels_temp = []; $valeurs_temp = [];
    foreach($temp_intervals as $tranche => $ratio) {
        $labels_temp[] = $tranche . '°C à ' . ($tranche + 5) . '°C';
        $valeurs_temp[] = $ratio;
    }
    $labels_temp_json = js($labels_temp);
    $valeurs_temp_json = js($valeurs_temp);

    // 11. Direction Vent - Ratio pour Boussole (Radar Chart)
    $points_cardinaux = ['N', 'NE', 'E', 'SE', 'S', 'SO', 'O', 'NO'];
    $data_vent_finale = array_fill_keys($points_cardinaux, 0);

    $stmtVent = $pdo->prepare("
        SELECT s.DIREC_VENT, 
               (SUM(COALESCE(sub.nb_poissons, 0)) / COUNT(s.ID_SESSION)) as ratio
        FROM session_p s
        LEFT JOIN (
            SELECT ID_SESSION, SUM(COALESCE(QUANTITE, 1)) as nb_poissons
            FROM prise
            GROUP BY ID_SESSION
        ) sub ON s.ID_SESSION = sub.ID_SESSION
        WHERE s.ID_UTILISATEUR = :id_user AND s.DIREC_VENT IS NOT NULL AND s.DIREC_VENT != ''
        GROUP BY s.DIREC_VENT
    ");
    $stmtVent->execute(['id_user' => $id_user]);
    $data_vent = $stmtVent->fetchAll(PDO::FETCH_ASSOC);
    
    foreach($data_vent as $v) {
        $dir = trim($v['DIREC_VENT']);
        if(array_key_exists($dir, $data_vent_finale)) {
            $data_vent_finale[$dir] = round((float)$v['ratio'], 2);
        }
    }

    // =========================================================================
    // NOUVELLES STATISTIQUES (note.txt : « faire d'autres stats »)
    // =========================================================================

    // Sous-requête réutilisée : nombre de poissons par session (0 pour une session capot)
    $sql_poissons = "LEFT JOIN (SELECT ID_SESSION, SUM(COALESCE(QUANTITE, 1)) AS nb_poissons FROM prise GROUP BY ID_SESSION) sub ON s.ID_SESSION = sub.ID_SESSION";

    // 12. Efficacité : prises par heure de pêche, taux de capot, durée moyenne
    $stmtEff = $pdo->prepare("
        SELECT
            COUNT(*) AS nb_sessions,
            SUM(COALESCE(sub.nb_poissons, 0) = 0) AS nb_capots,
            SUM(CASE WHEN s.DATE_FIN > s.DATE_DEBUT THEN TIMESTAMPDIFF(MINUTE, s.DATE_DEBUT, s.DATE_FIN) END) AS minutes_total,
            SUM(CASE WHEN s.DATE_FIN > s.DATE_DEBUT THEN COALESCE(sub.nb_poissons, 0) END) AS poissons_sessions_datees,
            AVG(CASE WHEN s.DATE_FIN > s.DATE_DEBUT THEN TIMESTAMPDIFF(MINUTE, s.DATE_DEBUT, s.DATE_FIN) END) AS duree_moyenne
        FROM session_p s $sql_poissons
        WHERE s.ID_UTILISATEUR = :id_user
    ");
    $stmtEff->execute(['id_user' => $id_user]);
    $eff = $stmtEff->fetch(PDO::FETCH_ASSOC);
    $taux_capot = $eff['nb_sessions'] > 0 ? round($eff['nb_capots'] / $eff['nb_sessions'] * 100) : 0;
    $prises_par_heure = $eff['minutes_total'] > 0 ? round($eff['poissons_sessions_datees'] / ($eff['minutes_total'] / 60), 2) : null;
    $duree_moyenne = $eff['duree_moyenne'] !== null ? sprintf('%dh%02d', intdiv((int) $eff['duree_moyenne'], 60), (int) $eff['duree_moyenne'] % 60) : '--';

    // 13. Pression atmosphérique : ratio par tranche de 5 hPa
    $stmtPress = $pdo->prepare("
        SELECT FLOOR(s.PRESSION_HPA / 5) * 5 AS tranche,
               SUM(COALESCE(sub.nb_poissons, 0)) / COUNT(*) AS ratio, COUNT(*) AS nb
        FROM session_p s $sql_poissons
        WHERE s.ID_UTILISATEUR = :id_user AND s.PRESSION_HPA IS NOT NULL
        GROUP BY tranche ORDER BY tranche
    ");
    $stmtPress->execute(['id_user' => $id_user]);
    $data_press = $stmtPress->fetchAll(PDO::FETCH_ASSOC);
    $labels_press = js(array_map(fn($r) => (int) $r['tranche'] . '-' . ((int) $r['tranche'] + 5) . ' hPa', $data_press));
    $valeurs_press = js(array_map(fn($r) => round((float) $r['ratio'], 2), $data_press));

    // 14. Marée : ratio par état et par force de coefficient
    $etats_maree = ['Montante', 'Haute', 'Descendante', 'Basse'];
    $stmtEtat = $pdo->prepare("
        SELECT s.DESC_MAREE, SUM(COALESCE(sub.nb_poissons, 0)) / COUNT(*) AS ratio
        FROM session_p s $sql_poissons
        WHERE s.ID_UTILISATEUR = :id_user AND s.DESC_MAREE IS NOT NULL
        GROUP BY s.DESC_MAREE
    ");
    $stmtEtat->execute(['id_user' => $id_user]);
    $ratio_etat = array_fill_keys($etats_maree, 0);
    foreach ($stmtEtat->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (isset($ratio_etat[$r['DESC_MAREE']])) $ratio_etat[$r['DESC_MAREE']] = round((float) $r['ratio'], 2);
    }

    $stmtCoef = $pdo->prepare("
        SELECT CASE
                 WHEN s.COEFF_MAREE < 45 THEN 'Mortes-eaux (< 45)'
                 WHEN s.COEFF_MAREE < 70 THEN 'Moyen (45-69)'
                 WHEN s.COEFF_MAREE < 95 THEN 'Fort (70-94)'
                 ELSE 'Vives-eaux (95+)'
               END AS tranche,
               MIN(s.COEFF_MAREE) AS ordre,
               SUM(COALESCE(sub.nb_poissons, 0)) / COUNT(*) AS ratio
        FROM session_p s $sql_poissons
        WHERE s.ID_UTILISATEUR = :id_user AND s.COEFF_MAREE IS NOT NULL
        GROUP BY tranche ORDER BY ordre
    ");
    $stmtCoef->execute(['id_user' => $id_user]);
    $data_coef = $stmtCoef->fetchAll(PDO::FETCH_ASSOC);
    $a_donnees_maree = array_sum($ratio_etat) > 0 || !empty($data_coef);

    // 15. Saisonnalité : ratio prises/session par mois de l'année (toutes années confondues)
    $stmtSaison = $pdo->prepare("
        SELECT MONTH(s.DATE_DEBUT) AS mois, SUM(COALESCE(sub.nb_poissons, 0)) / COUNT(*) AS ratio
        FROM session_p s $sql_poissons
        WHERE s.ID_UTILISATEUR = :id_user
        GROUP BY mois
    ");
    $stmtSaison->execute(['id_user' => $id_user]);
    $valeurs_saison = array_fill(1, 12, 0);
    foreach ($stmtSaison->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $valeurs_saison[(int) $r['mois']] = round((float) $r['ratio'], 2);
    }

    // 16. Par type de pêche
    $stmtType = $pdo->prepare("
        SELECT ts.NOM_TYPE_SESSION, COUNT(*) AS nb_sessions, SUM(COALESCE(sub.nb_poissons, 0)) / COUNT(*) AS ratio
        FROM session_p s $sql_poissons
        JOIN type_session ts ON s.ID_TYPE_SESSION = ts.ID_TYPE_SESSION
        WHERE s.ID_UTILISATEUR = :id_user
        GROUP BY ts.ID_TYPE_SESSION, ts.NOM_TYPE_SESSION
        ORDER BY ratio DESC
    ");
    $stmtType->execute(['id_user' => $id_user]);
    $stats_types = $stmtType->fetchAll(PDO::FETCH_ASSOC);
