<?php
/**
 * Envoi d'une photo supplémentaire pendant la saisie d'une session (étape 3, une photo par appel).
 * La photo est vérifiée, ré-encodée (EXIF et GPS retirés du fichier) et rattachée à la saisie en cours :
 * seule une photo envoyée ici ou à l'étape 1 peut ensuite être liée à une prise.
 * Réponse JSON : { chemin, heure, gps } ou { error }.
 */
require_once './includes/bootstrap.php';

const PHOTOS_MAX_PAR_SAISIE = 60;

$id_user = utilisateur_id();
if (!$id_user) json_out(['error' => 'Session expirée, reconnecte-toi.'], 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'Méthode non autorisée.'], 405); // le jeton CSRF est vérifié par bootstrap.php

$saisie = $_SESSION['nouvelle_session'] ?? null;
if (!$saisie || empty($saisie['step2']) || !empty($saisie['terminee'])) {
    json_out(['error' => "Aucune session en cours de saisie : recommence depuis l'étape 1."], 409);
}
if (count($saisie['photos'] ?? []) >= PHOTOS_MAX_PAR_SAISIE) {
    json_out(['error' => 'Nombre maximum de photos atteint pour cette session.'], 413);
}

$fichier = $_FILES['photo'] ?? null;
if (!$fichier || is_array($fichier['name'] ?? null)) json_out(['error' => 'Aucune photo reçue.'], 400);
if (($fichier['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_INI_SIZE || ($fichier['error'] ?? 0) === UPLOAD_ERR_FORM_SIZE) {
    json_out(['error' => 'Photo trop lourde (15 Mo maximum).'], 413);
}

$resultat = enregistrer_image_uploadee($fichier, $id_user);
if (!$resultat) json_out(['error' => 'Photo refusée : format non supporté (JPEG, PNG ou WebP), image illisible ou trop lourde.'], 415);

$client = json_decode((string) ($_POST['meta'] ?? ''), true);
$meta = meta_photo($fichier['tmp_name'], $resultat['exif'], is_array($client) ? $client : null);

$photo = [
    'chemin' => $resultat['chemin'],
    'heure' => $meta['date'] ? date('H:i', strtotime($meta['date'])) : null,
    'date' => $meta['date'],
    'lat' => $meta['lat'],
    'lng' => $meta['lng'],
    'source_gps' => $meta['source_gps'],
];
$_SESSION['nouvelle_session']['photos'][] = $photo;

json_out(['chemin' => $photo['chemin'], 'heure' => $photo['heure'], 'gps' => $photo['lat'] !== null]);
