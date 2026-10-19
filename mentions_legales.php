<?php
require_once './includes/bootstrap.php';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mentions Légales - Mon Carnet de Pêche</title>
    
    <?php include './includes/head.php'; ?>
</head>
<body class="bg-light pb-5">

    <!-- En-tête avec bouton retour -->
    <header class="custom-header text-white text-center py-4 shadow-sm mb-4 position-relative">
        <a href="parametre.php" class="text-white position-absolute start-0 translate-middle-y ms-3 text-decoration-none" style="top: 50%;">
            <span class="material-symbols-rounded">arrow_back_ios_new</span>
        </a>
        <h1 class="h4 mb-0 fw-semibold">Mentions Légales & RGPD</h1>
    </header>

    <main class="container mb-5">
        
        <p class="text-muted text-center small mb-4">En vigueur au <?= date('d/m/Y') ?></p>

        <!-- 1. Éditeur du site -->
        <div class="card border-0 shadow-sm rounded-4 p-4 mb-4 bg-white">
            <h2 class="h5 fw-bold text-dark d-flex align-items-center mb-3">
                <span class="material-symbols-rounded text-primary me-2">gavel</span> 1. Éditeur du site
            </h2>
            <p class="text-secondary small text-justify">
                Conformément aux dispositions de l'article 6-III-1 de la loi n° 2004-575 du 21 juin 2004 pour la confiance dans l'économie numérique (LCEN), il est précisé aux utilisateurs du site <strong>Mon P'tit Carnet</strong> l'identité des différents intervenants dans le cadre de sa réalisation et de son suivi.<br><br>
                Le présent site, accessible à l'URL <strong>[À REMPLIR : www.ton-nom-de-domaine.fr]</strong>, est édité par :<br><br>
                <strong>Nom / Prénom :</strong> CHUQUET Anaël<br>
                <strong>Adresse de contact :</strong> anaelchuquet04@gmail.com<br>
                <strong>Directeur de la publication :</strong> CHUQUET Anaël
            </p>
        </div>

        <!-- 2. Hébergement -->
        <div class="card border-0 shadow-sm rounded-4 p-4 mb-4 bg-white">
            <h2 class="h5 fw-bold text-dark d-flex align-items-center mb-3">
                <span class="material-symbols-rounded text-primary me-2">dns</span> 2. Hébergement
            </h2>
            <p class="text-secondary small text-justify">
                Le site est hébergé par la société <strong>[À REMPLIR : Nom de ton hébergeur, ex: OVH, o2switch, Hostinger]</strong>.<br>
                <strong>Adresse postale :</strong> [À REMPLIR : Adresse postale de l'hébergeur]<br>
                <strong>Téléphone :</strong> [À REMPLIR : Numéro de téléphone de l'hébergeur]<br>
                <strong>Site web :</strong> [À REMPLIR : Lien vers le site de l'hébergeur]
            </p>
        </div>

        <!-- 3. Propriété intellectuelle -->
        <div class="card border-0 shadow-sm rounded-4 p-4 mb-4 bg-white">
            <h2 class="h5 fw-bold text-dark d-flex align-items-center mb-3">
                <span class="material-symbols-rounded text-primary me-2">copyright</span> 3. Propriété intellectuelle et APIs
            </h2>
            <p class="text-secondary small text-justify">
                L'architecture du site, les textes, images animées ou non (à l'exception du contenu généré par les utilisateurs) sont la propriété exclusive de l'éditeur du site.<br><br>
                Ce site utilise des services tiers pour enrichir ses fonctionnalités :
            </p>
            <ul class="text-secondary small">
                <li><strong>Cartographie :</strong> Les fonds de carte sont fournis par <em>© OpenStreetMap contributors</em> et affichés via la bibliothèque <em>Leaflet.js</em>.</li>
                <li><strong>Météorologie :</strong> Les données météorologiques sont générées en temps réel et en archive par l'API gratuite <em>Open-Meteo</em>.</li>
                <li><strong>Codex :</strong> Les descriptions scientifiques des espèces de poissons proviennent de l'encyclopédie libre <em>Wikipedia / Wikimedia Commons</em>.</li>
                <li><strong>Rivières et crues :</strong> Débits et hauteurs d'eau issus de l'API <a href="https://hubeau.eaufrance.fr/" target="_blank" rel="noopener">Hub'eau</a> (OFB / Eaufrance) et niveaux de vigilance du service <a href="https://www.vigicrues.gouv.fr/" target="_blank" rel="noopener">Vigicrues</a> (ministère de la Transition écologique), réutilisés sous <a href="https://www.etalab.gouv.fr/licence-ouverte-open-licence/" target="_blank" rel="noopener">Licence Ouverte Etalab 2.0</a>.</li>
                <li><strong>Découpage administratif :</strong> Le département d'un spot est déterminé avec l'API <a href="https://geo.api.gouv.fr/" target="_blank" rel="noopener">geo.api.gouv.fr</a> (Licence Ouverte Etalab 2.0). Seules des coordonnées lui sont transmises, sans aucune donnée d'identification.</li>
                <li><strong>Lecture de la carte de pêche :</strong> L'aperçu des PDF et la lecture des QR codes se font dans le navigateur avec les bibliothèques libres <em>PDF.js</em> (Apache 2.0) et <em>jsQR</em> (Apache 2.0) ; le QR code est réaffiché avec <em>QR Code Generator</em> de Kazuhiko Arase (MIT).</li>
                <li><strong>Marées :</strong> Données de marée fournies par <a href="https://api-maree.fr/" target="_blank">api-maree.fr</a> sous licence <a href="https://creativecommons.org/licenses/by/4.0/deed.fr" target="_blank">CC BY</a>, calculées à partir de composantes harmoniques <a href="https://sextant.ifremer.fr/geonetwork/srv/api/records/1bc4a49d-d4cd-469e-827c-bd3bd0eeabba/formatters/xsl-view" target="_blank">Ifremer / PREVIMER</a>, elles-mêmes sous licence <a href="https://creativecommons.org/licenses/by/4.0/deed.fr" target="_blank">CC BY</a>.</li>
            </ul>
        </div>

        <!-- 4. Données personnelles et RGPD -->
        <div class="card border-0 shadow-sm rounded-4 p-4 mb-4 bg-white">
            <h2 class="h5 fw-bold text-dark d-flex align-items-center mb-3">
                <span class="material-symbols-rounded text-primary me-2">shield_person</span> 4. Protection des données (RGPD)
            </h2>
            <p class="text-secondary small text-justify">
                La création d'un compte sur "Mon P'tit Carnet" implique la collecte de certaines données personnelles, strictement limitées au bon fonctionnement de l'application :<br><br>
                - <strong>Adresse e-mail & Mot de passe (haché cryptographiquement) :</strong> Utilisés uniquement pour l'authentification et la sécurisation du compte.<br>
                - <strong>Données de géolocalisation & Photos :</strong> Les métadonnées (EXIF) des photos téléchargées servent exclusivement à alimenter votre carnet de pêche personnel. Ces données ne sont ni revendues, ni exploitées à des fins commerciales.<br>
                - <strong>Carte de pêche :</strong> La copie de votre carte (PDF ou photo), sa date de validité, votre AAPPMA et le contenu de son QR code sont conservés dans un espace privé du serveur, inaccessible depuis Internet et lisible par vous seul. Finalité : vous permettre de présenter votre carte lors d'un contrôle. Base légale : votre consentement, donné en téléversant la carte. Durée de conservation : jusqu'à son remplacement, sa suppression ou la suppression de votre compte. Une copie est aussi gardée sur votre appareil pour l'affichage hors-ligne et effacée à la déconnexion.<br>
                - <strong>Statistiques communautaires :</strong> L'espèce, les conditions de capture (pression, vent, marée, débit, heure, saison) et une zone large (département, façade maritime, cours d'eau) de vos prises peuvent être agrégées avec celles des autres membres pour produire des statistiques anonymes, affichées seulement au-delà de 10 prises de 3 pêcheurs différents. Vos spots, coordonnées, photos et votre identité n'y figurent jamais. Base légale : l'intérêt légitime de fournir des conseils de pêche à la communauté. Vous pouvez vous y opposer à tout moment dans Paramètres &gt; Statistiques communautaires.<br>
                - <strong>Alertes de crue :</strong> Le code de la station hydrométrique que vous choisissez de surveiller est enregistré uniquement sur votre appareil. Les notifications ne sont envoyées qu'après votre autorisation, que vous pouvez retirer dans les réglages du navigateur.<br><br>
                Conformément à la loi "Informatique et Libertés" et au Règlement Général sur la Protection des Données (RGPD), vous disposez d'un droit d'accès, de rectification, de portabilité et de suppression de vos données. Pour exercer ce droit, vous pouvez supprimer votre compte depuis les paramètres de votre profil, ou nous contacter par e-mail à : <strong>anaelchuquet04@gmail.com</strong>.
            </p>
        </div>

        <!-- 5. Cookies -->
        <div class="card border-0 shadow-sm rounded-4 p-4 mb-4 bg-white">
            <h2 class="h5 fw-bold text-dark d-flex align-items-center mb-3">
                <span class="material-symbols-rounded text-primary me-2">cookie</span> 5. Cookies et stockage sur l'appareil
            </h2>
            <p class="text-secondary small text-justify">
                Le site "Mon P'tit Carnet" n'utilise aucun traceur publicitaire ou outil d'analyse comportementale externe. <br><br>
                Nous utilisons uniquement un cookie technique de session (nommé <code>PHPSESSID</code>). Ce cookie est strictement nécessaire au maintien de votre connexion lorsque vous naviguez entre les différentes pages de votre carnet.<br><br>
                L'application enregistre aussi quelques informations sur votre appareil, à votre demande : le mode « plein soleil », la station de rivière surveillée et, dans le cache de l'application, votre carte de pêche et les fichiers de l'interface pour le fonctionnement hors-ligne. Ces données ne quittent pas votre appareil et sont effacées à la déconnexion (sauf le choix du thème).<br><br>
                Conformément aux directives de la CNIL, ces cookies et stockages strictement nécessaires au service que vous demandez sont exemptés de consentement préalable.
            </p>
        </div>

        <!-- 6. CGU et Contenu utilisateur -->
        <div class="card border-0 shadow-sm rounded-4 p-4 mb-4 bg-white">
            <h2 class="h5 fw-bold text-dark d-flex align-items-center mb-3">
                <span class="material-symbols-rounded text-primary me-2">policy</span> 6. Contenu Utilisateur (CGU)
            </h2>
            <p class="text-secondary small text-justify">
                L'utilisateur est le seul responsable des photos, descriptions et spots de pêche qu'il enregistre sur la plateforme. Il s'engage à ne publier aucun contenu à caractère illicite, diffamatoire ou portant atteinte aux droits d'un tiers.<br><br>
                L'éditeur du site se réserve le droit de supprimer sans préavis tout compte ou contenu ne respectant pas ces conditions, et ne saurait être tenu pour responsable en cas de perte de données.
            </p>
        </div>

    </main>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>