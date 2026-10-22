/**
 * Lecture des métadonnées EXIF (date de prise de vue et position GPS) directement
 * dans les octets de la photo choisie, AVANT tout envoi ou recompression.
 * Formats : JPEG, WebP, PNG (bloc eXIf) et HEIC/HEIF (recherche du bloc « Exif »).
 *
 * Limite à connaître : depuis Android 10 et sur iPhone, le système retire souvent la position GPS
 * des photos choisies dans la galerie par un site web (la date est conservée). Dans ce cas aucune
 * lecture ne peut la retrouver ; Exif.positionAppareil() propose alors la position actuelle du
 * téléphone (seulement en HTTPS ou sur localhost) et la carte de l'étape 2 reste disponible.
 */
const Exif = (function () {
    'use strict';
    const OCTETS_LUS = 2 * 1024 * 1024;

    function lireTiff(vue, debut) {
        const ordre = vue.getUint16(debut);
        const le = ordre === 0x4949; // "II" = little-endian
        if (!le && ordre !== 0x4D4D) return null;
        const u16 = o => vue.getUint16(debut + o, le);
        const u32 = o => vue.getUint32(debut + o, le);
        if (u16(2) !== 42) return null;
        const taille = vue.byteLength - debut;

        function lireIfd(offset) {
            const tags = {};
            if (offset <= 0 || offset + 2 > taille) return tags;
            const n = u16(offset);
            for (let i = 0; i < n && i < 500; i++) {
                const e = offset + 2 + i * 12;
                if (e + 12 > taille) break;
                tags[u16(e)] = { type: u16(e + 2), nombre: u32(e + 4), valeur: e + 8 };
            }
            return tags;
        }
        function texte(t) {
            if (!t || t.type !== 2) return null;
            const o = t.nombre > 4 ? u32(t.valeur) : t.valeur;
            let s = '';
            for (let i = 0; i < t.nombre - 1 && o + i < taille; i++) s += String.fromCharCode(vue.getUint8(debut + o + i));
            return s;
        }
        function rationnels(t) {
            if (!t || (t.type !== 5 && t.type !== 10)) return null;
            const o = u32(t.valeur), res = [];
            for (let i = 0; i < t.nombre && i < 3; i++) {
                if (o + i * 8 + 8 > taille) return null;
                const num = u32(o + i * 8), den = u32(o + i * 8 + 4);
                res.push(den ? num / den : 0);
            }
            return res;
        }
        const ifd0 = lireIfd(u32(4));
        const exif = ifd0[0x8769] ? lireIfd(u32(ifd0[0x8769].valeur)) : {};
        const gps = ifd0[0x8825] ? lireIfd(u32(ifd0[0x8825].valeur)) : {};

        let date = texte(exif[0x9003]) || texte(exif[0x9004]) || texte(ifd0[0x0132]);
        const m = date && date.match(/^(\d{4}):(\d{2}):(\d{2}) (\d{2}):(\d{2})(?::(\d{2}))?/);
        date = m && m[1] !== '0000' ? `${m[1]}-${m[2]}-${m[3]}T${m[4]}:${m[5]}` : null;

        let lat = null, lng = null;
        const la = rationnels(gps[2]), lo = rationnels(gps[4]);
        if (la && lo && la.length === 3 && lo.length === 3) {
            const refLat = texte(gps[1]) || (gps[1] ? String.fromCharCode(vue.getUint8(debut + gps[1].valeur)) : 'N');
            const refLng = texte(gps[3]) || (gps[3] ? String.fromCharCode(vue.getUint8(debut + gps[3].valeur)) : 'E');
            lat = (la[0] + la[1] / 60 + la[2] / 3600) * (/S/i.test(refLat) ? -1 : 1);
            lng = (lo[0] + lo[1] / 60 + lo[2] / 3600) * (/W/i.test(refLng) ? -1 : 1);
            if (!Number.isFinite(lat) || !Number.isFinite(lng) || Math.abs(lat) > 90 || Math.abs(lng) > 180 || (Math.abs(lat) < 1e-6 && Math.abs(lng) < 1e-6)) {
                lat = lng = null;
            } else {
                lat = Math.round(lat * 1e6) / 1e6; lng = Math.round(lng * 1e6) / 1e6;
            }
        }
        return { date, lat, lng };
    }

    /** Position (octet) de l'en-tête TIFF dans le fichier, ou -1. */
    function trouverTiff(vue) {
        const n = vue.byteLength;
        // JPEG : parcours des marqueurs jusqu'au bloc APP1 « Exif » (jamais de recherche dans l'image elle-même)
        if (n > 4 && vue.getUint16(0) === 0xFFD8) {
            let o = 2;
            while (o + 10 < n) {
                if (vue.getUint8(o) !== 0xFF) return -1;
                const marqueur = vue.getUint8(o + 1), longueur = vue.getUint16(o + 2);
                if (marqueur === 0xE1 && vue.getUint32(o + 4) === 0x45786966 && vue.getUint16(o + 8) === 0) return o + 10;
                if (marqueur === 0xDA || longueur < 2) return -1; // début de l'image : plus de métadonnées
                o += 2 + longueur;
            }
            return -1;
        }
        // PNG : bloc eXIf (TIFF directement)
        if (n > 8 && vue.getUint32(0) === 0x89504E47) {
            let o = 8;
            while (o + 8 < n) {
                const longueur = vue.getUint32(o), type = vue.getUint32(o + 4);
                if (type === 0x65584966) return o + 8;
                if (type === 0x49454E44) break;
                o += 12 + longueur;
            }
            return -1;
        }
        // WebP : bloc RIFF « EXIF » (TIFF direct, parfois précédé de « Exif\0\0 »)
        if (n > 12 && vue.getUint32(0) === 0x52494646 && vue.getUint32(8) === 0x57454250) {
            let o = 12;
            while (o + 8 < n) {
                const type = vue.getUint32(o), longueur = vue.getUint32(o + 4, true);
                if (type === 0x45584946) {
                    const d = o + 8;
                    return (d + 10 < n && vue.getUint32(d) === 0x45786966 && vue.getUint16(d + 4) === 0) ? d + 6 : d;
                }
                o += 8 + longueur + (longueur % 2);
            }
            return -1;
        }
        // HEIC/HEIF : signature « Exif\0\0 » immédiatement suivie d'un en-tête TIFF (8 octets : pas de faux positif)
        for (let o = 0; o + 10 < n; o++) {
            if (vue.getUint32(o) === 0x45786966 && vue.getUint16(o + 4) === 0) {
                const t = vue.getUint32(o + 6);
                if (t === 0x49492A00 || t === 0x4D4D002A) return o + 6;
            }
        }
        return -1;
    }

    /** @returns {Promise<{date: ?string, lat: ?number, lng: ?number}>} jamais d'exception */
    async function lire(fichier) {
        try {
            const tampon = await fichier.slice(0, OCTETS_LUS).arrayBuffer();
            const vue = new DataView(tampon);
            const debut = trouverTiff(vue);
            if (debut < 0) return { date: null, lat: null, lng: null };
            return lireTiff(vue, debut) || { date: null, lat: null, lng: null };
        } catch (e) {
            return { date: null, lat: null, lng: null };
        }
    }

    /** Position actuelle du téléphone (une seule demande à la fois). Null hors HTTPS/localhost ou si refusée. */
    let demandeEnCours = null;
    function positionAppareil() {
        if (!window.isSecureContext || !navigator.geolocation) return Promise.resolve(null);
        if (!demandeEnCours) {
            demandeEnCours = new Promise(ok => {
                navigator.geolocation.getCurrentPosition(
                    p => ok({ lat: Math.round(p.coords.latitude * 1e6) / 1e6, lng: Math.round(p.coords.longitude * 1e6) / 1e6, precision: Math.round(p.coords.accuracy) }),
                    () => ok(null), { enableHighAccuracy: true, timeout: 10000, maximumAge: 120000 });
            });
            demandeEnCours.then(() => setTimeout(() => { demandeEnCours = null; }, 60000));
        }
        return demandeEnCours;
    }

    /** Photo prise il y a moins de 3 h (ou sans date) : la position actuelle du téléphone est plausible. */
    function recente(date) {
        if (!date) return true;
        const t = new Date(date).getTime();
        return Number.isFinite(t) && Math.abs(Date.now() - t) < 3 * 3600 * 1000;
    }

    return { lire, positionAppareil, recente };
})();
