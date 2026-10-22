-- =====================================================================
-- Mon P'tit Carnet — Migration V0.4 de la base « mlr1 »
-- (fil d'actualité, classements du mois, profil enrichi, sessions masquables,
--  garde-fou biologique)
--
-- Prérequis : les migrations V0.2 (2026-10-13) et V0.3 (2026-10-19) doivent déjà être passées.
-- À exécuter dans phpMyAdmin : base mlr1 > onglet Importer > ce fichier.
-- IDEMPOTENTE : on peut la relancer sans erreur ; ce qui existe déjà est laissé tel quel.
-- (chaque ajout de colonne ou d'index vérifie d'abord information_schema ;
--  compatible MariaDB 10.4+ (XAMPP) et MySQL 8)
-- Faire une sauvegarde avant.
-- =====================================================================
SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1. SESSIONS : masquer une session sur son profil public
--    0 = visible des autres membres (jamais le lieu ni le GPS), 1 = privée.
--    Une session masquée n'apparaît ni sur le profil, ni dans le fil, ni dans les classements.
-- ---------------------------------------------------------------------
SET @sql = (SELECT IF(COUNT(*) = 0,
    "ALTER TABLE `session_p` ADD COLUMN `MASQUEE` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = session invisible pour les autres membres' AFTER `DATE_EPINGLE`",
    'DO 0') FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'session_p' AND COLUMN_NAME = 'MASQUEE');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;

-- ---------------------------------------------------------------------
-- 2. PROFIL ENRICHI
-- ---------------------------------------------------------------------
SET @sql = (SELECT IF(COUNT(*) = 0,
    "ALTER TABLE `utilisateur` ADD COLUMN `NIVEAU_EXPERIENCE` enum('debutant','intermediaire','confirme','expert') DEFAULT NULL COMMENT 'Niveau déclaré par le pêcheur'",
    'DO 0') FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'utilisateur' AND COLUMN_NAME = 'NIVEAU_EXPERIENCE');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;

SET @sql = (SELECT IF(COUNT(*) = 0,
    "ALTER TABLE `utilisateur` ADD COLUMN `ANNEE_DEBUT` smallint(6) DEFAULT NULL COMMENT 'Année où il a commencé la pêche'",
    'DO 0') FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'utilisateur' AND COLUMN_NAME = 'ANNEE_DEBUT');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;

SET @sql = (SELECT IF(COUNT(*) = 0,
    "ALTER TABLE `utilisateur` ADD COLUMN `CODE_DEPARTEMENT` varchar(3) DEFAULT NULL COMMENT 'Département affiché sur le profil (choisi par le pêcheur)'",
    'DO 0') FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'utilisateur' AND COLUMN_NAME = 'CODE_DEPARTEMENT');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;

SET @sql = (SELECT IF(COUNT(*) = 0,
    "ALTER TABLE `utilisateur` ADD COLUMN `MILIEU_PREFERE` enum('eau_douce','mer','les_deux') DEFAULT NULL",
    'DO 0') FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'utilisateur' AND COLUMN_NAME = 'MILIEU_PREFERE');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;

SET @sql = (SELECT IF(COUNT(*) = 0,
    "ALTER TABLE `utilisateur` ADD COLUMN `ID_ESPECE_FETICHE` int(11) DEFAULT NULL COMMENT 'Espèce de cœur affichée sur le profil'",
    'DO 0') FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'utilisateur' AND COLUMN_NAME = 'ID_ESPECE_FETICHE');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;

SET @sql = (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE `utilisateur` ADD CONSTRAINT `FK_UTILISATEUR_ESPECE_FETICHE` FOREIGN KEY (`ID_ESPECE_FETICHE`) REFERENCES `espece` (`ID_ESPECE`) ON DELETE SET NULL',
    'DO 0') FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'utilisateur' AND CONSTRAINT_NAME = 'FK_UTILISATEUR_ESPECE_FETICHE');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;

-- Techniques et types de pêche favoris (plusieurs par pêcheur)
CREATE TABLE IF NOT EXISTS `utilisateur_technique` (
  `ID_UTILISATEUR` int(11) NOT NULL,
  `ID_TECHNIQUE` smallint(6) NOT NULL,
  PRIMARY KEY (`ID_UTILISATEUR`, `ID_TECHNIQUE`),
  KEY `I_FK_UT_TECHNIQUE` (`ID_TECHNIQUE`),
  CONSTRAINT `FK_UT_UTILISATEUR` FOREIGN KEY (`ID_UTILISATEUR`) REFERENCES `utilisateur` (`ID_UTILISATEUR`) ON DELETE CASCADE,
  CONSTRAINT `FK_UT_TECHNIQUE` FOREIGN KEY (`ID_TECHNIQUE`) REFERENCES `technique` (`ID_TECHNIQUE`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Techniques favorites affichées sur le profil';

CREATE TABLE IF NOT EXISTS `utilisateur_type_session` (
  `ID_UTILISATEUR` int(11) NOT NULL,
  `ID_TYPE_SESSION` tinyint(4) NOT NULL,
  PRIMARY KEY (`ID_UTILISATEUR`, `ID_TYPE_SESSION`),
  KEY `I_FK_UTS_TYPE` (`ID_TYPE_SESSION`),
  CONSTRAINT `FK_UTS_UTILISATEUR` FOREIGN KEY (`ID_UTILISATEUR`) REFERENCES `utilisateur` (`ID_UTILISATEUR`) ON DELETE CASCADE,
  CONSTRAINT `FK_UTS_TYPE` FOREIGN KEY (`ID_TYPE_SESSION`) REFERENCES `type_session` (`ID_TYPE_SESSION`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Types de pêche favoris affichés sur le profil';

-- ---------------------------------------------------------------------
-- 3. INDEX : fil d'actualité et classements du mois
-- ---------------------------------------------------------------------
SET @sql = (SELECT IF(COUNT(*) = 0,
    'CREATE INDEX `I_SESSION_DATE_MASQUEE` ON `session_p` (`DATE_DEBUT`, `MASQUEE`)',
    'DO 0') FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'session_p' AND INDEX_NAME = 'I_SESSION_DATE_MASQUEE');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;

SET @sql = (SELECT IF(COUNT(*) = 0,
    'CREATE INDEX `I_SESSION_UTILISATEUR_MASQUEE` ON `session_p` (`ID_UTILISATEUR`, `MASQUEE`, `DATE_DEBUT`)',
    'DO 0') FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'session_p' AND INDEX_NAME = 'I_SESSION_UTILISATEUR_MASQUEE');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;

SET @sql = (SELECT IF(COUNT(*) = 0,
    'CREATE INDEX `I_PRISE_DATE` ON `prise` (`DATE_HEURE`)',
    'DO 0') FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'prise' AND INDEX_NAME = 'I_PRISE_DATE');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;

SET @sql = (SELECT IF(COUNT(*) = 0,
    'CREATE INDEX `I_PHOTO_STATUT` ON `photo_prise` (`ID_PRISE`, `STATUT_MODERATION`)',
    'DO 0') FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'photo_prise' AND INDEX_NAME = 'I_PHOTO_STATUT');
PREPARE etape FROM @sql; EXECUTE etape; DEALLOCATE PREPARE etape;

-- ---------------------------------------------------------------------
-- 4. GARDE-FOU BIOLOGIQUE : valeurs de référence trop basses dans le codex
--    (une carpe de 40 kg ou un brochet de 24 kg doivent rester saisissables)
--    Relancer ces lignes ne change rien : elles ne font que relever un plafond.
-- ---------------------------------------------------------------------
UPDATE `espece` SET `POIDS_MAX` = 50.00 WHERE `NOM_SCIEN` IN ('Cyprinus carpio', 'Cyprinus carpio specularis') AND (`POIDS_MAX` IS NULL OR `POIDS_MAX` < 50);
UPDATE `espece` SET `POIDS_MAX` = 25.00 WHERE `NOM_SCIEN` = 'Esox lucius' AND (`POIDS_MAX` IS NULL OR `POIDS_MAX` < 25);
