# Mon P'tit Carnet — Évolutions V0.4

Branche `evolutions-v0.4`, partie de `main`. Elle reprend les idées de `note.txt` : fil d'actualité et classements, garde-fou biologique, photos par prise à l'étape 3, profil enrichi, statistiques corrigées, marées sur 7 jours, QR à l'envers, page Réglementation ouverte à tous.

> Développé avec l'aide d'une IA (Claude), puis testé : 118 tests de bout en bout V0.4 et 48 dans un vrai navigateur mobile (390 px). Les suites précédentes passent toujours : V0.2 (68), V0.3 (91 de bout en bout, 26 dans le navigateur).

## Mettre à jour son poste

1. Récupérer la branche, puis **sauvegarder la base** (phpMyAdmin > Exporter).
2. Exécuter `BDD/migrations/2026-10-22_v0.4_communaute.sql` sur la base `mlr1` (onglet Importer). Les migrations V0.2 et V0.3 doivent déjà être passées. Celle-ci est **idempotente** : la relancer ne casse rien et ne modifie pas ce qui existe déjà.
3. Vider le cache du navigateur du téléphone, ou recharger deux fois : les fichiers CSS et JS passent en `?v=4` et le service worker en `v4`.
4. Pour tester la position GPS du téléphone en développement, voir « Photos et GPS » plus bas.

> **Changement de confidentialité.** Avant, seules les sessions épinglées étaient visibles des autres membres. Désormais, **toutes les sessions sont visibles par défaut** (sans le lieu ni le GPS), et chacun peut masquer une session, ou toutes d'un coup, depuis son profil. Le fil et les classements ne montrent que les membres qui ont laissé « Partage communautaire » activé dans Paramètres. Les CGU (articles 10 et 11) et les mentions légales sont à jour. Prévenez les membres actuels.

## Ce qui change dans la base

| Objet | Rôle |
|---|---|
| `session_p.MASQUEE` | 1 = session invisible pour les autres (profil, fil, classements). 0 par défaut. Masquer retire aussi l'épingle. |
| `utilisateur` + 5 colonnes | Niveau, année de début, département, milieu préféré, espèce fétiche (clé étrangère, `SET NULL` si l'espèce disparaît). |
| `utilisateur_technique`, `utilisateur_type_session` | Techniques et types de pêche du profil (5 au plus chacun). |
| 4 index | Fil et classements : date et visibilité des sessions, date des prises, statut des photos. |
| `espece.POIDS_MAX` | Corrigé pour la carpe commune et la carpe miroir (50 kg) et le brochet (25 kg), trop bas pour le garde-fou. |

Aucune donnée n'est supprimée.

---

## 1. Communauté : fil et classements

**Fil « Au bord de l'eau »** (`accueil.php`, `fil.php`, `includes/communaute.php`)
- L'accueil montre les 4 dernières prises partagées ; `fil.php` affiche la suite par paquets de 8 (« Voir plus »).
- Chaque carte : photos (ouvertes en grand), espèce, taille et poids, pseudo et avatar, date relative (« il y a 3 h », « hier »), relâché ou non, et le lieu en large : « Mer · Manche (50) » ou « Eau douce · Calvados (14) ».
- **Jamais de nom de spot ni de coordonnées.** Une prise n'apparaît que si sa session n'est pas masquée, que son auteur a activé le partage communautaire et qu'elle a au moins une photo au statut « visible » (les photos signalées ou retirées par la modération n'apparaissent pas).
- Les anciennes prises aux tailles impossibles (un brochet de 500 cm saisi avant le garde-fou) sont écartées du fil et des classements.

**Classements du mois** (`classements.php`)
- Quatre vues, avec navigation de mois en mois :
  - **Espèces** : pour chaque espèce, le plus gros poisson et le plus de prises ;
  - **Assiduité** : le temps passé au bord de l'eau ;
  - **Polyvalence** : le nombre d'espèces différentes ;
  - **No-kill** : les poissons relâchés (avec le taux), et le total des prises.
- Top 10 ; si tu n'y es pas, ta ligne s'ajoute en dessous avec ton rang. Les ex æquo partagent le rang (1, 2, 2, 4).
- Règles anti-abus : une ligne de prise compte au plus pour 100 poissons ; une session compte au plus 72 h ; deux sessions qui se chevauchent ne comptent qu'une fois ; seules les sessions avec une heure de fin entrent dans l'assiduité.

## 2. Garde-fou biologique (`includes/biologie.php`, `script/biologie.js`)

Le même calcul tourne dans le navigateur (à chaque frappe) et sur le serveur (à l'enregistrement, pour qui contournerait le JavaScript).

| Situation | Résultat |
|---|---|
| Taille ou poids entre la référence de l'espèce et +15 % | Avertissement « ce serait un record », enregistrement possible. |
| Au-delà de +15 % | **Bloqué** : « 500 cm, c'est impossible : l'espèce Brochet ne dépasse pas environ 130 cm. La taille se note en centimètres. » |
| Espèce sans taille de référence | Plafond absolu de 700 cm. |
| Espèce sans poids de référence | Plafond déduit de la taille (poisson très trapu). |
| Poids 3 fois au-dessus du poids attendu pour cette taille | **Bloqué** : « 20 kg pour 80 cm, c'est bien trop lourd pour l'espèce Brochet (environ 5,8 kg attendus à cette taille) ». |
| Poids 4 fois en dessous | **Bloqué**, c'est presque toujours une erreur d'unité (grammes au lieu de kilos). |
| Entre 1,8× et 3×, ou entre ÷2,5 et ÷4 | Avertissement, enregistrement possible. |

Le poids attendu vient de la règle du cube : poids max × (taille / taille max)³. Une marge de 50 g couvre l'arrondi des petits poissons, et le contrôle « trop léger » est ignoré sous 100 g (balances imprécises).

À l'étape 3, si une carte est bloquée, le serveur renvoie sur l'étape 3 avec les valeurs saisies conservées et le message devant le nom de l'espèce. Même chose dans `form_prise.php`.

## 3. Saisie d'une session

**Photos par prise à l'étape 3** (`photo_session.php`, `script/JS_session.php`)
- Chaque carte de prise a une tuile « + » pour ajouter ses photos (6 par prise). Elles partent tout de suite, avec un indicateur d'envoi, et la carte garde ses vignettes, qu'on peut retirer. L'enregistrement attend la fin des envois.
- « Ajouter une autre prise » crée une carte vide. « Même poisson que la prise au-dessus : regrouper les photos » rassemble deux cartes.
- L'étape 1 reste là pour les photos prises pendant la sortie : elles créent les cartes de départ, la première date règle l'heure de la session, et la première position règle le point.
- 60 photos au plus par saisie, 15 Mo chacune.

**Espèces favorites** (`includes/saisie.php`)
- Les 5 espèces que tu prends le plus apparaissent en raccourcis au-dessus du champ, et en tête de la liste déroulante. À l'étape 3 comme dans `form_prise.php`.

**Photos et GPS** (`script/exif.js`, `script/photos.js`, `includes/images.php`)
- Le navigateur lit l'EXIF du **fichier d'origine** avant toute réduction (JPEG, PNG, WebP, HEIC). Le serveur relit l'EXIF avec PHP, puis avec son propre lecteur si l'extension `exif` échoue. Les deux résultats sont fusionnés.
- Les photos de plus de 1,5 Mo sont réduites à 2 000 px dans le navigateur avant l'envoi, ce qui accélère beaucoup l'envoi en 4G.
- Chaque vignette affiche un badge : « GPS », « Position du téléphone » ou « Sans GPS ».

**La limite à connaître.** Depuis Android 10 et sur iOS, quand une page web demande une photo de la galerie, **le téléphone retire lui-même la position** du fichier avant de le donner au navigateur, sauf si l'application a une autorisation spéciale qu'aucun site web ne peut obtenir. La date reste. Aucun code ne peut récupérer une position qui n'est plus dans le fichier. Deux solutions sont prévues :
- si la photo a été prise il y a moins de 3 h (ou n'a pas de date), on propose la **position actuelle du téléphone** ; cela demande **HTTPS** (ou `localhost`), et ne marche donc pas en `http://192.168.x.x` ;
- sinon, l'étape 2 le signale et on place le point sur la carte.

Pour tester la position en développement sur Android avec Chrome :
- **Redirection de port :** branchez le téléphone en USB, ouvrez `chrome://inspect` sur le PC, puis « Port forwarding » `8080 → localhost:80`. Sur le téléphone, `http://localhost:8080/...` compte comme sécurisé.
- **Ou :** sur le téléphone, `chrome://flags`, option « Insecure origins treated as secure », ajoutez `http://IP-du-PC`.

## 4. Profil

- **Sessions visibles.** Les sessions épinglées restent en tête. En dessous, toutes les autres sessions non masquées, sur **2 colonnes** sur téléphone (3 sur tablette), 12 par page.
- **Interrupteur par session** « Visible / Masquée » (œil), plus « Tout masquer » et « Tout afficher ». Le même bouton existe dans le détail d'une session.
- **Aperçu** : « Voir comme les autres » montre son profil tel qu'un membre le voit.
- **Fiche du pêcheur** (`modifier_profil.php`) : niveau (débutant à expert), pêche depuis (année), département, milieu préféré, espèce fétiche, 5 techniques et 5 types de pêche au plus, et la bio.
- **Records** : un onglet avec le record de chaque espèce, calculé sur les sessions visibles pour les autres.

**Visionneuse de photos** (`script/visionneuse.js`, chargée partout)
- Un appui sur une photo de prise l'ouvre en plein écran : glisser ou flèches pour passer à la suivante, Échap ou le bouton retour du téléphone pour fermer.
- Elle sert dans le profil, le détail d'une session, la vue publique, le fil et la fiche espèce des statistiques.

## 5. Statistiques (`BDD/BDD_stat.php`, `statistiques.php`, `script/stats_espece.js`)

**Heures de pêche.** L'ancien ratio « prises / session » par heure donnait 0,09 à 22 h, un chiffre qui ne voulait rien dire : il divisait les prises d'une heure par toutes les sessions, même celles qui n'avaient pas duré jusque-là. Le nouveau calcul mesure, pour chaque heure de la journée, **le temps réellement passé au bord de l'eau** (à partir du début et de la fin de chaque session). Il en tire :
- la part de tes poissons prise à chaque heure (barres) ;
- les poissons par heure de pêche (courbe), affichés seulement au-delà d'1 h passée sur ce créneau ;
- une phrase : « 8 h – 9 h est ton créneau le plus rentable : 1 poisson par heure de pêche (5 poissons en 5 h) ». Il faut au moins 2 h cumulées sur un créneau pour qu'il soit retenu.

Une session sans heure de fin compte jusqu'à sa dernière prise (1 h au moins, 72 h au plus).

**Fiche espèce.** Dans « Bilan par espèce », un appui sur une espèce ouvre sa fiche :
- poissons, no-kill, taille et poids moyens (et sur combien de poissons mesurés ou pesés) ;
- ton record, avec sa photo ;
- le leurre, l'appât et la technique qui ont pris le plus de poissons ;
- la saisonnalité : poissons par mois, meilleur mois, meilleure heure, première et dernière prise.

## 6. Marées sur 7 jours (`includes/maree.php`, `fetch_maree.php`, `script/meteo.js`)

- Page Météo > Marée : bouton « Voir les 7 prochains jours ». Des puces (jour, date, coefficient) permettent de choisir le jour ; un tableau résume la semaine.
- **Économie de l'API.** Rien n'est demandé tant qu'on n'appuie pas sur le bouton. Ensuite, un **seul appel** couvre tous les jours manquants, et chaque jour est rangé dans `cache_maree`, partagé par tous les membres et gardé 400 jours. Un deuxième pêcheur du même port ne déclenche aucun appel. La marée du jour, déjà en cache, n'est pas redemandée.

## 7. Carte de pêche : QR à l'envers (`carte_peche.php`)

Le PDF de cartedepeche.fr met parfois le QR code à l'envers. La lecture essaie maintenant, dans l'ordre : l'image telle quelle, retournée à 180°, à 90° et 270°, en miroir, puis zone par zone avec un agrandissement ×2. La page est dessinée en haute définition (2 400 px) pour la détection. BarcodeDetector est tenté d'abord, jsQR ensuite. Le texte « Recherche du QR code… » indique l'essai en cours.

## 8. Réglementation pour tous (`reglementation.php`)

- Accessible à tous les membres (tuile sur l'accueil, lien « voir les règles » à l'étape 3).
- Filtres : recherche d'espèce, milieu, catégorie, département.
- « Pour mon spot, aujourd'hui » : on choisit un de ses spots et on voit les règles qui s'y appliquent ce jour-là, avec l'état ouvert ou fermé.
- Règles regroupées par espèce (taille, quota, période) et carte des réserves.
- Rappel en tête : seules les sources officielles font foi.

## 9. Ménage du dépôt et corrections

- `.gitignore` réécrit : secrets (`env.local.php`, `cle/`), photos des membres, **toute sauvegarde `.sql`** (sauf les migrations), bundles, patchs, archives, guides PDF, dossiers d'outils d'IA (`.claude/`, `.cursor/`…), fichiers d'éditeurs et de systèmes.
- Documents rangés dans `docs/` : `AUDIT_V0.2.md`, `EVOLUTIONS_V0.3.md`, et ce fichier.
- Étape 2 : le choix de la catégorie piscicole fonctionne même si la carte (Leaflet, chargée depuis un CDN) n'a pas pu s'afficher, par exemple avec un mauvais réseau.

## Limites connues

- La position des photos de la galerie reste perdue sur Android 10+ et iOS (voir partie 3). La position actuelle du téléphone ne fonctionne qu'en HTTPS.
- Les classements reposent sur la bonne foi : le garde-fou empêche les valeurs impossibles, pas un brochet de 95 cm inventé. Le signalement de photo existant reste le recours.
- Le fil ne se met pas à jour tout seul : il faut recharger la page.
- Si le mode SQL `ONLY_FULL_GROUP_BY` est activé sur MariaDB (il ne l'est pas sous XAMPP par défaut), `codex.php` échoue. C'était déjà le cas avant ; les nouvelles requêtes V0.4 fonctionnent dans ce mode.
- Pour une nouvelle version des fichiers CSS ou JS, il faut changer `?v=4` dans `includes/head.php`, `sw.js`, `offline.html` et `index.php`, ainsi que `VERSION` dans `sw.js`.
