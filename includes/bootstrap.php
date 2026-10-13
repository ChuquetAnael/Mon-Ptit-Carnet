<?php
/**
 * Socle commun à toutes les pages.
 * - configuration + connexion BDD unique (utf8mb4, exceptions)
 * - session sécurisée (cookie HttpOnly / SameSite / Secure si HTTPS)
 * - protection CSRF vérifiée automatiquement sur TOUTES les requêtes POST
 * - échappement HTML, gestion d'erreurs sans fuite d'informations, appels HTTP sécurisés
 */
require_once __DIR__ . '/../BDD/env.php';
require_once __DIR__ . '/images.php';

ini_set('display_errors', APP_DEBUG ? '1' : '0');
ini_set('log_errors', '1');
date_default_timezone_set('Europe/Paris');

const PHOTOS_MAX_PAR_PRISE = 6;
const SESSIONS_EPINGLEES_MAX = 3;
const SEUIL_SIGNALEMENTS_MASQUAGE = 1; // nb de signalements qui masquent une photo en attendant la modération
const MOTIFS_SIGNALEMENT = [
    'Contenu choquant ou violent',
    'Nudité ou contenu sexuel',
    'Contenu haineux ou harcèlement',
    'Photo sans rapport avec la pêche',
    'Atteinte à la vie privée',
    'Autre',
];

// -------------------------------------------------------------------------
// SESSION
// -------------------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// -------------------------------------------------------------------------
// OUTILS GÉNÉRAUX
// -------------------------------------------------------------------------

/** Échappement HTML (texte ET attributs). */
function e($valeur): string
{
    return htmlspecialchars((string) ($valeur ?? ''), ENT_QUOTES, 'UTF-8');
}

/** Connexion PDO unique pour toute la requête. */
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host=' . $GLOBALS['host'] . ';dbname=' . $GLOBALS['dbname'] . ';charset=utf8mb4',
            $GLOBALS['user'],
            $GLOBALS['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }
    return $pdo;
}

/** Page d'erreur minimale (pas de détail technique hors mode debug). */
function app_page_message(string $titre, string $message, int $code = 400): void
{
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: text/html; charset=UTF-8');
    }
    echo '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
        . '<title>' . e($titre) . '</title>'
        . '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">'
        . '</head><body class="bg-light"><main class="container py-5" style="max-width:560px">'
        . '<div class="card border-0 shadow-sm rounded-4 p-4 text-center">'
        . '<h1 class="h5 fw-bold mb-3">' . e($titre) . '</h1>'
        . '<div class="text-secondary mb-4">' . $message . '</div>'
        . '<a href="javascript:history.back()" class="btn btn-primary rounded-pill">Revenir en arrière</a>'
        . '</div></main></body></html>';
    exit();
}

/** Journalise l'erreur et affiche un message générique. */
function app_fatal(Throwable $e, string $message = "Une erreur technique est survenue. Réessaie dans un instant."): void
{
    error_log('[MonPtitCarnet] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO && $GLOBALS['pdo']->inTransaction()) {
        $GLOBALS['pdo']->rollBack();
    }
    $detail = APP_DEBUG ? '<pre class="text-start small text-danger mt-3 mb-0" style="white-space:pre-wrap">' . e($e->getMessage()) . '</pre>' : '';
    app_page_message('Oups…', e($message) . $detail, 500);
}

set_exception_handler(function (Throwable $e) {
    app_fatal($e);
});

/** Réponse JSON (endpoints AJAX). */
function json_out(array $data, int $code = 200): void
{
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=UTF-8');
    }
    echo json_encode($data);
    exit();
}

/** Encodage JSON sûr à placer dans une balise <script>. */
function js($valeur): string
{
    return json_encode($valeur, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
}

/** Entier strictement positif ou null. */
function id_ou_null($valeur): ?int
{
    if ($valeur === null || $valeur === '' || !ctype_digit((string) $valeur)) return null;
    $id = (int) $valeur;
    return $id > 0 ? $id : null;
}

/** Nombre décimal (accepte la virgule) borné, ou null. */
function nombre_ou_null($valeur, float $min, float $max): ?float
{
    if ($valeur === null || $valeur === '') return null;
    $valeur = str_replace(',', '.', trim((string) $valeur));
    if (!is_numeric($valeur)) return null;
    $nb = (float) $valeur;
    return ($nb < $min || $nb > $max) ? null : $nb;
}

/** Date "Y-m-d\TH:i" (input datetime-local) valide, convertie au format SQL, ou null. */
function datetime_ou_null($valeur): ?string
{
    if (!is_string($valeur) || $valeur === '') return null;
    foreach (['Y-m-d\TH:i', 'Y-m-d\TH:i:s', 'Y-m-d H:i:s', 'Y-m-d H:i'] as $format) {
        $d = DateTime::createFromFormat($format, $valeur);
        if ($d && $d->format($format) === $valeur) return $d->format('Y-m-d H:i:s');
    }
    return null;
}

/** Heure "HH:MM" valide ou null. */
function heure_ou_null($valeur): ?string
{
    return (is_string($valeur) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $valeur)) ? $valeur : null;
}

/** Coordonnées GPS valides → "lat, lng" (format de SPOT.LOCALISATION) ou null. */
function localisation_ou_null($lat, $lng): ?string
{
    $lat = nombre_ou_null($lat, -90, 90);
    $lng = nombre_ou_null($lng, -180, 180);
    if ($lat === null || $lng === null || ($lat == 0 && $lng == 0)) return null;
    return sprintf('%.6F, %.6F', $lat, $lng);
}

/** Lecture robuste de SPOT.LOCALISATION → [lat, lng] (floats) ou null. */
function lire_localisation(?string $loc): ?array
{
    if (!$loc) return null;
    $parts = array_map('trim', explode(',', $loc));
    if (count($parts) !== 2 || !is_numeric($parts[0]) || !is_numeric($parts[1])) return null;
    return [(float) $parts[0], (float) $parts[1]];
}

// -------------------------------------------------------------------------
// AUTHENTIFICATION / DROITS
// -------------------------------------------------------------------------

function utilisateur_id(): ?int
{
    return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
}

function exiger_connexion(): int
{
    $id = utilisateur_id();
    if (!$id) {
        header('Location: index.php');
        exit();
    }
    return $id;
}

function est_admin(int $id_user): bool
{
    static $cache = [];
    if (!isset($cache[$id_user])) {
        $stmt = db()->prepare("SELECT 1 FROM admin WHERE ID_UTILISATEUR = ?");
        $stmt->execute([$id_user]);
        $cache[$id_user] = (bool) $stmt->fetchColumn();
    }
    return $cache[$id_user];
}

function exiger_admin(): int
{
    $id = exiger_connexion();
    if (!est_admin($id)) {
        header('Location: accueil.php');
        exit();
    }
    return $id;
}

/** Ouvre la session d'un utilisateur authentifié (anti-fixation de session). */
function connecter_utilisateur(array $utilisateur): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $utilisateur['ID_UTILISATEUR'];
    $_SESSION['user_pseudo'] = $utilisateur['PSEUDO'];
    unset($_SESSION['csrf_token']);
}

// -------------------------------------------------------------------------
// CSRF
// -------------------------------------------------------------------------

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . csrf_token() . '">';
}

/** Vérification automatique de toutes les requêtes POST. */
function csrf_verifier_requete(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') return;

    // Envoi plus gros que post_max_size : PHP vide $_POST et $_FILES sans prévenir
    $taille = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if (empty($_POST) && empty($_FILES) && $taille > 0) {
        app_page_message(
            'Envoi trop volumineux',
            'Les fichiers envoyés dépassent la limite du serveur (' . e(ini_get('post_max_size')) . ').<br>Envoie moins de photos à la fois.',
            413
        );
    }

    $jeton = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($jeton) || !hash_equals(csrf_token(), $jeton)) {
        app_page_message(
            'Formulaire expiré',
            'Ta session a expiré ou le formulaire ne vient pas de l’application.<br>Recharge la page et recommence.',
            419
        );
    }
}
csrf_verifier_requete();

// -------------------------------------------------------------------------
// APPELS HTTP SORTANTS (APIs marée, Hub'eau, Wikipedia)
// -------------------------------------------------------------------------

/**
 * GET HTTP avec vérification TLS active (bundle de certificats fourni dans
 * includes/cacert.pem, ce qui évite de désactiver SSL sous XAMPP).
 * Retourne le corps de la réponse (2xx) ou null.
 */
function http_get(string $url, int $timeout = 10): ?string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_CAINFO => __DIR__ . '/cacert.pem',
        CURLOPT_ENCODING => '',
        CURLOPT_USERAGENT => 'MonPtitCarnet/0.2 (application de carnet de peche)',
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($body === false || $code < 200 || $code >= 300) {
        error_log("[MonPtitCarnet] HTTP $code sur " . preg_replace('/key=[^&]+/', 'key=***', $url) . " $err");
        return null;
    }
    return $body;
}
