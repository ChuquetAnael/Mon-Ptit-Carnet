<?php
// Sert le fichier de la carte de pêche à son seul propriétaire (jamais d'accès direct au dossier).
//   ?type=apercu   image affichée (et gardée hors-ligne)
//   ?type=original fichier envoyé (PDF ou image)
require_once './includes/bootstrap.php';
require_once './includes/carte_peche.php';

$id_user = utilisateur_id();
if (!$id_user) {
    http_response_code(401);
    exit();
}
session_write_close();

$carte = carte_de(db(), $id_user);
$type = ($_GET['type'] ?? 'apercu') === 'original' ? 'original' : 'apercu';
$nom = $carte ? ($type === 'original' ? $carte['FICHIER'] : ($carte['APERCU'] ?: ($carte['TYPE_FICHIER'] === 'image' ? $carte['FICHIER'] : null))) : null;
$chemin = $carte ? chemin_fichier_prive($id_user, $nom) : null;
if (!$chemin) {
    http_response_code(404);
    exit();
}

$pdf = str_ends_with($chemin, '.pdf');
header('Content-Type: ' . ($pdf ? 'application/pdf' : 'image/jpeg'));
header('Content-Length: ' . filesize($chemin));
header('Content-Disposition: inline; filename="carte-de-peche.' . ($pdf ? 'pdf' : 'jpg') . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=31536000'); // l'URL change à chaque nouvelle carte (?v=…)
header('Referrer-Policy: no-referrer');
readfile($chemin);
