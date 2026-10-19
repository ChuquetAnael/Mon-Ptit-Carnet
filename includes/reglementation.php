<?php
/**
 * Garde-fou réglementaire : règles applicables à une session et contrôle des prises.
 *
 * Principe :
 *  1. contexte_reglementaire() décrit où et quand on pêche (milieu, catégorie, façade, département, réserves) ;
 *  2. regles_resolues() sélectionne les règles de la table `reglementation` pour ce contexte
 *     et calcule, pour la date de la session, si chaque fermeture est active ;
 *  3. controler_prise() produit les alertes d'une prise (taille, fermeture, quota, marquage…).
 * Le même résultat est envoyé au navigateur (alertes instantanées, même hors-ligne)
 * et recalculé côté serveur à l'enregistrement.
 *
 * Ces informations sont indicatives : seuls les textes officiels font foi.
 */
require_once __DIR__ . '/geo.php';

const JOURS_REGLE = ['mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7];
const JOURS_FR = [1 => 'lundi', 2 => 'mardi', 3 => 'mercredi', 4 => 'jeudi', 5 => 'vendredi', 6 => 'samedi', 7 => 'dimanche'];

/**
 * Date d'une borne de règle pour une année donnée.
 *   'MM-JJ'                → date fixe ;
 *   'N-jour-MM[+/-k]'      → N-ième jour de la semaine du mois (N = 1..5 ou 'last'), décalé de k jours.
 */
function date_borne_regle(string $borne, int $annee): ?DateTimeImmutable
{
    $borne = trim($borne);
    if (preg_match('/^(\d{2})-(\d{2})$/', $borne, $m)) {
        return checkdate((int) $m[1], (int) $m[2], $annee) ? new DateTimeImmutable(sprintf('%04d-%s-%s', $annee, $m[1], $m[2])) : null;
    }
    if (!preg_match('/^([1-5]|last)-(mon|tue|wed|thu|fri|sat|sun)-(\d{2})([+-]\d{1,3})?$/', $borne, $m)) return null;
    $mois = (int) $m[3];
    if ($mois < 1 || $mois > 12) return null;
    $jour_cible = JOURS_REGLE[$m[2]];

    if ($m[1] === 'last') {
        $d = new DateTimeImmutable(sprintf('%04d-%02d-01', $annee, $mois));
        $d = $d->modify('last day of this month');
        while ((int) $d->format('N') !== $jour_cible) $d = $d->modify('-1 day');
    } else {
        $d = new DateTimeImmutable(sprintf('%04d-%02d-01', $annee, $mois));
        while ((int) $d->format('N') !== $jour_cible) $d = $d->modify('+1 day');
        $d = $d->modify('+' . (7 * ((int) $m[1] - 1)) . ' days');
        if ((int) $d->format('n') !== $mois) return null; // pas de 5e samedi ce mois-ci
    }
    if (!empty($m[4])) $d = $d->modify($m[4] . ' days');
    return $d;
}

/** Libellé lisible d'une borne (« 2e samedi de mars », « lendemain du dernier dimanche de janvier »). */
function libelle_borne_regle(string $borne): string
{
    $mois = [1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    if (preg_match('/^(\d{2})-(\d{2})$/', $borne, $m)) {
        return ((int) $m[2] === 1 ? '1er' : (int) $m[2]) . ' ' . $mois[(int) $m[1]];
    }
    if (!preg_match('/^([1-5]|last)-(mon|tue|wed|thu|fri|sat|sun)-(\d{2})([+-]\d{1,3})?$/', $borne, $m)) return $borne;
    $rang = $m[1] === 'last' ? 'dernier' : ($m[1] === '1' ? '1er' : $m[1] . 'e');
    $nom_mois = $mois[(int) $m[3]];
    $texte = $rang . ' ' . JOURS_FR[JOURS_REGLE[$m[2]]] . (in_array($nom_mois[0], ['a', 'o'], true) ? " d'" : ' de ') . $nom_mois;
    if (($m[4] ?? '') === '+1') return 'lendemain du ' . $texte;
    if (($m[4] ?? '') === '-1') return 'veille du ' . $texte;
    return $texte . (!empty($m[4]) ? ' (' . $m[4] . ' j)' : '');
}

/**
 * Fermeture active à une date ? Gère les périodes à cheval sur deux années
 * (ex. 1re catégorie : du lendemain du 3e dimanche de septembre à la veille du 2e samedi de mars).
 * @return array ['active' => bool, 'du' => 'd/m/Y', 'au' => 'd/m/Y'] ou null si la règle n'a pas de période
 */
function fermeture_a_la_date(?string $debut, ?string $fin, string $date): ?array
{
    if (!$debut || !$fin) return null;
    $jour = new DateTimeImmutable($date);
    $annee = (int) $jour->format('Y');
    $d = date_borne_regle($debut, $annee);
    $f = date_borne_regle($fin, $annee);
    if (!$d || !$f) return null;

    if ($d <= $f) {
        $active = $jour >= $d && $jour <= $f;
        $du = $d; $au = $f;
        if (!$active && $jour > $f) { // prochaine fermeture l'an prochain
            $du = date_borne_regle($debut, $annee + 1) ?? $d;
            $au = date_borne_regle($fin, $annee + 1) ?? $f;
        }
    } elseif ($jour <= $f) { // période à cheval, partie de début d'année
        $active = true;
        $du = date_borne_regle($debut, $annee - 1) ?? $d; $au = $f;
    } else {
        $active = $jour >= $d;
        $du = $d; $au = date_borne_regle($fin, $annee + 1) ?? $f;
    }
    return ['active' => $active, 'du' => $du->format('d/m/Y'), 'au' => $au->format('d/m/Y')];
}

/**
 * Décrit le lieu et le moment de la pêche.
 * @param array $s clés possibles : id_type_spot, categorie, id_type_session, mer (bool), lat, lng, date (Y-m-d), departement
 */
function contexte_reglementaire(PDO $pdo, array $s): array
{
    $lat = isset($s['lat']) && $s['lat'] !== null ? (float) $s['lat'] : null;
    $lng = isset($s['lng']) && $s['lng'] !== null ? (float) $s['lng'] : null;

    $milieu = milieu_type_spot($s['id_type_spot'] ?? null)
        ?? (!empty($s['mer']) ? 'mer' : null)
        ?? milieu_type_session($s['id_type_session'] ?? null);
    $milieux = match ($milieu) {
        'mer' => ['mer'],
        'eau_douce' => ['eau_douce'],
        default => ['mer', 'eau_douce'], // estuaire ou inconnu : les règles de l'espèce décident
    };

    $categorie = in_array((int) ($s['categorie'] ?? 0), [1, 2], true) ? (int) $s['categorie'] : null;
    $departement = $s['departement'] ?? null;
    if ($departement === null && $lat !== null) $departement = departement_coords($lat, $lng);
    $date = $s['date'] ?? date('Y-m-d');

    $libelle = [];
    if ($milieu) $libelle[] = MILIEUX[$milieu];
    if (in_array('eau_douce', $milieux, true) && $milieu !== 'mer') {
        $libelle[] = $categorie ? ($categorie === 1 ? '1re catégorie' : '2e catégorie') : 'catégorie piscicole inconnue';
    }
    if (in_array('mer', $milieux, true) && $lat !== null && $milieu !== 'eau_douce') $libelle[] = FACADES[facade_maritime($lat, $lng)];
    if ($departement) $libelle[] = 'département ' . $departement;

    return [
        'milieu' => $milieu,
        'milieux' => $milieux,
        'categorie' => $categorie,
        'facade' => facade_maritime($lat, $lng),
        'lat' => $lat,
        'lng' => $lng,
        'departement' => $departement,
        'date' => $date,
        'reserves' => reserves_au_point($pdo, $lat, $lng, $date),
        'libelle' => implode(' · ', $libelle) ?: 'Lieu non précisé',
    ];
}

/** Clé de quota d'une règle (quota partagé ou propre à l'espèce). */
function cle_quota(array $r): string
{
    return $r['GROUPE_QUOTA'] ? 'groupe:' . $r['GROUPE_QUOTA'] : 'espece:' . (int) $r['ID_ESPECE'];
}

/**
 * Règles applicables au contexte, prêtes à l'emploi (fermetures résolues pour la date).
 * Chaque règle peut être « conditionnelle » quand une information manque
 * (catégorie inconnue, pas de GPS) : elle s'affiche alors comme un avertissement.
 */
function regles_resolues(PDO $pdo, array $ctx): array
{
    try {
        $place = implode(',', array_fill(0, count($ctx['milieux']), '?'));
        $stmt = $pdo->prepare("
            SELECT r.*, e.NOM_COM FROM reglementation r
            LEFT JOIN espece e ON e.ID_ESPECE = r.ID_ESPECE
            WHERE r.ACTIF = 1 AND r.MILIEU IN ($place)
            ORDER BY r.CODE_DEPARTEMENT IS NULL, r.ID_REGLE
        ");
        $stmt->execute($ctx['milieux']);
        $lignes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return []; // table absente : pas d'alerte plutôt qu'une page en erreur
    }

    $regles = [];
    $remplace = []; // [milieu|espece|type] couverts par une règle départementale
    foreach ($lignes as $r) {
        // Règle départementale : seulement dans ce département ; elle remplace la règle nationale du même type
        if ($r['CODE_DEPARTEMENT'] !== null && $r['CODE_DEPARTEMENT'] !== $ctx['departement']) continue;
        $types = [];
        if ($r['TAILLE_MIN_CM'] !== null) $types[] = 'taille';
        if ($r['QUOTA_JOUR'] !== null) $types[] = 'quota:' . cle_quota($r);
        if ($r['FERMETURE_DEBUT'] !== null) $types[] = 'fermeture';
        $base = $r['MILIEU'] . '|' . ($r['ID_ESPECE'] ?? '*') . '|';
        if ($r['CODE_DEPARTEMENT'] !== null) {
            foreach ($types as $t) $remplace[$base . $t] = true;
        } else {
            $types = array_values(array_filter($types, fn($t) => !isset($remplace[$base . $t])));
            if (!$types && ($r['TAILLE_MIN_CM'] !== null || $r['QUOTA_JOUR'] !== null || $r['FERMETURE_DEBUT'] !== null)) continue;
        }

        // Conditions de zone : remplies, non remplies, ou inconnues (→ règle conditionnelle)
        $conditions = [];
        if ($r['MILIEU'] === 'eau_douce' && $r['CATEGORIE_PISCICOLE'] !== null) {
            if ($ctx['categorie'] === null) $conditions[] = 'en ' . ((int) $r['CATEGORIE_PISCICOLE'] === 1 ? '1re' : '2e') . ' catégorie';
            elseif ((int) $r['CATEGORIE_PISCICOLE'] !== $ctx['categorie']) continue;
        }
        if ($r['MILIEU'] === 'mer' && $r['FACADE'] !== null) {
            if ($ctx['lat'] === null) $conditions[] = 'en ' . FACADES[$r['FACADE']];
            elseif ($r['FACADE'] !== $ctx['facade']) continue;
        }
        if ($r['LAT_MIN'] !== null || $r['LAT_MAX'] !== null) {
            if ($ctx['lat'] === null) {
                $conditions[] = $r['LAT_MIN'] !== null ? 'au nord du 48e parallèle' : 'au sud du 48e parallèle';
            } elseif (($r['LAT_MIN'] !== null && $ctx['lat'] < (float) $r['LAT_MIN']) || ($r['LAT_MAX'] !== null && $ctx['lat'] >= (float) $r['LAT_MAX'])) {
                continue;
            }
        }
        // Milieu inconnu (ou estuaire) : les habitats de l'espèce disent si la règle la concerne sûrement
        if (count($ctx['milieux']) > 1) {
            if ($r['ID_ESPECE'] === null) continue; // règle générale d'eau douce : impossible de savoir
            $habitats = milieux_des_especes($pdo)[(int) $r['ID_ESPECE']] ?? ['mer' => true, 'eau_douce' => true];
            if (!empty($habitats['mer']) && !empty($habitats['eau_douce'])) {
                $conditions[] = $r['MILIEU'] === 'mer' ? 'en mer' : 'en eau douce';
            } elseif (empty($habitats[$r['MILIEU']])) {
                continue;
            }
        }

        $fermeture = fermeture_a_la_date($r['FERMETURE_DEBUT'], $r['FERMETURE_FIN'], $ctx['date']);
        $regles[] = [
            'id' => (int) $r['ID_REGLE'],
            'espece' => $r['ID_ESPECE'] !== null ? (int) $r['ID_ESPECE'] : null,
            'nom_espece' => $r['NOM_COM'],
            'milieu' => $r['MILIEU'],
            'taille_min' => $r['TAILLE_MIN_CM'] !== null && in_array('taille', $types, true) ? (int) $r['TAILLE_MIN_CM'] : null,
            'quota' => $r['QUOTA_JOUR'] !== null && in_array('quota:' . cle_quota($r), $types, true) ? (int) $r['QUOTA_JOUR'] : null,
            'cle_quota' => cle_quota($r),
            'groupe' => $r['GROUPE_QUOTA'],
            'fermee' => $fermeture && in_array('fermeture', $types, true) ? $fermeture['active'] : false,
            'periode' => $fermeture ? (($r['FERMETURE_DEBUT'] === '01-01' && $r['FERMETURE_FIN'] === '12-31') ? "toute l'année" : 'du ' . $fermeture['du'] . ' au ' . $fermeture['au']) : null,
            'nature' => $r['FERMETURE_NATURE'],
            'marquage' => (bool) $r['MARQUAGE'],
            'declaration' => (bool) $r['DECLARATION'],
            'note' => $r['NOTE'],
            'source' => $r['SOURCE'],
            'condition' => $conditions ? implode(', ', $conditions) : null,
        ];
    }
    return $regles;
}

/**
 * Poissons déjà gardés le même jour par l'utilisateur, par espèce (pour les quotas).
 * $exclure_prise : la prise en cours de modification ; $exclure_session : la session en cours de saisie.
 */
function gardes_du_jour(PDO $pdo, int $id_user, string $date, ?int $exclure_prise = null, ?int $exclure_session = null): array
{
    $stmt = $pdo->prepare("
        SELECT p.ID_ESPECE, SUM(p.QUANTITE) AS NB FROM prise p
        JOIN session_p s ON s.ID_SESSION = p.ID_SESSION
        WHERE s.ID_UTILISATEUR = ? AND p.RELACHE = 0 AND DATE(COALESCE(p.DATE_HEURE, s.DATE_DEBUT)) = ?
          AND p.ID_PRISE <> ? AND s.ID_SESSION <> ?
        GROUP BY p.ID_ESPECE
    ");
    $stmt->execute([$id_user, $date, $exclure_prise ?? 0, $exclure_session ?? 0]);
    $resultat = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $l) $resultat[(int) $l['ID_ESPECE']] = (int) $l['NB'];
    return $resultat;
}

/** Total gardé pour une clé de quota, à partir des gardés par espèce. */
function total_quota(array $regles, string $cle, array $gardes_par_espece): int
{
    $especes = [];
    foreach ($regles as $r) {
        if ($r['cle_quota'] === $cle && $r['espece'] !== null) $especes[$r['espece']] = true;
    }
    if (str_starts_with($cle, 'espece:')) $especes[(int) substr($cle, 7)] = true;
    $total = 0;
    foreach (array_keys($especes) as $id) $total += $gardes_par_espece[$id] ?? 0;
    return $total;
}

/**
 * Alertes d'une prise.
 * @param array $prise ['espece' => int, 'taille' => ?float, 'quantite' => int, 'relache' => bool]
 * @param array $gardes gardés par espèce AVANT cette prise (mis à jour par l'appelant après coup)
 * @return array liste de ['niveau' => danger|warning|info, 'texte' => string, 'bloquant' => bool]
 */
function controler_prise(array $regles, array $ctx, array $prise, array $gardes): array
{
    $alertes = [];
    $garde = empty($prise['relache']);
    $quantite = max(1, (int) ($prise['quantite'] ?? 1));
    $quotas_vus = [];

    foreach ($ctx['reserves'] as $res) {
        $alertes[] = [
            'niveau' => $garde ? 'danger' : 'warning',
            'texte' => 'Zone de réserve « ' . $res['nom'] . ' » : ' . $res['regle'],
            'bloquant' => $garde,
        ];
    }

    foreach ($regles as $r) {
        if ($r['espece'] !== null && $r['espece'] !== (int) $prise['espece']) continue;
        $si = $r['condition'] ? 'Si tu pêches ' . $r['condition'] . ' : ' : '';
        $Nom = $r['nom_espece'] ?? 'Toutes espèces';
        $certain = $r['condition'] === null;
        $nom = $r['nom_espece'] ? mb_strtolower($r['nom_espece']) : 'cette espèce';

        if ($r['fermee']) {
            if ($r['nature'] === 'no_kill') {
                if ($garde) $alertes[] = ['niveau' => $certain ? 'danger' : 'warning', 'texte' => $si . "$Nom : remise à l'eau obligatoire " . $r['periode'] . '.', 'bloquant' => $certain];
            } else {
                $alertes[] = [
                    'niveau' => $garde && $certain ? 'danger' : 'warning',
                    'texte' => $si . ($r['espece'] === null ? 'Pêche fermée ' : "$Nom : pêche interdite ") . $r['periode'] . '.' . ($r['note'] && $r['espece'] === null ? ' ' . $r['note'] : '') . ($garde ? '' : " Tu l'as relâché : c'est la bonne réaction."),
                    'bloquant' => $garde && $certain,
                ];
            }
        }

        if ($garde && $r['taille_min'] !== null) {
            $taille = $prise['taille'] ?? null;
            if ($taille === null || $taille === '') {
                $alertes[] = ['niveau' => 'info', 'texte' => $si . "$Nom : taille minimale {$r['taille_min']} cm. Mesure-le avant de le garder.", 'bloquant' => false];
            } elseif ((float) $taille < $r['taille_min']) {
                $alertes[] = ['niveau' => $certain ? 'danger' : 'warning', 'texte' => $si . "$Nom sous la taille légale : " . (float) $taille . " cm pour {$r['taille_min']} cm minimum.", 'bloquant' => $certain];
            }
        }

        if ($garde && $r['quota'] !== null && !isset($quotas_vus[$r['cle_quota']])) {
            $quotas_vus[$r['cle_quota']] = true;
            $total = total_quota($regles, $r['cle_quota'], $gardes) + $quantite;
            if ($total > $r['quota']) {
                $quoi = $r['groupe'] ? 'des ' . $r['groupe'] : "de $nom";
                $alertes[] = ['niveau' => $certain ? 'danger' : 'warning', 'texte' => $si . "Quota $quoi dépassé : $total gardés aujourd'hui pour {$r['quota']} autorisés par jour.", 'bloquant' => $certain];
            }
        }

        if ($garde && $r['marquage'] && $certain) {
            $alertes[] = ['niveau' => 'info', 'texte' => "Marquage obligatoire de $nom : coupe la partie inférieure de la nageoire caudale.", 'bloquant' => false];
        }
        if ($garde && $r['declaration'] && $certain) {
            $alertes[] = ['niveau' => 'info', 'texte' => "Déclaration numérique de la capture obligatoire sous 24 h (application RecFishing).", 'bloquant' => false];
        }
        if ($r['note'] && $r['taille_min'] === null && $r['quota'] === null && $r['periode'] === null && !$r['marquage']) {
            $alertes[] = ['niveau' => 'info', 'texte' => $si . $r['note'], 'bloquant' => false];
        }
    }

    // Doublons possibles (plusieurs règles qui disent la même chose)
    $uniques = [];
    foreach ($alertes as $a) $uniques[$a['texte']] = $a;
    return array_values($uniques);
}

/**
 * Contrôle serveur d'une liste de prises (dans l'ordre de saisie, les quotas se cumulent).
 * @return array index de la prise => alertes bloquantes non confirmées
 */
function prises_non_conformes(array $regles, array $ctx, array $prises, array $gardes): array
{
    $problemes = [];
    foreach ($prises as $i => $p) {
        $bloquantes = array_filter(controler_prise($regles, $ctx, $p, $gardes), fn($a) => $a['bloquant']);
        if ($bloquantes && empty($p['confirme'])) $problemes[$i] = array_values($bloquantes);
        if (empty($p['relache'])) $gardes[(int) $p['espece']] = ($gardes[(int) $p['espece']] ?? 0) + max(1, (int) $p['quantite']);
    }
    return $problemes;
}

/**
 * Milieux de vie de chaque espèce d'après la table habiter (habitats 1-9 eau douce, 10 estuaire, 11-18 mer).
 * @return array id_espece => ['mer' => bool, 'eau_douce' => bool]
 */
function milieux_des_especes(PDO $pdo): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    foreach ($pdo->query("SELECT ID_ESPECE, ID_HABIT FROM habiter")->fetchAll(PDO::FETCH_ASSOC) as $h) {
        $id = (int) $h['ID_ESPECE'];
        $hab = (int) $h['ID_HABIT'];
        $cache[$id] ??= ['mer' => false, 'eau_douce' => false];
        if ($hab <= 10) $cache[$id]['eau_douce'] = true;
        if ($hab >= 10) $cache[$id]['mer'] = true;
    }
    return $cache;
}

/**
 * Contexte réglementaire d'une session déjà enregistrée (ajout ou modification d'une prise).
 * La position retenue est celle de la prise si elle en a une, sinon celle du spot.
 */
function contexte_session_existante(PDO $pdo, int $id_session, ?float $lat_prise = null, ?float $lng_prise = null): array
{
    $stmt = $pdo->prepare("
        SELECT s.DATE_DEBUT, s.ID_TYPE_SESSION, s.MILIEU, s.CODE_DEPARTEMENT,
               sp.ID_TYPE_SPOT, sp.CATEGORIE_PISCICOLE, sp.LOCALISATION, sp.CODE_DEPARTEMENT AS DEP_SPOT
        FROM session_p s LEFT JOIN spot sp ON sp.ID_SPOT = s.ID_SPOT
        WHERE s.ID_SESSION = ?
    ");
    $stmt->execute([$id_session]);
    $s = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $coords = lire_localisation($s['LOCALISATION'] ?? null);
    $lat = $lat_prise ?? ($coords[0] ?? null);
    $lng = $lng_prise ?? ($coords[1] ?? null);
    return contexte_reglementaire($pdo, [
        'id_type_spot' => isset($s['ID_TYPE_SPOT']) ? (int) $s['ID_TYPE_SPOT'] : null,
        'categorie' => $s['CATEGORIE_PISCICOLE'] ?? null,
        'id_type_session' => isset($s['ID_TYPE_SESSION']) ? (int) $s['ID_TYPE_SESSION'] : null,
        'mer' => ($s['MILIEU'] ?? null) === 'mer',
        'lat' => $lat,
        'lng' => $lng,
        'date' => substr($s['DATE_DEBUT'] ?? date('Y-m-d'), 0, 10),
        'departement' => ($lat_prise === null ? ($s['DEP_SPOT'] ?? $s['CODE_DEPARTEMENT'] ?? null) : null),
    ]);
}

/** Données du garde-fou à injecter dans la page (JSON pour script/reglementation.js). */
function donnees_garde_fou(array $ctx, array $regles, array $gardes): array
{
    return [
        'regles' => $regles,
        'contexte' => ['reserves' => $ctx['reserves'], 'libelle' => $ctx['libelle']],
        'gardes' => (object) $gardes,
    ];
}
