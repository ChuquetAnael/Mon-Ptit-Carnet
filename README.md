# Mon P'tit Carnet - Application Web de Pêche

**Mon P'tit Carnet** est une application web "Mobile First" conçue pour les pêcheurs. Elle permet d'enregistrer, d'analyser et de cartographier ses sessions de pêche, tout en offrant une véritable encyclopédie interactive des espèces françaises.

Conçue pour être utilisée directement au bord de l'eau grâce à son interface ergonomique, elle automatise la récupération des données complexes (Météo, Marée, Coordonnées GPS via les photos).

---

## Fonctionnalités Principales

### Tableau de bord & Profil
* **Accueil dynamique :** Suivi des dernières prises et accès rapide aux outils (Boîte de pêche, Codex, Spots).
* **Statistiques & Profil :** Bilan automatique des sessions (temps de pêche, leurre fétiche) et calcul des Records Personnels (Personal Bests / PB) par espèce.

### Sessions & Prises Intelligentes
* **Création de session multi-étapes :** Enregistrement fluide du lieu, de la date et du milieu (eau douce ou mer avec gestion des coefficients de marée).
* **Extraction EXIF :** Upload de photos avec récupération automatique de l'heure exacte et des coordonnées GPS de la capture (lues dans le navigateur avant la réduction, puis relues par le serveur).
* **Photos par prise :** ajout des photos directement sur chaque prise à l'étape 3, espèces les plus prises proposées en premier.
* **Compression d'images :** Redimensionnement et optimisation des photos à la volée côté serveur pour économiser de l'espace.
* **Météo Historique & Direct (Open-Meteo API) :** Récupération automatique de la température, pression atmosphérique, vent et couverture nuageuse en fonction du point GPS et de l'heure exacte de la session.

### Gestion du Matériel (Boîte de Pêche)
* **Inventaire personnalisé :** Ajout, modification et suppression de ses propres leurres (avec gestion du grammage et coloris) et appâts.
* **Sélection rapide :** Interface dynamique permettant de choisir son matériel directement lors de l'ajout d'une prise.

### Cartographie & Spots
* **Carte interactive globale (Leaflet.js) :** Visualisation de tous ses spots de pêche enregistrés avec des marqueurs dynamiques.
* **Création tactile :** Ajout de nouveaux spots en cliquant/touchant directement la carte interactive.

### Au bord de l'eau (V0.3)
* **Conditions gagnantes :** le meilleur créneau des 3 prochains jours, comparé à tes meilleures sorties (pression et sa tendance, vent, marée, débit), et les tendances anonymes de la communauté par secteur.
* **Garde-fou réglementaire :** alerte à la saisie si un poisson gardé est sous la taille légale, hors période ou au-delà du quota, ou si le point est dans une réserve.
* **Vigilance crues :** montée brutale du débit et vigilance Vigicrues, avec bandeau, vibreur et notification.
* **Porte-carte de pêche :** la carte et son QR code en grand, disponibles sans réseau.
* **Application installable (PWA)** avec un mode « plein soleil » à fort contraste.

### Communauté et profil (V0.4)
* **Fil « Au bord de l'eau » :** les dernières prises partagées par les membres, avec photos, espèce, mesures et lieu large (jamais le spot ni le GPS).
* **Classements du mois :** plus gros poisson et plus de prises par espèce, assiduité, polyvalence, no-kill.
* **Garde-fou biologique :** une taille ou un poids impossible pour l'espèce est refusé, un record possible est signalé.
* **Profil enrichi :** niveau, région, techniques, bio, sessions sur 2 colonnes, chaque session masquable, visionneuse de photos.
* **Statistiques :** rendement réel par heure de pêche, fiche détaillée par espèce (moyennes, record, meilleur leurre, saisonnalité).
* **Marées sur 7 jours**, lecture du QR de la carte de pêche même à l'envers, page **Réglementation** ouverte à tous.

Détails techniques, choix et limites : [V0.4](docs/EVOLUTIONS_V0.4.md), [V0.3](docs/EVOLUTIONS_V0.3.md). Audit de sécurité : [AUDIT_V0.2.md](docs/AUDIT_V0.2.md).

### Codex des Espèces
* **Encyclopédie intégrée :** Plus de 100 espèces de poissons répertoriées avec tailles/poids maximums, descriptions, habitats et zones de répartition.
* **Mise à jour Wikipedia :** Script backend (`maj_wikipedia.php`) capable d'interroger l'API Wikipedia pour enrichir automatiquement la base de données avec des descriptions scientifiques.

---

## Technologies Utilisées

**Front-end :**
* HTML5 / CSS3 (Interface 100% Mobile First)
* [Bootstrap 5](https://getbootstrap.com/) (Grilles, Modales, Composants UI)
* Google Fonts : *Atkinson Hyperlegible* (texte) & *Material Symbols Rounded* (icônes)
* Service worker et manifeste (application installable, carte de pêche hors-ligne)
* PDF.js et jsQR (lecture de la carte de pêche dans le navigateur), QR Code Generator (affichage du QR)
* [Leaflet.js](https://leafletjs.com/) (Cartographie OpenStreetMap)

**Back-end & Base de Données :**
* PHP 8+ (Architecture procédurale, PDO)
* MySQL / MariaDB (Base de données relationnelle sécurisée avec clés étrangères)

**APIs Tiers :**
* [Open-Meteo](https://open-meteo.com/) (Données météorologiques et archives)
* [api-maree.fr](https://api-maree.fr/) (Marées, clé dans `cle/api-maree.php`)
* [Hub'eau](https://hubeau.eaufrance.fr/) et [Vigicrues](https://www.vigicrues.gouv.fr/) (Débits, hauteurs d'eau, vigilance crues)
* [geo.api.gouv.fr](https://geo.api.gouv.fr/) (Département d'un spot)
* MediaWiki API (Descriptions encyclopédiques)

---

## Installation & Prérequis

Ce projet est conçu pour tourner sur un environnement de serveur local standard (WAMP, XAMPP, MAMP).

1. **Cloner le dépôt :**
   Placez le dossier du projet dans votre répertoire public web (ex: `C:/xampp/htdocs/Carnet-de-peche/` ou `www/`).

2. **Base de données :**
   * Ouvrez phpMyAdmin (généralement `http://localhost/phpmyadmin`).
   * Importez le dump de la base `mlr1`, puis, dans l'ordre, les migrations de `BDD/migrations/` (V0.2, V0.3, puis V0.4).
   * Ne publiez jamais un dump de la base : il contient les e-mails et les mots de passe chiffrés des membres (`*.sql` est ignoré par Git, sauf les migrations).
   * Facultatif : `php BDD/maj_conditions.php` complète les anciennes sessions (tendance de pression, débit, département).

3. **Configuration de l'environnement :**
   * `BDD/env.php` contient les valeurs XAMPP par défaut (base `mlr1`, utilisateur `root`). Pour un autre poste ou le serveur, créez `BDD/env.local.php` (ignoré par Git) :
     ```php
     <?php
     $dbname = 'mlr1';
     $pass = 'mot_de_passe';
     define('APP_DEBUG', false); // en production
     ```

4. **Dossier d'Uploads :**
   * PHP doit pouvoir écrire dans `uploads/` (photos) et `stockage_prive/` (cartes de pêche, interdit d'accès web).
   * Extensions PHP nécessaires : `gd` (avec WebP), `fileinfo`, `exif`, `curl`.
   * En production, servez le site en **HTTPS** : c'est indispensable pour l'application installable, le hors-ligne et les notifications.

---

## Utilisation

1. Accédez à l'application via `http://localhost/Carnet-de-peche/`.
2. La page `index.php` sert de vitrine. Créez un profil pour accéder à votre espace personnel.
3. Commencez par remplir votre **Boîte de pêche** avec votre matériel, puis enregistrez votre première **Session**.

---
*Projet développé dans le cadre d'un carnet d'apprentissage personnel (BUT Info).*

---

Développé avec l'aide d'outils d'IA (Claude) pour l'audit, les V0.3 et V0.4 et la documentation, puis testé et relu.
