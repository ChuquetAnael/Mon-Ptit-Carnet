<?php
require_once './bdd/env.php';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // 1. On sélectionne UNIQUEMENT les espèces qui n'ont pas encore de description
    $stmt = $pdo->query("SELECT ID_ESPECE, NOM_COM, NOM_SCIEN FROM ESPECE WHERE DESCRIPTION IS NULL OR DESCRIPTION = ''");
    $especes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<h1>Mise à jour encyclopédique via Wikipedia</h1>";
    
    if (count($especes) === 0) {
        echo "<p>Toutes vos espèces ont déjà une description ! <a href='codex.php'>Retour au Codex</a></p>";
        exit();
    }
    
    echo "<ul>";

    // 2. On définit l'identité du script (User-Agent obligatoire)
    $options = [
        "http" => [
            "method" => "GET",
            "header" => "User-Agent: MonCarnetDePecheBot/1.1 (alex@test.fr)\r\n"
        ]
    ];
    $contexte = stream_context_create($options);

    // 3. Fonction dédiée pour interroger Wikipedia
    function fetchWikipediaDescription($titre, $contexte) {
        // Ajout de redirects=1 (très important) et exintro=1 (résumé uniquement)
        $url_api = "https://fr.wikipedia.org/w/api.php?action=query&prop=extracts&exintro=1&explaintext=1&redirects=1&titles=" . urlencode($titre) . "&format=json";
        
        $json_response = @file_get_contents($url_api, false, $contexte);
        
        if ($json_response) {
            $data = json_decode($json_response, true);
            if (isset($data['query']['pages'])) {
                $page = reset($data['query']['pages']);
                // On vérifie si l'extrait existe et n'est pas vide
                if (isset($page['extract']) && !empty(trim($page['extract']))) {
                    return trim($page['extract']);
                }
            }
        }
        return false;
    }

    // 4. Boucle de traitement
    foreach ($especes as $espece) {
        // On tente d'abord avec le nom scientifique (plus précis)
        $description = fetchWikipediaDescription($espece['NOM_SCIEN'], $contexte);
        
        // Si ça échoue, on tente avec le nom commun (le Plan B)
        if (!$description) {
            $description = fetchWikipediaDescription($espece['NOM_COM'], $contexte);
        }
        
        if ($description) {
            // Nettoyage des sauts de ligne intempestifs de Wikipedia
            $description = preg_replace("/\n{3,}/", "\n\n", $description);
            
            // Mise à jour dans la base
            $update = $pdo->prepare("UPDATE ESPECE SET DESCRIPTION = :desc WHERE ID_ESPECE = :id");
            $update->execute([
                'desc' => $description,
                'id' => $espece['ID_ESPECE']
            ]);
            
            echo "<li>✅ <strong>" . htmlspecialchars($espece['NOM_COM']) . "</strong> : Description récupérée et sauvegardée.</li>";
        } else {
            echo "<li>❌ <strong style='color:red;'>" . htmlspecialchars($espece['NOM_COM']) . "</strong> : Page introuvable ou vide sur Wikipedia (Nom scientifique et commun testés).</li>";
        }
    }
    
    echo "</ul>";
    echo "<p>Mise à jour terminée ! <a href='codex.php'>Retour au Codex</a></p>";

} catch (PDOException $e) {
    die("Erreur de base de données : " . $e->getMessage());
}
?>