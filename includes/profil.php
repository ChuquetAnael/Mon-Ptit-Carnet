<?php
/**
 * Profil public enrichi et visibilité des sessions.
 * Règle de confidentialité : une session « masquée » n'est visible que de son auteur ;
 * les autres membres ne voient jamais ni le nom du spot ni une position, même d'une session visible.
 */
require_once __DIR__ . '/geo.php';

const NIVEAUX_EXPERIENCE = [
    'debutant' => 'Débutant',
    'intermediaire' => 'Intermédiaire',
    'confirme' => 'Confirmé',
    'expert' => 'Expert',
];
const MILIEUX_PREFERES = ['eau_douce' => 'Eau douce', 'mer' => 'Mer', 'les_deux' => 'Eau douce et mer'];
const FAVORIS_PROFIL_MAX = 5;
const SESSIONS_PAR_PAGE_PROFIL = 12;

/** Informations publiques d'un profil (jamais l'e-mail). */
function profil_public(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare("
        SELECT u.ID_UTILISATEUR, u.PSEUDO, u.DATE_CREATION, u.DESCRIPTION, u.PDP_CHEMIN, u.BANNIERE_CHEMIN,
               u.NIVEAU_EXPERIENCE, u.ANNEE_DEBUT, u.CODE_DEPARTEMENT, u.MILIEU_PREFERE, u.ID_ESPECE_FETICHE,
               e.NOM_COM AS ESPECE_FETICHE, e.ICONE_CHEMIN AS ICONE_FETICHE
        FROM utilisateur u LEFT JOIN espece e ON e.ID_ESPECE = u.ID_ESPECE_FETICHE
        WHERE u.ID_UTILISATEUR = ?");
    $stmt->execute([$id]);
    $p = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$p) return null;

    $stmt = $pdo->prepare("SELECT t.ID_TECHNIQUE, t.NOM_TECHNIQUE FROM utilisateur_technique ut JOIN technique t ON t.ID_TECHNIQUE = ut.ID_TECHNIQUE WHERE ut.ID_UTILISATEUR = ? ORDER BY t.NOM_TECHNIQUE");
    $stmt->execute([$id]);
    $p['techniques'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt = $pdo->prepare("SELECT ts.ID_TYPE_SESSION, ts.NOM_TYPE_SESSION FROM utilisateur_type_session uts JOIN type_session ts ON ts.ID_TYPE_SESSION = uts.ID_TYPE_SESSION WHERE uts.ID_UTILISATEUR = ? ORDER BY ts.NOM_TYPE_SESSION");
    $stmt->execute([$id]);
    $p['types'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return $p;
}

/** Chiffres affichés sur le profil, calculés sur les seules sessions visibles (ou toutes pour l'auteur). */
function chiffres_profil(PDO $pdo, int $id, bool $toutes): array
{
    $filtre = $toutes ? '' : 'AND s.MASQUEE = 0';
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT s.ID_SESSION) AS sessions,
               COALESCE(SUM(COALESCE(p.QUANTITE, 1)), 0) AS poissons,
               COUNT(DISTINCT p.ID_ESPECE) AS especes,
               COALESCE(SUM(CASE WHEN p.RELACHE = 1 THEN COALESCE(p.QUANTITE, 1) ELSE 0 END), 0) AS relaches
        FROM session_p s LEFT JOIN prise p ON p.ID_SESSION = s.ID_SESSION
        WHERE s.ID_UTILISATEUR = ? $filtre");
    $stmt->execute([$id]);
    $c = $stmt->fetch(PDO::FETCH_ASSOC);
    $c['nokill'] = $c['poissons'] > 0 ? (int) round($c['relaches'] / $c['poissons'] * 100) : null;
    return array_map(fn($v) => $v === null ? null : (int) $v, $c);
}

/**
 * Photos des prises de plusieurs sessions, pour la visionneuse.
 * Les autres membres ne voient que les photos laissées visibles par la modération.
 * @return array id_session => [['src', 'titre']]
 */
function photos_des_sessions(PDO $pdo, array $ids_sessions, bool $est_auteur): array
{
    $ids = array_values(array_filter(array_map('intval', $ids_sessions)));
    if (!$ids) return [];
    $marques = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("
        SELECT p.ID_SESSION, ph.CHEMIN, e.NOM_COM, p.TAILLE_CM, p.POIDS_KG
        FROM photo_prise ph
        JOIN prise p ON p.ID_PRISE = ph.ID_PRISE
        JOIN espece e ON e.ID_ESPECE = p.ID_ESPECE
        WHERE p.ID_SESSION IN ($marques) " . ($est_auteur ? '' : "AND ph.STATUT_MODERATION = 'visible'") . "
        ORDER BY p.DATE_HEURE, p.ID_PRISE, ph.ORDRE, ph.ID_PHOTO");
    $stmt->execute($ids);
    $res = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $res[(int) $r['ID_SESSION']][] = ['src' => $r['CHEMIN'], 'titre' => titre_prise($r['NOM_COM'], $r['TAILLE_CM'], $r['POIDS_KG'])];
    }
    return $res;
}

/** « Brochet · 82 cm · 4,1 kg » */
function titre_prise(string $espece, $taille, $poids): string
{
    $parts = [$espece];
    if ($taille) $parts[] = (int) $taille . ' cm';
    if ($poids !== null && (float) $poids > 0) $parts[] = str_replace('.', ',', rtrim(rtrim(number_format((float) $poids, 2, '.', ''), '0'), '.')) . ' kg';
    return implode(' · ', $parts);
}

/** Masquer / afficher une session de l'utilisateur. Masquer retire aussi l'épingle. */
function changer_visibilite_session(PDO $pdo, int $id_user, int $id_session, bool $masquer): bool
{
    $stmt = $pdo->prepare($masquer
        ? "UPDATE session_p SET MASQUEE = 1, EPINGLEE = 0, DATE_EPINGLE = NULL WHERE ID_SESSION = ? AND ID_UTILISATEUR = ?"
        : "UPDATE session_p SET MASQUEE = 0 WHERE ID_SESSION = ? AND ID_UTILISATEUR = ?");
    $stmt->execute([$id_session, $id_user]);
    return $stmt->rowCount() > 0;
}

/** Attribut data-photos (JSON échappé) pour la visionneuse. */
function attr_visionneuse(array $photos): string
{
    return e(json_encode(array_values($photos), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP));
}
