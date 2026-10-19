# Mon P'tit Carnet — Évolutions V0.3

Branche `evolutions-v0.3`, partie de `main` (audit V0.2 fusionné). Cinq modules ajoutés, l'interface revue pour le bord de l'eau, CGU et mentions légales mises à jour.

> Rédigé et développé avec l'aide d'une IA (Claude), puis testé : 91 tests de bout en bout V0.3, 26 tests dans un vrai navigateur mobile, et les 68 tests V0.2 qui passent toujours.

## Mettre à jour son poste

1. Récupérer la branche, puis **sauvegarder la base** (phpMyAdmin > Exporter).
2. Exécuter **une fois** `BDD/migrations/2026-10-19_v0.3_evolutions.sql` sur la base `mlr1` (onglet Importer). La migration V0.2 doit déjà être passée. Un second lancement s'arrête dès la 1re ligne (#1060), sans rien modifier.
3. Facultatif, réseau nécessaire : `php BDD/maj_conditions.php` complète les anciennes sessions (département, tendance de pression, débit). Sans cela, elles comptent moins dans « Conditions gagnantes ».
4. Les miniatures des images sont déjà générées (`asset/mini/`). Après l'ajout d'une image d'espèce : `php BDD/miniatures.php`.
5. L'application installable (PWA), le cache hors-ligne et les notifications exigent **HTTPS** en production (ou `localhost` en développement).

## Ce qui change dans la base

| Objet | Rôle |
|---|---|
| `reglementation` | Une ligne = une contrainte (taille, quota, période) pour une espèce et une zone. 55 règles de référence fournies. |
| `zone_reserve` | Polygones GeoJSON des réserves, saisis par l'admin. Vide au départ. |
| `carte_peche` | Une carte par membre (fichier, aperçu, contenu du QR, validité). |
| `cache_api` | Cache des appels Vigicrues, Hub'eau, geo.api.gouv.fr et Open-Meteo. |
| `session_p` + 9 colonnes | Tendance de pression 3 h / 24 h, débit et rapport à la moyenne, station, cours d'eau, milieu, façade, département. **Aucune coordonnée.** |
| `spot` + 2 colonnes | Catégorie piscicole (1re/2e), département. |
| `utilisateur.PARTAGE_COMMUNAUTE` | Choix de participer aux statistiques communautaires (oui par défaut, modifiable). |

Les URL des services externes sont des constantes (`API_HUBEAU`, `API_VIGICRUES`, `API_GEO`, `API_OPEN_METEO`, `API_MAREE`) redéfinissables dans `BDD/env.local.php`. Les tests s'en servent pour simuler les API.

---

## 1. Conditions gagnantes (`conditions.php`, `includes/conditions.php`)

**Mode « Pour moi ».** Pour un spot ou la position actuelle, chaque heure de 5 h à 22 h sur 3 jours est comparée aux 5 meilleures sorties du pêcheur. Les meilleures sorties sont celles avec le plus de poissons, puis les plus gros. On les cherche d'abord sur ce spot, puis dans ce milieu, puis partout.

La comparaison est une distance pondérée. Chaque écart est ramené entre 0 et 1 par une échelle, puis pondéré :

| Critère | Poids | Écart jugé « différent » |
|---|---|---|
| Tendance de pression sur 3 h | 1,5 | 2 hPa |
| Débit / moyenne 30 jours | 1,3 | 0,4 |
| Coefficient de marée | 1,2 | 25 |
| Pression, tendance 24 h, état de marée | 1,0 | 8 hPa, 5 hPa, état différent |
| Vent | 0,8 | 12 km/h |
| Heure de la journée | 0,7 | 3 h |
| Température | 0,6 | 6 °C |

Le score d'une heure est la moyenne des 3 meilleures similarités. L'écran affiche le score actuel, le meilleur créneau, la courbe heure par heure et les sorties de référence. Il explique aussi le résultat en clair, par exemple « Pression en baisse, comme lors de 4 de tes 5 meilleures sorties ». Le calcul se fait côté serveur : les coordonnées du spot ne quittent jamais le serveur.

**Mode « Communauté ».** Les poissons de tous les membres sont répartis par condition : tendance, pression, débit, coefficient, état de marée, vent, moment de la journée, saison. On regroupe par espèce et par zone large (façade, département, cours d'eau, milieu). Exemple d'affichage : « 78 % des prises avec une pression en baisse ».

**Confidentialité.** Seules l'espèce, les conditions et la zone large sont lues, jamais un point GPS, un nom de spot ou une identité. Un résultat n'apparaît qu'à partir de **10 prises venant d'au moins 3 pêcheurs**, pour la zone comme pour chaque critère. La liste des secteurs ne propose que ceux qui atteignent ce seuil. Un membre peut retirer ses prises dans Paramètres.

**Données capturées à la saisie.** À l'étape 2, le navigateur récupère la pression de la veille pour calculer les tendances 3 h et 24 h. Il demande aussi le débit au début de la session (`fetch_riviere.php?action=session`, obs. temps réel ou débit moyen du jour) et son rapport à la moyenne des 30 jours précédents. Le serveur déduit le milieu, la façade (calcul local) et le département (geo.api.gouv.fr, mis en cache 1 an).

## 2. Garde-fou réglementaire (`includes/reglementation.php`, `script/reglementation.js`)

- **Contexte.** Le milieu vient du type de spot, de l'interrupteur « mer » ou du type de pêche. S'y ajoutent la catégorie piscicole (demandée à l'étape 2 et modifiable dans Mes spots), la façade, la latitude (48e parallèle), le département et la date de la session.
- **Règles.** Chaque ligne peut porter une taille minimale, un quota journalier (partagé ou non, par exemple « carnassiers » = 3), une période de fermeture (« pêche interdite » ou « no-kill obligatoire »), le marquage et la déclaration. Une règle départementale remplace la règle nationale du même type.
- **Dates mobiles.** Format `MM-JJ` ou `rang-jour-mois[±décalage]`. Par exemple, `last-sun-01+1` correspond au lendemain du dernier dimanche de janvier. Cela couvre les périodes « du 2e samedi de mars au 3e dimanche de septembre » sans les ressaisir chaque année.
- **À la saisie.** Les règles du lieu et du jour sont calculées par le serveur puis évaluées dans le navigateur à chaque frappe, sans réseau. Les quotas cumulent les prises déjà gardées ce jour-là et les autres cartes de la saisie. Une alerte rouge propose « Je le relâche » ou une confirmation explicite. Sinon, l'envoi est bloqué.
- **Côté serveur.** Le même contrôle est refait à l'enregistrement : un envoi qui contourne le JavaScript est refusé. Aucune « infraction » n'est stockée.
- **Information incomplète.** Si la catégorie ou le GPS manquent, l'alerte devient un avertissement conditionnel, par exemple « Si tu pêches en 2e catégorie : … », sans blocage.
- **Réserves.** L'admin dessine le polygone sur une carte ou colle un GeoJSON. Le point de la session est testé côté serveur et le point cliqué est testé dans le navigateur, avec alerte immédiate et vibration.
- **Administration.** `admin_reglementation.php` : ajouter, modifier ou désactiver des règles et des zones.

**Règles fournies (vérifiées le 19/10/2026, à revoir chaque année) :**
- **Eau douce :** périodes de la 1re catégorie, brochet (taille, fermeture en 2e catégorie, quota de 2), quota de 3 carnassiers, truites et ombre (tailles, quota de 10 salmonidés, périodes).
- **Espèces protégées :** lamproies, esturgeon, ange de mer, requin-taupe, anguille en mer.
- **Bar :** 42 cm. Au nord du 48e parallèle, 3 par jour et no-kill du 1er février au 31 mars ; au sud, 2 par jour ; en Méditerranée, 30 cm.
- **Autres espèces marines :** lieu jaune (42 cm, 2 par jour), tailles courantes (dorade royale, maquereau, merlan, plie, turbot, etc.).
- **Interdictions et quotas ciblés :** moratoire mérou et corb en Méditerranée, raie brunette dans le golfe de Gascogne, dorade rose en Bretagne, homard (2 par jour).

Les sources sont dans la colonne `SOURCE`. Les arrêtés préfectoraux étant souvent plus stricts, il faut saisir les règles locales par département.

## 3. Vigilance crues (`includes/hydro.php`, `fetch_riviere.php`, `script/app.js`)

- **Montée anormale.** Elle est détectée sur les relevés temps réel Hub'eau.
  - Débit : +15 / +30 / +60 % en 1 h, ou +30 / +60 / +100 % en 3 h, ce qui donne les niveaux 1 / 2 / 3. La hausse doit dépasser 0,5 m³/s pour éviter le bruit des petits ruisseaux.
  - Hauteur : +5 / +10 / +25 cm en 1 h.
- **Vigicrues.** On remonte de la station au territoire, puis au tronçon, puis à son niveau de vigilance (`InfoVigiCru.geojson`, mis en cache 15 min). Le niveau retenu est le plus grave des deux.
- **Page Météo > Rivière.** Un encart « Sécurité au bord de l'eau » affiche ces informations et un bouton « Me prévenir si l'eau monte ». La station surveillée est gardée sur le téléphone.
- **Vérification.** Elle a lieu toutes les 10 min, quand l'application est ouverte. En cas d'alerte : bandeau rouge en haut de toutes les pages, vibreur, et notification via le service worker si elle est autorisée. Une même alerte ne se répète pas pendant 6 h, sauf si elle s'aggrave.
- **Limite assumée.** Une vraie notification **application fermée** demande du Web Push : clés VAPID, bibliothèque `minishlink/web-push` via Composer, et une tâche planifiée serveur qui interroge les stations. C'est la suite logique, non incluse ici.

## 4. Porte-carte de pêche (`carte_peche.php`, `carte_fichier.php`, `includes/carte_peche.php`, `sw.js`)

- **Envoi.** On envoie le PDF (cartedepeche.fr) ou une photo. Dans le navigateur, PDF.js dessine la 1re page et rogne les marges blanches ; BarcodeDetector ou jsQR lit le QR code. Le serveur reçoit le fichier, l'aperçu et le texte du QR.
- **Stockage.** Les fichiers vont dans `stockage_prive/user_X/`, qui est interdit d'accès web (`Require all denied`) et ignoré par Git. Les images sont ré-encodées par GD, ce qui retire l'EXIF et le GPS. Les PDF sont vérifiés : signature, type réel, refus du JavaScript et des fichiers embarqués. Le fichier n'est servi que par `carte_fichier.php`, au seul propriétaire (404 pour les autres, 401 sans connexion). Remplacer, supprimer la carte ou supprimer le compte efface les fichiers.
- **Affichage.** Le QR code est réaffiché en très grand (bibliothèque `script/vendor/qrcode.js`, MIT), à côté de l'aperçu et de la date de validité. Un bouton passe en plein écran, et l'écran reste allumé (Wake Lock).
- **Hors-ligne.** Le service worker garde la page et les fichiers de la carte dans un cache dédié, que la déconnexion vide. L'accès se fait en un geste : bouton « Ma carte » de la barre du bas, encart d'accueil, raccourci de l'application installée. Les autres pages affichent une page « hors-ligne » qui renvoie vers la carte.

## 5. Interface mobile (`css/app.css`, `includes/navbar.php`, `includes/head.php`)

- **Thème « carnet étanche ».**
  - Couleurs : encre `#0e2a33` pour les textes et en-têtes, ardoise `#1f5a66` en couleur principale, orange bouée `#c4430c` pour l'action « Prise » et les alertes de sécurité, fond eau claire `#eef3f2`.
  - Contrastes : 7:1 ou plus pour le texte principal et secondaire, 4,5:1 ou plus pour les alertes et les boutons. Les en-têtes passent en aplat, plus lisible au soleil que les dégradés.
- **Police.** Atkinson Hyperlegible, dessinée par le Braille Institute pour distinguer clairement chaque lettre, même avec une vue fatiguée ou un écran ébloui. Les formulaires sont en 16 px, ce qui évite le zoom automatique d'iOS.
- **Zone du pouce.** La barre du bas a 5 cibles de 56 px ou plus, avec la bouée « Prise » au centre et « Ma carte » à côté. Les sélecteurs de vue (Météo/Marée/Rivière, Pour moi/Communauté) sont posés au-dessus de cette barre. Sur les formulaires, le bouton d'enregistrement reste collé en bas.
- **Retours visuels.** Appui (léger rétrécissement), anneau de focus orange, vibrations sur les alertes.
- **Mode « plein soleil ».** Noir sur blanc, bordures de 2 px, aucune ombre (Paramètres ou bouton soleil de la carte).
- **Performance.** Les 150 icônes d'espèces pesaient 118 Mo, en PNG allant jusqu'à 2 752 px de large et affichés à 35 px. Leurs miniatures WebP pèsent 2 Mo au total, et les listes chargent les images à la demande.
- **Corrections au passage.** Sur la page Météo, l'heure est maintenant demandée à Open-Meteo en heure locale (elle était en UTC). Le titre de la page est corrigé.

## 6. CGU et mentions légales

- **CGU, articles 6 à 9 :**
  - conservation et sécurité de la carte de pêche, avec une copie locale effacée à la déconnexion ;
  - non-responsabilité sur les alertes crues ;
  - caractère indicatif des informations réglementaires ;
  - usage anonymisé et agrégé des prises, avec les seuils et le droit d'opposition.
- **Mentions légales :**
  - finalité, base légale et durée de conservation de la carte et des statistiques ;
  - sources (Hub'eau, Vigicrues, geo.api.gouv.fr sous Licence Ouverte) et bibliothèques ;
  - stockage sur l'appareil (cache, thème, station surveillée).

## Limites connues

- Aucune zone de réserve n'est fournie : il faut les saisir à partir des arrêtés officiels.
- Les notifications ne partent que si l'application est ouverte (voir la partie 3).
- Un script caché dans un flux PDF compressé n'est pas détectable sans analyseur PDF complet. Le fichier n'est de toute façon servi qu'à son propriétaire.
- Le 1er affichage d'une page dépend encore des CDN (Bootstrap, Leaflet, polices) ; ensuite, le service worker les garde en cache.
- Pour une nouvelle version des fichiers CSS ou JS, il faut changer `?v=3` dans `includes/head.php`, `sw.js` et `offline.html`, ainsi que `VERSION` dans `sw.js`.

---

## Monétiser sans abîmer la communauté

Un principe d'abord : **la sécurité et la légalité restent gratuites**. Alertes crues, garde-fou réglementaire et porte-carte ne passent jamais derrière un paiement. Les spots ne sont jamais vendus, et aucune publicité n'apparaît dans le carnet.

**1. Freemium « Carnet+ ».**
- Gratuit : le carnet, la carte, les alertes et les statistiques de base.
- Payant :
  - conditions gagnantes sur 7 jours, avec alerte « ton créneau idéal arrive » sur plusieurs spots ;
  - statistiques avancées (croisements espèce × leurre × conditions) ;
  - photos en haute définition ;
  - export et sauvegarde du carnet.
- C'est le modèle de Fishbrain : le carnet est gratuit, les cartes détaillées et les prévisions sont payantes.
- Prix de départ à tester : 2,99 €/mois ou 19,99 €/an.
- Le coût marginal est faible, puisque les API utilisées sont gratuites.

**2. Partenariats avec les AAPPMA et les fédérations.**
- Un espace payant à l'année pour chaque structure, avec trois services :
  - publier elle-même ses règles locales et ses réserves, directement dans la table `reglementation` ;
  - annoncer alevinages, ouvertures et concours ;
  - recevoir des statistiques anonymes de fréquentation et d'espèces sur ses parcours (mêmes seuils d'anonymat).
- La réglementation devient fiable parce qu'elle est tenue par ceux qui la font.
- Les AAPPMA prescrivent ensuite l'application à leurs adhérents.

**3. Affiliation matériel, discrète et contextuelle.**
- Lien « racheter ce leurre » dans la boîte de pêche, seulement sur le matériel déjà possédé ou sur les leurres qui marchent dans les statistiques du pêcheur.
- Passe par les programmes d'affiliation des revendeurs (en direct ou via des plateformes d'affiliation).
- Le lien est signalé comme tel, et il n'y a aucune bannière.

**4. Guides et moniteurs de pêche.**
- Un annuaire des guides locaux, avec réservation de sorties.
- Rémunération par commission sur la sortie : c'est le modèle de FishingBooker, où la commission est un pourcentage du prix de la sortie, prélevé sous forme d'acompte.
- Variante plus simple : un abonnement de visibilité pour le guide.
- Lien naturel avec les conditions gagnantes : « ce week-end s'annonce bon, un guide est disponible ».

**5. Données agrégées pour la recherche et la gestion des milieux.**
- Cible : conventions avec des scientifiques, des fédérations ou des gestionnaires (suivi des captures de loisir, de la pression de pêche, du no-kill).
- Conditions : volontariat explicite (en plus du choix actuel), données agrégées seulement, transparence sur les partenaires.
- Rapporte surtout en crédibilité et en financements de projets, rarement en ventes directes.

**Ordre conseillé.**
1. Partenariats AAPPMA : ils apportent la crédibilité et les données réglementaires.
2. Freemium : c'est le revenu récurrent.
3. Affiliation : peu d'effort.
4. Guides : quand la base d'utilisateurs est suffisante dans une région.
