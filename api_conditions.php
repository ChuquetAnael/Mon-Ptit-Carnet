<?php
// Données JSON de la page « Conditions gagnantes ».
//   ?mode=perso&spot=ID[&espece=ID]          (ou &lat=..&lng=.. pour la position actuelle, jamais enregistrée)
//   ?mode=communaute&zone=facade:...&espece=ID
//   ?mode=zones&espece=ID                     zones assez fournies pour être affichées
require_once './includes/bootstrap.php';
require_once './includes/conditions.php';

$id_user = utilisateur_id();
if (!$id_user) json_out(['erreur' => 'Session expirée, reconnecte-toi.'], 401);
session_write_close();

$pdo = db();
$mode = $_GET['mode'] ?? '';
$id_espece = id_ou_null($_GET['espece'] ?? null);

try {
    if ($mode === 'perso') {
        $lieu = null;
        if ($id_spot = id_ou_null($_GET['spot'] ?? null)) {
            $stmt = $pdo->prepare("SELECT ID_SPOT, NOM_SPOT, LOCALISATION, ID_TYPE_SPOT FROM spot WHERE ID_SPOT = ? AND ID_UTILISATEUR = ?");
            $stmt->execute([$id_spot, $id_user]);
            $spot = $stmt->fetch(PDO::FETCH_ASSOC);
            $coords = $spot ? lire_localisation($spot['LOCALISATION']) : null;
            if (!$coords) json_out(['erreur' => "Ce spot n'a pas de position GPS : place-le sur la carte dans Mes spots."], 400);
            $lieu = ['lat' => $coords[0], 'lng' => $coords[1], 'id_spot' => (int) $spot['ID_SPOT'], 'nom' => $spot['NOM_SPOT'], 'milieu' => milieu_type_spot((int) $spot['ID_TYPE_SPOT'])];
        } else {
            $lat = nombre_ou_null($_GET['lat'] ?? null, -90, 90);
            $lng = nombre_ou_null($_GET['lng'] ?? null, -180, 180);
            if ($lat === null || $lng === null) json_out(['erreur' => 'Choisis un spot ou active la localisation.'], 400);
            $milieu = in_array($_GET['milieu'] ?? '', ['mer', 'eau_douce'], true) ? $_GET['milieu'] : null;
            $lieu = ['lat' => $lat, 'lng' => $lng, 'id_spot' => null, 'nom' => 'Ma position', 'milieu' => $milieu];
        }
        json_out(analyse_personnelle($pdo, $id_user, $lieu, $id_espece));
    }

    if ($mode === 'communaute') {
        json_out(statistiques_communaute($pdo, $id_espece, (string) ($_GET['zone'] ?? 'tout')));
    }

    if ($mode === 'zones') {
        json_out(['zones' => zones_communaute($pdo, $id_espece)]);
    }

    json_out(['erreur' => 'Mode inconnu.'], 400);
} catch (Throwable $e) {
    error_log('[MonPtitCarnet] api_conditions : ' . $e->getMessage());
    json_out(['erreur' => "L'analyse est indisponible pour le moment."], 500);
}
