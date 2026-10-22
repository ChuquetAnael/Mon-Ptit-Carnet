<?php
/**
 * Aides communes à la saisie des prises (nouvelle session, ajout / modification d'une prise).
 */
require_once __DIR__ . '/biologie.php';

const ESPECES_FAVORITES_MAX = 6;

/**
 * Espèces les plus souvent capturées par le pêcheur (nombre de poissons), pour l'accès rapide
 * en haut du sélecteur d'espèces. En cas d'égalité, la plus récente passe devant.
 * @return int[] identifiants d'espèces
 */
function especes_favorites(PDO $pdo, int $id_user, int $max = ESPECES_FAVORITES_MAX): array
{
    $stmt = $pdo->prepare("
        SELECT p.ID_ESPECE
        FROM prise p
        JOIN session_p s ON s.ID_SESSION = p.ID_SESSION
        WHERE s.ID_UTILISATEUR = ?
        GROUP BY p.ID_ESPECE
        ORDER BY SUM(COALESCE(p.QUANTITE, 1)) DESC, MAX(s.DATE_DEBUT) DESC
        LIMIT " . max(1, min(20, $max)));
    $stmt->execute([$id_user]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}
