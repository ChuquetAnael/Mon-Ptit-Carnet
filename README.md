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
* **Extraction EXIF :** Upload de photos avec récupération automatique de l'heure exacte et des coordonnées GPS de la capture.
* **Compression d'images :** Redimensionnement et optimisation des photos à la volée côté serveur pour économiser de l'espace.
* **Météo Historique & Direct (Open-Meteo API) :** Récupération automatique de la température, pression atmosphérique, vent et couverture nuageuse en fonction du point GPS et de l'heure exacte de la session.

### Gestion du Matériel (Boîte de Pêche)
* **Inventaire personnalisé :** Ajout, modification et suppression de ses propres leurres (avec gestion du grammage et coloris) et appâts.
* **Sélection rapide :** Interface dynamique permettant de choisir son matériel directement lors de l'ajout d'une prise.

### Cartographie & Spots
* **Carte interactive globale (Leaflet.js) :** Visualisation de tous ses spots de pêche enregistrés avec des marqueurs dynamiques.
* **Création tactile :** Ajout de nouveaux spots en cliquant/touchant directement la carte interactive.

### Codex des Espèces
* **Encyclopédie intégrée :** Plus de 100 espèces de poissons répertoriées avec tailles/poids maximums, descriptions, habitats et zones de répartition.
* **Mise à jour Wikipedia :** Script backend (`maj_wikipedia.php`) capable d'interroger l'API Wikipedia pour enrichir automatiquement la base de données avec des descriptions scientifiques.

---

## Technologies Utilisées

**Front-end :**
* HTML5 / CSS3 (Interface 100% Mobile First)
* [Bootstrap 5](https://getbootstrap.com/) (Grilles, Modales, Composants UI)
* Google Fonts : *Poppins* (Typographie) & *Material Symbols Rounded* (Icônes vectorielles)
* [Leaflet.js](https://leafletjs.com/) (Cartographie OpenStreetMap)

**Back-end & Base de Données :**
* PHP 8+ (Architecture procédurale, PDO)
* MySQL / MariaDB (Base de données relationnelle sécurisée avec clés étrangères)

**APIs Tiers :**
* [Open-Meteo](https://open-meteo.com/) (Données météorologiques et archives)
* MediaWiki API (Descriptions encyclopédiques)

---

## Installation & Prérequis

Ce projet est conçu pour tourner sur un environnement de serveur local standard (WAMP, XAMPP, MAMP).

1. **Cloner le dépôt :**
   Placez le dossier du projet dans votre répertoire public web (ex: `C:/xampp/htdocs/Carnet-de-peche/` ou `www/`).

2. **Base de données :**
   * Ouvrez phpMyAdmin (généralement `http://localhost/phpmyadmin`).
   * Importez le script SQL fourni pour créer la base de données ainsi que toutes les tables et contraintes requises.
   * Un jeu d'essai (espèces, types de leurres, habitats) est généralement inclus dans le script d'initialisation.

3. **Configuration de l'environnement :**
   * Éditez le fichier `./bdd/env.php` (à créer si non existant) pour y placer vos identifiants de base de données :
     ```php
     <?php
     $host = 'localhost';
     $dbname = 'MLR1';
     $user = 'root';
     $pass = ''; // Votre mot de passe local
     ?>
     ```

4. **Dossier d'Uploads :**
   * Assurez-vous que PHP dispose des droits d'écriture à la racine du projet pour générer le dossier `uploads/` qui contiendra les photos compressées des utilisateurs.

---

## Utilisation

1. Accédez à l'application via `http://localhost/Carnet-de-peche/`.
2. La page `index.php` sert de vitrine. Créez un profil pour accéder à votre espace personnel.
3. Commencez par remplir votre **Boîte de pêche** avec votre matériel, puis enregistrez votre première **Session**.

---
*Projet développé dans le cadre d'un carnet d'apprentissage personnel (BUT Info).*