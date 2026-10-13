-- =====================================================================
-- Mon P'tit Carnet — Migration de la base « mlr1 » (branche V0.2 → audit)
-- À exécuter UNE fois : phpMyAdmin > base mlr1 > onglet SQL > coller > Exécuter
-- Testé sur MariaDB 10.4 / 10.11 (XAMPP). Faire une sauvegarde (Exporter) avant.
-- =====================================================================
SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1. INTÉGRITÉ ET COHÉRENCE AVEC LE CODE
-- ---------------------------------------------------------------------

-- Un email = un compte (la vérification PHP seule laissait passer deux inscriptions simultanées)
ALTER TABLE `utilisateur` ADD UNIQUE KEY `UK_UTILISATEUR_MAIL` (`MAIL`);

-- Les identifiants de port api-maree dépassent 20 caractères (« port-en-bessin-huppain »)
-- et fetch_maree.php créait déjà la table en VARCHAR(100)/MEDIUMTEXT : on aligne le schéma.
ALTER TABLE `cache_maree`
  MODIFY `ID_PORT` varchar(100) NOT NULL,
  MODIFY `DONNEES_JSON` mediumtext NOT NULL;

-- Chemins d'images dont la casse ne correspond pas aux fichiers (cassés sous Linux)
UPDATE `type_session` SET `TYPE_SES_ICONE_CHEMIN` = 'asset/type_session/Leurre.jpg' WHERE `TYPE_SES_ICONE_CHEMIN` = 'asset/type_session/leurre.jpg';
UPDATE `type_session` SET `TYPE_SES_ICONE_CHEMIN` = 'asset/type_session/Kayak_mer.jpg' WHERE `TYPE_SES_ICONE_CHEMIN` = 'asset/type_session/kayak_mer.jpg';
UPDATE `type_session` SET `TYPE_SES_ICONE_CHEMIN` = 'asset/type_session/Chasse_sous_marine.jpg' WHERE `TYPE_SES_ICONE_CHEMIN` = 'asset/type_session/chasse_sous_marine.jpg';
UPDATE `type_session` SET `TYPE_SES_ICONE_CHEMIN` = 'asset/type_session/Street_fishing.jpg' WHERE `TYPE_SES_ICONE_CHEMIN` = 'asset/type_session/street_fishing.jpg';
UPDATE `type_session` SET `TYPE_SES_ICONE_CHEMIN` = 'asset/type_session/Feeder.jpg' WHERE `TYPE_SES_ICONE_CHEMIN` = 'asset/type_session/feeder.jpg';

-- Suppressions en cascade gérées par la base (plus de prises orphelines si un DELETE échoue à mi-chemin)
ALTER TABLE `prise` DROP FOREIGN KEY `FK_PRISE_LEURRE`;
ALTER TABLE `prise` DROP FOREIGN KEY `FK_PRISE_APPAT`;
ALTER TABLE `prise` DROP FOREIGN KEY `FK_PRISE_SESSION`;
ALTER TABLE `prise`
  ADD CONSTRAINT `FK_PRISE_LEURRE` FOREIGN KEY (`ID_LEURRE`) REFERENCES `leurre` (`ID_LEURRE`) ON DELETE SET NULL,
  ADD CONSTRAINT `FK_PRISE_APPAT` FOREIGN KEY (`ID_APPAT`) REFERENCES `appat` (`ID_APPAT`) ON DELETE SET NULL,
  ADD CONSTRAINT `FK_PRISE_SESSION` FOREIGN KEY (`ID_SESSION`) REFERENCES `session_p` (`ID_SESSION`) ON DELETE CASCADE;

ALTER TABLE `session_p` DROP FOREIGN KEY `FK_SESSION_SPOT`;
ALTER TABLE `session_p`
  ADD CONSTRAINT `FK_SESSION_SPOT` FOREIGN KEY (`ID_SPOT`) REFERENCES `spot` (`ID_SPOT`) ON DELETE SET NULL;

-- Index pour la liste paginée des sessions du profil (WHERE utilisateur ORDER BY date)
ALTER TABLE `session_p` ADD KEY `I_SESSION_UTILISATEUR_DATE` (`ID_UTILISATEUR`, `DATE_DEBUT`);

-- Tickets : les anciens messages avaient été stockés déjà échappés (affichage « &#039; »)
UPDATE `ticket` SET
  `MESSAGE` = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(`MESSAGE`, '&#039;', ''''), '&quot;', '"'), '&lt;', '<'), '&gt;', '>'), '&amp;', '&'),
  `RAISON`  = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(`RAISON`,  '&#039;', ''''), '&quot;', '"'), '&lt;', '<'), '&gt;', '>'), '&amp;', '&'),
  `EMAIL`   = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(`EMAIL`,   '&#039;', ''''), '&quot;', '"'), '&lt;', '<'), '&gt;', '>'), '&amp;', '&');

-- ---------------------------------------------------------------------
-- 2. SÉCURITÉ : limitation des tentatives de connexion (anti force brute)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tentative_connexion` (
  `ID_TENTATIVE` int(11) NOT NULL AUTO_INCREMENT,
  `IP` varchar(45) NOT NULL,
  `MAIL` varchar(255) NOT NULL,
  `DATE_TENTATIVE` datetime NOT NULL,
  PRIMARY KEY (`ID_TENTATIVE`),
  KEY `I_TENTATIVE_MAIL` (`MAIL`, `DATE_TENTATIVE`),
  KEY `I_TENTATIVE_IP` (`IP`, `DATE_TENTATIVE`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- 3. NOTE.TXT — PLUSIEURS PHOTOS PAR PRISE (+ statut de modération)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `photo_prise` (
  `ID_PHOTO` int(11) NOT NULL AUTO_INCREMENT,
  `ID_PRISE` int(11) NOT NULL,
  `CHEMIN` varchar(255) NOT NULL,
  `ORDRE` tinyint(4) NOT NULL DEFAULT 0,
  `STATUT_MODERATION` enum('visible','signalee','masquee') NOT NULL DEFAULT 'visible',
  `DATE_AJOUT` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`ID_PHOTO`),
  KEY `I_FK_PHOTO_PRISE` (`ID_PRISE`, `ORDRE`),
  CONSTRAINT `FK_PHOTO_PRISE` FOREIGN KEY (`ID_PRISE`) REFERENCES `prise` (`ID_PRISE`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Reprise des photos existantes (une photo par prise jusqu'ici)
INSERT INTO `photo_prise` (`ID_PRISE`, `CHEMIN`, `ORDRE`, `DATE_AJOUT`)
SELECT p.`ID_PRISE`, p.`PHOTO_CHEMIN`, 0, COALESCE(p.`DATE_HEURE`, NOW())
FROM `prise` p
WHERE p.`PHOTO_CHEMIN` IS NOT NULL AND p.`PHOTO_CHEMIN` <> ''
  AND NOT EXISTS (SELECT 1 FROM `photo_prise` ph WHERE ph.`ID_PRISE` = p.`ID_PRISE` AND ph.`CHEMIN` = p.`PHOTO_CHEMIN`);

-- PRISE.PHOTO_CHEMIN n'est plus utilisée par le code. Une fois toute l'équipe à jour :
-- ALTER TABLE `prise` DROP COLUMN `PHOTO_CHEMIN`;

-- ---------------------------------------------------------------------
-- 4. NOTE.TXT — ÉPINGLER DES SESSIONS SUR SON PROFIL
-- ---------------------------------------------------------------------
ALTER TABLE `session_p`
  ADD COLUMN `EPINGLEE` tinyint(1) NOT NULL DEFAULT 0,
  ADD COLUMN `DATE_EPINGLE` datetime DEFAULT NULL,
  ADD KEY `I_SESSION_EPINGLEE` (`ID_UTILISATEUR`, `EPINGLEE`);

-- ---------------------------------------------------------------------
-- 5. NOTE.TXT — MODÉRATION DES IMAGES PUBLIÉES (signalements)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `signalement` (
  `ID_SIGNALEMENT` int(11) NOT NULL AUTO_INCREMENT,
  `ID_SIGNALEUR` int(11) DEFAULT NULL,
  `TYPE_CONTENU` enum('photo_prise','photo_profil','banniere') NOT NULL,
  `ID_PHOTO` int(11) DEFAULT NULL,
  `ID_UTILISATEUR_CIBLE` int(11) NOT NULL,
  `MOTIF` varchar(50) NOT NULL,
  `COMMENTAIRE` varchar(255) DEFAULT NULL,
  `DATE_SIGNALEMENT` datetime NOT NULL DEFAULT current_timestamp(),
  `STATUT` enum('ouvert','traite') NOT NULL DEFAULT 'ouvert',
  PRIMARY KEY (`ID_SIGNALEMENT`),
  KEY `I_SIGNALEMENT_STATUT` (`STATUT`, `TYPE_CONTENU`),
  KEY `I_FK_SIGNALEMENT_PHOTO` (`ID_PHOTO`),
  KEY `I_FK_SIGNALEMENT_SIGNALEUR` (`ID_SIGNALEUR`),
  KEY `I_FK_SIGNALEMENT_CIBLE` (`ID_UTILISATEUR_CIBLE`),
  CONSTRAINT `FK_SIGNALEMENT_PHOTO` FOREIGN KEY (`ID_PHOTO`) REFERENCES `photo_prise` (`ID_PHOTO`) ON DELETE CASCADE,
  CONSTRAINT `FK_SIGNALEMENT_SIGNALEUR` FOREIGN KEY (`ID_SIGNALEUR`) REFERENCES `utilisateur` (`ID_UTILISATEUR`) ON DELETE SET NULL,
  CONSTRAINT `FK_SIGNALEMENT_CIBLE` FOREIGN KEY (`ID_UTILISATEUR_CIBLE`) REFERENCES `utilisateur` (`ID_UTILISATEUR`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- 6. NOTE.TXT — ESPÈCES MANQUANTES (coquette, merlan, cernier)
--    + lamproie de Planer (image asset/espece_png/lamproie_planer.png déjà présente mais inutilisée)
-- ---------------------------------------------------------------------
INSERT INTO `espece` (`NOM_COM`, `NOM_SCIEN`, `DESCRIPTION`, `REPRODUCTION`, `HABITAT_INFO`, `TAILLE_MAX`, `POIDS_MAX`, `ICONE_CHEMIN`)
SELECT * FROM (
  SELECT 'Merlan' AS NOM_COM, 'Merlangius merlangus' AS NOM_SCIEN,
         'Le merlan (Merlangius merlangus) est un poisson marin de la famille des Gadidés, cousin du lieu et du tacaud. Corps allongé argenté au dos gris-vert, petite tache sombre à la base de la nageoire pectorale et mâchoire supérieure plus longue que l''inférieure. Pêché du bord comme en bateau, surtout en automne et en hiver.' AS DESCRIPTION,
         'Ponte en pleine eau de janvier (golfe de Gascogne) à mai plus au nord. Maturité vers 2 ans, pour une trentaine de centimètres.' AS REPRODUCTION,
         'Fonds sableux et vaseux entre 10 et 200 m. Les jeunes restent près des plages et des digues, les gros sujets fréquentent roches, ridens et épaves.' AS HABITAT_INFO,
         70 AS TAILLE_MAX, 3.20 AS POIDS_MAX, NULL AS ICONE_CHEMIN
  UNION ALL
  SELECT 'Coquette', 'Labrus mixtus',
         'La coquette (Labrus mixtus), ou vieille coquette, est un labridé aux couleurs spectaculaires : le mâle est bleu vif et orangé, la femelle orange avec des taches noires et blanches sur l''arrière du dos. Poisson curieux des fonds rocheux, souvent pris au leurre souple ou à l''appât près des roches.',
         'Hermaphrodite protogyne : les poissons naissent femelles et certains deviennent mâles vers 25 cm. Ponte au printemps ; le mâle aménage et défend le site de ponte.',
         'Fonds rocheux, tombants et éboulis, le plus souvent entre 20 et 80 m de profondeur.',
         40, NULL, NULL
  UNION ALL
  SELECT 'Cernier', 'Polyprion americanus',
         'Le cernier (Polyprion americanus) est un grand poisson massif à grosse tête, de la famille des Polyprionidés. Les jeunes, pélagiques, accompagnent les objets flottants ; les adultes vivent en solitaires près du fond. Croissance lente et maturité tardive : espèce sensible à la surpêche.',
         'Sexes séparés. Maturité tardive (vers 80 à 90 cm, après une dizaine d''années). Ponte en profondeur, à des périodes variables selon les régions.',
         'Fonds rocheux, épaves et tombants du talus continental, généralement entre 40 et 600 m. Les juvéniles dérivent près de la surface sous les épaves flottantes.',
         210, 100.00, NULL
  UNION ALL
  SELECT 'Lamproie de Planer', 'Lampetra planeri',
         'La lamproie de Planer (Lampetra planeri) est une petite lamproie d''eau douce, non parasite. Sa larve (ammocète) vit plusieurs années enfouie dans les sédiments ; l''adulte ne se nourrit plus et ne vit que quelques mois. Espèce d''intérêt communautaire (directive Habitats) dont les frayères sont protégées en France.',
         'Au printemps, les adultes creusent un nid dans le gravier, pondent en groupe puis meurent.',
         'Ruisseaux et petites rivières à eau fraîche et bien oxygénée, sur fonds de graviers et de sable.',
         20, NULL, 'asset/espece_png/lamproie_planer.png'
) nouvelles
WHERE NOT EXISTS (SELECT 1 FROM `espece` e WHERE e.`NOM_SCIEN` = nouvelles.NOM_SCIEN);

-- Milieux (table habiter) — les noms correspondent à la table habitat
INSERT IGNORE INTO `habiter` (`ID_HABIT`, `ID_ESPECE`)
SELECT h.`ID_HABIT`, e.`ID_ESPECE`
FROM (
  SELECT 'Merlangius merlangus' AS sci, 'Mer / Océan' AS hab UNION ALL
  SELECT 'Merlangius merlangus', 'Plage / Baïne' UNION ALL
  SELECT 'Merlangius merlangus', 'Fonds sableux / Vaseux' UNION ALL
  SELECT 'Merlangius merlangus', 'Épave' UNION ALL
  SELECT 'Labrus mixtus', 'Mer / Océan' UNION ALL
  SELECT 'Labrus mixtus', 'Plateau rocheux / Tombant' UNION ALL
  SELECT 'Polyprion americanus', 'Mer / Océan' UNION ALL
  SELECT 'Polyprion americanus', 'Plateau rocheux / Tombant' UNION ALL
  SELECT 'Polyprion americanus', 'Pleine eau (Pélagique)' UNION ALL
  SELECT 'Polyprion americanus', 'Épave' UNION ALL
  SELECT 'Lampetra planeri', 'Ruisseau' UNION ALL
  SELECT 'Lampetra planeri', 'Rivière'
) l
JOIN `espece` e ON e.`NOM_SCIEN` = l.sci
JOIN `habitat` h ON h.`NOM_HABIT` = l.hab;

-- Répartition géographique (table se_trouver)
INSERT IGNORE INTO `se_trouver` (`ID_ESPECE`, `ID_REPARTITION`)
SELECT e.`ID_ESPECE`, r.`ID_REPARTITION`
FROM (
  SELECT 'Merlangius merlangus' AS sci, 'Océan Atlantique' AS zone UNION ALL
  SELECT 'Merlangius merlangus', 'Mer Méditerranée' UNION ALL
  SELECT 'Labrus mixtus', 'Océan Atlantique' UNION ALL
  SELECT 'Labrus mixtus', 'Mer Méditerranée' UNION ALL
  SELECT 'Polyprion americanus', 'Océan Atlantique' UNION ALL
  SELECT 'Polyprion americanus', 'Mer Méditerranée' UNION ALL
  SELECT 'Lampetra planeri', 'Europe'
) l
JOIN `espece` e ON e.`NOM_SCIEN` = l.sci
JOIN `repartition_monde` r ON r.`NOM_REPARTITION` = l.zone;
