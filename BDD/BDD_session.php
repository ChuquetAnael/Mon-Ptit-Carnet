<?php
// --- FONCTIONS GPS ULTRA-SÉCURISÉES ---
function gps2Num($coordPart) {
    $parts = explode('/', $coordPart);
    if (count($parts) <= 0) return 0;
    if (count($parts) == 1) return floatval($parts[0]);
    if (floatval($parts[1]) == 0) return 0; // Sécurité anti-crash (Division by Zero)
    return floatval($parts[0]) / floatval($parts[1]);
}

function getGps($exifCoord, $hemi) {
    if (!is_array($exifCoord)) return null;
    $degrees = count($exifCoord) > 0 ? gps2Num($exifCoord[0]) : 0;
    $minutes = count($exifCoord) > 1 ? gps2Num($exifCoord[1]) : 0;
    $seconds = count($exifCoord) > 2 ? gps2Num($exifCoord[2]) : 0;
    $flip = ($hemi == 'W' or $hemi == 'S') ? -1 : 1;
    $result = round($flip * ($degrees + $minutes / 60 + $seconds / 3600), 6);
    return ($result == 0) ? null : $result;
}

// --- NOUVELLE FONCTION DE COMPRESSION ---
function compresserImage($source, $destination, $qualite = 80, $maxWidth = 1200) {
    $info = getimagesize($source);
    if (!$info) return false;

    $width = $info[0];
    $height = $info[1];

    // Calcul du nouveau ratio pour éviter de déformer l'image
    if ($width > $maxWidth) {
        $newWidth = $maxWidth;
        $newHeight = ($height / $width) * $newWidth;
    } else {
        $newWidth = $width;
        $newHeight = $height;
    }

    // Création de l'image source selon le format
    switch ($info['mime']) {
        case 'image/jpeg': $image = imagecreatefromjpeg($source); break;
        case 'image/png':  $image = imagecreatefrompng($source); break;
        case 'image/webp': $image = imagecreatefromwebp($source); break;
        default: return false; // Format non supporté (on fera un upload classique)
    }

    if (!$image) return false;

    // Création de la nouvelle image redimensionnée
    $newImage = imagecreatetruecolor((int)$newWidth, (int)$newHeight);

    // Redimensionnement
    imagecopyresampled($newImage, $image, 0, 0, 0, 0, (int)$newWidth, (int)$newHeight, $width, $height);

    // Sauvegarde en JPEG avec la qualité souhaitée
    $result = imagejpeg($newImage, $destination, $qualite);

    // Libération de la mémoire
    imagedestroy($image);
    imagedestroy($newImage);

    return $result;
}
?>