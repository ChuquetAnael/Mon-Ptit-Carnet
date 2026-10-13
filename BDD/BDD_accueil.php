<?php
/**
 * Supprime les photos présentes sur le disque mais référencées nulle part en base
 * (ex : session abandonnée entre l'étape 1 et l'étape 3).
 */
function nettoyerImagesOrphelines($pdo, $id_user) {
    $dossier = 'uploads/user_' . (int) $id_user . '/';
    if (!is_dir($dossier)) return;

    // 1. Fichiers physiques du dossier de l'utilisateur
    $fichiers_physiques = glob($dossier . "*.jpg") ?: [];

    // 2. Toutes les images de cet utilisateur référencées en base
    $stmt = $pdo->prepare("
        SELECT PDP_CHEMIN AS chemin FROM utilisateur WHERE ID_UTILISATEUR = ? AND PDP_CHEMIN IS NOT NULL
        UNION
        SELECT BANNIERE_CHEMIN AS chemin FROM utilisateur WHERE ID_UTILISATEUR = ? AND BANNIERE_CHEMIN IS NOT NULL
        UNION
        SELECT ph.CHEMIN AS chemin FROM photo_prise ph
        JOIN prise p ON ph.ID_PRISE = p.ID_PRISE
        JOIN session_p s ON p.ID_SESSION = s.ID_SESSION
        WHERE s.ID_UTILISATEUR = ?
    ");
    $stmt->execute([$id_user, $id_user, $id_user]);
    $fichiers_bdd = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));

    // Photos envoyées pour une session encore en cours de saisie : à conserver
    foreach ($_SESSION['nouvelle_session']['photos'] ?? [] as $photo) {
        $fichiers_bdd[$photo['chemin']] = true;
    }

    // 3. Comparer et supprimer (sécurité : fichiers de plus de 2 heures uniquement)
    foreach ($fichiers_physiques as $fichier) {
        if (!isset($fichiers_bdd[$fichier]) && filemtime($fichier) < (time() - 7200)) {
            unlink($fichier);
        }
    }
}
