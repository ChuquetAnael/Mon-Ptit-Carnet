<?php
require_once './includes/bootstrap.php';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Conditions Générales d'Utilisation - Mon Carnet de Pêche</title>
    
    <?php include './includes/head.php'; ?>
    
</head>
<body class="bg-light pb-5">

    <!-- En-tête avec bouton retour -->
    <header class="custom-header text-white text-center py-4 shadow-sm mb-4 position-relative">
        <a href="parametre.php" class="text-white position-absolute start-0 translate-middle-y ms-3 text-decoration-none" style="top: 50%;">
            <span class="material-symbols-rounded">arrow_back_ios_new</span>
        </a>
        <h1 class="h4 mb-0 fw-semibold">Conditions d'Utilisation</h1>
    </header>

    <main class="container mb-5">
        
        <p class="text-muted text-center small mb-4">En vigueur au <?= date('d/m/Y') ?></p>

        <!-- 1. Accès et Inscription -->
        <div class="card border-0 shadow-sm rounded-4 p-4 mb-4 bg-white">
            <h2 class="h5 fw-bold text-dark d-flex align-items-center mb-3">
                <span class="material-symbols-rounded text-primary me-2">login</span> 1. Accès et Inscription
            </h2>
            <p class="text-secondary small text-justify">
                L'application "Mon P'tit Carnet" est accessible gratuitement à tout utilisateur disposant d'un accès à Internet. L'éditeur se réserve le droit de proposer ultérieurement des fonctionnalités supplémentaires sous forme d'abonnement optionnel.<br><br>
                <strong>Mineurs :</strong> L'inscription et l'utilisation de l'application par des mineurs sont autorisées. Néanmoins, il est rappelé que l'utilisation de la plateforme par un mineur se fait sous l'entière responsabilité et avec l'autorisation de ses parents ou représentants légaux.
            </p>
        </div>

        <!-- 2. Le secret des spots de pêche -->
        <div class="card border-0 shadow-sm rounded-4 p-4 mb-4 bg-white">
            <h2 class="h5 fw-bold text-dark d-flex align-items-center mb-3">
                <span class="material-symbols-rounded text-primary me-2">visibility_off</span> 2. Confidentialité absolue des Spots
            </h2>
            <p class="text-secondary small text-justify">
                Nous savons à quel point le secret d'un bon coin de pêche est précieux. Il est ici expressément garanti à l'utilisateur que <strong>les coordonnées GPS exactes de ses spots et de ses prises sont strictement privées</strong>. <br><br>
                Même dans l'éventualité du déploiement de fonctionnalités publiques (comme un fil d'actualité ou des classements), la localisation précise de vos captures ne sera jamais divulguée publiquement sans votre action explicite.
            </p>
        </div>

        <!-- 3. Photos et Droits d'auteur -->
        <div class="card border-0 shadow-sm rounded-4 p-4 mb-4 bg-white">
            <h2 class="h5 fw-bold text-dark d-flex align-items-center mb-3">
                <span class="material-symbols-rounded text-primary me-2">photo_camera</span> 3. Contenus et Droits sur les Images
            </h2>
            <p class="text-secondary small text-justify">
                L'utilisateur conserve <strong>l'intégralité des droits de propriété intellectuelle</strong> sur les photographies (profil, bannières, poissons) qu'il téléverse sur la plateforme.<br><br>
                Toutefois, en utilisant le service, l'utilisateur concède à l'éditeur de "Mon P'tit Carnet" une licence non-exclusive et gratuite lui permettant d'utiliser occasionnellement ces images dans un but strictement promotionnel (par exemple : captures d'écran pour illustrer l'application sur un store, les réseaux sociaux ou une page de présentation).
            </p>
        </div>

        <!-- 4. Modération et Signalement -->
        <div class="card border-0 shadow-sm rounded-4 p-4 mb-4 bg-white">
            <h2 class="h5 fw-bold text-dark d-flex align-items-center mb-3">
                <span class="material-symbols-rounded text-primary me-2">gavel</span> 4. Règles de conduite et Modération
            </h2>
            <p class="text-secondary small text-justify">
                L'utilisateur s'engage à ne publier aucun contenu à caractère pornographique, violent, discriminatoire, illicite ou portant atteinte à la dignité animale et humaine. <br><br>
                La plateforme est soumise à une <strong>modération a posteriori</strong>. Un système de signalement permet à la communauté de remonter les contenus inappropriés. L'éditeur se réserve le droit souverain de supprimer toute photo, session, ou compte utilisateur enfreignant ces règles, et ce sans préavis ni justification.
            </p>
        </div>

        <!-- 5. Responsabilité et Sécurité -->
        <div class="card border-0 shadow-sm rounded-4 p-4 mb-4 bg-white">
            <h2 class="h5 fw-bold text-dark d-flex align-items-center mb-3">
                <span class="material-symbols-rounded text-primary me-2">warning</span> 5. Avertissements et Responsabilités
            </h2>
            <p class="text-secondary small text-justify">
                <strong>Données fournies :</strong> Les données météorologiques (Open-Meteo), les marées (api-maree.fr), les débits et hauteurs d'eau (Hub'eau, Vigicrues) et les coordonnées de géolocalisation sont fournies à titre purement indicatif et peuvent comporter des erreurs.<br><br>
                <strong>Sécurité :</strong> "Mon P'tit Carnet" est un outil d'archivage. L'utilisateur demeure le seul responsable de sa sécurité au bord de l'eau, du respect de la législation en vigueur concernant la pêche, et de la validation de ses droits de pêche (carte de pêche à jour, respect des tailles légales et des périodes de fermeture). L'éditeur décline toute responsabilité en cas d'accident ou d'infraction commise par un utilisateur.
            </p>
        </div>

    
        <!-- 6. Porte-carte de pêche -->
        <div class="card border-0 shadow-sm rounded-4 p-4 mb-4 bg-white">
            <h2 class="h5 fw-bold text-dark d-flex align-items-center mb-3">
                <span class="material-symbols-rounded text-primary me-2">badge</span> 6. Porte-carte de pêche
            </h2>
            <p class="text-secondary small text-justify">
                L'utilisateur peut téléverser une copie de sa carte de pêche (PDF ou photo) afin de l'afficher rapidement, y compris sans connexion. Cette copie est une <strong>donnée personnelle</strong> : elle est conservée dans un espace de stockage privé du serveur, inaccessible depuis Internet, et n'est affichée qu'à son titulaire une fois connecté. Elle n'est jamais publiée, partagée ni utilisée à d'autres fins.<br><br>
                Pour fonctionner hors-ligne, une copie est également enregistrée sur l'appareil de l'utilisateur (cache de l'application). Elle est effacée lors de la déconnexion. L'utilisateur doit donc se déconnecter s'il prête ou cède son téléphone.<br><br>
                La copie est conservée tant que l'utilisateur la garde : elle est supprimée définitivement lorsqu'il la remplace, la supprime ou supprime son compte.<br><br>
                L'affichage dans l'application est une copie de confort. Il ne remplace pas le document délivré par la fédération départementale ou le service officiel. L'utilisateur reste responsable de la validité de sa carte, de sa présentation en cas de contrôle et de l'exactitude des informations qu'il saisit (date de validité, AAPPMA). En cas de doute de l'agent de contrôle, seul le document officiel fait foi.
            </p>
        </div>

        <!-- 7. Alertes crues et sécurité -->
        <div class="card border-0 shadow-sm rounded-4 p-4 mb-4 bg-white">
            <h2 class="h5 fw-bold text-dark d-flex align-items-center mb-3">
                <span class="material-symbols-rounded text-primary me-2">flood</span> 7. Alertes de crue et de montée des eaux
            </h2>
            <p class="text-secondary small text-justify">
                L'application peut signaler une montée rapide du niveau ou du débit d'une rivière, à partir des mesures publiques Hub'eau et des niveaux de vigilance Vigicrues. Ces alertes sont <strong>fournies à titre indicatif</strong> et peuvent être absentes, retardées ou erronées : une station peut être en panne, éloignée du lieu de pêche ou ne pas mesurer un lâcher de barrage en amont, et la vérification n'a lieu que lorsque l'application est ouverte et connectée.<br><br>
                L'absence d'alerte ne signifie jamais l'absence de danger. Ces informations ne remplacent ni le site officiel <a href="https://www.vigicrues.gouv.fr/" target="_blank" rel="noopener">vigicrues.gouv.fr</a>, ni les panneaux et sirènes des exploitants de barrages, ni les consignes des autorités. L'utilisateur reste seul responsable de sa sécurité et de celle des personnes qui l'accompagnent. L'éditeur ne peut être tenu responsable d'un dommage lié à une alerte manquante, tardive ou inexacte.
            </p>
        </div>

        <!-- 8. Informations réglementaires -->
        <div class="card border-0 shadow-sm rounded-4 p-4 mb-4 bg-white">
            <h2 class="h5 fw-bold text-dark d-flex align-items-center mb-3">
                <span class="material-symbols-rounded text-primary me-2">gavel</span> 8. Informations réglementaires
            </h2>
            <p class="text-secondary small text-justify">
                Les tailles minimales, quotas, périodes de fermeture et zones de réserve affichés lors de la saisie d'une prise sont une <strong>aide indicative</strong>, construite à partir des textes nationaux et européens et de quelques règles locales. Ils peuvent être incomplets ou ne pas être à jour : les arrêtés préfectoraux, les règlements des AAPPMA et les décisions européennes changent chaque année et diffèrent d'un département à l'autre.<br><br>
                Seuls les textes officiels font foi. Il appartient au pêcheur de connaître et de respecter la réglementation du lieu où il pêche. Une absence d'alerte ne vaut pas autorisation, et la confirmation d'une alerte par l'utilisateur relève de sa seule responsabilité. L'éditeur décline toute responsabilité en cas d'infraction.
            </p>
        </div>

        <!-- 9. Conditions gagnantes et statistiques communautaires -->
        <div class="card border-0 shadow-sm rounded-4 p-4 mb-4 bg-white">
            <h2 class="h5 fw-bold text-dark d-flex align-items-center mb-3">
                <span class="material-symbols-rounded text-primary me-2">insights</span> 9. Conditions gagnantes et statistiques communautaires
            </h2>
            <p class="text-secondary small text-justify">
                Les prévisions « Conditions gagnantes » comparent les prévisions météorologiques aux conditions des sorties de l'utilisateur. Elles sont calculées pour lui seul et constituent une estimation, sans garantie de résultat.<br><br>
                Le mode « Communauté » présente des statistiques construites à partir des prises de l'ensemble des membres, <strong>sous forme anonymisée et agrégée</strong> : seuls l'espèce, les conditions (pression, vent, marée, débit, heure, saison) et une zone large (façade maritime, département, cours d'eau ou milieu) sont utilisés. Les coordonnées des spots et des prises, leurs noms, les photos et l'identité des membres ne sont jamais utilisés ni affichés. Un résultat n'apparaît qu'à partir de 10 prises provenant d'au moins 3 pêcheurs différents, afin qu'aucune habitude individuelle ne puisse être déduite.<br><br>
                Chaque membre peut retirer ses prises de ces statistiques à tout moment depuis <a href="parametre.php">Paramètres &gt; Statistiques communautaires</a>.
            </p>
        </div>
</main>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>