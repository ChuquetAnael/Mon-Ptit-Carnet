-- =====================================================================
-- Mon P'tit Carnet — Migration V0.3 de la base « mlr1 »
-- (conditions gagnantes, réglementation, vigilance crues, carte de pêche)
--
-- Prérequis : la migration 2026-10-13_v0.2_audit.sql doit déjà être passée.
-- À exécuter UNE fois : phpMyAdmin > base mlr1 > onglet Importer > ce fichier.
-- Testé sur MariaDB 10.4 / 10.11 (XAMPP) en mode strict. Faire une sauvegarde avant.
-- Un 2e lancement s'arrête dès la 1re requête (#1060 Duplicate column name) sans rien modifier.
-- =====================================================================
SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1. SPOTS : catégorie piscicole et département (pour la réglementation)
-- ---------------------------------------------------------------------
ALTER TABLE `spot`
  ADD COLUMN `CATEGORIE_PISCICOLE` tinyint(1) DEFAULT NULL COMMENT '1 = 1re catégorie, 2 = 2e catégorie, NULL = inconnue (eau douce uniquement)',
  ADD COLUMN `CODE_DEPARTEMENT` varchar(3) DEFAULT NULL COMMENT 'Déduit des coordonnées (geo.api.gouv.fr)';

-- ---------------------------------------------------------------------
-- 2. SESSIONS : conditions complètes pour l'analyse « conditions gagnantes »
--    Aucune coordonnée n'est ajoutée ici : l'agrégation communautaire se fait
--    au département, à la façade maritime ou au cours d'eau, jamais au point GPS.
-- ---------------------------------------------------------------------
ALTER TABLE `session_p`
  ADD COLUMN `TENDANCE_PRESSION_3H` decimal(5,1) DEFAULT NULL COMMENT 'hPa : pression(t) - pression(t-3h)',
  ADD COLUMN `TENDANCE_PRESSION_24H` decimal(5,1) DEFAULT NULL COMMENT 'hPa : pression(t) - pression(t-24h)',
  ADD COLUMN `DEBIT_M3S` decimal(10,3) DEFAULT NULL COMMENT 'Débit Hub''eau au début de la session',
  ADD COLUMN `DEBIT_RATIO` decimal(5,2) DEFAULT NULL COMMENT 'Débit / moyenne des 30 jours précédents',
  ADD COLUMN `CODE_STATION_HYDRO` varchar(12) DEFAULT NULL,
  ADD COLUMN `COURS_EAU` varchar(100) DEFAULT NULL,
  ADD COLUMN `MILIEU` enum('mer','eau_douce','estuaire') DEFAULT NULL,
  ADD COLUMN `FACADE` enum('atlantique_manche','mediterranee') DEFAULT NULL,
  ADD COLUMN `CODE_DEPARTEMENT` varchar(3) DEFAULT NULL,
  ADD KEY `I_SESSION_DEPARTEMENT` (`CODE_DEPARTEMENT`),
  ADD KEY `I_SESSION_FACADE` (`FACADE`),
  ADD KEY `I_SESSION_MILIEU` (`MILIEU`);

-- Milieu des sessions existantes, déduit du type de spot (1-8 eau douce, 9 estuaire, 10-18 mer)
UPDATE `session_p` s JOIN `spot` sp ON sp.`ID_SPOT` = s.`ID_SPOT`
SET s.`MILIEU` = CASE WHEN sp.`ID_TYPE_SPOT` <= 8 THEN 'eau_douce' WHEN sp.`ID_TYPE_SPOT` = 9 THEN 'estuaire' ELSE 'mer' END
WHERE s.`MILIEU` IS NULL;

-- ---------------------------------------------------------------------
-- 3. UTILISATEURS : choix de participer (ou non) aux statistiques communautaires
-- ---------------------------------------------------------------------
ALTER TABLE `utilisateur`
  ADD COLUMN `PARTAGE_COMMUNAUTE` tinyint(1) NOT NULL DEFAULT 1 COMMENT '0 = ses prises sont exclues des statistiques communautaires';

-- ---------------------------------------------------------------------
-- 4. RÉGLEMENTATION (garde-fou à la saisie)
--    Une ligne = une contrainte (taille, quota, période) pour une espèce et une zone.
--    Une ligne propre à un département remplace la règle nationale du même type.
--    Format des dates : 'MM-JJ' ou '<rang>-<jour>-<mois>[+/-décalage]'
--      ex. '2-sat-03' = 2e samedi de mars, 'last-sun-01+1' = lendemain du dernier dimanche de janvier.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `reglementation` (
  `ID_REGLE` int(11) NOT NULL AUTO_INCREMENT,
  `ID_ESPECE` int(11) DEFAULT NULL COMMENT 'NULL = toutes les espèces',
  `MILIEU` enum('eau_douce','mer') NOT NULL,
  `CATEGORIE_PISCICOLE` tinyint(1) DEFAULT NULL COMMENT 'Eau douce : 1 ou 2, NULL = les deux',
  `FACADE` enum('atlantique_manche','mediterranee') DEFAULT NULL COMMENT 'Mer : NULL = toutes les façades',
  `LAT_MIN` decimal(6,3) DEFAULT NULL,
  `LAT_MAX` decimal(6,3) DEFAULT NULL,
  `CODE_DEPARTEMENT` varchar(3) DEFAULT NULL COMMENT 'NULL = règle nationale',
  `TAILLE_MIN_CM` smallint(6) DEFAULT NULL,
  `QUOTA_JOUR` smallint(6) DEFAULT NULL COMMENT 'Poissons gardés par pêcheur et par jour',
  `GROUPE_QUOTA` varchar(40) DEFAULT NULL COMMENT 'Quota partagé entre espèces (ex. carnassiers)',
  `FERMETURE_DEBUT` varchar(20) DEFAULT NULL,
  `FERMETURE_FIN` varchar(20) DEFAULT NULL,
  `FERMETURE_NATURE` enum('interdite','no_kill') DEFAULT NULL COMMENT 'interdite = pêche fermée ; no_kill = remise à l''eau obligatoire',
  `MARQUAGE` tinyint(1) NOT NULL DEFAULT 0,
  `DECLARATION` tinyint(1) NOT NULL DEFAULT 0,
  `NOTE` varchar(500) DEFAULT NULL,
  `SOURCE` varchar(255) DEFAULT NULL,
  `DATE_VERIFICATION` date DEFAULT NULL,
  `ACTIF` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`ID_REGLE`),
  KEY `I_REGLE_ESPECE` (`ID_ESPECE`),
  KEY `I_REGLE_MILIEU` (`MILIEU`, `ACTIF`),
  CONSTRAINT `FK_REGLE_ESPECE` FOREIGN KEY (`ID_ESPECE`) REFERENCES `espece` (`ID_ESPECE`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Règles de référence (nationales ou européennes) vérifiées le 19/10/2026.
-- Elles sont modifiables dans Espace Administrateur > Réglementation.
-- Les arrêtés préfectoraux ajoutent souvent des règles plus strictes : à saisir par département.

-- 4.1 Eau douce — périodes générales
INSERT INTO `reglementation` (`ID_ESPECE`, `MILIEU`, `CATEGORIE_PISCICOLE`, `FERMETURE_DEBUT`, `FERMETURE_FIN`, `FERMETURE_NATURE`, `NOTE`, `SOURCE`, `DATE_VERIFICATION`) VALUES
(NULL, 'eau_douce', 1, '3-sun-09+1', '2-sat-03-1', 'interdite', 'Pêche fermée en 1re catégorie : ouverture du 2e samedi de mars au 3e dimanche de septembre.', 'Code de l''environnement, art. R436-6', '2026-10-19');

INSERT INTO `reglementation` (`ID_ESPECE`, `MILIEU`, `CATEGORIE_PISCICOLE`, `TAILLE_MIN_CM`, `QUOTA_JOUR`, `GROUPE_QUOTA`, `FERMETURE_DEBUT`, `FERMETURE_FIN`, `FERMETURE_NATURE`, `NOTE`, `SOURCE`, `DATE_VERIFICATION`)
SELECT `ID_ESPECE`, 'eau_douce', 1, 50, 2, NULL, '2-sat-03', 'last-sat-04-1', 'no_kill', 'En 1re catégorie, le brochet pris avant le dernier samedi d''avril doit être remis à l''eau.', 'Code de l''environnement, art. R436-6, R436-18 et R436-21', '2026-10-19' FROM `espece` WHERE `NOM_SCIEN` = 'Esox lucius';

INSERT INTO `reglementation` (`ID_ESPECE`, `MILIEU`, `CATEGORIE_PISCICOLE`, `TAILLE_MIN_CM`, `QUOTA_JOUR`, `GROUPE_QUOTA`, `FERMETURE_DEBUT`, `FERMETURE_FIN`, `FERMETURE_NATURE`, `NOTE`, `SOURCE`, `DATE_VERIFICATION`)
SELECT `ID_ESPECE`, 'eau_douce', 2, 60, 2, NULL, 'last-sun-01+1', 'last-sat-04-1', 'interdite', 'Fermeture du brochet en 2e catégorie : du lendemain du dernier dimanche de janvier au vendredi précédant le dernier samedi d''avril. Beaucoup de départements ferment aussi le sandre sur cette période.', 'Code de l''environnement, art. R436-7, R436-18 et R436-21 ; arrêtés préfectoraux 2026', '2026-10-19' FROM `espece` WHERE `NOM_SCIEN` = 'Esox lucius';

-- Quota commun « carnassiers » en 2e catégorie : 3 par jour (brochet + sandre + black-bass), dont 2 brochets
INSERT INTO `reglementation` (`ID_ESPECE`, `MILIEU`, `CATEGORIE_PISCICOLE`, `TAILLE_MIN_CM`, `QUOTA_JOUR`, `GROUPE_QUOTA`, `NOTE`, `SOURCE`, `DATE_VERIFICATION`)
SELECT `ID_ESPECE`, 'eau_douce', 2, NULL, 3, 'carnassiers', '3 carnassiers (brochet, sandre, black-bass) par pêcheur et par jour en 2e catégorie, dont 2 brochets au maximum.', 'Code de l''environnement, art. R436-21', '2026-10-19' FROM `espece` WHERE `NOM_SCIEN` = 'Esox lucius'
UNION ALL
SELECT `ID_ESPECE`, 'eau_douce', 2, 50, 3, 'carnassiers', '3 carnassiers (brochet, sandre, black-bass) par pêcheur et par jour en 2e catégorie.', 'Code de l''environnement, art. R436-18 et R436-21', '2026-10-19' FROM `espece` WHERE `NOM_SCIEN` = 'Sander lucioperca'
UNION ALL
SELECT `ID_ESPECE`, 'eau_douce', 2, 30, 3, 'carnassiers', '3 carnassiers (brochet, sandre, black-bass) par pêcheur et par jour en 2e catégorie.', 'Code de l''environnement, art. R436-18 et R436-21', '2026-10-19' FROM `espece` WHERE `NOM_SCIEN` = 'Micropterus salmoides';

-- Salmonidés : taille et quota de 10 par jour (hors saumon et truite de mer)
INSERT INTO `reglementation` (`ID_ESPECE`, `MILIEU`, `CATEGORIE_PISCICOLE`, `TAILLE_MIN_CM`, `QUOTA_JOUR`, `GROUPE_QUOTA`, `NOTE`, `SOURCE`, `DATE_VERIFICATION`)
SELECT `ID_ESPECE`, 'eau_douce', NULL, 23, 10, 'salmonides', 'Truites : 23 cm minimum (souvent 25 cm par arrêté préfectoral). 10 salmonidés par jour au maximum, quota souvent abaissé localement.', 'Code de l''environnement, art. R436-18 et R436-21', '2026-10-19' FROM `espece` WHERE `NOM_SCIEN` IN ('Salmo trutta fario', 'Oncorhynchus mykiss')
UNION ALL
SELECT `ID_ESPECE`, 'eau_douce', NULL, 30, 10, 'salmonides', 'Ombre commun : 30 cm minimum. 10 salmonidés par jour au maximum.', 'Code de l''environnement, art. R436-18 et R436-21', '2026-10-19' FROM `espece` WHERE `NOM_SCIEN` = 'Thymallus thymallus'
UNION ALL
SELECT `ID_ESPECE`, 'eau_douce', NULL, NULL, 10, 'salmonides', '10 salmonidés par jour au maximum (hors saumon et truite de mer).', 'Code de l''environnement, art. R436-21', '2026-10-19' FROM `espece` WHERE `NOM_SCIEN` IN ('Salvelinus fontinalis', 'Salvelinus alpinus', 'Salvelinus namaycush');

-- Truite fario en 2e catégorie et ombre commun : périodes propres
INSERT INTO `reglementation` (`ID_ESPECE`, `MILIEU`, `CATEGORIE_PISCICOLE`, `FERMETURE_DEBUT`, `FERMETURE_FIN`, `FERMETURE_NATURE`, `NOTE`, `SOURCE`, `DATE_VERIFICATION`)
SELECT `ID_ESPECE`, 'eau_douce', 2, '3-sun-09+1', '2-sat-03-1', 'interdite', 'La truite fario suit la période de la 1re catégorie, même en 2e catégorie.', 'Code de l''environnement, art. R436-7', '2026-10-19' FROM `espece` WHERE `NOM_SCIEN` = 'Salmo trutta fario'
UNION ALL
SELECT `ID_ESPECE`, 'eau_douce', 1, '3-sun-09+1', '3-sat-05-1', 'interdite', 'Ombre commun en 1re catégorie : ouvert du 3e samedi de mai au 3e dimanche de septembre.', 'Code de l''environnement, art. R436-6', '2026-10-19' FROM `espece` WHERE `NOM_SCIEN` = 'Thymallus thymallus'
UNION ALL
SELECT `ID_ESPECE`, 'eau_douce', 2, '01-01', '3-sat-05-1', 'interdite', 'Ombre commun en 2e catégorie : ouvert du 3e samedi de mai au 31 décembre.', 'Code de l''environnement, art. R436-7', '2026-10-19' FROM `espece` WHERE `NOM_SCIEN` = 'Thymallus thymallus';

-- 4.2 Espèces protégées ou interdites toute l'année
INSERT INTO `reglementation` (`ID_ESPECE`, `MILIEU`, `FERMETURE_DEBUT`, `FERMETURE_FIN`, `FERMETURE_NATURE`, `NOTE`, `SOURCE`, `DATE_VERIFICATION`)
SELECT `ID_ESPECE`, m.`MILIEU`, '01-01', '12-31', 'interdite', 'Espèce protégée : sa pêche est interdite toute l''année. Remise à l''eau immédiate.', 'Code de l''environnement (espèces dont la pêche est interdite) ; arrêtés de protection', '2026-10-19'
FROM `espece` JOIN (SELECT 'eau_douce' AS `MILIEU` UNION ALL SELECT 'mer') m
WHERE `NOM_SCIEN` IN ('Petromyzon marinus', 'Lampetra fluviatilis', 'Acipenser sturio');

INSERT INTO `reglementation` (`ID_ESPECE`, `MILIEU`, `FERMETURE_DEBUT`, `FERMETURE_FIN`, `FERMETURE_NATURE`, `NOTE`, `SOURCE`, `DATE_VERIFICATION`)
SELECT `ID_ESPECE`, 'mer', '01-01', '12-31', 'interdite', 'Espèce interdite de capture dans les eaux de l''Union européenne : remise à l''eau immédiate.', 'Règlement (UE) fixant les possibilités de pêche 2026 (espèces interdites)', '2026-10-19'
FROM `espece` WHERE `NOM_SCIEN` IN ('Squatina squatina', 'Lamna nasus')
UNION ALL
SELECT `ID_ESPECE`, 'mer', '01-01', '12-31', 'interdite', 'L''anguille est interdite de pêche à tous ses stades en aval de la limite de salure des eaux.', 'DIRM NAMO, réglementation pêche de loisir 2024', '2026-10-19'
FROM `espece` WHERE `NOM_SCIEN` = 'Anguilla anguilla';

INSERT INTO `reglementation` (`ID_ESPECE`, `MILIEU`, `NOTE`, `SOURCE`, `DATE_VERIFICATION`)
SELECT `ID_ESPECE`, 'eau_douce', 'Anguille : l''anguille argentée et la civelle sont interdites ; l''anguille jaune n''est autorisée que sur la période fixée par arrêté.', 'Code de l''environnement, art. R436-65-4', '2026-10-19' FROM `espece` WHERE `NOM_SCIEN` = 'Anguilla anguilla'
UNION ALL
SELECT `ID_ESPECE`, 'eau_douce', 'Saumon atlantique : régime spécifique (marque d''identification, déclaration de capture, quotas par bassin fixés par le COGEPOMI).', 'Code de l''environnement (poissons migrateurs) ; COGEPOMI du bassin', '2026-10-19' FROM `espece` WHERE `NOM_SCIEN` = 'Salmo salar'
UNION ALL
SELECT `ID_ESPECE`, 'mer', 'Thon rouge : pêche de loisir soumise à une autorisation annuelle, bague de marquage et déclaration obligatoires.', 'Arrêté ministériel thon rouge (campagne 2026)', '2026-10-19' FROM `espece` WHERE `NOM_SCIEN` = 'Thunnus thynnus';

-- 4.3 Mer — bar (Dicentrarchus labrax)
INSERT INTO `reglementation` (`ID_ESPECE`, `MILIEU`, `FACADE`, `LAT_MIN`, `LAT_MAX`, `TAILLE_MIN_CM`, `QUOTA_JOUR`, `FERMETURE_DEBUT`, `FERMETURE_FIN`, `FERMETURE_NATURE`, `MARQUAGE`, `DECLARATION`, `NOTE`, `SOURCE`, `DATE_VERIFICATION`)
SELECT `ID_ESPECE`, 'mer', 'atlantique_manche', 48.000, NULL, 42, 3, '02-01', '03-31', 'no_kill', 1, 1, 'Bar au nord du 48e parallèle (Manche, mer du Nord, Atlantique nord) : 42 cm, 3 par jour ; du 1er février au 31 mars, no-kill obligatoire.', 'Règlement (UE) 2026/249 ; FFESSM, réglementation bar 2026', '2026-10-19' FROM `espece` WHERE `NOM_SCIEN` = 'Dicentrarchus labrax'
UNION ALL
SELECT `ID_ESPECE`, 'mer', 'atlantique_manche', NULL, 48.000, 42, 2, NULL, NULL, NULL, 1, 1, 'Bar au sud du 48e parallèle (golfe de Gascogne) : 42 cm, 2 par jour toute l''année.', 'Règlement (UE) 2026/249 ; FFESSM, réglementation bar 2026', '2026-10-19' FROM `espece` WHERE `NOM_SCIEN` = 'Dicentrarchus labrax'
UNION ALL
SELECT `ID_ESPECE`, 'mer', 'mediterranee', NULL, NULL, 30, NULL, NULL, NULL, NULL, 1, 0, 'Bar (loup) en Méditerranée : 30 cm.', 'Arrêté du 26 octobre 2012 (tailles minimales), consolidé 2026', '2026-10-19' FROM `espece` WHERE `NOM_SCIEN` = 'Dicentrarchus labrax'
UNION ALL
SELECT `ID_ESPECE`, 'mer', 'atlantique_manche', NULL, NULL, 30, NULL, NULL, NULL, NULL, 0, 0, 'Bar moucheté : 30 cm, sans quota.', 'FFESSM, réglementation bar 2026', '2026-10-19' FROM `espece` WHERE `NOM_SCIEN` = 'Dicentrarchus punctatus';

-- 4.4 Mer — lieu jaune
INSERT INTO `reglementation` (`ID_ESPECE`, `MILIEU`, `FACADE`, `LAT_MIN`, `LAT_MAX`, `TAILLE_MIN_CM`, `QUOTA_JOUR`, `FERMETURE_DEBUT`, `FERMETURE_FIN`, `FERMETURE_NATURE`, `MARQUAGE`, `DECLARATION`, `NOTE`, `SOURCE`, `DATE_VERIFICATION`)
SELECT `ID_ESPECE`, 'mer', 'atlantique_manche', 48.000, NULL, 42, 2, NULL, NULL, NULL, 1, 1, 'Lieu jaune au nord du 48e parallèle : 42 cm, 2 par jour. Des fermetures saisonnières peuvent s''appliquer : vérifie auprès de la DIRM.', 'Arrêté du 24 décembre 2024 ; quotas de loisir 2026', '2026-10-19' FROM `espece` WHERE `NOM_SCIEN` = 'Pollachius pollachius'
UNION ALL
SELECT `ID_ESPECE`, 'mer', 'atlantique_manche', NULL, 48.000, 42, 2, '01-01', '04-30', 'no_kill', 1, 1, 'Lieu jaune au sud du 48e parallèle : 42 cm, 2 par jour ; remise à l''eau obligatoire de janvier à avril.', 'Arrêté du 24 décembre 2024 ; quotas de loisir 2026', '2026-10-19' FROM `espece` WHERE `NOM_SCIEN` = 'Pollachius pollachius';

-- 4.5 Mer — tailles minimales courantes (arrêté du 26 octobre 2012 modifié)
INSERT INTO `reglementation` (`ID_ESPECE`, `MILIEU`, `FACADE`, `TAILLE_MIN_CM`, `MARQUAGE`, `NOTE`, `SOURCE`, `DATE_VERIFICATION`)
SELECT e.`ID_ESPECE`, 'mer', t.`FACADE`, t.`TAILLE`, t.`MARQUAGE`, NULL, 'Arrêté du 26 octobre 2012 (tailles minimales) ; arrêté du 17 mai 2011 (marquage)', '2026-10-19'
FROM `espece` e JOIN (
  SELECT 'Sparus aurata' AS `SCI`, NULL AS `FACADE`, 23 AS `TAILLE`, 1 AS `MARQUAGE` UNION ALL
  SELECT 'Scomber scombrus', 'atlantique_manche', 20, 1 UNION ALL
  SELECT 'Scomber scombrus', 'mediterranee', 18, 1 UNION ALL
  SELECT 'Merlangius merlangus', 'atlantique_manche', 27, 0 UNION ALL
  SELECT 'Pleuronectes platessa', 'atlantique_manche', 27, 0 UNION ALL
  SELECT 'Scophthalmus maximus', 'atlantique_manche', 30, 0 UNION ALL
  SELECT 'Pollachius virens', 'atlantique_manche', 35, 1 UNION ALL
  SELECT 'Argyrosomus regius', 'atlantique_manche', 45, 1 UNION ALL
  SELECT 'Merluccius merluccius', 'atlantique_manche', 27, 0 UNION ALL
  SELECT 'Molva molva', 'atlantique_manche', 63, 0 UNION ALL
  SELECT 'Mullus surmuletus', 'atlantique_manche', 15, 0 UNION ALL
  SELECT 'Mullus surmuletus', 'mediterranee', 11, 0
) t ON t.`SCI` = e.`NOM_SCIEN`;

-- Marquage obligatoire sans taille saisie ici
INSERT INTO `reglementation` (`ID_ESPECE`, `MILIEU`, `MARQUAGE`, `NOTE`, `SOURCE`, `DATE_VERIFICATION`)
SELECT `ID_ESPECE`, 'mer', 1, 'Marquage obligatoire (ablation de la partie inférieure de la nageoire caudale). Vérifie la taille minimale de ta zone.', 'Arrêté du 17 mai 2011 (marquage)', '2026-10-19'
FROM `espece` WHERE `NOM_SCIEN` IN ('Solea solea', 'Pagrus pagrus');

-- 4.6 Mer — fermetures et interdictions ciblées
INSERT INTO `reglementation` (`ID_ESPECE`, `MILIEU`, `FACADE`, `LAT_MIN`, `LAT_MAX`, `CODE_DEPARTEMENT`, `QUOTA_JOUR`, `FERMETURE_DEBUT`, `FERMETURE_FIN`, `FERMETURE_NATURE`, `MARQUAGE`, `DECLARATION`, `NOTE`, `SOURCE`, `DATE_VERIFICATION`)
SELECT `ID_ESPECE`, 'mer', 'atlantique_manche', NULL, NULL, NULL, 2, NULL, NULL, NULL, 0, 0, 'Homard : 2 par pêcheur et par jour. Taille minimale de 9 cm mesurée au céphalothorax (pas la longueur totale).', 'Arrêté du 24 décembre 2024', '2026-10-19' FROM `espece` WHERE `NOM_SCIEN` = 'Homarus gammarus'
UNION ALL
SELECT `ID_ESPECE`, 'mer', 'atlantique_manche', NULL, 48.000, NULL, NULL, '01-01', '12-31', 'interdite', 0, 0, 'Raie brunette : capture interdite dans le golfe de Gascogne (zone CIEM VIII).', 'DIRM NAMO, réglementation pêche de loisir 2024', '2026-10-19' FROM `espece` WHERE `NOM_SCIEN` = 'Raja undulata'
UNION ALL
SELECT `ID_ESPECE`, 'mer', 'mediterranee', NULL, NULL, NULL, NULL, '01-01', '12-31', 'interdite', 0, 0, 'Moratoire en Méditerranée : pêche de loisir (hameçon et chasse sous-marine) interdite jusqu''en 2033.', 'Arrêté R20-2023-103 (Corse) ; DIRM Méditerranée', '2026-10-19' FROM `espece` WHERE `NOM_SCIEN` IN ('Epinephelus marginatus', 'Sciaena umbra')
UNION ALL
SELECT `ID_ESPECE`, 'mer', 'atlantique_manche', NULL, NULL, d.`DEP`, NULL, '01-01', '06-30', 'interdite', 1, 1, 'Dorade rose : pêche interdite du 1er janvier au 30 juin en Bretagne.', 'DIRM NAMO, réglementation pêche de loisir Bretagne 2024', '2026-10-19'
FROM `espece` JOIN (SELECT '22' AS `DEP` UNION ALL SELECT '29' UNION ALL SELECT '35' UNION ALL SELECT '56') d
WHERE `NOM_SCIEN` = 'Pagellus bogaraveo';

-- ---------------------------------------------------------------------
-- 5. ZONES DE RÉSERVE (polygones GeoJSON saisis par l'administrateur)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `zone_reserve` (
  `ID_RESERVE` int(11) NOT NULL AUTO_INCREMENT,
  `NOM` varchar(150) NOT NULL,
  `TYPE_ZONE` enum('reserve_peche','aire_marine_protegee','zone_interdite','autre') NOT NULL DEFAULT 'reserve_peche',
  `REGLE` varchar(500) NOT NULL COMMENT 'Texte affiché, ex. « Pêche interdite toute l''année »',
  `CODE_DEPARTEMENT` varchar(3) DEFAULT NULL,
  `GEOJSON` mediumtext NOT NULL COMMENT 'Géométrie Polygon ou MultiPolygon, coordonnées [lng, lat]',
  `LAT_MIN` decimal(10,6) NOT NULL,
  `LAT_MAX` decimal(10,6) NOT NULL,
  `LNG_MIN` decimal(10,6) NOT NULL,
  `LNG_MAX` decimal(10,6) NOT NULL,
  `DATE_FIN` date DEFAULT NULL COMMENT 'Fin de validité de l''arrêté (NULL = permanente)',
  `SOURCE` varchar(255) DEFAULT NULL,
  `ACTIF` tinyint(1) NOT NULL DEFAULT 1,
  `DATE_MAJ` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`ID_RESERVE`),
  KEY `I_RESERVE_BBOX` (`ACTIF`, `LAT_MIN`, `LAT_MAX`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- 6. PORTE-CARTE DE PÊCHE (une carte active par membre, fichier hors dossier public)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `carte_peche` (
  `ID_CARTE` int(11) NOT NULL AUTO_INCREMENT,
  `ID_UTILISATEUR` int(11) NOT NULL,
  `FICHIER` varchar(255) NOT NULL COMMENT 'Nom du fichier dans stockage_prive/user_X/',
  `TYPE_FICHIER` enum('pdf','image') NOT NULL,
  `APERCU` varchar(255) DEFAULT NULL COMMENT 'Image JPEG affichée hors-ligne (1re page du PDF)',
  `QR_CONTENU` varchar(1024) DEFAULT NULL COMMENT 'Contenu du QR code lu sur la carte, réaffiché en grand',
  `FEDERATION` varchar(100) DEFAULT NULL COMMENT 'AAPPMA ou fédération (facultatif)',
  `DATE_FIN_VALIDITE` date DEFAULT NULL,
  `DATE_AJOUT` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`ID_CARTE`),
  UNIQUE KEY `UK_CARTE_UTILISATEUR` (`ID_UTILISATEUR`),
  CONSTRAINT `FK_CARTE_UTILISATEUR` FOREIGN KEY (`ID_UTILISATEUR`) REFERENCES `utilisateur` (`ID_UTILISATEUR`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- 7. CACHE DES APIS (Vigicrues, Hub'eau, découpage administratif, prévisions)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `cache_api` (
  `CLE` varchar(190) NOT NULL,
  `DONNEES` mediumtext NOT NULL,
  `DATE_EXPIRATION` datetime NOT NULL,
  PRIMARY KEY (`CLE`),
  KEY `I_CACHE_EXPIRATION` (`DATE_EXPIRATION`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- 8. ICÔNES DES ESPÈCES AJOUTÉES EN V0.2 (images déposées le 19/10)
-- ---------------------------------------------------------------------
UPDATE `espece` SET `ICONE_CHEMIN` = 'asset/espece_png/merlan.png' WHERE `NOM_SCIEN` = 'Merlangius merlangus' AND `ICONE_CHEMIN` IS NULL;
UPDATE `espece` SET `ICONE_CHEMIN` = 'asset/espece_png/coquette.png' WHERE `NOM_SCIEN` = 'Labrus mixtus' AND `ICONE_CHEMIN` IS NULL;
UPDATE `espece` SET `ICONE_CHEMIN` = 'asset/espece_png/cernier.png' WHERE `NOM_SCIEN` = 'Polyprion americanus' AND `ICONE_CHEMIN` IS NULL;
