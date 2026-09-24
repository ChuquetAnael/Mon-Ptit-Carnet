<?php
// --- FONCTIONS GPS ULTRA-SÉCURISÉES ---
function gps2Num($coordPart) {
    $parts = explode('/', $coordPart);
    if (count($parts) <= 0) return 0;
    if (count($parts) == 1) return floatval($parts[0]);
    if (floatval($parts[1]) == 0) return 0; // Sécurité anti-crash
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

function compresserImage($source, $destination, $qualite = 80, $maxWidth = 1200) {
    $info = getimagesize($source);
    if (!$info) return false;

    switch ($info['mime']) {
        case 'image/jpeg': $image = @imagecreatefromjpeg($source); break;
        case 'image/png':  $image = @imagecreatefrompng($source); break;
        case 'image/webp': $image = @imagecreatefromwebp($source); break;
        default: return false; 
    }
    if (!$image) return false;

    // CORRECTION DE LA ROTATION EXIF
    $exif = @exif_read_data($source);
    if (!empty($exif['Orientation'])) {
        switch ($exif['Orientation']) {
            case 3: $image = imagerotate($image, 180, 0); break;
            case 6: $image = imagerotate($image, -90, 0); break;
            case 8: $image = imagerotate($image, 90, 0); break;
        }
    }

    $width = imagesx($image);
    $height = imagesy($image);

    if ($width > $maxWidth) {
        $newWidth = $maxWidth;
        $newHeight = ($height / $width) * $newWidth;
    } else {
        $newWidth = $width;
        $newHeight = $height;
    }

    $newImage = imagecreatetruecolor((int)$newWidth, (int)$newHeight);
    imagecopyresampled($newImage, $image, 0, 0, 0, 0, (int)$newWidth, (int)$newHeight, $width, $height);
    $result = imagejpeg($newImage, $destination, $qualite);

    imagedestroy($image);
    imagedestroy($newImage);

    return $result;
}
?>