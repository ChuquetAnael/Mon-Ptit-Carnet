<?php
/**
 * Génère les miniatures WebP des images lourdes (icônes d'espèces et photos de types de session).
 * Une icône d'espèce passe d'environ 2 Mo (PNG 2752 px) à 15-30 Ko : le Codex et la liste
 * des espèces de la saisie se chargent enfin vite en 4G au bord de l'eau.
 *
 * Usage (ligne de commande uniquement) : php BDD/miniatures.php
 * À relancer après l'ajout d'une image dans asset/espece_png ou asset/type_session.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit();
}
$racine = dirname(__DIR__);
$lots = [
    'espece_png' => 480,   // affichées au plus à 120 px de haut (x2 pour les écrans Retina)
    'type_session' => 800, // vignettes de sessions sur le profil
];
$total = 0;
foreach ($lots as $dossier => $largeur_max) {
    $sortie = "$racine/asset/mini/$dossier";
    if (!is_dir($sortie)) mkdir($sortie, 0755, true);
    foreach (glob("$racine/asset/$dossier/*.{png,PNG,jpg,JPG,jpeg}", GLOB_BRACE) as $fichier) {
        $cible = $sortie . '/' . pathinfo($fichier, PATHINFO_FILENAME) . '.webp';
        if (is_file($cible) && filemtime($cible) >= filemtime($fichier)) continue;
        $info = getimagesize($fichier);
        $image = $info['mime'] === 'image/png' ? imagecreatefrompng($fichier) : imagecreatefromjpeg($fichier);
        if (!$image) { echo "Illisible : $fichier\n"; continue; }
        $l = imagesx($image); $h = imagesy($image);
        $nl = min($l, $largeur_max); $nh = (int) round($h * $nl / $l);
        $mini = imagecreatetruecolor($nl, $nh);
        imagealphablending($mini, false);
        imagesavealpha($mini, true);
        imagefill($mini, 0, 0, imagecolorallocatealpha($mini, 0, 0, 0, 127));
        imagecopyresampled($mini, $image, 0, 0, 0, 0, $nl, $nh, $l, $h);
        imagewebp($mini, $cible, 80);
        imagedestroy($image); imagedestroy($mini);
        $total++;
        printf("%-60s %6.1f Ko\n", str_replace($racine . '/', '', $cible), filesize($cible) / 1024);
    }
}
echo "$total miniature(s) générée(s).\n";
