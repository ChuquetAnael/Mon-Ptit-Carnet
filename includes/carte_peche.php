<?php
/**
 * Porte-carte de pêche : stockage confiné de la carte (donnée personnelle).
 *
 * Les fichiers sont rangés dans stockage_prive/user_X/, dossier interdit d'accès direct
 * (.htaccess « Require all denied ») et ignoré par Git. Ils ne sont servis que par
 * carte_fichier.php, au seul propriétaire connecté.
 * - Image : ré-encodée en JPEG par GD (aucun contenu caché, plus de métadonnées EXIF).
 * - PDF : vérifié (signature %PDF, taille, pas de JavaScript ni de fichier embarqué) puis conservé tel quel ;
 *   son aperçu (1re page rendue dans le navigateur) est ré-encodé comme une image.
 */
require_once __DIR__ . '/images.php';

const CARTE_MAX_OCTETS = 8 * 1024 * 1024;
const CARTE_QR_MAX = 1024;

function dossier_prive(int $id_user, bool $creer = true): string
{
    $dossier = __DIR__ . '/../stockage_prive/user_' . $id_user;
    if ($creer && !is_dir($dossier)) mkdir($dossier, 0700, true);
    return $dossier;
}

/** Chemin absolu d'un fichier de carte, uniquement s'il est bien dans le dossier privé de l'utilisateur. */
function chemin_fichier_prive(int $id_user, ?string $nom): ?string
{
    if (!$nom || !preg_match('/^[a-f0-9]{24}\.(pdf|jpg)$/', $nom)) return null;
    $chemin = dossier_prive($id_user, false) . '/' . $nom;
    return is_file($chemin) ? $chemin : null;
}

/** Image envoyée (fichier ou Data URL) → JPEG ré-encodé dans le dossier privé. Retourne le nom ou null. */
function carte_enregistrer_image(string $source_tmp, int $id_user): ?string
{
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($source_tmp);
    if (!in_array($mime, UPLOAD_TYPES_AUTORISES, true)) return null;
    $nom = bin2hex(random_bytes(12)) . '.jpg';
    $chemin = dossier_prive($id_user) . '/' . $nom;
    // 1600 px de large et qualité 88 : le texte et le QR code de la carte restent nets
    if (!compresserImage($source_tmp, $chemin, 88, 1600)) {
        if (is_file($chemin)) unlink($chemin);
        return null;
    }
    return $nom;
}

/** PDF : signature, taille et refus des PDF contenant du JavaScript ou des actions automatiques. */
function carte_pdf_acceptable(string $tmp): bool
{
    if (filesize($tmp) > CARTE_MAX_OCTETS) return false;
    $debut = (string) file_get_contents($tmp, false, null, 0, 1024);
    if (strpos($debut, '%PDF-') === false) return false;
    if ((new finfo(FILEINFO_MIME_TYPE))->file($tmp) !== 'application/pdf') return false;
    $contenu = (string) file_get_contents($tmp);
    // /OpenAction seul est courant (zoom d'ouverture) : on ne refuse que le code et les fichiers embarqués.
    // Un script caché dans un flux compressé n'est pas détectable ici : le fichier n'est de toute façon servi qu'à son propriétaire.
    return !preg_match('#/(JavaScript|JS|Launch|EmbeddedFiles?|RichMedia|XFA)\b#', $contenu);
}

function carte_enregistrer_pdf(string $tmp, int $id_user): ?string
{
    if (!carte_pdf_acceptable($tmp)) return null;
    $nom = bin2hex(random_bytes(12)) . '.pdf';
    $chemin = dossier_prive($id_user) . '/' . $nom;
    if (!copy($tmp, $chemin)) return null;
    chmod($chemin, 0600);
    return $nom;
}

/** Aperçu (Data URL JPEG/PNG produit par le navigateur) → JPEG ré-encodé. */
function carte_enregistrer_apercu(string $data_url, int $id_user): ?string
{
    if (!preg_match('#^data:image/(jpeg|png|webp);base64,#', $data_url)) return null;
    $binaire = base64_decode(substr($data_url, strpos($data_url, ',') + 1), true);
    if ($binaire === false || strlen($binaire) > CARTE_MAX_OCTETS) return null;
    $tmp = tempnam(sys_get_temp_dir(), 'mpc');
    file_put_contents($tmp, $binaire);
    $nom = carte_enregistrer_image($tmp, $id_user);
    unlink($tmp);
    return $nom;
}

/** Texte du QR code lu sur la carte (caractères imprimables uniquement). */
function qr_contenu_valide($texte): ?string
{
    if (!is_string($texte)) return null;
    $texte = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $texte) ?? '');
    return ($texte === '' || mb_strlen($texte) > CARTE_QR_MAX) ? null : $texte;
}

function carte_de(PDO $pdo, int $id_user): ?array
{
    try {
        $stmt = $pdo->prepare("SELECT * FROM carte_peche WHERE ID_UTILISATEUR = ?");
        $stmt->execute([$id_user]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (PDOException $e) {
        return null; // migration V0.3 pas encore passée
    }
}

/** Supprime les fichiers d'une carte (ligne en base gérée par l'appelant). */
function carte_supprimer_fichiers(int $id_user, array $carte): void
{
    foreach ([$carte['FICHIER'] ?? null, $carte['APERCU'] ?? null] as $nom) {
        if ($chemin = chemin_fichier_prive($id_user, $nom)) unlink($chemin);
    }
}

/** Suppression de compte : tout le dossier privé disparaît. */
function supprimer_dossier_prive(int $id_user): void
{
    $dossier = dossier_prive($id_user, false);
    if (!is_dir($dossier)) return;
    foreach (glob($dossier . '/*') ?: [] as $f) if (is_file($f)) unlink($f);
    @rmdir($dossier);
}
