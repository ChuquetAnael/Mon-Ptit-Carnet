/**
 * Préparation des photos avant envoi (nouvelle session, ajout / modification d'une prise) :
 * 1. lecture des EXIF sur le fichier ORIGINAL (date, GPS) avec script/exif.js ;
 * 2. sans GPS dans la photo, position actuelle du téléphone si la photo vient d'être prise ;
 * 3. réduction à 2000 px dans le navigateur : envoi plus rapide au bord de l'eau
 *    (le serveur ré-encode de toute façon en 1200 px ; l'orientation est appliquée par le navigateur).
 */
const Photos = (function () {
    'use strict';
    const COTE_MAX = 2000, SEUIL_REDUCTION = 1.5 * 1024 * 1024;
    const TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    async function reduire(fichier) {
        if (!fichier || fichier.size < SEUIL_REDUCTION || !TYPES.includes(fichier.type) || !window.createImageBitmap) return fichier;
        try {
            const bmp = await createImageBitmap(fichier, { imageOrientation: 'from-image' });
            const echelle = Math.min(1, COTE_MAX / Math.max(bmp.width, bmp.height));
            const canvas = document.createElement('canvas');
            canvas.width = Math.round(bmp.width * echelle);
            canvas.height = Math.round(bmp.height * echelle);
            const ctx = canvas.getContext('2d');
            ctx.fillStyle = '#fff';
            ctx.fillRect(0, 0, canvas.width, canvas.height);
            ctx.drawImage(bmp, 0, 0, canvas.width, canvas.height);
            if (bmp.close) bmp.close();
            const blob = await new Promise(ok => canvas.toBlob(ok, 'image/jpeg', 0.86));
            if (!blob || blob.size >= fichier.size) return fichier;
            const nom = (fichier.name || 'photo').replace(/\.[^.]+$/, '') + '.jpg';
            return new File([blob], nom, { type: 'image/jpeg', lastModified: fichier.lastModified || Date.now() });
        } catch (e) {
            return fichier;
        }
    }

    /** @returns {Promise<{fichier: File, meta: {date, lat, lng, appareil?}}>} */
    async function preparer(fichier) {
        const meta = await Exif.lire(fichier);
        if (meta.lat === null && Exif.recente(meta.date)) {
            const pos = await Exif.positionAppareil();
            if (pos) meta.appareil = pos;
        }
        return { fichier: await reduire(fichier), meta };
    }

    /** Petit texte d'état sous la vignette. */
    function etiquette(meta) {
        if (meta.lat !== null) return { icone: 'location_on', texte: 'GPS', ok: true };
        if (meta.appareil) return { icone: 'my_location', texte: 'Position du téléphone', ok: true };
        return { icone: 'location_off', texte: 'Sans GPS', ok: false };
    }

    return { preparer, reduire, etiquette };
})();
