<?php
/**
 * Gestion des images envoyées par les utilisateurs.
 *
 * Principe de sécurité : un fichier envoyé n'est JAMAIS copié tel quel dans uploads/.
 * Il est vérifié (taille, type réel, dimensions) puis entièrement ré-encodé en JPEG par GD :
 * tout contenu caché (script, polyglotte) et toutes les métadonnées EXIF (dont le GPS) disparaissent.
 */

const UPLOAD_MAX_OCTETS = 15 * 1024 * 1024; // 15 Mo par photo
const UPLOAD_MAX_PIXELS = 50000000;          // ~50 Mpx : au-delà, GD risque de saturer la mémoire
const UPLOAD_TYPES_AUTORISES = ['image/jpeg', 'image/png', 'image/webp'];

// --- FONCTIONS GPS (lecture des EXIF) ---
function gps2Num($coordPart)
{
    $parts = explode('/', (string) $coordPart);
    if (count($parts) <= 0) return 0;
    if (count($parts) == 1) return floatval($parts[0]);
    if (floatval($parts[1]) == 0) return 0; // Sécurité anti-crash
    return floatval($parts[0]) / floatval($parts[1]);
}

function getGps($exifCoord, $hemi)
{
    if (!is_array($exifCoord)) return null;
    $degrees = count($exifCoord) > 0 ? gps2Num($exifCoord[0]) : 0;
    $minutes = count($exifCoord) > 1 ? gps2Num($exifCoord[1]) : 0;
    $seconds = count($exifCoord) > 2 ? gps2Num($exifCoord[2]) : 0;
    $flip = ($hemi == 'W' or $hemi == 'S') ? -1 : 1;
    $result = round($flip * ($degrees + $minutes / 60 + $seconds / 3600), 6);
    return ($result == 0) ? null : $result;
}

/**
 * Ré-encode une image en JPEG (redimensionnée, orientation EXIF corrigée, fond blanc pour la transparence).
 */
function compresserImage($source, $destination, $qualite = 80, $maxWidth = 1200)
{
    $info = @getimagesize($source);
    if (!$info || ($info[0] * $info[1]) > UPLOAD_MAX_PIXELS) return false;

    switch ($info['mime']) {
        case 'image/jpeg': $image = @imagecreatefromjpeg($source); break;
        case 'image/png':  $image = @imagecreatefrompng($source); break;
        case 'image/webp': $image = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($source) : false; break;
        default: return false;
    }
    if (!$image) return false;

    // Correction de la rotation (EXIF présent uniquement sur les JPEG)
    if ($info['mime'] === 'image/jpeg' && function_exists('exif_read_data')) {
        $exif = @exif_read_data($source);
        if (!empty($exif['Orientation'])) {
            switch ($exif['Orientation']) {
                case 3: $image = imagerotate($image, 180, 0); break;
                case 6: $image = imagerotate($image, -90, 0); break;
                case 8: $image = imagerotate($image, 90, 0); break;
            }
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

    $newImage = imagecreatetruecolor((int) $newWidth, (int) $newHeight);
    imagefill($newImage, 0, 0, imagecolorallocate($newImage, 255, 255, 255)); // PNG transparent -> fond blanc
    imagecopyresampled($newImage, $image, 0, 0, 0, 0, (int) $newWidth, (int) $newHeight, $width, $height);
    $result = imagejpeg($newImage, $destination, $qualite);

    imagedestroy($image);
    imagedestroy($newImage);
    return $result;
}

/** Dossier d'upload propre à un utilisateur (créé si besoin, sans droits d'écriture pour tous). */
function dossier_utilisateur(int $id_user): string
{
    $dossier = 'uploads/user_' . $id_user . '/';
    if (!is_dir($dossier)) mkdir($dossier, 0755, true);
    return $dossier;
}

/**
 * Valide et enregistre un fichier issu de $_FILES.
 * @return array|null ['chemin' => string, 'exif' => array|false] ou null si le fichier est refusé
 */
function enregistrer_image_uploadee(array $fichier, int $id_user, string $prefixe = '', int $maxWidth = 1200): ?array
{
    if (($fichier['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return null;
    $tmp = $fichier['tmp_name'] ?? '';
    if (!is_uploaded_file($tmp) || filesize($tmp) > UPLOAD_MAX_OCTETS) return null;

    // Type réel du contenu (on ignore le nom et le type annoncés par le navigateur)
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    if (!in_array($mime, UPLOAD_TYPES_AUTORISES, true)) return null;

    // Les EXIF (heure, GPS) sont lus AVANT le ré-encodage qui les supprime
    $exif = ($mime === 'image/jpeg' && function_exists('exif_read_data')) ? @exif_read_data($tmp) : false;

    $chemin = dossier_utilisateur($id_user) . bin2hex(random_bytes(8)) . ($prefixe ? '_' . $prefixe : '') . '.jpg';
    if (!compresserImage($tmp, $chemin, 80, $maxWidth)) {
        if (is_file($chemin)) unlink($chemin);
        return null;
    }
    return ['chemin' => $chemin, 'exif' => $exif];
}

/** Normalise un tableau $_FILES['champ'] multiple en liste de fichiers. */
function fichiers_multiples(array $champ): array
{
    $liste = [];
    if (!isset($champ['name']) || !is_array($champ['name'])) return $liste;
    foreach ($champ['name'] as $i => $nom) {
        $liste[] = [
            'name' => $nom,
            'tmp_name' => $champ['tmp_name'][$i] ?? '',
            'error' => $champ['error'][$i] ?? UPLOAD_ERR_NO_FILE,
            'size' => $champ['size'][$i] ?? 0,
        ];
    }
    return $liste;
}

/**
 * Valide et enregistre une image transmise en Data URL base64 (Cropper.js).
 */
function enregistrer_image_base64(string $dataUrl, int $id_user, string $prefixe, int $maxWidth): ?string
{
    if (!preg_match('#^data:image/(jpeg|png|webp);base64,#', $dataUrl)) return null;
    $binaire = base64_decode(substr($dataUrl, strpos($dataUrl, ',') + 1), true);
    if ($binaire === false || strlen($binaire) > UPLOAD_MAX_OCTETS) return null;

    $tmp = tempnam(sys_get_temp_dir(), 'mpc');
    file_put_contents($tmp, $binaire);
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);

    $chemin = dossier_utilisateur($id_user) . bin2hex(random_bytes(8)) . '_' . $prefixe . '.jpg';
    $ok = in_array($mime, UPLOAD_TYPES_AUTORISES, true) && compresserImage($tmp, $chemin, 82, $maxWidth);
    unlink($tmp);
    if (!$ok) {
        if (is_file($chemin)) unlink($chemin);
        return null;
    }
    return $chemin;
}

/**
 * Supprime un fichier UNIQUEMENT s'il se trouve dans le dossier uploads de l'utilisateur.
 * Empêche toute suppression arbitraire de fichiers via un chemin falsifié en base.
 */
function supprimer_fichier_utilisateur(?string $chemin, int $id_user): bool
{
    if (!$chemin) return false;
    $dossier = realpath('uploads/user_' . $id_user);
    $fichier = realpath($chemin);
    if ($dossier === false || $fichier === false) return false;
    if (strpos($fichier, $dossier . DIRECTORY_SEPARATOR) !== 0 || !is_file($fichier)) return false;
    return unlink($fichier);
}

/** Supprime le dossier uploads complet d'un utilisateur (suppression de compte / RGPD). */
function supprimer_dossier_utilisateur(int $id_user): void
{
    $dossier = 'uploads/user_' . $id_user;
    if (!is_dir($dossier)) return;
    foreach (glob($dossier . '/*') ?: [] as $fichier) {
        if (is_file($fichier)) unlink($fichier);
    }
    @rmdir($dossier);
}
