<?php
    // 1. KPI : Total des sessions
    $stmtSess = $pdo->prepare("SELECT COUNT(*) as total_sessions FROM SESSION_P WHERE ID_UTILISATEUR = :id_user");
    $stmtSess->execute(['id_user' => $id_user]);
    $total_sessions = $stmtSess->fetchColumn();

    // 2. KPI : Total des prises et No-Kill
    $stmtPrises = $pdo->prepare("
        SELECT 
            SUM(COALESCE(p.QUANTITE, 1)) as total_poissons,
            SUM(CASE WHEN p.RELACHE = 1 THEN COALESCE(p.QUANTITE, 1) ELSE 0 END) as relaches
        FROM PRISE p
        JOIN SESSION_P s ON p.ID_SESSION = s.ID_SESSION
        WHERE s.ID_UTILISATEUR = :id_user
    ");
    $stmtPrises->execute(['id_user' => $id_user]);
    $kpi_prises = $stmtPrises->fetch(PDO::FETCH_ASSOC);
    
    $total_poissons = $kpi_prises['total_poissons'] ?? 0;
    $taux_nokill = ($total_poissons > 0) ? round(($kpi_prises['relaches'] / $total_poissons) * 100) : 0;

    // 3. KPI : Plus gros poisson (Record Personnel / PB)
    $stmtRecord = $pdo->prepare("
        SELECT MAX(p.TAILLE_CM) as record_taille, e.NOM_COM 
        FROM PRISE p 
        JOIN ESPECE e ON p.ID_ESPECE = e.ID_ESPECE 
        JOIN SESSION_P s ON p.ID_SESSION = s.ID_SESSION 
        WHERE s.ID_UTILISATEUR = :id_user 
        GROUP BY p.ID_ESPECE, e.NOM_COM
        ORDER BY record_taille DESC LIMIT 1
    ");
    $stmtRecord->execute(['id_user' => $id_user]);
    $record = $stmtRecord->fetch(PDO::FETCH_ASSOC);

    // 4. Liste détaillée des espèces
    $stmtEspeces = $pdo->prepare("
        SELECT e.NOM_COM, e.ICONE_CHEMIN, 
               SUM(COALESCE(p.QUANTITE, 1)) as nb,
               MAX(p.TAILLE_CM) as pb_cm
        FROM PRISE p 
        JOIN ESPECE e ON p.ID_ESPECE = e.ID_ESPECE 
        JOIN SESSION_P s ON p.ID_SESSION = s.ID_SESSION 
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
        FROM SESSION_P s
        JOIN SPOT sp ON s.ID_SPOT = sp.ID_SPOT
        LEFT JOIN (
            SELECT ID_SESSION, SUM(COALESCE(QUANTITE, 1)) as nb_poissons
            FROM PRISE
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
        FROM PRISE p 
        JOIN TECHNIQUE t ON p.ID_TECHNIQUE = t.ID_TECHNIQUE 
        JOIN SESSION_P s ON p.ID_SESSION = s.ID_SESSION 
        WHERE s.ID_UTILISATEUR = :id_user 
        GROUP BY t.ID_TECHNIQUE, t.NOM_TECHNIQUE ORDER BY nb DESC LIMIT 3
    ");
    $stmtTech->execute(['id_user' => $id_user]);
    $top_techs = $stmtTech->fetchAll(PDO::FETCH_ASSOC);

    $stmtLeurre = $pdo->prepare("
        SELECT l.NOM_LEURRE, SUM(COALESCE(p.QUANTITE, 1)) as nb 
        FROM PRISE p 
        JOIN LEURRE l ON p.ID_LEURRE = l.ID_LEURRE 
        JOIN SESSION_P s ON p.ID_SESSION = s.ID_SESSION 
        WHERE s.ID_UTILISATEUR = :id_user 
        GROUP BY l.ID_LEURRE, l.NOM_LEURRE ORDER BY nb DESC LIMIT 3
    ");
    $stmtLeurre->execute(['id_user' => $id_user]);
    $top_leurres = $stmtLeurre->fetchAll(PDO::FETCH_ASSOC);

    // 7. Graphique : Effort de pêche mensuel
    $stmtMois = $pdo->prepare("
        SELECT 
            DATE_FORMAT(s.DATE_DEBUT, '%m') as mois_num, 
            DATE_FORMAT(s.DATE_DEBUT, '%Y') as annee,
            SUM(COALESCE(sub.nb_poissons, 0)) as nb_prises,
            SUM(TIMESTAMPDIFF(MINUTE, s.DATE_DEBUT, s.DATE_FIN)) as minutes_peche
        FROM SESSION_P s 
        LEFT JOIN (
            SELECT ID_SESSION, SUM(COALESCE(QUANTITE, 1)) as nb_poissons
            FROM PRISE
            GROUP BY ID_SESSION
        ) sub ON s.ID_SESSION = sub.ID_SESSION
        WHERE s.ID_UTILISATEUR = :id_user 
        GROUP BY annee, mois_num 
        ORDER BY annee ASC, mois_num ASC LIMIT 12
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

    // 8. Heures d'activité (Boucle à 24h)
    $stmtHeures = $pdo->prepare("
        SELECT HOUR(p.DATE_HEURE) as heure, 
               (SUM(COALESCE(p.QUANTITE, 1)) / COUNT(DISTINCT s.ID_SESSION)) as ratio
        FROM PRISE p 
        JOIN SESSION_P s ON p.ID_SESSION = s.ID_SESSION 
        WHERE s.ID_UTILISATEUR = :id_user AND p.DATE_HEURE IS NOT NULL 
        GROUP BY heure ORDER BY heure ASC
    ");
    $stmtHeures->execute(['id_user' => $id_user]);
    $data_heures = $stmtHeures->fetchAll(PDO::FETCH_ASSOC);
    
    $labels_heures = [];
    $valeurs_heures_touches = array_fill(0, 25, 0);
    
    foreach ($data_heures as $h) { 
        $valeurs_heures_touches[(int)$h['heure']] = round((float)$h['ratio'], 2); 
    }
    // Copie la valeur de 0h dans l'index 24 pour boucler visuellement
    $valeurs_heures_touches[24] = $valeurs_heures_touches[0];
    
    for ($i = 0; $i <= 23; $i++) { $labels_heures[] = $i . 'h'; }
    $labels_heures[] = '0h'; // Affiche explicitement "0h" à la fin

    // 9. Météo (Ciel) - Ratio Prises/Session
    $stmtCiel = $pdo->prepare("
        SELECT s.DESCRIP_CIEL, 
               (SUM(COALESCE(sub.nb_poissons, 0)) / COUNT(s.ID_SESSION)) as ratio
        FROM SESSION_P s
        LEFT JOIN (
            SELECT ID_SESSION, SUM(COALESCE(QUANTITE, 1)) as nb_poissons
            FROM PRISE
            GROUP BY ID_SESSION
        ) sub ON s.ID_SESSION = sub.ID_SESSION
        WHERE s.ID_UTILISATEUR = :id_user AND s.DESCRIP_CIEL IS NOT NULL AND s.DESCRIP_CIEL != ''
        GROUP BY s.DESCRIP_CIEL ORDER BY ratio DESC
    ");
    $stmtCiel->execute(['id_user' => $id_user]);
    $data_ciel = $stmtCiel->fetchAll(PDO::FETCH_ASSOC);
    
    $labels_ciel = json_encode(array_column($data_ciel, 'DESCRIP_CIEL'));
    $valeurs_ciel = json_encode(array_map(function($val) { return round((float)$val, 2); }, array_column($data_ciel, 'ratio')));

    // 10. Température - Ratio par tranches fixes de 5°C (de -15°C à 45°C)
    $stmtTemp = $pdo->prepare("
        SELECT 
            FLOOR(s.TEMPERATURE / 5) * 5 AS tranche_debut,
            (SUM(COALESCE(sub.nb_poissons, 0)) / COUNT(s.ID_SESSION)) as ratio
        FROM SESSION_P s
        LEFT JOIN (
            SELECT ID_SESSION, SUM(COALESCE(QUANTITE, 1)) as nb_poissons
            FROM PRISE
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
    $labels_temp_json = json_encode($labels_temp);
    $valeurs_temp_json = json_encode($valeurs_temp);

    // 11. Direction Vent - Ratio pour Boussole (Radar Chart)
    $points_cardinaux = ['N', 'NE', 'E', 'SE', 'S', 'SO', 'O', 'NO'];
    $data_vent_finale = array_fill_keys($points_cardinaux, 0);

    $stmtVent = $pdo->prepare("
        SELECT s.DIREC_VENT, 
               (SUM(COALESCE(sub.nb_poissons, 0)) / COUNT(s.ID_SESSION)) as ratio
        FROM SESSION_P s
        LEFT JOIN (
            SELECT ID_SESSION, SUM(COALESCE(QUANTITE, 1)) as nb_poissons
            FROM PRISE
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
?>