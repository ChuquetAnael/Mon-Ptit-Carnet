<?php
/**
 * Dimension communautaire : fil d'actualité et classements du mois.
 *
 * Qui apparaît : uniquement les membres qui ont laissé le « partage communautaire » activé
 * (Paramètres), et uniquement leurs sessions non masquées.
 * Ce qui n'apparaît jamais : le nom du spot, une position GPS, l'e-mail.
 * Le lieu se limite au milieu et au département (« Mer · Manche (50) »).
 * Le fil ne montre que les photos laissées « visibles » par la modération.
 */
require_once __DIR__ . '/geo.php';
require_once __DIR__ . '/biologie.php';
require_once __DIR__ . '/profil.php';

const FIL_PAR_PAGE = 8;
const CLASSEMENT_TAILLE = 10;
const CLASSEMENT_QUANTITE_MAX_PAR_LIGNE = 100; // une ligne de prise compte au plus pour 100 poissons
const CLASSEMENT_DUREE_MAX_H = 72;             // une session compte au plus pour 72 h

/** Conditions communes : session visible d'un membre qui partage. */
function sql_visible_communaute(string $s = 's', string $u = 'u'): string
{
    return "$s.MASQUEE = 0 AND $u.PARTAGE_COMMUNAUTE = 1";
}

// =====================================================================
// FIL D'ACTUALITÉ
// =====================================================================

/**
 * Dernières prises partagées (avec au moins une photo visible), plus récentes d'abord.
 * @param string|null $avant curseur « AAAA-MM-JJ HH:MM:SS|idPrise » renvoyé par la page précédente
 * @return array ['prises' => [...], 'suivant' => ?string]
 */
function fil_actualite(PDO $pdo, int $limite = FIL_PAR_PAGE, ?string $avant = null): array
{
    $conditions = [sql_visible_communaute(), sql_prise_plausible('p', 'e'),
        "EXISTS (SELECT 1 FROM photo_prise ph WHERE ph.ID_PRISE = p.ID_PRISE AND ph.STATUT_MODERATION = 'visible')",
        "COALESCE(p.DATE_HEURE, s.DATE_DEBUT) <= NOW() + INTERVAL 1 DAY"];
    $params = [];
    if ($avant && preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\|(\d+)$/', $avant, $m)) {
        $conditions[] = "(COALESCE(p.DATE_HEURE, s.DATE_DEBUT) < ? OR (COALESCE(p.DATE_HEURE, s.DATE_DEBUT) = ? AND p.ID_PRISE < ?))";
        array_push($params, $m[1], $m[1], (int) $m[2]);
    }
    $limite = max(1, min(30, $limite));
    $stmt = $pdo->prepare("
        SELECT p.ID_PRISE, p.TAILLE_CM, p.POIDS_KG, p.QUANTITE, p.RELACHE,
               COALESCE(p.DATE_HEURE, s.DATE_DEBUT) AS DATE_PRISE,
               s.ID_SESSION, s.MILIEU, s.CODE_DEPARTEMENT, s.FACADE,
               e.NOM_COM, e.ICONE_CHEMIN,
               u.ID_UTILISATEUR, u.PSEUDO, u.PDP_CHEMIN
        FROM prise p
        JOIN session_p s ON s.ID_SESSION = p.ID_SESSION
        JOIN utilisateur u ON u.ID_UTILISATEUR = s.ID_UTILISATEUR
        JOIN espece e ON e.ID_ESPECE = p.ID_ESPECE
        WHERE " . implode(' AND ', $conditions) . "
        ORDER BY DATE_PRISE DESC, p.ID_PRISE DESC
        LIMIT " . ($limite + 1));
    $stmt->execute($params);
    $prises = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $suivant = null;
    if (count($prises) > $limite) {
        array_pop($prises);
        $der = end($prises);
        $suivant = $der['DATE_PRISE'] . '|' . $der['ID_PRISE'];
    }

    if ($prises) {
        $ids = array_map('intval', array_column($prises, 'ID_PRISE'));
        $stmt = $pdo->prepare("SELECT ID_PRISE, CHEMIN FROM photo_prise WHERE STATUT_MODERATION = 'visible' AND ID_PRISE IN ("
            . implode(',', array_fill(0, count($ids), '?')) . ") ORDER BY ORDRE, ID_PHOTO");
        $stmt->execute($ids);
        $photos = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $ph) $photos[(int) $ph['ID_PRISE']][] = $ph['CHEMIN'];
        foreach ($prises as &$p) $p['photos'] = $photos[(int) $p['ID_PRISE']] ?? [];
        unset($p);
    }
    return ['prises' => $prises, 'suivant' => $suivant];
}

/** « à l'instant », « il y a 3 h », « hier », « 12 oct. » */
function date_relative(string $date): string
{
    $t = strtotime($date);
    $ecart = time() - $t;
    if ($ecart < 0) $ecart = 0;
    if ($ecart < 3600) return $ecart < 120 ? "à l'instant" : 'il y a ' . intdiv($ecart, 60) . ' min';
    if (date('Y-m-d', $t) === date('Y-m-d')) return 'il y a ' . intdiv($ecart, 3600) . ' h';
    if (date('Y-m-d', $t) === date('Y-m-d', strtotime('-1 day'))) return 'hier';
    $mois = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
    return (int) date('j', $t) . ' ' . $mois[(int) date('n', $t) - 1] . (date('Y', $t) !== date('Y') ? ' ' . date('Y', $t) : '');
}

/** Une carte du fil (HTML). */
function carte_fil(array $p): void
{
    $titre = titre_prise($p['NOM_COM'], $p['TAILLE_CM'], $p['POIDS_KG']);
    $galerie = array_map(fn($c) => ['src' => $c, 'titre' => $titre . ' · ' . $p['PSEUDO']], $p['photos']);
    $lieu = lieu_large($p['MILIEU'], $p['CODE_DEPARTEMENT'], $p['FACADE']);
    $qte = max(1, (int) $p['QUANTITE']);
    ?>
    <article class="fil-carte">
        <header class="fil-entete">
            <a href="profil.php?id=<?= (int) $p['ID_UTILISATEUR'] ?>" class="fil-auteur">
                <?php if ($p['PDP_CHEMIN']): ?>
                    <img src="<?= e($p['PDP_CHEMIN']) ?>" alt="" class="fil-avatar" loading="lazy">
                <?php else: ?>
                    <span class="fil-avatar"><span class="material-symbols-rounded" aria-hidden="true">person</span></span>
                <?php endif; ?>
                <span><strong><?= e($p['PSEUDO']) ?></strong><small><?= e(date_relative($p['DATE_PRISE'])) ?><?= $lieu ? ' · ' . e($lieu) : '' ?></small></span>
            </a>
        </header>
        <?php if ($p['photos']): ?>
            <button type="button" class="fil-photo" data-visionneuse data-photos="<?= attr_visionneuse($galerie) ?>" aria-label="Voir <?= count($p['photos']) > 1 ? 'les ' . count($p['photos']) . ' photos' : 'la photo' ?> de <?= e($p['NOM_COM']) ?> en grand">
                <img src="<?= e($p['photos'][0]) ?>" alt="<?= e($titre) ?>" loading="lazy">
                <?php if (count($p['photos']) > 1): ?><span class="badge-photos"><span class="material-symbols-rounded" aria-hidden="true">photo_library</span><?= count($p['photos']) ?></span><?php endif; ?>
            </button>
        <?php endif; ?>
        <div class="fil-pied">
            <?php if ($p['ICONE_CHEMIN']): ?><img src="<?= e(icone_legere($p['ICONE_CHEMIN'])) ?>" alt="" class="fil-icone" loading="lazy"><?php endif; ?>
            <div class="flex-grow-1 min-w-0">
                <strong class="fil-espece"><?= $qte > 1 ? '<span class="fil-qte">×' . $qte . '</span> ' : '' ?><?= e($p['NOM_COM']) ?></strong>
                <span class="fil-mesures">
                    <?php if ($p['TAILLE_CM']): ?><span><?= (int) $p['TAILLE_CM'] ?> cm</span><?php endif; ?>
                    <?php if ($p['POIDS_KG'] !== null && (float) $p['POIDS_KG'] > 0): ?><span><?= e(str_replace('.', ',', (float) $p['POIDS_KG'])) ?> kg</span><?php endif; ?>
                </span>
            </div>
            <?php if ($p['RELACHE']): ?><span class="fil-nokill">No-kill</span><?php endif; ?>
        </div>
    </article>
    <?php
}

// =====================================================================
// CLASSEMENTS DU MOIS
// =====================================================================

const MOIS_FR = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

/** Mois demandé (« AAAA-MM »), borné entre le premier mois d'activité et le mois courant. */
function periode_classement(?string $ym): array
{
    $courant = date('Y-m');
    if (!$ym || !preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $ym) || $ym > $courant || $ym < '2020-01') $ym = $courant;
    $debut = $ym . '-01 00:00:00';
    $fin = date('Y-m-d H:i:s', strtotime($debut . ' +1 month'));
    $prec = date('Y-m', strtotime($debut . ' -1 month'));
    $suiv = date('Y-m', strtotime($debut . ' +1 month'));
    return [
        'ym' => $ym, 'debut' => $debut, 'fin' => $fin,
        'libelle' => MOIS_FR[(int) substr($ym, 5, 2) - 1] . ' ' . substr($ym, 0, 4),
        'precedent' => $prec >= '2020-01' ? $prec : null,
        'suivant' => $suiv <= $courant ? $suiv : null,
        'en_cours' => $ym === $courant,
    ];
}

/** Rangs « à égalité » (1, 2, 2, 4) sur la clé donnée. */
function avec_rangs(array $lignes, string $cle): array
{
    $rang = 0; $precedent = null;
    foreach ($lignes as $i => &$l) {
        if ($precedent === null || (string) $l[$cle] !== (string) $precedent) $rang = $i + 1;
        $l['rang'] = $rang;
        $precedent = $l[$cle];
    }
    unset($l);
    return $lignes;
}

/** Garde le top N, plus la ligne du membre connecté s'il est plus loin. */
function top_et_moi(array $lignes, int $id_user, int $n = CLASSEMENT_TAILLE): array
{
    $top = array_slice($lignes, 0, $n);
    $moi = null;
    foreach ($lignes as $i => $l) {
        if ((int) $l['ID_UTILISATEUR'] === $id_user) { if ($i >= $n) $moi = $l; break; }
    }
    return ['top' => $top, 'moi' => $moi, 'participants' => count($lignes)];
}

/** Prises éligibles du mois (jointures et filtres communs). */
function sql_prises_mois(): string
{
    return "FROM prise p
        JOIN session_p s ON s.ID_SESSION = p.ID_SESSION
        JOIN utilisateur u ON u.ID_UTILISATEUR = s.ID_UTILISATEUR
        JOIN espece e ON e.ID_ESPECE = p.ID_ESPECE
        WHERE " . sql_visible_communaute() . " AND " . sql_prise_plausible('p', 'e') . "
          AND s.DATE_DEBUT >= :debut AND s.DATE_DEBUT < :fin";
}

/** Espèces pêchées dans le mois (poissons, pêcheurs) avec le plus gros spécimen de chacune. */
function records_especes_mois(PDO $pdo, array $periode): array
{
    $q = CLASSEMENT_QUANTITE_MAX_PAR_LIGNE;
    $params = ['debut' => $periode['debut'], 'fin' => $periode['fin']];
    $stmt = $pdo->prepare("
        SELECT e.ID_ESPECE, e.NOM_COM, e.ICONE_CHEMIN,
               SUM(LEAST(COALESCE(p.QUANTITE, 1), $q)) AS nb_poissons, COUNT(DISTINCT u.ID_UTILISATEUR) AS nb_pecheurs
        " . sql_prises_mois() . "
        GROUP BY e.ID_ESPECE, e.NOM_COM, e.ICONE_CHEMIN
        ORDER BY nb_poissons DESC, e.NOM_COM
        LIMIT 30");
    $stmt->execute($params);
    $especes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT x.ID_ESPECE, x.TAILLE_CM, x.POIDS_KG, x.ID_UTILISATEUR, x.PSEUDO FROM (
            SELECT p.ID_ESPECE, p.TAILLE_CM, p.POIDS_KG, u.ID_UTILISATEUR, u.PSEUDO,
                   ROW_NUMBER() OVER (PARTITION BY p.ID_ESPECE ORDER BY p.TAILLE_CM DESC, p.POIDS_KG DESC, p.ID_PRISE) AS rang
            " . sql_prises_mois() . " AND p.TAILLE_CM IS NOT NULL
        ) x WHERE x.rang = 1");
    $stmt->execute($params);
    $records = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $records[(int) $r['ID_ESPECE']] = $r;
    foreach ($especes as &$e) $e['record'] = $records[(int) $e['ID_ESPECE']] ?? null;
    unset($e);
    return $especes;
}

/** Classement d'une espèce : plus gros spécimen par pêcheur, et nombre de poissons par pêcheur. */
function classement_espece(PDO $pdo, array $periode, int $id_espece, int $id_user): array
{
    $q = CLASSEMENT_QUANTITE_MAX_PAR_LIGNE;
    $stmt = $pdo->prepare("
        SELECT x.ID_UTILISATEUR, x.PSEUDO, x.PDP_CHEMIN, x.TAILLE_CM, x.POIDS_KG FROM (
            SELECT u.ID_UTILISATEUR, u.PSEUDO, u.PDP_CHEMIN, p.TAILLE_CM, p.POIDS_KG,
                   ROW_NUMBER() OVER (PARTITION BY u.ID_UTILISATEUR ORDER BY p.TAILLE_CM DESC, p.POIDS_KG DESC, p.ID_PRISE) AS rang
            " . sql_prises_mois() . " AND p.ID_ESPECE = :espece AND p.TAILLE_CM IS NOT NULL
        ) x WHERE x.rang = 1
        ORDER BY x.TAILLE_CM DESC, x.POIDS_KG DESC, x.PSEUDO");
    $stmt->execute(['debut' => $periode['debut'], 'fin' => $periode['fin'], 'espece' => $id_espece]);
    $plus_gros = top_et_moi(avec_rangs($stmt->fetchAll(PDO::FETCH_ASSOC), 'TAILLE_CM'), $id_user);

    $stmt = $pdo->prepare("
        SELECT u.ID_UTILISATEUR, u.PSEUDO, u.PDP_CHEMIN, SUM(LEAST(COALESCE(p.QUANTITE, 1), $q)) AS nb
        " . sql_prises_mois() . " AND p.ID_ESPECE = :espece
        GROUP BY u.ID_UTILISATEUR, u.PSEUDO, u.PDP_CHEMIN
        ORDER BY nb DESC, u.PSEUDO");
    $stmt->execute(['debut' => $periode['debut'], 'fin' => $periode['fin'], 'espece' => $id_espece]);
    $nombre = top_et_moi(avec_rangs($stmt->fetchAll(PDO::FETCH_ASSOC), 'nb'), $id_user);
    return ['plus_gros' => $plus_gros, 'nombre' => $nombre];
}

/**
 * Assiduité : temps passé au bord de l'eau dans le mois (sessions avec une heure de fin).
 * Les sessions qui se chevauchent ne comptent qu'une fois ; une session compte au plus 72 h ;
 * seule la partie de la session comprise dans le mois est comptée.
 */
function classement_assiduite(PDO $pdo, array $periode, int $id_user): array
{
    $stmt = $pdo->prepare("
        SELECT u.ID_UTILISATEUR, u.PSEUDO, u.PDP_CHEMIN, s.DATE_DEBUT, s.DATE_FIN
        FROM session_p s JOIN utilisateur u ON u.ID_UTILISATEUR = s.ID_UTILISATEUR
        WHERE " . sql_visible_communaute() . " AND s.DATE_FIN IS NOT NULL AND s.DATE_FIN > s.DATE_DEBUT
          AND s.DATE_DEBUT < :fin AND s.DATE_FIN > :debut
        ORDER BY u.ID_UTILISATEUR, s.DATE_DEBUT");
    $stmt->execute(['debut' => $periode['debut'], 'fin' => $periode['fin']]);
    $debut_mois = strtotime($periode['debut']);
    $fin_mois = min(strtotime($periode['fin']), time());
    $par_pecheur = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $a = max($debut_mois, strtotime($r['DATE_DEBUT']));
        $b = min($fin_mois, strtotime($r['DATE_FIN']), strtotime($r['DATE_DEBUT']) + CLASSEMENT_DUREE_MAX_H * 3600);
        if ($b <= $a) continue;
        $id = (int) $r['ID_UTILISATEUR'];
        $par_pecheur[$id]['infos'] = ['ID_UTILISATEUR' => $id, 'PSEUDO' => $r['PSEUDO'], 'PDP_CHEMIN' => $r['PDP_CHEMIN']];
        $par_pecheur[$id]['intervalles'][] = [$a, $b];
    }
    $lignes = [];
    foreach ($par_pecheur as $p) {
        // Fusion des intervalles qui se chevauchent
        $total = 0; $cour = null; $nb = count($p['intervalles']);
        foreach ($p['intervalles'] as [$a, $b]) {
            if ($cour && $a <= $cour[1]) { $cour[1] = max($cour[1], $b); continue; }
            if ($cour) $total += $cour[1] - $cour[0];
            $cour = [$a, $b];
        }
        if ($cour) $total += $cour[1] - $cour[0];
        $lignes[] = $p['infos'] + ['minutes' => intdiv($total, 60), 'sorties' => $nb];
    }
    usort($lignes, fn($x, $y) => [$y['minutes'], $x['PSEUDO']] <=> [$x['minutes'], $y['PSEUDO']]);
    return top_et_moi(avec_rangs($lignes, 'minutes'), $id_user);
}

/** Polyvalence : nombre d'espèces différentes du codex capturées dans le mois. */
function classement_polyvalence(PDO $pdo, array $periode, int $id_user): array
{
    $q = CLASSEMENT_QUANTITE_MAX_PAR_LIGNE;
    $stmt = $pdo->prepare("
        SELECT u.ID_UTILISATEUR, u.PSEUDO, u.PDP_CHEMIN, COUNT(DISTINCT p.ID_ESPECE) AS especes,
               SUM(LEAST(COALESCE(p.QUANTITE, 1), $q)) AS poissons
        " . sql_prises_mois() . "
        GROUP BY u.ID_UTILISATEUR, u.PSEUDO, u.PDP_CHEMIN
        ORDER BY especes DESC, poissons DESC, u.PSEUDO");
    $stmt->execute(['debut' => $periode['debut'], 'fin' => $periode['fin']]);
    return top_et_moi(avec_rangs($stmt->fetchAll(PDO::FETCH_ASSOC), 'especes'), $id_user);
}

/** No-kill (poissons relâchés) et total des prises du mois. */
function classement_nokill(PDO $pdo, array $periode, int $id_user): array
{
    $q = CLASSEMENT_QUANTITE_MAX_PAR_LIGNE;
    $stmt = $pdo->prepare("
        SELECT u.ID_UTILISATEUR, u.PSEUDO, u.PDP_CHEMIN,
               SUM(LEAST(COALESCE(p.QUANTITE, 1), $q)) AS total,
               SUM(CASE WHEN p.RELACHE = 1 THEN LEAST(COALESCE(p.QUANTITE, 1), $q) ELSE 0 END) AS relaches
        " . sql_prises_mois() . "
        GROUP BY u.ID_UTILISATEUR, u.PSEUDO, u.PDP_CHEMIN");
    $stmt->execute(['debut' => $periode['debut'], 'fin' => $periode['fin']]);
    $lignes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($lignes as &$l) $l['taux'] = $l['total'] > 0 ? (int) round($l['relaches'] / $l['total'] * 100) : 0;
    unset($l);

    $nokill = array_values(array_filter($lignes, fn($l) => $l['relaches'] > 0));
    usort($nokill, fn($x, $y) => [$y['relaches'], $y['taux'], $x['PSEUDO']] <=> [$x['relaches'], $x['taux'], $y['PSEUDO']]);
    $total = $lignes;
    usort($total, fn($x, $y) => [$y['total'], $x['PSEUDO']] <=> [$x['total'], $y['PSEUDO']]);
    return [
        'nokill' => top_et_moi(avec_rangs($nokill, 'relaches'), $id_user),
        'total' => top_et_moi(avec_rangs($total, 'total'), $id_user),
    ];
}

/** « 12 h 30 » */
function duree_lisible(int $minutes): string
{
    $h = intdiv($minutes, 60); $m = $minutes % 60;
    return $h ? $h . ' h' . ($m ? ' ' . str_pad((string) $m, 2, '0', STR_PAD_LEFT) : '') : $m . ' min';
}
