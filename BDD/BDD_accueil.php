<?php
function nettoyerImagesOrphelines($pdo, $id_user) {
    $dossier = 'uploads/user_' . $id_user . '/';
    
    // Si le dossier n'existe pas, on arrête
    if (!is_dir($dossier)) return;

    // 1. Lister tous les fichiers physiques présents dans le dossier de l'utilisateur
    $fichiers_physiques = glob($dossier . "*.jpg"); 

    // 2. Récupérer TOUTES les images de cet utilisateur validées dans la base de données
    // On combine les photos de profil, bannières, et les photos de prises
    $stmt = $pdo->prepare("
        SELECT PDP_CHEMIN AS chemin FROM UTILISATEUR WHERE ID_UTILISATEUR = ? AND PDP_CHEMIN IS NOT NULL
        UNION
        SELECT BANNIERE_CHEMIN AS chemin FROM UTILISATEUR WHERE ID_UTILISATEUR = ? AND BANNIERE_CHEMIN IS NOT NULL
        UNION
        SELECT p.PHOTO_CHEMIN AS chemin FROM PRISE p 
        JOIN SESSION_P s ON p.ID_SESSION = s.ID_SESSION 
        WHERE s.ID_UTILISATEUR = ? AND p.PHOTO_CHEMIN IS NOT NULL
    ");
    $stmt->execute([$id_user, $id_user, $id_user]);
    
    // On crée un tableau simple avec juste les chemins validés
    $fichiers_bdd = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // 3. Comparer et supprimer
    foreach ($fichiers_physiques as $fichier) {
        // Si le fichier physique n'est pas trouvé dans la liste de la base de données
        if (!in_array($fichier, $fichiers_bdd)) {
            // SÉCURITÉ : On vérifie que le fichier a plus de 2 heures
            // Cela évite de supprimer l'image d'un joueur qui est actuellement à l'Étape 2 !
            if (filemtime($fichier) < (time() - 7200)) {
                unlink($fichier);
            }
        }
    }
}
?>