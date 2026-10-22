# Audit de la branche V0.2

Audit de sécurité et de qualité du commit `b5ddbad` (branche `V0.2`), avec les correctifs appliqués dans la branche `audit-securite-v0.2`. Les numéros de ligne renvoient au code **avant** correction.

> Rédigé avec l'aide d'une IA (Claude), relu et testé.

## En bref

| Sévérité | Nombre | État |
|---|---|---|
| Critique | 7 | corrigé |
| Important | 16 | corrigé |
| Mineur | 15 | corrigé |

**Validation.** 68 tests de bout en bout passent sur MariaDB 10.11 configuré comme un serveur Linux (`lower_case_table_names=0`, mode strict). Les tests couvrent l'inscription, le CSRF, l'upload piégé, les IDOR, les XSS, la modération et la suppression de compte. Les scripts JS ont aussi été exécutés dans un navigateur simulé.

### Mettre à jour son poste

1. Récupérer la branche, puis exécuter **une fois** `BDD/migrations/2026-10-13_v0.2_audit.sql` dans phpMyAdmin (base `mlr1`). Exporter la base avant.
2. Le nom de la base passe à `mlr1` en minuscules dans `BDD/env.php`. Il faut l'adapter si votre base locale s'appelle autrement. Les réglages propres à chaque poste vont dans `BDD/env.local.php`, qui est ignoré par Git.
3. Rien d'autre à faire. `includes/cacert.pem` permet aux appels HTTPS d'être vérifiés sous XAMPP sans désactiver SSL.

### Le socle commun

Tout passe désormais par `includes/bootstrap.php` :
- une connexion PDO unique en utf8mb4 ;
- un cookie de session HttpOnly/SameSite ;
- un jeton **CSRF vérifié automatiquement sur chaque POST** ;
- `e()` pour échapper le HTML, `js()` pour injecter des données dans un `<script>` ;
- des erreurs journalisées sans détail affiché en production (`APP_DEBUG`).

Les images passent par `includes/images.php`, qui vérifie, ré-encode et supprime les fichiers de façon confinée.

---

## 1. Audit technique et sécurité

### Critique

#### C1. Tickets du support accessibles à tous les membres
`admin_tickets.php:6`. Seule la connexion était vérifiée, pas le rôle admin. Tout membre pouvait lire les emails et messages des tickets (données personnelles), les fermer ou les supprimer.
```php
// Avant
if (!isset($_SESSION['user_id'])) { header('Location: connexion.php'); exit(); }
// Après
require_once './includes/bootstrap.php';
exiger_admin();
```

#### C2. Suppression des prises et photos d'un autre membre (IDOR)
`detail_session.php:21-36`. Les photos et les prises étaient supprimées pour `ID_SESSION = ?` **sans vérifier le propriétaire**. Seule la dernière requête vérifiait `ID_UTILISATEUR`. Il suffisait de poster `delete_session` sur `detail_session.php?id=<id d'un autre>`.
```php
// Avant : DELETE FROM PRISE WHERE ID_SESSION = ?   (aucun contrôle)
// Après : la session est chargée avec AND s.ID_UTILISATEUR = :id_user AVANT toute action,
// sinon redirection ; les suppressions se font ensuite en transaction.
```

#### C3. Chemin de photo choisi par le client, d'où une suppression de fichiers arbitraire
`nouvelle_session.php:222`, `form_prise.php:124`, `detail_session.php:29`. Le chemin de la photo venait d'un champ caché (`prises[i][photo]`) et était enregistré tel quel. On pouvait y mettre `BDD/env.php` ou la photo d'un autre membre. Une modification ou une suppression de la prise appelait ensuite `unlink()` sur ce chemin.
```php
// Avant
!empty($p['photo']) && $p['photo'] !== 'no_photo' ? $p['photo'] : null,
// Après : seuls les chemins envoyés à l'étape 1 de CETTE saisie sont acceptés
if (isset($photos_session[$chemin]) && !isset($photos_utilisees[$chemin])) { ... }
// et toute suppression passe par :
supprimer_fichier_utilisateur($chemin, $id_user); // realpath() confiné à uploads/user_X/
```

#### C4. Upload sans validation
`nouvelle_session.php:37`, `form_prise.php:113`, `modifier_profil.php:34`. N'importe quel contenu était copié dans `uploads/` sous une extension `.jpg`, y compris un script PHP ou du HTML. Le recadrage base64 était écrit sans contrôle. Si la compression échouait, l'original restait sur le disque **avec son GPS EXIF**, alors que les CGU promettent que le GPS reste privé.
```php
// Avant
move_uploaded_file($tmp_name, $chemin_final);  compresserImage($chemin_final, $chemin_final, ...);
// Après (includes/images.php)
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);          // type réel
if (!in_array($mime, UPLOAD_TYPES_AUTORISES, true)) return null;
$exif = @exif_read_data($tmp);                                // lu AVANT nettoyage
if (!compresserImage($tmp, $chemin, 80, $maxWidth)) { ... return null; }  // ré-encodage GD
```
En complément, `uploads/.htaccess` interdit l'exécution de scripts. Les fichiers sont limités à 15 Mo et 50 Mpx, et les dossiers sont créés en `0755` au lieu de `0777`.

#### C5. Aucune protection CSRF (19 formulaires)
C'est le cas de tous les fichiers à formulaire POST. Une page piégée visitée par un admin connecté pouvait poster `action=promouvoir` sur `admin_compte.php` et donner les droits admin à l'attaquant. Le même procédé permettait de supprimer un compte ou d'injecter un spot.
```php
// Après : vérification centrale (bootstrap.php) + champ dans chaque formulaire
<?= csrf_field() ?>
if (!hash_equals(csrf_token(), $jeton)) app_page_message('Formulaire expiré', ..., 419);
```

#### C6. Inscription impossible
`inscription.php:2`. La page incluait `auth_bdd.php`, qui redirige tout visiteur **non connecté**, donc personne ne pouvait créer de compte.
```php
// Avant
require_once './includes/auth_bdd.php';
// Après
require_once './includes/bootstrap.php';
if (utilisateur_id()) { header('Location: accueil.php'); exit(); }
```
La même page valide désormais pseudo, email et mot de passe (8 caractères minimum), et un index `UNIQUE` sur `MAIL` bloque les doublons concurrents.

#### C7. Session impossible à enregistrer sans météo
`nouvelle_session.php:87-91`. Sans réseau au bord de l'eau, ou sans clic sur la carte, les champs météo arrivent vides. MariaDB (XAMPP) est en mode strict, et `''` dans une colonne `tinyint` déclenche l'erreur 1366 : la session est perdue.
```php
// Avant
'temp' => $_POST['meteo_temp'] ?? null,
// Après
$temp = nombre_ou_null($_POST['meteo_temp'] ?? null, -60, 60);
'temp' => $temp === null ? null : (int) round($temp),
```

### Important

| # | Fichier : ligne | Risque | Correctif |
|---|---|---|---|
| I1 | `nouvelle_session.php:110,155,203-207`, `editer_session.php:23`, `form_prise.php:79-80` | Un spot, leurre ou appât d'un autre membre pouvait être rattaché à sa session. Son nom fuitait ensuite dans `detail_session`. | Chaque ID est vérifié : `WHERE ID_SPOT = ? AND ID_UTILISATEUR = ?`. Pour le matériel, le sien ou le matériel commun (`ID_UTILISATEUR IS NULL`). |
| I2 | `boite_peche.php:12,24`, `mes_spots.php:17` | `UPDATE PRISE SET ID_LEURRE = NULL WHERE ID_LEURRE = ?` s'exécutait avant le contrôle de propriété. N'importe qui pouvait délier un leurre, y compris le matériel commun, de toutes les prises du site. | Contrôle de propriété d'abord, puis transaction. La base gère aussi `ON DELETE SET NULL`. |
| I3 | `form_boite.php:109,125` | XSS réfléchie : `form_boite.php?action="><script>…` | Les valeurs passent par une liste fermée (`'edit'`/`'add'`, `'leurre'`/`'appat'`) puis par `e()`. |
| I4 | `mes_spots.php:8,138,219,235`, `meteo.php:72` | `lat`/`lng` étaient du texte libre, réinjecté dans `onclick="focusMap(...)"`, `data-lat` et une popup Leaflet. C'est une XSS stockée, exploitable sur un autre membre via C5. | `localisation_ou_null()` impose des nombres bornés. Sortie via `(float)`, `js()` et `escapeHtml()`. |
| I5 | `script/gestion_prise.js:16-18,89` | Noms de leurres insérés bruts dans `innerHTML` et dans `value="${nom}"`. Un leurre « Shad 3" » était tronqué, une apostrophe cassait le clic. | `escapeHtml()`, `data-*` avec délégation d'événement, champs créés avec `.value`. |
| I6 | `connexion.php:22` | Pas de `session_regenerate_id` (fixation de session) et pas de limite d'essais. | `connecter_utilisateur()` régénère la session. Table `tentative_connexion` : 5 échecs en 15 minutes. |
| I7 | `parametre.php:35` | `\|\| $password_fourni === $userData['mot_de_passe']` : le **hash** suffisait à supprimer le compte. | Uniquement `password_verify()`. Suppression en transaction, avec le dossier photos. |
| I8 | `admin_compte.php:33` | `(int) === (string)` est toujours faux : la protection « pas soi-même » ne marchait pas. | Comparaison d'entiers. La suppression d'un compte efface aussi son dossier `uploads/` (RGPD). |
| I9 | `fetch_maree.php:19-20`, `fetch_riviere.php:19-21` | Vérification TLS désactivée : attaque de l'homme du milieu possible, et la clé API circule dans l'URL. | `http_get()` avec `CURLOPT_CAINFO` = `includes/cacert.pem`. |
| I10 | `fetch_riviere.php` (aucune auth), `BDD/maj_wikipedia.php` | Le proxy Hub'eau était ouvert à tout Internet. Le script Wikipedia pouvait être lancé par n'importe quel visiteur. | Connexion exigée et `session_write_close()`. Script en ligne de commande uniquement. `BDD/.htaccess` et `includes/.htaccess` refusent l'accès direct. |
| I11 | `connexion.php:31`, `nouvelle_session.php:131,236` et 22 autres | `die($e->getMessage())` affiche la structure SQL et les chemins du serveur. | `app_fatal()` journalise et affiche un message générique. Détail visible seulement si `APP_DEBUG`. |
| I12 | Toutes les requêtes, `BDD/env.php:3`, `includes/auth_bdd.php:7` | Le code utilisait `UTILISATEUR` et `'MLR1'` alors que le schéma déclare `utilisateur` et `mlr1`. `./bdd/env.php` pointait vers un dossier `BDD`. **L'application ne démarre pas sur un serveur Linux.** | Tables en minuscules (commit dédié), `mlr1`, chemins basés sur `__DIR__`. |
| I13 | `fetch_maree.php:44` vs schéma | Le schéma déclare `cache_maree.ID_PORT varchar(20)`. « port-en-bessin-huppain » dépasse cette taille : erreur 1406 et plus de marée pour ce port. | Migration en `varchar(100)` et `mediumtext`. Le `CREATE TABLE` exécuté à chaque appel a été retiré. |
| I14 | `fetch_maree.php:9`, `script/meteo_map.js:93` | La marée était **toujours celle du jour**. Une session saisie après coup recevait un coefficient et un état faux. | Paramètre `date` validé et transmis par le JS. Cache par port et par date, `REPLACE`. |
| I15 | `script/meteo.js:160` | `Promise.all` : si la marée échoue (clé absente, `cle/` est ignoré par Git), **toute la page météo** tombe en erreur. | La marée est attrapée à part. L'API météo suffit à afficher la page. |
| I16 | `script/meteo_map.js:60` | `surface_pressure` dépend de l'altitude (931 hPa enregistrés dans le Jura). Les statistiques de pression mélangeaient des valeurs non comparables. | `pressure_msl` (niveau de la mer), la référence des cartes météo. |

### Mineur

| # | Fichier : ligne | Problème | Correctif |
|---|---|---|---|
| M1 | `boite_peche.php:156` | `NOM_TYPE_LEURRE` n'existe pas (la requête renvoie `NOM_TYPE`) : le type affichait toujours « Non classé ». | Bonne colonne. |
| M2 | `detail_session.php:186` | `href="detail_session.css"` renvoyait une erreur 404. | `css/detail_session.css` |
| M3 | `editer_session.php:52` | `!empty()` transformait 0 °C et un vent nul en `NULL`. | `nombre_ou_null()` |
| M4 | `BDD/BDD_stat.php:108`, `admin.php:68` | `ORDER BY ASC LIMIT 12` affiche les **12 premiers** mois : le graphique se fige. | Sous-requête `DESC LIMIT 12`, puis tri croissant. |
| M5 | `BDD/BDD_stat.php:128` | Ratio horaire divisé par les seules sessions ayant pris à cette heure, ce qui gonfle les heures peu pêchées. | Division par le nombre total de sessions. |
| M6 | Données `type_session` | 5 chemins en minuscules (`leurre.jpg`) alors que les fichiers sont `Leurre.jpg` : images cassées sous Linux. | `UPDATE` dans la migration. |
| M7 | `script/JS_session.php:124` | `step="0.5"` sur `TAILLE_CM smallint` : 45,5 devient 46 sans prévenir, et une taille négative provoque une erreur SQL. | `step="1"` et une valeur bornée côté serveur. |
| M8 | `contact.php:29-31` | `htmlspecialchars` avant stockage, puis à l'affichage : « &amp;#039; ». Pas d'anti-spam. | Stockage brut, échappement à l'affichage, champ piège, 1 ticket par minute, validations. Les anciens tickets sont décodés par la migration. |
| M9 | `nouvelle_session.php:458`, `script/meteo_map.js:184` | Script de carte chargé aux étapes 1, 3 et 4, sans carte : `TypeError`. | Chargé à l'étape 2 seulement, avec une garde `if (!document.getElementById('map'))`. |
| M10 | `script/JS_session.php:19` | Suppression d'aperçu **par nom de fichier**. Sur iPhone, les photos s'appellent souvent toutes `image.jpg` : on les retirait toutes. Le nom était aussi injecté dans `onclick`. | Identifiant unique par fichier, `addEventListener`. |
| M11 | `codex.php:104` | `strtolower` ne gère pas « É ». Le filtre de milieu se faisait par sous-chaîne. | `mb_strtolower`, recherche insensible aux accents, comparaison exacte des tags. |
| M12 | `accueil.php:29` | Nettoyage des fichiers à chaque affichage du tableau de bord. Il pouvait supprimer les photos d'une saisie en cours depuis plus de 2 h. | Au plus 1 fois par heure, en épargnant les photos de la saisie en cours. |
| M13 | `editer_session.php` | Changer la date d'une session laissait ses prises à l'ancienne date. | Les prises suivent la date de la session. |
| M14 | `statistiques.php:25`, `meteo.php`, `admin.php` | Chart.js sans version : une version majeure peut tout casser. | `chart.js@4.4.4` |
| M15 | `nouvelle_session.php:140` | Un double envoi de l'étape 3 (bouton retour) créait une session en double. | Drapeau `terminee` en session et bouton désactivé à l'envoi. |

**Hors périmètre, à prévoir.** Un en-tête CSP, et HTTPS obligatoire en production avec un cookie `Secure` (automatique si HTTPS). Supprimer la colonne `prise.PHOTO_CHEMIN` quand toute l'équipe aura migré. Le dump SQL contient des hash de mots de passe et des emails : le dépôt est public, il ne faut pas le committer.

---

## 2. Demandes de `note.txt`

| Demande | Ce qui a été fait |
|---|---|
| Codex : « asset a changé » | Les 144 chemins `ICONE_CHEMIN` pointent bien vers des fichiers existants. Trois images n'étaient référencées nulle part. `lamproie_planer.png` correspondait à une espèce absente, qui a été ajoutée. `dorade_grise.png` et `dorade_royale.png` sont des doublons de `daurade_*.png` : vous pouvez en supprimer une paire. |
| BDD : coquette, merlan, cernier | Ajoutés avec description, reproduction, habitat, tailles et poids max, milieux et répartition. Ils n'ont pas encore d'image : le Codex affiche l'icône par défaut. Il suffit de déposer `merlan.png`, `coquette.png` et `cernier.png`, puis de renseigner `ICONE_CHEMIN`. |
| Ajout session | Rubrique vide dans la note. La saisie a tout de même été fiabilisée (C3, C4, C7, I1, I14, M15). |
| Épingler des sessions | Jusqu'à 3 sessions par profil. Profil public `profil.php?id=X` et vue `session_publique.php`. |
| Modération des images (URGENT) | Signalement, masquage préventif et page `admin_moderation.php`. |
| Plusieurs photos par prise | Table `photo_prise`. Regroupement à l'étape 3 et gestion dans `form_prise.php`. |
| Autres stats | Prises par heure, durée moyenne, taux de capot, pression, marée (état et coefficient), saisonnalité, type de pêche. |

### Ordre d'implémentation (sans régression)

1. **Sécurité d'abord.** Les fonctionnalités « réseau social » rendent visibles aux autres membres des données qui ne l'étaient pas. Avec C2, C3 et C5 encore ouvertes, chaque nouvelle page publique aggravait le risque.
2. **Espèces et Codex.** Ce sont des ajouts de données seulement, sans impact sur le code.
3. **Plusieurs photos par prise.** C'est le seul changement de modèle (`PRISE.PHOTO_CHEMIN` devient la table `photo_prise`). Il doit passer **avant** la modération et l'épinglage, qui raisonnent photo par photo : le statut de modération vit sur `photo_prise`.
4. **Modération.** Elle doit exister **avant** que le moindre contenu devienne public.
5. **Épinglage et profil public.** C'est lui qui ouvre le contenu aux autres.
6. **Statistiques.** Lecture seule, indépendantes du reste, elles peuvent passer à tout moment.

### Points délicats

**Migration des photos.** Les données existantes sont reprises sans rien casser. La colonne d'origine est conservée le temps que tout le monde migre :
```sql
CREATE TABLE photo_prise (
  ID_PHOTO int AUTO_INCREMENT PRIMARY KEY,
  ID_PRISE int NOT NULL,
  CHEMIN varchar(255) NOT NULL,
  ORDRE tinyint NOT NULL DEFAULT 0,
  STATUT_MODERATION enum('visible','signalee','masquee') NOT NULL DEFAULT 'visible',
  DATE_AJOUT datetime NOT NULL DEFAULT current_timestamp(),
  CONSTRAINT FK_PHOTO_PRISE FOREIGN KEY (ID_PRISE) REFERENCES prise (ID_PRISE) ON DELETE CASCADE
);
INSERT INTO photo_prise (ID_PRISE, CHEMIN, ORDRE, DATE_AJOUT)
SELECT ID_PRISE, PHOTO_CHEMIN, 0, COALESCE(DATE_HEURE, NOW()) FROM prise
WHERE PHOTO_CHEMIN IS NOT NULL AND PHOTO_CHEMIN <> '';
```

**Regrouper des photos à l'étape 3.** Chaque photo de l'étape 1 crée encore une carte. Le bouton « Même poisson que la prise au-dessus » déplace les vignettes et renomme leurs champs. Tout se fait côté navigateur, sans nouvel upload :
```js
items.forEach(item => {
    item.querySelector('input').name = `prises[${prev.dataset.index}][photos][]`;
    prev.querySelector('.photo-strip').appendChild(item);
});
```
Côté serveur, chaque chemin doit appartenir aux photos de la saisie et n'être utilisé qu'une fois (voir C3).

**Confidentialité des sessions épinglées.** La vue publique ne sélectionne **jamais** `spot`, `LOCALISATION`, `LATITUDE` ni `LONGITUDE`. Elle ne montre que les photos `visible`. Les fichiers ont été ré-encodés et ne contiennent plus de GPS EXIF. Les CGU (« coordonnées GPS strictement privées ») sont donc respectées.

**Modération a posteriori.** C'est le modèle annoncé dans les CGU :
- un signalement n'est possible que sur une image qu'on voit, c'est-à-dire la session épinglée d'un **autre** membre, et une seule fois par personne ;
- dès `SEUIL_SIGNALEMENTS_MASQUAGE` signalements (1 par défaut), la photo passe en `signalee` et disparaît pour les autres ;
- l'admin choisit alors de **conserver**, **masquer** (l'auteur la voit encore) ou **supprimer** (fichier compris) ;
- l'onglet « Photos publiques » permet en plus de passer en revue tout ce qui vient d'être publié.

---

## 3. Cinq fonctionnalités pour se démarquer

### 1. Carnet hors-ligne : saisir sans réseau, synchroniser au retour
Le bord de l'eau est souvent en zone blanche, et aujourd'hui la saisie exige une connexion à chaque étape.
- **Fonctionnement.** Le site devient une PWA (`manifest.json` et service worker qui met en cache le squelette des pages). Les prises sont enregistrées dans **IndexedDB** avec l'heure et la position GPS du téléphone, qui fonctionne sans réseau. Elles partent ensuite par `Background Sync` dès que la 4G revient.
- **Données récupérées après coup.** La météo et la marée sont retrouvées a posteriori à partir de l'horodatage. L'API d'archive Open-Meteo et le paramètre `date` de `fetch_maree.php` le permettent déjà.
- **Côté serveur.** Un endpoint `api/sync_prises.php` qui accepte un lot JSON. Une clé d'idempotence (UUID généré sur le téléphone) évite les doublons en cas de renvoi.

### 2. Saisie « mains mouillées » en 2 secondes
Sortir un poisson, le décrocher, le photographier et le relâcher vite ne laisse pas le temps de remplir un formulaire.
- **Bouton « Prise ! ».** Un très gros bouton sur l'accueil horodate et géolocalise immédiatement. Une grille propose ensuite **les 6 espèces que l'utilisateur prend le plus**, une requête qui existe déjà dans les stats, et la photo est facultative.
- **Saisie vocale.** La Web Speech API (`fr-FR`) permet de dicter « bar 62 relâché ». Le code extrait l'espèce, la taille et le no-kill, puis confirme par une vibration (`navigator.vibrate`).
- **Le reste plus tard.** Leurre, technique et poids se complètent depuis la session, au calme.

### 3. « Tes conditions gagnantes » : analyser ses propres données
Le carnet collecte déjà météo, pression, vent, marée et débit Hub'eau, mais ne s'en sert que pour des graphiques.
- **À stocker en plus.** La **tendance de pression** (écart sur 3 h et 24 h, calculé depuis la série horaire Open-Meteo déjà téléchargée) et le **débit Hub'eau au moment de la session** (débit et rapport à la moyenne). Aujourd'hui, le débit est affiché mais jamais enregistré.
- **Le calcul.** Pour un spot et une date, on compare les prévisions aux sessions passées les plus productives de l'utilisateur (simple distance pondérée entre conditions). On affiche par exemple « Conditions proches de 4 de tes 5 meilleures sorties : pression en baisse, coefficient 85, montant ». C'est un algorithme simple, explicable et propre à chaque pêcheur.
- **Notifications.** Elles viennent naturellement ensuite : prévenir quand le week-end ressemble à tes meilleures sorties.

### 4. Garde-fou réglementaire à la saisie
Garder un poisson sous la taille légale ou en période de fermeture expose à une amende, et beaucoup de pêcheurs ne connaissent pas les règles locales.
- **Une table `reglementation`.** Elle contient l'espèce, la zone (département et catégorie d'eau douce, ou façade maritime), la taille minimale, les dates de fermeture, le quota journalier et l'obligation de marquage.
- **Des alertes à la saisie.** Si la prise n'est pas relâchée, l'application prévient. Par exemple :
  - « Bar de 38 cm : la taille minimale est de 42 cm en Manche et en Atlantique. » En Manche, entre le 1er février et le 31 mars, seul le no-kill est autorisé, et le quota est de 3 bars par jour le reste de l'année ([Figaro Nautisme, oct. 2026](https://figaronautisme.meteoconsult.fr/actus-nautisme-loisirs/2026-10-11/92765-peche-en-mer-ce-que-vous-avez-vraiment-le-droit-de-garder-a-bord-en-2026)).
  - « Brochet en 2e catégorie : fermé du dernier lundi de janvier au dernier samedi d'avril. Taille minimale de 60 cm, 3 carnassiers par jour dont 2 brochets maximum. » Ces règles sont nationales mais **ajustées par chaque arrêté préfectoral** ([exemple Charente 2025](https://www.garat.fr/wp-content/uploads/2025/01/ARP_2025_signe.pdf)). La table doit donc être tenue par département.
- **Zones de réserve.** Des polygones GeoJSON (réserves de pêche des arrêtés préfectoraux, aires marines protégées) sont comparés au GPS de la session pour signaler « tu es dans une réserve ».
- **Déclaration du bar.** La déclaration numérique des captures de bar, obligatoire dans certains secteurs depuis 2026, pourrait être pré-remplie à partir du carnet.

### 5. Sécurité au bord de l'eau
La marée montante en pêche à pied et les montées d'eau brutales en rivière sont les vrais dangers du loisir.
- **Pêche à pied.** On calcule une heure de retour à partir des horaires de marée déjà récupérés (par exemple, retour avant basse mer + 2 h), avec un compte à rebours et une alerte sur le téléphone.
- **Rivière.** La tendance de débit Hub'eau existe déjà ; on y ajoute la vigilance **Vigicrues**. Un « débit en hausse rapide » déclenche un avertissement visible dans la session en cours.
- **Bouton « Je suis ici ».** Il prépare un SMS avec la position GPS, qui part même avec très peu de réseau, à envoyer à un proche.
