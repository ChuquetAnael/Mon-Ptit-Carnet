import os
from PIL import Image, ImageDraw

dossier_entree = "C:/Users/achuq/Pictures/Asset Carnet/new"
dossier_sortie = "C:/xampp/htdocs/Carnet-de-peche/Mon-Ptit-Carnet/asset/espece_png"

# Tolérance pour la compression JPG (30 à 50 est idéal pour attraper le blanc imparfait)
TOLERANCE = 50

os.makedirs(dossier_sortie, exist_ok=True)

print("🚀 Début de la conversion et transparence...")

for nom_fichier in os.listdir(dossier_entree):
    if nom_fichier.lower().endswith(('.jpg', '.jpeg', '.png')):
        chemin_entree = os.path.join(dossier_entree, nom_fichier)
        nom_sans_ext = os.path.splitext(nom_fichier)[0]
        chemin_sortie = os.path.join(dossier_sortie, nom_sans_ext + ".png")

        try:
            with Image.open(chemin_entree) as img:
                # 1. On passe l'image en mode RGBA (avec canal de transparence)
                img = img.convert("RGBA")
                largeur, hauteur = img.size

                # 2. On lance l'effacement transparent depuis les 4 coins
                coins = [
                    (0, 0),
                    (largeur - 1, 0),
                    (0, hauteur - 1),
                    (largeur - 1, hauteur - 1)
                ]
                for coin in coins:
                    ImageDraw.floodfill(img, xy=coin, value=(255, 255, 255, 0), thresh=TOLERANCE)

                # 3. Sauvegarde en PNG transparent
                img.save(chemin_sortie, "PNG")
                print(f"  -> ✅ Réussi : {nom_fichier} -> {nom_sans_ext}.png")

        except Exception as e:
            print(f"  -> ❌ Erreur avec {nom_fichier} : {e}")

print("🎉 Terminé ! Les images transparentes sont dans 'asset/png'.")