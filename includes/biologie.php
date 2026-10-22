<?php
/**
 * Garde-fou biologique : refuse les tailles et poids impossibles pour l'espèce
 * (un bar de 100 m, un carassin de 20 kg…) qui fausseraient statistiques et classements.
 *
 * Références : colonnes TAILLE_MAX (cm) et POIDS_MAX (kg) de la table espece (codex).
 * - au-delà de la référence + 15 % : saisie refusée (un vrai record dépasse rarement de plus) ;
 * - entre la référence et +15 % : simple avertissement (« ce serait un record ») ;
 * - taille ET poids saisis : le poids doit rester cohérent avec la taille.
 *   Poids attendu ≈ POIDS_MAX × (taille / TAILLE_MAX)³ (croissance d'un poisson en volume).
 *
 * Les mêmes règles sont appliquées dans le navigateur (script/biologie.js) : l'erreur s'affiche
 * pendant la saisie, et le serveur refuse tout envoi qui contourne le JavaScript.
 */

const BIO_TOLERANCE_RECORD = 0.15;   // +15 % au-delà du maximum connu
const BIO_TAILLE_ABSOLUE = 700;      // cm : rien ne mesure plus de 7 m (plafond si l'espèce n'a pas de référence)
const BIO_POIDS_ABSOLU = 999.99;     // kg : limite de la colonne POIDS_KG
const BIO_EMBONPOINT_MAX = 2.5;      // facteur de condition maximal pour déduire un poids plafond d'une taille
const BIO_RAPPORT_LOURD_BLOQUANT = 3.0;  // 3 fois plus lourd que le poids attendu : refusé
const BIO_RAPPORT_LOURD_ALERTE = 1.8;
const BIO_RAPPORT_LEGER_BLOQUANT = 4.0;  // 4 fois plus léger : refusé (souvent une erreur d'unité)
const BIO_RAPPORT_LEGER_ALERTE = 2.5;
const BIO_MARGE_KG = 0.05;           // marge d'arrondi pour les petits poissons
const BIO_POIDS_MIN_CONTROLE = 0.1;  // en dessous, un poids « trop léger » n'est pas contrôlé (balances imprécises)

/** Limites de toutes les espèces : id => ['nom', 'tmax', 'pmax'] (valeurs de référence du codex). */
function limites_biologiques(PDO $pdo): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    foreach ($pdo->query("SELECT ID_ESPECE, NOM_COM, TAILLE_MAX, POIDS_MAX FROM espece") as $e) {
        $cache[(int) $e['ID_ESPECE']] = [
            'nom' => $e['NOM_COM'],
            'tmax' => $e['TAILLE_MAX'] !== null && (float) $e['TAILLE_MAX'] > 0 ? (float) $e['TAILLE_MAX'] : null,
            'pmax' => $e['POIDS_MAX'] !== null && (float) $e['POIDS_MAX'] > 0 ? (float) $e['POIDS_MAX'] : null,
        ];
    }
    return $cache;
}

/** Version compacte pour le JavaScript : id => [tmax, pmax] */
function limites_biologiques_js(PDO $pdo): array
{
    $out = [];
    foreach (limites_biologiques($pdo) as $id => $l) $out[$id] = [$l['tmax'], $l['pmax']];
    return $out;
}

/** Taille et poids au-delà desquels la saisie est refusée. */
function plafonds_biologiques(?float $tmax, ?float $pmax): array
{
    $t_lim = $tmax !== null ? min(BIO_TAILLE_ABSOLUE, (float) round($tmax * (1 + BIO_TOLERANCE_RECORD))) : BIO_TAILLE_ABSOLUE;
    if ($pmax !== null) {
        $p_lim = round($pmax * (1 + BIO_TOLERANCE_RECORD), 2);
    } elseif ($tmax !== null) {
        // Pas de poids de référence : poids d'un poisson très trapu de la taille plafond
        $p_lim = round(BIO_EMBONPOINT_MAX * ($t_lim ** 3) / 100000, 2);
    } else {
        $p_lim = BIO_POIDS_ABSOLU;
    }
    return ['taille' => $t_lim, 'poids' => min(BIO_POIDS_ABSOLU, max(0.01, $p_lim))];
}

/** Nombre au format français (virgule), sans zéros inutiles. */
function bio_nombre(float $n, int $decimales = 2): string
{
    $txt = number_format($n, $decimales, ',', ' ');
    return strpos($txt, ',') !== false ? rtrim(rtrim($txt, '0'), ',') : $txt;
}

/**
 * Contrôle d'une prise.
 * @return array liste de ['niveau' => danger|warning, 'texte' => string, 'bloquant' => bool, 'champ' => taille|poids|rapport]
 */
function controler_biologie(array $limites, int $id_espece, ?float $taille, ?float $poids): array
{
    $l = $limites[$id_espece] ?? ['nom' => 'inconnue', 'tmax' => null, 'pmax' => null];
    $nom = $l['nom'];
    $taille = ($taille !== null && $taille > 0) ? $taille : null;
    $poids = ($poids !== null && $poids > 0) ? $poids : null;
    $plaf = plafonds_biologiques($l['tmax'], $l['pmax']);
    $alertes = [];
    $bloque = false;

    if ($taille !== null) {
        if ($taille > $plaf['taille']) {
            $bloque = true;
            $alertes[] = ['niveau' => 'danger', 'bloquant' => true, 'champ' => 'taille', 'texte' => $l['tmax'] !== null
                ? sprintf("%s cm, c'est impossible : l'espèce %s ne dépasse pas environ %s cm. La taille se note en centimètres.", bio_nombre($taille, 0), $nom, bio_nombre($l['tmax'], 0))
                : sprintf("%s cm, c'est une taille impossible. La taille se note en centimètres.", bio_nombre($taille, 0))];
        } elseif ($l['tmax'] !== null && $taille > $l['tmax']) {
            $alertes[] = ['niveau' => 'warning', 'bloquant' => false, 'champ' => 'taille', 'texte' => sprintf("%s cm : ce serait un record pour l'espèce (maximum connu d'environ %s cm). Vérifie ta mesure.", bio_nombre($taille, 0), bio_nombre($l['tmax'], 0))];
        }
    }
    if ($poids !== null) {
        if ($poids > $plaf['poids']) {
            $bloque = true;
            $alertes[] = ['niveau' => 'danger', 'bloquant' => true, 'champ' => 'poids', 'texte' => $l['pmax'] !== null
                ? sprintf("%s kg, c'est impossible : l'espèce %s ne dépasse pas environ %s kg. Le poids se note en kilos (850 g = 0,85).", bio_nombre($poids), $nom, bio_nombre($l['pmax']))
                : sprintf("%s kg, c'est impossible pour l'espèce %s. Le poids se note en kilos (850 g = 0,85).", bio_nombre($poids), $nom)];
        } elseif ($l['pmax'] !== null && $poids > $l['pmax']) {
            $alertes[] = ['niveau' => 'warning', 'bloquant' => false, 'champ' => 'poids', 'texte' => sprintf("%s kg : ce serait un record pour l'espèce (maximum connu d'environ %s kg). Vérifie ta pesée.", bio_nombre($poids), bio_nombre($l['pmax']))];
        }
    }

    // Cohérence taille / poids (seulement avec des références complètes et des valeurs plausibles)
    if (!$bloque && $taille !== null && $poids !== null && $l['tmax'] !== null && $l['pmax'] !== null) {
        $attendu = $l['pmax'] * (($taille / $l['tmax']) ** 3);
        $txt_attendu = bio_nombre($attendu, $attendu < 1 ? 2 : 1);
        if ($poids > $attendu * BIO_RAPPORT_LOURD_BLOQUANT + BIO_MARGE_KG) {
            $alertes[] = ['niveau' => 'danger', 'bloquant' => true, 'champ' => 'rapport', 'texte' => sprintf("%s kg pour %s cm, c'est bien trop lourd pour l'espèce %s (environ %s kg attendus à cette taille). Vérifie la taille et le poids.", bio_nombre($poids), bio_nombre($taille, 0), $nom, $txt_attendu)];
        } elseif ($poids > $attendu * BIO_RAPPORT_LOURD_ALERTE + BIO_MARGE_KG) {
            $alertes[] = ['niveau' => 'warning', 'bloquant' => false, 'champ' => 'rapport', 'texte' => sprintf("Poids élevé pour %s cm (environ %s kg habituellement). Vérifie ta pesée.", bio_nombre($taille, 0), $txt_attendu)];
        } elseif ($attendu >= BIO_POIDS_MIN_CONTROLE && $poids < $attendu / BIO_RAPPORT_LEGER_BLOQUANT) {
            $alertes[] = ['niveau' => 'danger', 'bloquant' => true, 'champ' => 'rapport', 'texte' => sprintf("%s kg pour %s cm, c'est bien trop léger pour l'espèce %s (environ %s kg attendus à cette taille). Le poids se note en kilos, la taille en centimètres.", bio_nombre($poids), bio_nombre($taille, 0), $nom, $txt_attendu)];
        } elseif ($attendu >= BIO_POIDS_MIN_CONTROLE && $poids < $attendu / BIO_RAPPORT_LEGER_ALERTE) {
            $alertes[] = ['niveau' => 'warning', 'bloquant' => false, 'champ' => 'rapport', 'texte' => sprintf("Poids faible pour %s cm (environ %s kg habituellement). Vérifie ta pesée.", bio_nombre($taille, 0), $txt_attendu)];
        }
    }
    return $alertes;
}

/** Vrai si au moins une alerte bloquante. */
function biologie_bloquante(array $alertes): bool
{
    foreach ($alertes as $a) if ($a['bloquant']) return true;
    return false;
}

/**
 * Condition SQL « valeurs biologiquement plausibles » pour les classements et le fil
 * (écarte les anciennes saisies aberrantes enregistrées avant ce garde-fou).
 * $p = alias de la table prise, $e = alias de la table espece.
 */
function sql_prise_plausible(string $p = 'p', string $e = 'e'): string
{
    $t = 1 + BIO_TOLERANCE_RECORD;
    return "($p.TAILLE_CM IS NULL OR $p.TAILLE_CM <= LEAST(" . BIO_TAILLE_ABSOLUE . ", COALESCE(ROUND($e.TAILLE_MAX * $t), " . BIO_TAILLE_ABSOLUE . ")))"
        . " AND ($p.POIDS_KG IS NULL OR $e.POIDS_MAX IS NULL OR $p.POIDS_KG <= ROUND($e.POIDS_MAX * $t, 2))";
}
