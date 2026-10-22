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
 * Lecture de secours des EXIF (date + GPS), en PHP pur : utilisée quand l'extension exif est absente,
 * quand exif_read_data() échoue (MakerNote de certains téléphones) ou pour les PNG / WebP.
 * Même logique que script/exif.js.
 * @return array ['date' => 'Y-m-d H:i:s'|null, 'lat' => ?float, 'lng' => ?float]
 */
function exif_secours(string $fichier): array
{
    $vide = ['date' => null, 'lat' => null, 'lng' => null];
    $b = @file_get_contents($fichier, false, null, 0, 2 * 1024 * 1024);
    if (!is_string($b) || strlen($b) < 16) return $vide;
    $n = strlen($b);
    $debut = -1;

    if (substr($b, 0, 2) === "\xFF\xD8") {                       // JPEG : marqueurs jusqu'à APP1 « Exif »
        $o = 2;
        while ($o + 10 < $n && $b[$o] === "\xFF") {
            $m = ord($b[$o + 1]);
            $l = unpack('n', substr($b, $o + 2, 2))[1];
            if ($m === 0xE1 && substr($b, $o + 4, 6) === "Exif\0\0") { $debut = $o + 10; break; }
            if ($m === 0xDA || $l < 2) break;
            $o += 2 + $l;
        }
    } elseif (substr($b, 0, 8) === "\x89PNG\r\n\x1a\n") {        // PNG : bloc eXIf
        $o = 8;
        while ($o + 8 < $n) {
            $l = unpack('N', substr($b, $o, 4))[1];
            $type = substr($b, $o + 4, 4);
            if ($type === 'eXIf') { $debut = $o + 8; break; }
            if ($type === 'IEND') break;
            $o += 12 + $l;
        }
    } elseif (substr($b, 0, 4) === 'RIFF' && substr($b, 8, 4) === 'WEBP') { // WebP : bloc EXIF
        $o = 12;
        while ($o + 8 < $n) {
            $l = unpack('V', substr($b, $o + 4, 4))[1];
            if (substr($b, $o, 4) === 'EXIF') {
                $debut = substr($b, $o + 8, 6) === "Exif\0\0" ? $o + 14 : $o + 8;
                break;
            }
            $o += 8 + $l + ($l % 2);
        }
    }
    if ($debut < 0 || $debut + 8 > $n) return $vide;

    $t = substr($b, $debut);
    $tn = strlen($t);
    $ordre = substr($t, 0, 2);
    if ($ordre !== 'II' && $ordre !== 'MM') return $vide;
    $le = $ordre === 'II';
    $u16 = fn(int $o) => $o + 2 <= $tn ? unpack($le ? 'v' : 'n', substr($t, $o, 2))[1] : 0;
    $u32 = fn(int $o) => $o + 4 <= $tn ? unpack($le ? 'V' : 'N', substr($t, $o, 4))[1] : 0;
    if ($u16(2) !== 42) return $vide;

    $ifd = function (int $o) use ($u16, $u32, $tn): array {
        $tags = [];
        if ($o <= 0 || $o + 2 > $tn) return $tags;
        $nb = min($u16($o), 500);
        for ($i = 0; $i < $nb; $i++) {
            $e = $o + 2 + $i * 12;
            if ($e + 12 > $tn) break;
            $tags[$u16($e)] = ['type' => $u16($e + 2), 'nombre' => $u32($e + 4), 'valeur' => $e + 8];
        }
        return $tags;
    };
    $texte = function (?array $tag) use ($t, $u32): ?string {
        if (!$tag || $tag['type'] !== 2) return null;
        $o = $tag['nombre'] > 4 ? $u32($tag['valeur']) : $tag['valeur'];
        return rtrim((string) substr($t, $o, max(0, $tag['nombre'] - 1)), "\0");
    };
    $rationnels = function (?array $tag) use ($u32): ?array {
        if (!$tag || !in_array($tag['type'], [5, 10], true) || $tag['nombre'] < 3) return null;
        $o = $u32($tag['valeur']);
        $r = [];
        for ($i = 0; $i < 3; $i++) {
            $num = $u32($o + $i * 8);
            $den = $u32($o + $i * 8 + 4);
            $r[] = $den ? $num / $den : 0;
        }
        return $r;
    };

    $ifd0 = $ifd($u32(4));
    $exif = isset($ifd0[0x8769]) ? $ifd($u32($ifd0[0x8769]['valeur'])) : [];
    $gps = isset($ifd0[0x8825]) ? $ifd($u32($ifd0[0x8825]['valeur'])) : [];

    $date = null;
    $brut = $texte($exif[0x9003] ?? null) ?? $texte($exif[0x9004] ?? null) ?? $texte($ifd0[0x0132] ?? null);
    if ($brut && ($d = DateTime::createFromFormat('Y:m:d H:i:s', substr($brut, 0, 19))) && (int) $d->format('Y') > 1990) {
        $date = $d->format('Y-m-d H:i:s');
    }

    $lat = $lng = null;
    $la = $rationnels($gps[2] ?? null);
    $lo = $rationnels($gps[4] ?? null);
    if ($la && $lo) {
        $ref_lat = isset($gps[1]) ? ($texte($gps[1]) ?: $t[$gps[1]['valeur']] ?? 'N') : 'N';
        $ref_lng = isset($gps[3]) ? ($texte($gps[3]) ?: $t[$gps[3]['valeur']] ?? 'E') : 'E';
        $lat = ($la[0] + $la[1] / 60 + $la[2] / 3600) * (stripos($ref_lat, 'S') !== false ? -1 : 1);
        $lng = ($lo[0] + $lo[1] / 60 + $lo[2] / 3600) * (stripos($ref_lng, 'W') !== false ? -1 : 1);
        if (abs($lat) > 90 || abs($lng) > 180 || (abs($lat) < 1e-6 && abs($lng) < 1e-6)) {
            $lat = $lng = null;
        } else {
            $lat = round($lat, 6);
            $lng = round($lng, 6);
        }
    }
    return ['date' => $date, 'lat' => $lat, 'lng' => $lng];
}

/**
 * Date et position d'une photo envoyée, de la source la plus fiable à la moins fiable :
 * 1. EXIF lus par PHP sur le fichier original ;  2. lecture de secours en PHP pur ;
 * 3. EXIF lus par le navigateur (script/exif.js) ;
 * 4. position actuelle du téléphone, seulement pour une photo prise il y a moins de 3 h.
 * @param array|false $exif   résultat de exif_read_data() (ou false)
 * @param array|null  $client ['date' => 'Y-m-dTH:i', 'lat', 'lng', 'appareil' => ['lat', 'lng']] envoyé par le navigateur
 * @return array ['date' => 'Y-m-d H:i:s'|null, 'lat' => ?float, 'lng' => ?float, 'source_gps' => exif|navigateur|appareil|null]
 */
function meta_photo(?string $tmp, $exif, ?array $client = null): array
{
    $meta = ['date' => null, 'lat' => null, 'lng' => null, 'source_gps' => null];
    if (is_array($exif)) {
        if (!empty($exif['DateTimeOriginal']) && ($d = DateTime::createFromFormat('Y:m:d H:i:s', substr((string) $exif['DateTimeOriginal'], 0, 19)))) {
            $meta['date'] = $d->format('Y-m-d H:i:s');
        }
        if (isset($exif['GPSLatitude'], $exif['GPSLongitude'])) {
            $lat = getGps($exif['GPSLatitude'], $exif['GPSLatitudeRef'] ?? 'N');
            $lng = getGps($exif['GPSLongitude'], $exif['GPSLongitudeRef'] ?? 'E');
            if ($lat !== null && $lng !== null && abs($lat) <= 90 && abs($lng) <= 180) {
                $meta = array_merge($meta, ['lat' => $lat, 'lng' => $lng, 'source_gps' => 'exif']);
            }
        }
    }
    if ($tmp && ($meta['date'] === null || $meta['lat'] === null)) {
        $secours = exif_secours($tmp);
        $meta['date'] = $meta['date'] ?? $secours['date'];
        if ($meta['lat'] === null && $secours['lat'] !== null) {
            $meta = array_merge($meta, ['lat' => $secours['lat'], 'lng' => $secours['lng'], 'source_gps' => 'exif']);
        }
    }
    if ($client) {
        if ($meta['date'] === null && is_string($client['date'] ?? null) && ($d = DateTime::createFromFormat('Y-m-d\TH:i', substr($client['date'], 0, 16)))) {
            $meta['date'] = $d->format('Y-m-d H:i:00');
        }
        $lat = nombre_ou_null($client['lat'] ?? null, -90, 90);
        $lng = nombre_ou_null($client['lng'] ?? null, -180, 180);
        if ($meta['lat'] === null && $lat !== null && $lng !== null && !($lat == 0 && $lng == 0)) {
            $meta = array_merge($meta, ['lat' => round($lat, 6), 'lng' => round($lng, 6), 'source_gps' => 'navigateur']);
        }
        $app = is_array($client['appareil'] ?? null) ? $client['appareil'] : [];
        $lat = nombre_ou_null($app['lat'] ?? null, -90, 90);
        $lng = nombre_ou_null($app['lng'] ?? null, -180, 180);
        $recente = $meta['date'] === null || abs(time() - strtotime($meta['date'])) < 3 * 3600;
        if ($meta['lat'] === null && $lat !== null && $lng !== null && $recente && !($lat == 0 && $lng == 0)) {
            $meta = array_merge($meta, ['lat' => round($lat, 6), 'lng' => round($lng, 6), 'source_gps' => 'appareil']);
        }
    }
    return $meta;
}

/** Métadonnées envoyées par le navigateur pour le fichier n° $i (champ caché JSON « photos_meta »). */
function meta_client(?string $json, int $i): ?array
{
    static $cache = [];
    if ($json === null || $json === '') return null;
    $cle = md5($json);
    if (!array_key_exists($cle, $cache)) {
        $d = json_decode(mb_substr($json, 0, 20000), true);
        $cache[$cle] = is_array($d) ? array_values($d) : [];
    }
    return is_array($cache[$cle][$i] ?? null) ? $cache[$cle][$i] : null;
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
