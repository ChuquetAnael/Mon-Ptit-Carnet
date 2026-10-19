<?php
require_once './includes/auth_bdd.php';
require_once './includes/reglementation.php';
require_once './includes/hydro.php';

$pdo = db();
$id_user = (int) $_SESSION['user_id'];
$etape = isset($_GET['etape']) ? (int)$_GET['etape'] : 1;
if ($etape < 1 || $etape > 4) $etape = 1;

const CIELS_AUTORISES = ['Soleil dégagé', 'Nuageux', 'Brouillard', 'Pluie', 'Neige', 'Averses', 'Orage', 'Inconnu'];
const DIRECTIONS_VENT = ['N', 'NE', 'E', 'SE', 'S', 'SO', 'O', 'NO'];
const ETATS_MAREE = ['Montante', 'Haute', 'Descendante', 'Basse'];
const PHOTOS_MAX_PAR_ENVOI = 20;

/** Crée (si besoin) le spot puis la session ; retourne l'ID de la session. */
function enregistrerSpotEtSession(PDO $pdo, int $id_user, array $s2): int
{
    $id_spot = $s2['id_spot'];
    if ($s2['nouveau_spot']) {
        $stmtSpot = $pdo->prepare("INSERT INTO spot (ID_UTILISATEUR, ID_TYPE_SPOT, NOM_SPOT, LOCALISATION, CATEGORIE_PISCICOLE, CODE_DEPARTEMENT) VALUES (?, ?, ?, ?, ?, ?)");
        $stmtSpot->execute([$id_user, $s2['nouveau_spot']['type'], $s2['nouveau_spot']['nom'], $s2['localisation'], $s2['categorie'], $s2['departement']]);
        $id_spot = (int) $pdo->lastInsertId();
    } elseif ($id_spot && $s2['categorie'] !== null) {
        // Catégorie piscicole précisée pour un spot existant : on la mémorise (spot de l'utilisateur uniquement)
        $pdo->prepare("UPDATE spot SET CATEGORIE_PISCICOLE = ? WHERE ID_SPOT = ? AND ID_UTILISATEUR = ?")->execute([$s2['categorie'], $id_spot, $id_user]);
    }

    $stmtSess = $pdo->prepare("
        INSERT INTO session_p (
            ID_UTILISATEUR, ID_TYPE_SESSION, ID_SPOT, DATE_DEBUT, DATE_FIN,
            TEMPERATURE, PRESSION_HPA, VITESSE_VENT, DIREC_VENT, DESCRIP_CIEL, COEFF_MAREE, DESC_MAREE,
            TENDANCE_PRESSION_3H, TENDANCE_PRESSION_24H, DEBIT_M3S, DEBIT_RATIO, CODE_STATION_HYDRO, COURS_EAU,
            MILIEU, FACADE, CODE_DEPARTEMENT
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmtSess->execute([
        $id_user, $s2['id_type_session'], $id_spot, $s2['date_debut'], $s2['date_fin'],
        $s2['temp'], $s2['press'], $s2['wind'], $s2['direc_vent'], $s2['ciel'],
        $s2['coeff_maree'], $s2['desc_maree'],
        $s2['tendance_3h'], $s2['tendance_24h'], $s2['debit'], $s2['debit_ratio'], $s2['code_station'], $s2['cours_eau'],
        $s2['milieu'], $s2['facade'], $s2['departement'],
    ]);
    return (int) $pdo->lastInsertId();
}

// =========================================================================
// TRAITEMENT POST DES FORMULAIRES (AVANT AFFICHAGE)
// =========================================================================

// POST ÉTAPE 1 : Upload des photos (validées et ré-encodées, EXIF lus avant nettoyage)
if ($etape === 1 && $_SERVER["REQUEST_METHOD"] == "POST") {
    $_SESSION['nouvelle_session'] = [
        'photos' => [],
        'date_heure' => date('Y-m-d\TH:i'), // Heure de la session (globale)
        'lat' => null,
        'lng' => null,
        'photos_refusees' => 0,
    ];

    $fichiers = isset($_FILES['photos']) ? fichiers_multiples($_FILES['photos']) : [];
    foreach (array_slice($fichiers, 0, PHOTOS_MAX_PAR_ENVOI) as $fichier) {
        if ($fichier['error'] === UPLOAD_ERR_NO_FILE) continue;

        $resultat = enregistrer_image_uploadee($fichier, $id_user);
        if (!$resultat) {
            $_SESSION['nouvelle_session']['photos_refusees']++;
            continue;
        }

        $exif = $resultat['exif'];
        $heure_photo = date('H:i');
        $lat = $lng = null;
        $premiere = empty($_SESSION['nouvelle_session']['photos']);

        if (is_array($exif)) {
            if (isset($exif['DateTimeOriginal'])) {
                $date_exif = DateTime::createFromFormat('Y:m:d H:i:s', $exif['DateTimeOriginal']);
                if ($date_exif) {
                    $heure_photo = $date_exif->format('H:i');
                    if ($premiere) $_SESSION['nouvelle_session']['date_heure'] = $date_exif->format('Y-m-d\TH:i');
                }
            }
            if (isset($exif['GPSLatitude'], $exif['GPSLongitude'])) {
                $lat = getGps($exif['GPSLatitude'], $exif['GPSLatitudeRef'] ?? 'N');
                $lng = getGps($exif['GPSLongitude'], $exif['GPSLongitudeRef'] ?? 'E');
                if ($premiere) {
                    $_SESSION['nouvelle_session']['lat'] = $lat;
                    $_SESSION['nouvelle_session']['lng'] = $lng;
                }
            }
        }
        $_SESSION['nouvelle_session']['photos'][] = [
            'chemin' => $resultat['chemin'],
            'heure' => $heure_photo,
            'lat' => $lat,
            'lng' => $lng,
        ];
    }
    $refusees = $_SESSION['nouvelle_session']['photos_refusees'] + max(0, count($fichiers) - PHOTOS_MAX_PAR_ENVOI);
    $_SESSION['nouvelle_session']['photos_refusees'] = $refusees;

    header('Location: nouvelle_session.php?etape=2');
    exit();
}

// POST ÉTAPE 2 : validation et mémorisation (aucune écriture en base, sauf session "capot")
if ($etape === 2 && $_SERVER["REQUEST_METHOD"] == "POST") {
    if (!isset($_SESSION['nouvelle_session'])) {
        header('Location: nouvelle_session.php?etape=1');
        exit();
    }
    $erreurs = [];

    $date_debut = datetime_ou_null($_POST['date_debut'] ?? '');
    $date_fin = datetime_ou_null($_POST['date_fin'] ?? '');
    if (!$date_debut) $erreurs[] = "La date de début est invalide.";
    if ($date_debut && $date_fin && $date_fin < $date_debut) $erreurs[] = "La date de fin doit être après la date de début.";

    $id_type_session = id_ou_null($_POST['id_type_session'] ?? null);
    $stmt = $pdo->prepare("SELECT 1 FROM type_session WHERE ID_TYPE_SESSION = ?");
    $stmt->execute([$id_type_session]);
    if (!$id_type_session || !$stmt->fetchColumn()) $erreurs[] = "Choisis un type de pêche.";

    // Spot : existant (et appartenant à l'utilisateur), nouveau, ou inconnu
    $id_spot = null;
    $nouveau_spot = null;
    if (($_POST['id_spot'] ?? '') === 'new') {
        $nom_spot = trim((string) ($_POST['nouveau_nom_spot'] ?? ''));
        $type_spot = id_ou_null($_POST['nouveau_type_spot'] ?? null);
        $stmt = $pdo->prepare("SELECT 1 FROM type_spot WHERE ID_TYPE_SPOT = ?");
        $stmt->execute([$type_spot]);
        if ($nom_spot === '' || mb_strlen($nom_spot) > 50 || !$type_spot || !$stmt->fetchColumn()) {
            $erreurs[] = "Pour un nouveau spot, indique un nom (50 caractères max) et un type de milieu.";
        } else {
            $nouveau_spot = ['nom' => $nom_spot, 'type' => $type_spot];
        }
    } elseif ($id = id_ou_null($_POST['id_spot'] ?? null)) {
        $stmt = $pdo->prepare("SELECT ID_TYPE_SPOT, CATEGORIE_PISCICOLE, LOCALISATION, CODE_DEPARTEMENT FROM spot WHERE ID_SPOT = ? AND ID_UTILISATEUR = ?");
        $stmt->execute([$id, $id_user]);
        if ($spot_existant = $stmt->fetch(PDO::FETCH_ASSOC)) $id_spot = $id;
    }

    if ($erreurs) {
        $_SESSION['nouvelle_session']['erreurs'] = $erreurs;
        header('Location: nouvelle_session.php?etape=2');
        exit();
    }

    $localisation = localisation_ou_null($_POST['latitude'] ?? null, $_POST['longitude'] ?? null);
    $coords = lire_localisation($localisation);

    // Milieu, catégorie, département : servent au garde-fou réglementaire et aux statistiques communautaires
    $id_type_spot = $nouveau_spot['type'] ?? (isset($spot_existant) && $id_spot ? (int) $spot_existant['ID_TYPE_SPOT'] : null);
    $milieu = milieu_type_spot($id_type_spot) ?? (isset($_POST['peche_mer']) ? 'mer' : milieu_type_session($id_type_session));
    $categorie = in_array($_POST['categorie_piscicole'] ?? '', ['1', '2'], true) ? (int) $_POST['categorie_piscicole'] : null;
    if ($milieu === 'mer') $categorie = null;
    $categorie_effective = $categorie ?? (isset($spot_existant) && $id_spot && $spot_existant['CATEGORIE_PISCICOLE'] !== null ? (int) $spot_existant['CATEGORIE_PISCICOLE'] : null);
    if (!$coords && isset($spot_existant) && $id_spot) $coords = lire_localisation($spot_existant['LOCALISATION']);
    $departement = $coords ? departement_coords($coords[0], $coords[1]) : null;
    $tend3 = nombre_ou_null($_POST['meteo_tend3h'] ?? null, -30, 30);
    $tend24 = nombre_ou_null($_POST['meteo_tend24h'] ?? null, -60, 60);
    $debit = nombre_ou_null($_POST['debit_m3s'] ?? null, 0, 100000);
    $debit_ratio = nombre_ou_null($_POST['debit_ratio'] ?? null, 0, 100);
    $code_station = $milieu === 'mer' ? null : code_station_valide($_POST['code_station'] ?? null); // le débit d'une rivière voisine n'a pas de sens en mer
    $temp = nombre_ou_null($_POST['meteo_temp'] ?? null, -60, 60);
    $press = nombre_ou_null($_POST['meteo_press'] ?? null, 800, 1100);
    $wind = nombre_ou_null($_POST['meteo_wind'] ?? null, 0, 400);
    $coeff = id_ou_null($_POST['coeff_maree'] ?? null);

    $_SESSION['nouvelle_session']['step2'] = [
        'date_debut' => $date_debut,
        'date_fin' => $date_fin,
        'id_type_session' => $id_type_session,
        'id_spot' => $id_spot,
        'nouveau_spot' => $nouveau_spot,
        'localisation' => $localisation,
        'lat_final' => $coords[0] ?? null,
        'lng_final' => $coords[1] ?? null,
        // Météo & Marée : une valeur absente (pas de réseau, pas de GPS) devient NULL
        'temp' => $temp === null ? null : (int) round($temp),
        'press' => $press,
        'wind' => $wind === null ? null : (int) round($wind),
        'direc_vent' => in_array($_POST['meteo_direc'] ?? '', DIRECTIONS_VENT, true) ? $_POST['meteo_direc'] : null,
        'ciel' => in_array($_POST['meteo_ciel'] ?? '', CIELS_AUTORISES, true) ? $_POST['meteo_ciel'] : null,
        'coeff_maree' => ($coeff !== null && $coeff >= 20 && $coeff <= 120) ? $coeff : null,
        'desc_maree' => in_array($_POST['desc_maree'] ?? '', ETATS_MAREE, true) ? $_POST['desc_maree'] : null,
        // Conditions complètes (analyse « conditions gagnantes »)
        'tendance_3h' => $tend3 === null ? null : round($tend3, 1),
        'tendance_24h' => $tend24 === null ? null : round($tend24, 1),
        'debit' => $code_station ? $debit : null,
        'debit_ratio' => $code_station ? $debit_ratio : null,
        'code_station' => $code_station,
        'cours_eau' => $code_station ? (mb_substr(trim((string) ($_POST['cours_eau'] ?? '')), 0, 100) ?: null) : null,
        // Lieu (jamais de coordonnées dans la session : département et façade seulement)
        'milieu' => $milieu,
        'facade' => in_array($milieu, ['mer', 'estuaire'], true) && $coords ? facade_maritime($coords[0], $coords[1]) : null,
        'departement' => $departement,
        'categorie' => $categorie,
        'categorie_effective' => $categorie_effective,
        'id_type_spot' => $id_type_spot,
    ];

    // SI CAPOT : on enregistre directement la session et on saute l'étape 3
    if (isset($_POST['is_capot'])) {
        $pdo->beginTransaction();
        enregistrerSpotEtSession($pdo, $id_user, $_SESSION['nouvelle_session']['step2']);
        $pdo->commit();
        $_SESSION['nouvelle_session']['terminee'] = true;
        header('Location: nouvelle_session.php?etape=4');
        exit();
    }

    header('Location: nouvelle_session.php?etape=3');
    exit();
}

// POST ÉTAPE 3 : ENREGISTREMENT FINAL EN BASE DE DONNÉES
if ($etape === 3 && $_SERVER["REQUEST_METHOD"] == "POST") {
    if (!empty($_SESSION['nouvelle_session']['terminee'])) { // double envoi (bouton retour)
        header('Location: nouvelle_session.php?etape=4');
        exit();
    }
    if (empty($_SESSION['nouvelle_session']['step2'])) {
        header('Location: nouvelle_session.php?etape=1');
        exit();
    }
    $s2 = $_SESSION['nouvelle_session']['step2'];
    $date_base = substr($s2['date_debut'], 0, 10);
    $heure_debut = substr($s2['date_debut'], 11, 5);

    // Garde-fou réglementaire : un poisson gardé hors règles doit avoir été confirmé par le pêcheur
    $ctx = contexte_reglementaire($pdo, [
        'id_type_spot' => $s2['id_type_spot'] ?? null, 'categorie' => $s2['categorie_effective'] ?? null,
        'id_type_session' => $s2['id_type_session'], 'mer' => ($s2['milieu'] ?? null) === 'mer',
        'lat' => $s2['lat_final'], 'lng' => $s2['lng_final'], 'date' => $date_base, 'departement' => $s2['departement'] ?? null,
    ]);
    $prises_a_controler = [];
    foreach ((array) ($_POST['prises'] ?? []) as $p) {
        if (!$id_esp = id_ou_null($p['id_espece'] ?? null)) continue;
        $prises_a_controler[] = [
            'espece' => $id_esp, 'taille' => nombre_ou_null($p['taille'] ?? null, 0, 1000),
            'quantite' => id_ou_null($p['quantite'] ?? null) ?? 1, 'relache' => isset($p['relache']), 'confirme' => !empty($p['confirme_regles']),
        ];
    }
    $non_conformes = prises_non_conformes(regles_resolues($pdo, $ctx), $ctx, $prises_a_controler, gardes_du_jour($pdo, $id_user, $date_base));
    if ($non_conformes) {
        $_SESSION['nouvelle_session']['erreurs'] = ["Une ou plusieurs prises gardées ne respectent pas la réglementation affichée. Relâche-les ou confirme chaque alerte avant d'enregistrer."];
        header('Location: nouvelle_session.php?etape=3');
        exit();
    }

    // Photos autorisées = uniquement celles envoyées à l'étape 1 de CETTE saisie
    $photos_session = [];
    foreach ($_SESSION['nouvelle_session']['photos'] ?? [] as $ph) {
        $photos_session[$ph['chemin']] = $ph;
    }

    // Matériel autorisé = le sien + le matériel commun (ID_UTILISATEUR NULL)
    $stmt = $pdo->prepare("SELECT ID_LEURRE FROM leurre WHERE ID_UTILISATEUR = ? OR ID_UTILISATEUR IS NULL");
    $stmt->execute([$id_user]);
    $leurres_autorises = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));
    $stmt = $pdo->prepare("SELECT ID_APPAT FROM appat WHERE ID_UTILISATEUR = ? OR ID_UTILISATEUR IS NULL");
    $stmt->execute([$id_user]);
    $appats_autorises = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));

    // Catégories valides (un type inconnu ferait échouer toute la sauvegarde sur la clé étrangère)
    $types_leurre_ok = array_flip($pdo->query("SELECT ID_TYPE FROM type_leurre")->fetchAll(PDO::FETCH_COLUMN));
    $types_appat_ok = array_flip($pdo->query("SELECT ID_TYPE_APPAT FROM type_appat")->fetchAll(PDO::FETCH_COLUMN));
    $especes_ok = array_flip($pdo->query("SELECT ID_ESPECE FROM espece")->fetchAll(PDO::FETCH_COLUMN));
    $techniques_ok = array_flip($pdo->query("SELECT ID_TECHNIQUE FROM technique")->fetchAll(PDO::FETCH_COLUMN));

    $pdo->beginTransaction();

    // 1. Spot + Session (avec météo et marée)
    $id_session = enregistrerSpotEtSession($pdo, $id_user, $s2);

    // 2. Nouveaux leurres et appâts créés pendant la saisie
    $map_new_leurres = [];
    $stmtL = $pdo->prepare("INSERT INTO leurre (ID_UTILISATEUR, ID_TYPE, NOM_LEURRE, GRAMMAGE, COLORIS) VALUES (?, ?, ?, ?, ?)");
    foreach ((array) ($_POST['new_leurres'] ?? []) as $temp_id => $l) {
        $nom = trim((string) ($l['nom'] ?? ''));
        $type = id_ou_null($l['type'] ?? null);
        if (!preg_match('/^new_l_\d+$/', (string) $temp_id) || $nom === '' || !isset($types_leurre_ok[$type])) continue;
        $stmtL->execute([$id_user, $type, mb_substr($nom, 0, 50), nombre_ou_null($l['poids'] ?? null, 0, 999), mb_substr(trim((string) ($l['couleur'] ?? '')), 0, 50) ?: null]);
        $map_new_leurres[$temp_id] = (int) $pdo->lastInsertId();
    }
    $map_new_appats = [];
    $stmtA = $pdo->prepare("INSERT INTO appat (ID_UTILISATEUR, ID_TYPE_APPAT, NOM_APPAT) VALUES (?, ?, ?)");
    foreach ((array) ($_POST['new_appats'] ?? []) as $temp_id => $a) {
        $nom = trim((string) ($a['nom'] ?? ''));
        $type = id_ou_null($a['type'] ?? null);
        if (!preg_match('/^new_a_\d+$/', (string) $temp_id) || $nom === '' || !isset($types_appat_ok[$type])) continue;
        $stmtA->execute([$id_user, $type, mb_substr($nom, 0, 50)]);
        $map_new_appats[$temp_id] = (int) $pdo->lastInsertId();
    }

    // 3. Prises + leurs photos
    $stmtPrise = $pdo->prepare("
        INSERT INTO prise (
            ID_SESSION, ID_ESPECE, ID_LEURRE, ID_APPAT, ID_TECHNIQUE,
            TAILLE_CM, POIDS_KG, RELACHE, LATITUDE, LONGITUDE, QUANTITE, DATE_HEURE
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmtPhoto = $pdo->prepare("INSERT INTO photo_prise (ID_PRISE, CHEMIN, ORDRE) VALUES (?, ?, ?)");
    $photos_utilisees = [];

    foreach ((array) ($_POST['prises'] ?? []) as $p) {
        $id_espece = id_ou_null($p['id_espece'] ?? null);
        if (!$id_espece || !isset($especes_ok[$id_espece])) continue;
        $id_technique = id_ou_null($p['id_technique'] ?? null);
        if ($id_technique && !isset($techniques_ok[$id_technique])) $id_technique = null;

        $id_leurre = $p['id_leurre'] ?? '';
        $id_leurre = isset($map_new_leurres[$id_leurre]) ? $map_new_leurres[$id_leurre] : id_ou_null($id_leurre);
        if ($id_leurre && !isset($leurres_autorises[$id_leurre]) && !in_array($id_leurre, $map_new_leurres, true)) $id_leurre = null;

        $id_appat = $p['id_appat'] ?? '';
        $id_appat = isset($map_new_appats[$id_appat]) ? $map_new_appats[$id_appat] : id_ou_null($id_appat);
        if ($id_appat && !isset($appats_autorises[$id_appat]) && !in_array($id_appat, $map_new_appats, true)) $id_appat = null;

        // Photos de la prise (plusieurs possibles), chacune utilisable une seule fois
        $photos_prise = [];
        foreach ((array) ($p['photos'] ?? []) as $chemin) {
            if (isset($photos_session[$chemin]) && !isset($photos_utilisees[$chemin]) && count($photos_prise) < PHOTOS_MAX_PAR_PRISE) {
                $photos_prise[] = $chemin;
                $photos_utilisees[$chemin] = true;
            }
        }

        // Position : GPS de la 1re photo de la prise, sinon position de la session
        $lat = $s2['lat_final'];
        $lng = $s2['lng_final'];
        if ($photos_prise && $photos_session[$photos_prise[0]]['lat'] !== null) {
            $lat = $photos_session[$photos_prise[0]]['lat'];
            $lng = $photos_session[$photos_prise[0]]['lng'];
        }

        $taille = nombre_ou_null($p['taille'] ?? null, 0, 1000);
        $quantite = id_ou_null($p['quantite'] ?? null) ?? 1;
        $stmtPrise->execute([
            $id_session,
            $id_espece,
            $id_leurre,
            $id_appat,
            $id_technique,
            $taille === null ? null : (int) round($taille),
            nombre_ou_null($p['poids'] ?? null, 0, 999.99),
            isset($p['relache']) ? 1 : 0,
            $lat,
            $lng,
            min($quantite, 999),
            $date_base . ' ' . (heure_ou_null($p['heure'] ?? null) ?? $heure_debut) . ':00',
        ]);
        $id_prise = (int) $pdo->lastInsertId();

        foreach ($photos_prise as $ordre => $chemin) {
            $stmtPhoto->execute([$id_prise, $chemin, $ordre]);
        }
    }

    $pdo->commit();
    $_SESSION['nouvelle_session']['terminee'] = true;
    header('Location: nouvelle_session.php?etape=4');
    exit();
}

// =========================================================================
// PRÉPARATION DES DONNÉES HTML
// =========================================================================
$session_data = $_SESSION['nouvelle_session'] ?? [];
if ($etape > 1 && $etape < 4 && empty($session_data)) {
    header('Location: nouvelle_session.php?etape=1');
    exit();
}
$erreurs_etape = $session_data['erreurs'] ?? [];
unset($_SESSION['nouvelle_session']['erreurs']);

if ($etape === 2) {
    $spots = $pdo->prepare("SELECT ID_SPOT, NOM_SPOT, ID_TYPE_SPOT, CATEGORIE_PISCICOLE FROM spot WHERE ID_UTILISATEUR = ? ORDER BY NOM_SPOT");
    $spots->execute([$id_user]);
    $spots = $spots->fetchAll(PDO::FETCH_ASSOC);
    $infos_spots = [];
    foreach ($spots as $sp) {
        $infos_spots[(int) $sp['ID_SPOT']] = ['type' => (int) $sp['ID_TYPE_SPOT'], 'categorie' => $sp['CATEGORIE_PISCICOLE'] !== null ? (int) $sp['CATEGORIE_PISCICOLE'] : null];
    }
    $reserves_carte = reserves_pour_carte($pdo);
    $types_spot = $pdo->query("SELECT ID_TYPE_SPOT, NOM_TYPE_SPOT FROM type_spot ORDER BY NOM_TYPE_SPOT")->fetchAll(PDO::FETCH_ASSOC);
    $types_session = $pdo->query("SELECT ID_TYPE_SESSION, NOM_TYPE_SESSION FROM type_session ORDER BY NOM_TYPE_SESSION")->fetchAll(PDO::FETCH_ASSOC);
}

if ($etape === 3) {
    $especes = $pdo->query("SELECT ID_ESPECE, NOM_COM, ICONE_CHEMIN FROM espece ORDER BY NOM_COM")->fetchAll(PDO::FETCH_ASSOC);
    $techniques = $pdo->query("SELECT ID_TECHNIQUE, NOM_TECHNIQUE FROM technique ORDER BY NOM_TECHNIQUE")->fetchAll(PDO::FETCH_ASSOC);
    $types_leurre = $pdo->query("SELECT * FROM type_leurre")->fetchAll(PDO::FETCH_NUM); 
    $types_appat = $pdo->query("SELECT * FROM type_appat")->fetchAll(PDO::FETCH_NUM); 

    $leurres = $pdo->prepare("SELECT ID_LEURRE, NOM_LEURRE, ID_TYPE, GRAMMAGE, COLORIS FROM leurre WHERE ID_UTILISATEUR = ? OR ID_UTILISATEUR IS NULL ORDER BY NOM_LEURRE");
    $leurres->execute([$id_user]);
    $leurres = $leurres->fetchAll(PDO::FETCH_ASSOC);

    $appats = $pdo->prepare("SELECT ID_APPAT, NOM_APPAT, ID_TYPE_APPAT FROM appat WHERE ID_UTILISATEUR = ? OR ID_UTILISATEUR IS NULL ORDER BY NOM_APPAT");
    $appats->execute([$id_user]);
    $appats = $appats->fetchAll(PDO::FETCH_ASSOC);

    $photos = array_map(fn($ph) => ['chemin' => $ph['chemin'], 'heure' => $ph['heure']], $session_data['photos'] ?? []);
    foreach ($especes as &$esp) $esp['ICONE_CHEMIN'] = icone_legere($esp['ICONE_CHEMIN']);
    unset($esp);

    // Garde-fou réglementaire : règles du lieu et du jour de la session, quotas déjà utilisés aujourd'hui
    $s2 = $session_data['step2'] ?? [];
    $date_session = substr($s2['date_debut'] ?? date('Y-m-d'), 0, 10);
    $ctx_regles = contexte_reglementaire($pdo, [
        'id_type_spot' => $s2['id_type_spot'] ?? null, 'categorie' => $s2['categorie_effective'] ?? null,
        'id_type_session' => $s2['id_type_session'] ?? null, 'mer' => ($s2['milieu'] ?? null) === 'mer',
        'lat' => $s2['lat_final'] ?? null, 'lng' => $s2['lng_final'] ?? null, 'date' => $date_session, 'departement' => $s2['departement'] ?? null,
    ]);
    $garde_fou = donnees_garde_fou($ctx_regles, regles_resolues($pdo, $ctx_regles), gardes_du_jour($pdo, $id_user, $date_session));
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nouvelle Session - Mon Carnet</title>
    <?php include './includes/head.php'; ?>
    <?php if ($etape === 2): ?><link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" /><?php endif; ?>

</head>
<body>

    <header class="custom-header text-white text-center py-4 shadow-sm mb-4 position-relative">
        <?php if ($etape < 4): ?>
            <a href="<?= ($etape > 1) ? 'nouvelle_session.php?etape='.($etape-1) : 'accueil.php' ?>" class="text-white position-absolute start-0 translate-middle-y ms-3 text-decoration-none" style="top: 50%;">
                <span class="material-symbols-rounded"><?= ($etape > 1) ? 'arrow_back_ios_new' : 'close' ?></span>
            </a>
            <h1 class="h4 mb-0 fw-semibold">Étape <?= $etape ?>/3</h1>
            <div class="progress mt-3 mx-auto" style="height: 6px; width: 60%; background-color: rgba(255,255,255,0.2);">
                <div class="progress-bar bg-info" style="width: <?= ($etape/3)*100 ?>%;"></div>
            </div>
        <?php else: ?>
            <h1 class="h4 mb-0 fw-semibold">Terminé !</h1>
        <?php endif; ?>
    </header>

    <main class="container pb-5 mb-5">
        
        <?php if ($etape === 1): ?>
        <div class="card border-0 shadow-sm rounded-4 p-4 mb-3 text-center">
            <h5 class="fw-bold mb-3 text-dark">Vos prises du jour</h5>
            <p class="text-muted small mb-4">Uploadez vos photos. L'heure de capture individuelle sera extraite.</p>
            <form action="nouvelle_session.php?etape=1" method="POST" enctype="multipart/form-data" id="form-photos">
                <?= csrf_field() ?>
                <div class="mb-4">
                    <label for="photos-proxy" class="btn btn-light p-4 rounded-4 w-100 border-2 border-primary border-dashed d-flex flex-column align-items-center" style="border-style: dashed;">
                        <span class="material-symbols-rounded text-primary mb-2" style="font-size: 40px;">add_a_photo</span>
                        <span class="fw-semibold text-primary">Ajouter des photos</span>
                        <input type="file" id="photos-proxy" multiple accept="image/*" class="d-none">
                    </label>
                    <input type="file" id="photos-real" name="photos[]" multiple accept="image/jpeg,image/png,image/webp" class="d-none">
                    <small class="text-muted d-block mt-2">JPEG, PNG ou WebP • 15 Mo max par photo • <?= e(ini_get('post_max_size')) ?> max par envoi</small>
                    <div id="preview-container" class="d-flex flex-wrap gap-2 mt-3 justify-content-center"></div>
                </div>
                <div class="d-grid mt-4">
                    <button type="submit" id="submit-step-1" class="btn btn-primary btn-lg rounded-pill fw-semibold shadow-sm custom-btn-submit">
                        Continuer sans photo <span class="material-symbols-rounded align-middle ms-2">arrow_forward</span>
                    </button>
                </div>
            </form>
        </div>

        <?php elseif ($etape === 2): ?>
        <form action="nouvelle_session.php?etape=2" method="POST">
            <?= csrf_field() ?>
            <h5 class="fw-bold mb-3 text-dark">Localisation & Conditions</h5>
            <?php if (!empty($session_data['photos_refusees'])): ?>
                <div class="alert alert-warning rounded-4 border-0 shadow-sm small">
                    <?= (int) $session_data['photos_refusees'] ?> fichier(s) ignoré(s) : format non supporté, image illisible ou trop lourde.
                </div>
            <?php endif; ?>
            <?php foreach ($erreurs_etape as $err): ?>
                <div class="alert alert-danger rounded-4 border-0 shadow-sm small"><?= e($err) ?></div>
            <?php endforeach; ?>
            <div id="map" class="shadow-sm rounded-4 mb-3" style="height: 220px; z-index: 1;"></div>
            <div id="alerte-reserve" class="alerte-terrain alerte-danger mb-3" role="alert" hidden></div>
            <div id="alerte-crue" class="alerte-terrain mb-3" role="alert" hidden></div>
            
            <input type="hidden" name="latitude" id="input_lat" value="<?= e($session_data['lat'] ?? '') ?>">
            <input type="hidden" name="longitude" id="input_lng" value="<?= e($session_data['lng'] ?? '') ?>">
            
            <input type="hidden" name="meteo_temp" id="input_temp">
            <input type="hidden" name="meteo_press" id="input_press">
            <input type="hidden" name="meteo_wind" id="input_wind">
            <input type="hidden" name="meteo_direc" id="input_direc">
            <input type="hidden" name="meteo_ciel" id="input_ciel">
            <input type="hidden" name="meteo_tend3h" id="input_tend3h">
            <input type="hidden" name="meteo_tend24h" id="input_tend24h">
            <input type="hidden" name="debit_m3s" id="input_debit">
            <input type="hidden" name="debit_ratio" id="input_debit_ratio">
            <input type="hidden" name="code_station" id="input_station">
            <input type="hidden" name="cours_eau" id="input_cours_eau">
            
            <div id="weather-card" class="card border-0 shadow-sm rounded-4 p-3 mb-4 bg-primary text-white" style="background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%); display: none;">
                <div class="d-flex flex-wrap gap-2 justify-content-center">
                    <span class="badge bg-white text-primary rounded-pill"><span class="material-symbols-rounded align-middle fs-6 me-1">partly_cloudy_day</span><span id="ui_ciel">--</span></span>
                    <span class="badge bg-white text-primary rounded-pill"><span class="material-symbols-rounded align-middle fs-6 me-1">thermostat</span><span id="ui_temp">--</span>°C</span>
                    <span class="badge bg-white text-primary rounded-pill"><span class="material-symbols-rounded align-middle fs-6 me-1">compress</span><span id="ui_press">--</span> hPa</span>
                    <span class="badge bg-white text-primary rounded-pill"><span class="material-symbols-rounded align-middle fs-6 me-1">air</span><span id="ui_wind">--</span> km/h <span id="ui_direc" class="ms-1 fw-normal text-muted"></span></span>
                    <span class="badge bg-white text-primary rounded-pill" id="ui_tendance_badge" hidden><span class="material-symbols-rounded align-middle fs-6 me-1" id="ui_tendance_icone">trending_flat</span><span id="ui_tendance">--</span></span>
                    <span class="badge bg-white text-primary rounded-pill" id="ui_debit_badge" hidden><span class="material-symbols-rounded align-middle fs-6 me-1">water</span><span id="ui_debit">--</span></span>
                </div>
            </div>

            <div class="card border-0 shadow-sm rounded-4 p-4 mb-5">
                <div class="row g-2 mb-4">
                    <div class="col-6">
                        <label class="form-label text-secondary small fw-medium">Début</label>
                        <input type="datetime-local" class="form-control bg-light border-0" name="date_debut" value="<?= e($session_data['date_heure'] ?? date('Y-m-d\TH:i')) ?>" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label text-secondary small fw-medium">Fin (Optionnel)</label>
                        <input type="datetime-local" class="form-control bg-light border-0" name="date_fin">
                    </div>
                </div>

                <div class="form-check form-switch mb-3 bg-light p-3 rounded-4 d-flex align-items-center">
                    <input class="form-check-input ms-0 me-3" type="checkbox" id="toggle-maree" name="peche_mer" value="1" style="transform: scale(1.3);" onchange="document.getElementById('maree-panel').style.display = this.checked ? 'flex' : 'none';">
                    <label class="form-check-label fw-bold text-primary mb-0" for="toggle-maree">🌊 Pêche en Mer (Activer la marée)</label>
                </div>

                <div id="maree-panel" class="row g-2 mb-4" style="display: none;">
                    <div class="col-7">
                        <label class="form-label text-secondary small fw-medium">État de la Marée</label>
                        <select class="form-select bg-light border-0" name="desc_maree">
                            <option value="N/A" selected>Non renseigné</option>
                            <option value="Montante">Marée Montante</option>
                            <option value="Haute">Pleine Mer (Haute)</option>
                            <option value="Descendante">Marée Descendante</option>
                            <option value="Basse">Basse Mer</option>
                        </select>
                    </div>
                    <div class="col-5">
                        <label class="form-label text-secondary small fw-medium">Coefficient</label>
                        <input type="number" class="form-control bg-light border-0" name="coeff_maree" placeholder="Ex: 85" min="20" max="120">
                    </div>
                </div>

                <div class="mb-4">
                    <label class="form-label text-secondary small fw-medium">Type de pêche</label>
                    <select class="form-select bg-light border-0" name="id_type_session" required>
                        <option value="" selected disabled>Choisir...</option>
                        <?php foreach($types_session as $ts): ?>
                            <option value="<?= (int) $ts['ID_TYPE_SESSION'] ?>"><?= e($ts['NOM_TYPE_SESSION']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-4">
                    <label class="form-label text-secondary small fw-medium">Spot / Lieu</label>
                    <select class="form-select bg-light border-0 mb-2" name="id_spot" id="spot-select" onchange="toggleNewSpot()">
                        <option value="" selected>Spot inconnu</option>
                        <?php foreach($spots as $spot): ?>
                            <option value="<?= (int) $spot['ID_SPOT'] ?>"><?= e($spot['NOM_SPOT']) ?></option>
                        <?php endforeach; ?>
                        <option value="new" class="fw-bold text-primary">+ Créer un nouveau spot</option>
                    </select>

                    <div id="new-spot-panel" class="bg-white border rounded-4 p-3 mt-2 shadow-sm" style="display: none;">
                        <input type="hidden" name="is_new_spot" id="is_new_spot" value="0">
                        <input type="text" class="form-control bg-light border-0 mb-2" name="nouveau_nom_spot" maxlength="50" placeholder="Nom du spot">
                        <select class="form-select bg-light border-0" name="nouveau_type_spot" id="nouveau-type-spot">
                            <option value="" selected disabled>Type de milieu...</option>
                            <?php foreach($types_spot as $ts): ?>
                                <option value="<?= (int) $ts['ID_TYPE_SPOT'] ?>"><?= e($ts['NOM_TYPE_SPOT']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div id="categorie-panel" class="mt-3" hidden>
                        <label class="form-label text-secondary small fw-medium" for="categorie-piscicole">Catégorie piscicole <span class="text-muted fw-normal">(pour les alertes de réglementation)</span></label>
                        <select class="form-select bg-light border-0" name="categorie_piscicole" id="categorie-piscicole">
                            <option value="">Je ne sais pas</option>
                            <option value="1">1re catégorie (eaux à truites)</option>
                            <option value="2">2e catégorie (autres eaux)</option>
                        </select>
                        <small class="text-muted d-block mt-1">Elle figure sur l'arrêté préfectoral ou le site de ta fédération de pêche.</small>
                    </div>
                </div>

                <div class="form-check form-switch mt-4 bg-light p-3 rounded-4 d-flex align-items-center">
                    <input class="form-check-input ms-0 me-3" type="checkbox" name="is_capot" id="is_capot" style="transform: scale(1.3);" onchange="toggleCapotBtn()">
                    <label class="form-check-label fw-bold text-dark mb-0" for="is_capot">Session Bredouille (Capot)</label>
                </div>

                <div class="d-grid mt-4 action-collante">
                    <button type="submit" id="btn-submit-step2" class="btn btn-primary btn-lg rounded-pill fw-semibold shadow-sm custom-btn-submit">
                        Continuer <span class="material-symbols-rounded align-middle ms-2">arrow_forward</span>
                    </button>
                </div>
            </div>
        </form>

        <?php elseif ($etape === 3): ?>
        <h5 class="fw-bold mb-3 text-dark text-center">Détail des prises</h5>
        <?php foreach ($erreurs_etape as $err): ?>
            <div class="alert alert-danger rounded-4 border-0 shadow-sm small"><?= e($err) ?></div>
        <?php endforeach; ?>
        <div class="contexte-regles mb-3">
            <span class="material-symbols-rounded" aria-hidden="true">gavel</span>
            <div>
                <strong>Réglementation vérifiée pour :</strong> <?= e($ctx_regles['libelle']) ?>, le <?= e(date('d/m/Y', strtotime($date_session))) ?>
                <?php if (empty($ctx_regles['categorie']) && in_array('eau_douce', $ctx_regles['milieux'], true) && ($ctx_regles['milieu'] ?? null) !== 'mer'): ?>
                    <small class="d-block">Indique la catégorie de ton spot (étape précédente ou Mes spots) pour des alertes précises.</small>
                <?php endif; ?>
            </div>
        </div>
        
        <form id="form-etape-3" action="nouvelle_session.php?etape=3" method="POST">
            <?= csrf_field() ?>
            
            <div id="catches-container"></div>
            
            <button type="button" class="btn btn-outline-primary btn-lg w-100 rounded-4 border-2 border-dashed fw-bold mb-4 py-3 shadow-sm" onclick="addCatchCard('no_photo')">
                <span class="material-symbols-rounded align-middle me-2">add_circle</span> Ajouter une autre prise sans photo
            </button>

            <div id="new-items-container"></div>

            <div class="d-grid mt-4 action-collante">
                <button type="submit" id="submit-final" class="btn btn-success btn-lg rounded-pill fw-semibold shadow-lg py-3">
                    Terminer et Sauvegarder <span class="material-symbols-rounded align-middle ms-2">check_circle</span>
                </button>
            </div>
        </form>

        <?php include './includes/modal_boite_peche.php'; ?>

        <?php elseif ($etape === 4): ?>
        <div class="card border-0 shadow-sm rounded-4 p-5 text-center mt-5">
            <span class="material-symbols-rounded text-success mb-3" style="font-size: 80px;">task_alt</span>
            <h2 class="h4 fw-bold text-dark">Session enregistrée !</h2>
            <p class="text-muted">Votre session et vos prises ont été ajoutées avec succès.</p>
            <a href="accueil.php" class="btn btn-primary btn-lg rounded-pill mt-4 shadow-sm w-100 fw-semibold">Retour à l'accueil</a>
        </div>
        <?php unset($_SESSION['nouvelle_session']); ?>
        <?php endif; ?>

    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <?php if ($etape === 2): ?><script src="script/meteo_map.js"></script><?php endif; ?>
    <?php include 'script/JS_session.php'; ?>
</body>
</html>