<?php if (!isset($etape)) { http_response_code(404); exit; } // fichier inclus par nouvelle_session.php uniquement ?>
<!-- SCRIPT ÉTAPE 1 : AJOUT ASYNCHRONE DE PHOTOS -->
<?php if ($etape === 1): ?>
<script>
    const proxyInput = document.getElementById('photos-proxy');
    const realInput = document.getElementById('photos-real');
    const previewContainer = document.getElementById('preview-container');
    const submitBtn = document.getElementById('submit-step-1');
    // Chaque fichier reçoit un identifiant unique : sur iPhone, toutes les photos s'appellent souvent "image.jpg"
    const selectedFiles = new Map();
    let fileCounter = 0;

    function syncRealInput() {
        const dt = new DataTransfer();
        selectedFiles.forEach(file => dt.items.add(file));
        realInput.files = dt.files;
        updateBtnText();
    }

    proxyInput.addEventListener('change', function(e) {
        Array.from(e.target.files).forEach(file => {
            const id = ++fileCounter;
            selectedFiles.set(id, file);

            const div = document.createElement('div');
            div.className = 'position-relative';
            const img = document.createElement('img');
            img.className = 'rounded-3 shadow-sm border';
            img.style.cssText = 'width: 85px; height: 85px; object-fit: cover;';
            img.alt = 'Aperçu';
            img.src = URL.createObjectURL(file); // plus léger que FileReader pour de grosses photos
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn btn-danger btn-sm position-absolute top-0 start-100 translate-middle rounded-circle p-0 d-flex align-items-center justify-content-center shadow';
            btn.style.cssText = 'width:22px; height:22px;';
            btn.setAttribute('aria-label', 'Retirer cette photo');
            btn.innerHTML = '<span class="material-symbols-rounded" style="font-size:14px;">close</span>';
            btn.addEventListener('click', () => {
                selectedFiles.delete(id);
                URL.revokeObjectURL(img.src);
                div.remove();
                syncRealInput();
            });
            div.append(img, btn);
            previewContainer.appendChild(div);
        });
        proxyInput.value = ''; // On vide le faux input pour pouvoir recliquer
        syncRealInput();
    });

    function updateBtnText() {
        if (selectedFiles.size > 0) {
            submitBtn.innerHTML = 'Analyser les photos <span class="material-symbols-rounded align-middle ms-2">arrow_forward</span>';
        } else {
            submitBtn.innerHTML = 'Continuer sans photo <span class="material-symbols-rounded align-middle ms-2">arrow_forward</span>';
        }
    }

    document.getElementById('form-photos').addEventListener('submit', () => {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Envoi des photos…';
    });
</script>
<?php endif; ?>

<!-- SCRIPT ÉTAPE 2 : LEAFLET ET MÉTÉO -->
<?php if ($etape === 2): ?>
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
</script>

<!-- 2. CHARGEMENT DU FICHIER EXTERNE AVANT SON UTILISATION (escapeHtml, boîte de pêche) -->
<script src="script/gestion_prise.js"></script>

<!-- 3. EXÉCUTION PRINCIPALE -->
<script>
    let catchIndex = 0;

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
        img.style.cssText = 'width:110px;height:110px;object-fit:cover;';

        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = `prises[${index}][photos][]`;
        input.value = chemin;

        item.append(img, input);
        strip.appendChild(item);
        card.querySelector('.photo-placeholder')?.classList.add('d-none');
    }

    // Plusieurs photos du même poisson : on regroupe cette prise avec la précédente
    function mergeWithPrevious(btn) {
        const card = btn.closest('.catch-card');
        const prev = card.previousElementSibling;
        if (!prev || !prev.classList.contains('catch-card')) {
            alert("Il n'y a pas de prise au-dessus avec laquelle regrouper ces photos.");
            return;
        }
        const items = card.querySelectorAll('.photo-item');
        if (prev.querySelectorAll('.photo-item').length + items.length > PHOTOS_MAX_PAR_PRISE) {
            alert(`Une prise peut contenir ${PHOTOS_MAX_PAR_PRISE} photos maximum.`);
            return;
        }
        items.forEach(item => {
            item.querySelector('input').name = `prises[${prev.dataset.index}][photos][]`;
            prev.querySelector('.photo-strip').appendChild(item);
        });
        prev.querySelector('.photo-placeholder')?.classList.add('d-none');
        card.remove();
    }

    function addCatchCard(photoObj) {
        const i = catchIndex++;
        const isNoPhoto = (photoObj === 'no_photo');
        const captureTime = isNoPhoto ? <?= js(date('H:i')) ?> : photoObj.heure;

        const speciesOptions = especes.map(e => `
            <a class="dropdown-item d-flex align-items-center espece-option rounded-3 py-2 mb-1" href="#" data-value="${escapeHtml(e.ID_ESPECE)}" data-nom="${escapeHtml(e.NOM_COM)}">
                ${e.ICONE_CHEMIN ? `<img src="${escapeHtml(e.ICONE_CHEMIN)}" alt="" style="width:35px; height:35px; object-fit:contain;" class="me-3">` : `<span class="material-symbols-rounded text-muted me-3" style="font-size:35px;">set_meal</span>`}
                <span class="fw-medium text-dark">${escapeHtml(e.NOM_COM)}</span>
            </a>
        `).join('');

        const techOptions = techniques.map(t => `<option value="${escapeHtml(t.ID_TECHNIQUE)}">${escapeHtml(t.NOM_TECHNIQUE)}</option>`).join('');

        const html = `
        <div class="card border border-light-subtle shadow-sm rounded-4 p-4 mb-4 catch-card position-relative bg-white" data-index="${i}">
            <button type="button" class="btn-close position-absolute top-0 end-0 m-3" aria-label="Retirer cette prise" onclick="this.closest('.catch-card').remove();"></button>

            <div class="photo-strip d-flex gap-2 overflow-auto mb-2 pe-4"></div>
            <div class="photo-placeholder bg-light rounded-4 shadow-sm mb-3 d-flex align-items-center justify-content-center text-muted" style="height: 100px; width: 100%; border: 2px dashed #dee2e6;"><span class="material-symbols-rounded" style="font-size:40px;">no_photography</span></div>
            <button type="button" class="btn btn-sm btn-light rounded-pill mb-4 text-primary fw-semibold" onclick="mergeWithPrevious(this)">
                <span class="material-symbols-rounded align-middle fs-6 me-1">call_merge</span> Même poisson que la prise au-dessus
            </button>

            <div class="mb-4 dropdown custom-select-espece w-100">
                <label class="form-label text-secondary small fw-bold text-uppercase tracking-wider">Espèce <span class="text-danger">*</span></label>
                <button class="btn bg-light border-0 w-100 d-flex justify-content-between align-items-center text-start p-3 rounded-4 dropdown-toggle species-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <span class="selected-espece-text text-muted">Rechercher une espèce...</span>
                </button>
                <div class="dropdown-menu w-100 p-2 shadow-lg border-0 rounded-4 mt-1">
                    <div class="px-2 pb-2">
                        <input type="text" class="form-control bg-light border-0 shadow-none search-espece" placeholder="Taper un nom...">
                    </div>
                    <div class="espece-list" style="max-height: 250px; overflow-y: auto;">${speciesOptions}</div>
                </div>
                <input type="hidden" name="prises[${i}][id_espece]" class="hidden-espece-input">
            </div>

            <div class="row g-3 mb-4">
                <div class="col-4">
                    <label class="form-label text-secondary small fw-bold text-uppercase">Quantité</label>
                    <input type="number" min="1" max="999" value="1" class="form-control bg-light border-0 p-3 rounded-4" name="prises[${i}][quantite]">
                </div>
                <div class="col-4">
                    <label class="form-label text-secondary small fw-bold text-uppercase">Taille (cm)</label>
                    <input type="number" step="1" min="0" max="1000" inputmode="numeric" class="form-control bg-light border-0 p-3 rounded-4" name="prises[${i}][taille]">
                </div>
                <div class="col-4">
                    <label class="form-label text-secondary small fw-bold text-uppercase">Poids (kg)</label>
                    <input type="number" step="0.01" min="0" max="999.99" inputmode="decimal" class="form-control bg-light border-0 p-3 rounded-4" name="prises[${i}][poids]">
                </div>
            </div>

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
                    <input type="time" class="form-control bg-light border-0 p-3 rounded-4" name="prises[${i}][heure]" value="${escapeHtml(captureTime)}" required>
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
                <input class="form-check-input ms-0 me-3" type="checkbox" name="prises[${i}][relache]" id="relache${i}" checked style="transform: scale(1.3);">
                <label class="form-check-label fw-bold text-success mb-0" for="relache${i}">Poisson relâché (No-Kill)</label>
            </div>
        </div>`;

        const container = document.getElementById('catches-container');
        container.insertAdjacentHTML('beforeend', html);
        const card = container.lastElementChild;
        if (!isNoPhoto) addPhotoToCard(card, photoObj.chemin);

        attachDropdownEvents();
    }

    const photosToLoad = <?= js($photos) ?>;
    if (photosToLoad.length > 0) {
        photosToLoad.forEach(photo => addCatchCard(photo));
    } else {
        addCatchCard('no_photo');
    }

    document.getElementById('form-etape-3').addEventListener('submit', function(e) {
        const cards = document.querySelectorAll('.catch-card');
        if (cards.length === 0) {
            e.preventDefault();
            alert("Vous devez avoir au moins une prise pour enregistrer une session.");
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
        document.getElementById('submit-final').disabled = true; // évite le double envoi
    });
</script>
<?php endif; ?>