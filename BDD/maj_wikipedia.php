<?php
/**
 * Script de maintenance : complète les descriptions manquantes du Codex depuis Wikipedia.
 * À lancer en ligne de commande depuis la racine du projet :
 *     php BDD/maj_wikipedia.php
 * (Il n'est plus accessible depuis le navigateur : n'importe quel visiteur pouvait le déclencher.)
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Script réservé à la ligne de commande.');
}
chdir(__DIR__ . '/..');
require_once __DIR__ . '/../includes/bootstrap.php';

$pdo = db();

// 1. Uniquement les espèces sans description
$especes = $pdo->query("SELECT ID_ESPECE, NOM_COM, NOM_SCIEN FROM espece WHERE DESCRIPTION IS NULL OR DESCRIPTION = ''")->fetchAll(PDO::FETCH_ASSOC);
echo "Mise à jour encyclopédique via Wikipedia\n";
if (count($especes) === 0) {
    exit("Toutes les espèces ont déjà une description.\n");
}

function fetchWikipediaDescription(string $titre): ?string
{
    // redirects=1 (suit les redirections) et exintro=1 (résumé uniquement)
    $url_api = "https://fr.wikipedia.org/w/api.php?action=query&prop=extracts&exintro=1&explaintext=1&redirects=1&titles=" . urlencode($titre) . "&format=json";
    $json = http_get($url_api);
    if (!$json) return null;
    $data = json_decode($json, true);
    $page = isset($data['query']['pages']) ? reset($data['query']['pages']) : null;
    $extrait = trim((string) ($page['extract'] ?? ''));
    return $extrait !== '' ? preg_replace("/\n{3,}/", "\n\n", $extrait) : null;
}

$update = $pdo->prepare("UPDATE espece SET DESCRIPTION = :desc WHERE ID_ESPECE = :id");
foreach ($especes as $espece) {
    // Nom scientifique d'abord (plus précis), puis nom commun
    $description = fetchWikipediaDescription($espece['NOM_SCIEN']) ?? fetchWikipediaDescription($espece['NOM_COM']);
    if ($description) {
        $update->execute(['desc' => $description, 'id' => $espece['ID_ESPECE']]);
        echo "[OK] {$espece['NOM_COM']}\n";
    } else {
        echo "[--] {$espece['NOM_COM']} : page introuvable ou vide\n";
    }
    usleep(200000); // politesse envers l'API Wikipedia
}
echo "Terminé.\n";
