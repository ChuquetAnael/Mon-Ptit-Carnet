<?php if (!isset($etape)) { http_response_code(404); exit; } // fichier inclus par nouvelle_session.php uniquement ?>
<!-- SCRIPT ÉTAPE 1 : CHOIX DES PHOTOS (EXIF lus avant envoi, photos réduites dans le navigateur) -->
<?php if ($etape === 1): ?>
<script src="script/photos.js?v=4"></script>
<script>
    const proxyInput = document.getElementById('photos-proxy');
    const realInput = document.getElementById('photos-real');
    const metaInput = document.getElementById('photos-meta');
    const previewContainer = document.getElementById('preview-container');
    const noteGps = document.getElementById('note-gps');
    const submitBtn = document.getElementById('submit-step-1');
    const form = document.getElementById('form-photos');
    // Chaque fichier reçoit un identifiant unique : sur iPhone, toutes les photos s'appellent souvent "image.jpg"
    const selectedFiles = new Map();   // id -> { fichier, meta }
    const enCours = new Set();         // préparations pas encore terminées
    let fileCounter = 0;

    function syncRealInput() {
        const dt = new DataTransfer();
        const metas = [];
        selectedFiles.forEach(p => { if (p.fichier) { dt.items.add(p.fichier); metas.push(p.meta); } });
        realInput.files = dt.files;
        metaInput.value = JSON.stringify(metas);
        const sansGps = metas.filter(m => m.lat === null && !m.appareil).length;
        noteGps.hidden = sansGps === 0;
        if (sansGps) {
            noteGps.innerHTML = `<span class="material-symbols-rounded" aria-hidden="true">location_off</span>${sansGps} photo(s) sans position GPS. `
                + (window.isSecureContext
                    ? "Le téléphone retire souvent la position des photos de la galerie : tu pourras placer ton spot sur la carte à l'étape suivante."
                    : "Le téléphone retire souvent la position des photos de la galerie, et la position actuelle n'est disponible qu'en HTTPS : tu pourras placer ton spot sur la carte à l'étape suivante.");
        }
        updateBtnText();
    }

    proxyInput.addEventListener('change', function (e) {
        Array.from(e.target.files).forEach(file => {
            const id = ++fileCounter;
            selectedFiles.set(id, { fichier: null, meta: null });

            const div = document.createElement('div');
            div.className = 'position-relative vignette-envoi';
            const img = document.createElement('img');
            img.className = 'rounded-3 shadow-sm border';
            img.style.cssText = 'width: 85px; height: 85px; object-fit: cover;';
            img.alt = 'Aperçu';
            img.src = URL.createObjectURL(file); // plus léger que FileReader pour de grosses photos
            const etat = document.createElement('span');
            etat.className = 'vignette-etat';
            etat.innerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span>';
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn btn-danger btn-sm position-absolute top-0 start-100 translate-middle rounded-circle p-0 d-flex align-items-center justify-content-center shadow';
            btn.style.cssText = 'width:24px; height:24px;';
            btn.setAttribute('aria-label', 'Retirer cette photo');
            btn.innerHTML = '<span class="material-symbols-rounded" style="font-size:14px;">close</span>';
            btn.addEventListener('click', () => {
                selectedFiles.delete(id);
                enCours.delete(id);
                URL.revokeObjectURL(img.src);
                div.remove();
                syncRealInput();
            });
            div.append(img, etat, btn);
            previewContainer.appendChild(div);

            enCours.add(id);
            Photos.preparer(file).then(p => {
                if (!selectedFiles.has(id)) return;
                selectedFiles.set(id, p);
                enCours.delete(id);
                const lab = Photos.etiquette(p.meta);
                etat.className = 'vignette-etat ' + (lab.ok ? 'ok' : 'ko');
                etat.title = lab.texte;
                etat.innerHTML = `<span class="material-symbols-rounded" aria-hidden="true">${lab.icone}</span><span class="visually-hidden">${lab.texte}</span>`;
                syncRealInput();
            });
        });
        proxyInput.value = ''; // On vide le faux input pour pouvoir recliquer
        syncRealInput();
    });

    function updateBtnText() {
        if (enCours.size > 0) {
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Lecture des photos…';
        } else if (selectedFiles.size > 0) {
            submitBtn.innerHTML = 'Analyser les photos <span class="material-symbols-rounded align-middle ms-2">arrow_forward</span>';
        } else {
            submitBtn.innerHTML = 'Continuer sans photo <span class="material-symbols-rounded align-middle ms-2">arrow_forward</span>';
        }
    }

    form.addEventListener('submit', (e) => {
        if (enCours.size > 0) { // on attend la fin de la lecture des EXIF avant d'envoyer
            e.preventDefault();
            const attente = setInterval(() => {
                if (enCours.size === 0) { clearInterval(attente); syncRealInput(); envoyer(); }
            }, 150);
            return;
        }
        envoyer(e);
    });
    function envoyer(e) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Envoi des photos…';
        if (!e) form.submit();
    }
</script>
<?php endif; ?>

<!-- SCRIPT ÉTAPE 2 : LEAFLET ET MÉTÉO -->
<?php if ($etape === 2): ?>
<script>
    // Type et catégorie des spots (sans coordonnées) et zones de réserve publiques
    window.MPC_SPOTS = <?= js((object) $infos_spots) ?>;
    window.MPC_RESERVES = <?= js($reserves_carte) ?>;
</script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<?php endif; ?>

<!-- SCRIPT ÉTAPE 3 : GÉNÉRATION DES PRISES -->
<?php if ($etape === 3): ?>

<!-- 1. DÉCLARATION DES VARIABLES GLOBALES -->
<script>
    const especes = <?= js($especes) ?>;
    const techniques = <?= js($techniques) ?>;
    let leurres = <?= js($leurres) ?>;
    let appats = <?= js($appats) ?>;
    const typesLeurre = <?= js($types_leurre) ?>;
    const typesAppat = <?= js($types_appat) ?>;
    const PHOTOS_MAX_PAR_PRISE = <?= (int) PHOTOS_MAX_PAR_PRISE ?>;
    const GARDE_FOU = <?= js($garde_fou) ?>;
    const LIMITES_BIO = <?= js((object) $limites_bio) ?>;
    const ESPECES_FAVORITES = <?= js($especes_favorites) ?>;
    const BROUILLON = <?= js($brouillon) ?>;
    const CSRF = <?= js(csrf_token()) ?>;
    const HEURE_DEFAUT = <?= js(substr($s2['date_debut'] ?? date('Y-m-d H:i'), 11, 5) ?: date('H:i')) ?>;
</script>
<script src="script/reglementation.js"></script>
<script src="script/biologie.js?v=4"></script>
<script src="script/photos.js?v=4"></script>

<!-- 2. CHARGEMENT DU FICHIER EXTERNE AVANT SON UTILISATION (escapeHtml, boîte de pêche) -->
<script src="script/gestion_prise.js?v=4"></script>

<!-- 3. EXÉCUTION PRINCIPALE -->
<script>
    let catchIndex = 0;
    const especeParId = Object.fromEntries(especes.map(e => [String(e.ID_ESPECE), e]));

    // Ajoute une vignette (et son champ caché) dans la bande photo d'une prise
    function addPhotoToCard(card, chemin) {
        const index = card.dataset.index;
        const strip = card.querySelector('.photo-strip');
        const item = document.createElement('div');
        item.className = 'position-relative photo-item';

        const img = document.createElement('img');
        img.src = chemin;
        img.alt = 'Photo de la prise';
        img.className = 'rounded-3 shadow-sm border';
        img.style.cssText = 'width:96px;height:96px;object-fit:cover;';

        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = `prises[${index}][photos][]`;
        input.value = chemin;

        const retirer = document.createElement('button');
        retirer.type = 'button';
        retirer.className = 'photo-retirer';
        retirer.setAttribute('aria-label', 'Retirer cette photo de la prise');
        retirer.innerHTML = '<span class="material-symbols-rounded" aria-hidden="true">close</span>';
        retirer.addEventListener('click', () => { item.remove(); majPhotos(card); });

        item.append(img, input, retirer);
        strip.insertBefore(item, strip.querySelector('.photo-ajout'));
        majPhotos(card);
    }

    function nbPhotos(card) { return card.querySelectorAll('.photo-item').length + card.querySelectorAll('.photo-envoi').length; }

    // Compteur, bouton d'ajout et regroupement selon le nombre de photos de la carte
    function majPhotos(card) {
        const n = nbPhotos(card);
        const ajout = card.querySelector('.photo-ajout');
        ajout.classList.toggle('d-none', n >= PHOTOS_MAX_PAR_PRISE);
        ajout.querySelector('.photo-compteur').textContent = n ? `${n}/${PHOTOS_MAX_PAR_PRISE}` : 'Photo';
        const prev = card.previousElementSibling;
        card.querySelector('.btn-regrouper').classList.toggle('d-none', !(n > 0 && prev && prev.classList.contains('catch-card')));
    }

    // Photos ajoutées directement sur la carte (envoyées une par une, la saisie continue pendant l'envoi)
    async function envoyerPhotos(card, fichiers) {
        const strip = card.querySelector('.photo-strip');
        for (const f of fichiers) {
            if (nbPhotos(card) >= PHOTOS_MAX_PAR_PRISE) {
                signalerPhoto(card, `Une prise peut contenir ${PHOTOS_MAX_PAR_PRISE} photos maximum.`);
                break;
            }
            const tuile = document.createElement('div');
            tuile.className = 'photo-envoi';
            tuile.innerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span><span class="visually-hidden">Envoi…</span>';
            strip.insertBefore(tuile, strip.querySelector('.photo-ajout'));
            majPhotos(card);
            const avaitPhoto = card.querySelectorAll('.photo-item').length > 0;
            try {
                const { fichier, meta } = await Photos.preparer(f);
                const fd = new FormData();
                fd.append('photo', fichier);
                fd.append('meta', JSON.stringify(meta));
                const r = await fetch('photo_session.php', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-CSRF-Token': CSRF } });
                const j = await r.json().catch(() => ({ error: "Réponse illisible du serveur." }));
                tuile.remove();
                if (!r.ok || j.error) { signalerPhoto(card, j.error || "Photo refusée."); continue; }
                addPhotoToCard(card, j.chemin);
                // La 1re photo d'une prise sans photo donne l'heure de capture
                if (!avaitPhoto && j.heure && !card.dataset.heureModifiee) card.querySelector('.input-heure').value = j.heure;
            } catch (e) {
                tuile.remove();
                signalerPhoto(card, "Envoi impossible : vérifie ta connexion et réessaie.");
            }
            majPhotos(card);
        }
    }
    function signalerPhoto(card, texte) {
        const z = card.querySelector('.photo-message');
        z.textContent = texte;
        z.hidden = false;
        clearTimeout(z._t);
        z._t = setTimeout(() => { z.hidden = true; }, 6000);
    }

    // Plusieurs photos du même poisson envoyées à l'étape 1 : on les déplace vers la prise au-dessus
    function mergeWithPrevious(btn) {
        const card = btn.closest('.catch-card');
        const prev = card.previousElementSibling;
        if (!prev || !prev.classList.contains('catch-card')) return;
        const items = card.querySelectorAll('.photo-item');
        if (nbPhotos(prev) + items.length > PHOTOS_MAX_PAR_PRISE) {
            signalerPhoto(card, `Une prise peut contenir ${PHOTOS_MAX_PAR_PRISE} photos maximum.`);
            return;
        }
        items.forEach(item => {
            item.querySelector('input').name = `prises[${prev.dataset.index}][photos][]`;
            prev.querySelector('.photo-strip').insertBefore(item, prev.querySelector('.photo-ajout'));
        });
        card.remove();
        majPhotos(prev);
        document.querySelectorAll('.catch-card').forEach(majPhotos);
        verifierToutesLesPrises();
    }

    function optionEspeceHtml(e) {
        return `
            <a class="dropdown-item d-flex align-items-center espece-option rounded-3 py-2 mb-1" href="#" data-value="${escapeHtml(e.ID_ESPECE)}" data-nom="${escapeHtml(e.NOM_COM)}">
                ${e.ICONE_CHEMIN ? `<img src="${escapeHtml(e.ICONE_CHEMIN)}" alt="" loading="lazy" style="width:35px; height:35px; object-fit:contain;" class="me-3">` : `<span class="material-symbols-rounded text-muted me-3" style="font-size:35px;">set_meal</span>`}
                <span class="fw-medium text-dark">${escapeHtml(e.NOM_COM)}</span>
            </a>`;
    }

    /**
     * @param {object} [init] { photos: [chemins], heure, valeurs: brouillon d'une prise }
     */
    function addCatchCard(init = {}) {
        const i = catchIndex++;
        const v = init.valeurs || {};
        const captureTime = v.heure || init.heure || HEURE_DEFAUT;
        const favorites = ESPECES_FAVORITES.map(id => especeParId[String(id)]).filter(Boolean);

        const speciesOptions = especes.map(optionEspeceHtml).join('');
        const favOptions = favorites.length
            ? `<div class="espece-favorites"><div class="dropdown-header px-2">Tes espèces les plus pêchées</div>${favorites.map(optionEspeceHtml).join('')}<div class="dropdown-header px-2">Toutes les espèces</div></div>` : '';
        const chips = favorites.slice(0, 5).map(e => `
            <button type="button" class="chip-espece" data-value="${escapeHtml(e.ID_ESPECE)}">
                ${e.ICONE_CHEMIN ? `<img src="${escapeHtml(e.ICONE_CHEMIN)}" alt="" loading="lazy">` : ''}${escapeHtml(e.NOM_COM)}
            </button>`).join('');

        const techOptions = techniques.map(t => `<option value="${escapeHtml(t.ID_TECHNIQUE)}">${escapeHtml(t.NOM_TECHNIQUE)}</option>`).join('');

        const html = `
        <div class="card border border-light-subtle shadow-sm rounded-4 p-4 mb-4 catch-card position-relative bg-white" data-index="${i}">
            <button type="button" class="btn-close position-absolute top-0 end-0 m-3" aria-label="Retirer cette prise" onclick="this.closest('.catch-card').remove(); document.querySelectorAll('.catch-card').forEach(majPhotos); verifierToutesLesPrises();"></button>

            <div class="photo-strip d-flex gap-2 overflow-auto mb-2 pe-4 pt-2">
                <label class="photo-ajout" title="Ajouter des photos de ce poisson">
                    <span class="material-symbols-rounded" aria-hidden="true">add_a_photo</span>
                    <span class="photo-compteur">Photo</span>
                    <input type="file" accept="image/jpeg,image/png,image/webp" multiple class="d-none input-photo-carte" aria-label="Ajouter des photos à cette prise">
                </label>
            </div>
            <p class="photo-message small text-danger mb-2" role="status" hidden></p>
            <button type="button" class="btn btn-sm btn-link text-decoration-none px-0 mb-3 btn-regrouper d-none" onclick="mergeWithPrevious(this)">
                <span class="material-symbols-rounded align-middle fs-6 me-1">call_merge</span> Même poisson que la prise au-dessus : regrouper les photos
            </button>

            <div class="mb-4 dropdown custom-select-espece w-100">
                <label class="form-label text-secondary small fw-bold text-uppercase tracking-wider">Espèce <span class="text-danger">*</span></label>
                ${chips ? `<div class="chips-especes mb-2" aria-label="Accès rapide à tes espèces les plus pêchées">${chips}</div>` : ''}
                <button class="btn bg-light border-0 w-100 d-flex justify-content-between align-items-center text-start p-3 rounded-4 dropdown-toggle species-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <span class="selected-espece-text text-muted">Rechercher une espèce...</span>
                </button>
                <div class="dropdown-menu w-100 p-2 shadow-lg border-0 rounded-4 mt-1">
                    <div class="px-2 pb-2">
                        <input type="text" class="form-control bg-light border-0 shadow-none search-espece" placeholder="Taper un nom...">
                    </div>
                    <div class="espece-list" style="max-height: 280px; overflow-y: auto;">${favOptions}${speciesOptions}</div>
                </div>
                <input type="hidden" name="prises[${i}][id_espece]" class="hidden-espece-input">
            </div>

            <div class="row g-3 mb-2">
                <div class="col-4">
                    <label class="form-label text-secondary small fw-bold text-uppercase">Quantité</label>
                    <input type="number" min="1" max="999" value="1" class="form-control bg-light border-0 p-3 rounded-4" name="prises[${i}][quantite]">
                </div>
                <div class="col-4">
                    <label class="form-label text-secondary small fw-bold text-uppercase">Taille (cm)</label>
                    <input type="number" step="1" min="0" max="1000" inputmode="numeric" class="form-control bg-light border-0 p-3 rounded-4 input-taille" name="prises[${i}][taille]">
                </div>
                <div class="col-4">
                    <label class="form-label text-secondary small fw-bold text-uppercase">Poids (kg)</label>
                    <input type="number" step="0.01" min="0" max="999.99" inputmode="decimal" class="form-control bg-light border-0 p-3 rounded-4 input-poids" name="prises[${i}][poids]">
                </div>
            </div>
            <div class="bio-alertes mb-3" aria-live="polite"></div>

            <div class="row g-3 mb-4">
                <div class="col-12">
                    <label class="form-label text-secondary small fw-bold text-uppercase d-block">Appât / Leurre utilisé</label>
                    <button type="button" class="btn bg-light w-100 rounded-4 d-flex align-items-center justify-content-between p-3 border-0" onclick="openTackleBox(${i})">
                        <span id="tackle-text-${i}" class="text-truncate text-muted" style="max-width:85%;">Choisir dans la boîte...</span>
                        <span class="material-symbols-rounded text-primary">phishing</span>
                    </button>
                    <input type="hidden" name="prises[${i}][id_leurre]" id="hidden-leurre-${i}">
                    <input type="hidden" name="prises[${i}][id_appat]" id="hidden-appat-${i}">
                </div>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-6">
                    <label class="form-label text-secondary small fw-bold text-uppercase">Heure capture</label>
                    <input type="time" class="form-control bg-light border-0 p-3 rounded-4 input-heure" name="prises[${i}][heure]" value="${escapeHtml(captureTime)}" required>
                </div>
                <div class="col-6">
                    <label class="form-label text-secondary small fw-bold text-uppercase">Technique</label>
                    <select class="form-select bg-light border-0 p-3 rounded-4" name="prises[${i}][id_technique]">
                        <option value="" selected>Non précisée</option>
                        ${techOptions}
                    </select>
                </div>
            </div>

            <div class="form-check form-switch mt-4 bg-light p-3 rounded-4 d-flex align-items-center">
                <input class="form-check-input ms-0 me-3 input-relache" type="checkbox" name="prises[${i}][relache]" id="relache${i}" checked style="transform: scale(1.3);">
                <label class="form-check-label fw-bold text-success mb-0" for="relache${i}">Poisson relâché (No-Kill)</label>
            </div>
            <div class="regle-alertes mt-3" aria-live="polite"></div>
        </div>`;

        const container = document.getElementById('catches-container');
        container.insertAdjacentHTML('beforeend', html);
        const card = container.lastElementChild;
        (init.photos || []).forEach(chemin => addPhotoToCard(card, chemin));
        majPhotos(card);

        attachDropdownEvents();
        card.querySelector('.input-photo-carte').addEventListener('change', function () {
            const fichiers = Array.from(this.files || []);
            this.value = '';
            envoyerPhotos(card, fichiers);
        });
        card.querySelector('.input-heure').addEventListener('input', () => { card.dataset.heureModifiee = '1'; });
        card.querySelectorAll('.chip-espece').forEach(chip => chip.addEventListener('click', () => {
            choisirEspece(card, chip.dataset.value);
        }));
        card.addEventListener('input', verifierToutesLesPrises);
        card.addEventListener('change', verifierToutesLesPrises);
        card.querySelectorAll('.espece-option').forEach(o => o.addEventListener('click', () => setTimeout(() => { majChips(card); verifierToutesLesPrises(); }, 0)));

        if (init.valeurs) remplirCarte(card, i, init.valeurs);
        verifierToutesLesPrises();
        return card;
    }

    function choisirEspece(card, id) {
        const option = card.querySelector(`.espece-list > .espece-option[data-value="${CSS.escape(String(id))}"]`);
        if (option) option.click();
    }
    function majChips(card) {
        const id = card.querySelector('.hidden-espece-input').value;
        card.querySelectorAll('.chip-espece').forEach(c => c.classList.toggle('actif', c.dataset.value === id));
    }

    // Recrée une prise refusée par le serveur avec les valeurs saisies
    function remplirCarte(card, i, v) {
        if (v.id_espece) choisirEspece(card, v.id_espece);
        const champ = n => card.querySelector(`[name="prises[${i}][${n}]"]`);
        champ('quantite').value = v.quantite || 1;
        if (v.taille !== null && v.taille !== undefined) champ('taille').value = v.taille;
        if (v.poids !== null && v.poids !== undefined) champ('poids').value = v.poids;
        if (v.id_technique) champ('id_technique').value = String(v.id_technique);
        card.querySelector('.input-relache').checked = !!v.relache;
        if (v.confirme) card.dataset.confirme = '1';
        const l = v.id_leurre && leurres.find(x => String(x.ID_LEURRE) === String(v.id_leurre));
        const a = !l && v.id_appat && appats.find(x => String(x.ID_APPAT) === String(v.id_appat));
        if (l || a) {
            currentCatchIndex = i;
            const details = l ? [l.GRAMMAGE ? `${l.GRAMMAGE}g` : '', l.COLORIS || ''].filter(Boolean).join(' - ') : '';
            const txt = document.getElementById(`tackle-text-${i}`);
            txt.textContent = l ? (details ? `${l.NOM_LEURRE} (${details})` : l.NOM_LEURRE) : a.NOM_APPAT;
            txt.classList.replace('text-muted', 'text-dark');
            document.getElementById(`hidden-leurre-${i}`).value = l ? l.ID_LEURRE : '';
            document.getElementById(`hidden-appat-${i}`).value = a ? a.ID_APPAT : '';
        }
    }

    // Garde-fous : biologique (taille / poids possibles) et réglementaire (les quotas se cumulent dans l'ordre des cartes)
    function verifierToutesLesPrises() {
        const gardes = Object.assign({}, GARDE_FOU.gardes);
        document.querySelectorAll('.catch-card').forEach(card => {
            const i = card.dataset.index;
            const espece = parseInt(card.querySelector('.hidden-espece-input').value, 10);
            const zone = card.querySelector('.regle-alertes');
            const zoneBio = card.querySelector('.bio-alertes');
            const champTaille = card.querySelector('.input-taille'), champPoids = card.querySelector('.input-poids');
            if (!espece) { zone.innerHTML = ''; GardeBio.afficher(zoneBio, [], champTaille, champPoids); card.classList.remove('catch-card-bio'); return; }

            const nom = (especeParId[String(espece)] || {}).NOM_COM;
            const bioBloque = GardeBio.afficher(zoneBio, GardeBio.controler(LIMITES_BIO, espece, nom, champTaille.value, champPoids.value), champTaille, champPoids);
            card.classList.toggle('catch-card-bio', bioBloque);

            const relache = card.querySelector('.input-relache');
            const prise = {
                espece,
                taille: champTaille.value,
                quantite: card.querySelector(`[name="prises[${i}][quantite]"]`).value,
                relache: relache.checked,
            };
            const confirme = !!zone.querySelector('input[type="checkbox"]:checked') || card.dataset.confirme === '1';
            delete card.dataset.confirme;
            const alertes = Reglementation.controler(GARDE_FOU.regles, GARDE_FOU.contexte, prise, gardes);
            Reglementation.afficher(zone, alertes, `prises[${i}][confirme_regles]`, confirme, () => {
                relache.checked = true;
                verifierToutesLesPrises();
            });
            card.classList.toggle('catch-card-alerte', alertes.some(a => a.bloquant));
            if (!prise.relache) gardes[espece] = (gardes[espece] || 0) + Math.max(1, parseInt(prise.quantite, 10) || 1);
        });
    }

    const photosToLoad = <?= js($photos) ?>;
    if (Array.isArray(BROUILLON) && BROUILLON.length) {
        BROUILLON.forEach(v => addCatchCard({ photos: v.photos, valeurs: v }));
    } else if (photosToLoad.length > 0) {
        photosToLoad.forEach(photo => addCatchCard({ photos: [photo.chemin], heure: photo.heure }));
    } else {
        addCatchCard();
    }

    document.getElementById('form-etape-3').addEventListener('submit', function(e) {
        const cards = document.querySelectorAll('.catch-card');
        if (cards.length === 0) {
            e.preventDefault();
            alert("Vous devez avoir au moins une prise pour enregistrer une session.");
            return;
        }
        if (document.querySelector('.photo-envoi')) {
            e.preventDefault();
            alert("Une photo est encore en cours d'envoi : patiente quelques secondes.");
            return;
        }

        let allValid = true;
        cards.forEach(card => {
            const input = card.querySelector('.hidden-espece-input');
            const btn = card.querySelector('.species-btn');
            if (!input.value) {
                allValid = false;
                btn.classList.remove('border-0', 'bg-light');
                btn.classList.add('border', 'border-danger', 'bg-danger-subtle');
            }
        });

        if (!allValid) {
            e.preventDefault();
            alert("Erreur : Veuillez sélectionner l'espèce pour chaque prise (encadré en rouge).");
            return;
        }

        verifierToutesLesPrises();
        // Taille ou poids impossibles : à corriger (aucune confirmation possible)
        const bio = document.querySelector('.catch-card.catch-card-bio');
        if (bio) {
            e.preventDefault();
            bio.scrollIntoView({ behavior: 'smooth', block: 'center' });
            if (navigator.vibrate) navigator.vibrate(200);
            return;
        }
        // Une prise gardée hors réglementation doit être relâchée ou confirmée explicitement
        const nonConfirmee = [...cards].find(card => card.classList.contains('catch-card-alerte')
            && !card.querySelector('.regle-alertes input[type="checkbox"]:checked'));
        if (nonConfirmee) {
            e.preventDefault();
            nonConfirmee.scrollIntoView({ behavior: 'smooth', block: 'center' });
            if (navigator.vibrate) navigator.vibrate(200);
            return;
        }
        document.getElementById('submit-final').disabled = true; // évite le double envoi
    });
</script>
<?php endif; ?>
