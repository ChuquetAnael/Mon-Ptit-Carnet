<!-- SCRIPT ÉTAPE 1 : AJOUT ASYNCHRONE DE PHOTOS -->
<?php if ($etape === 1): ?>
<script>
    const proxyInput = document.getElementById('photos-proxy');
    const realInput = document.getElementById('photos-real');
    const previewContainer = document.getElementById('preview-container');
    const submitBtn = document.getElementById('submit-step-1');
    let dataTransfer = new DataTransfer();

    proxyInput.addEventListener('change', function(e) {
        Array.from(e.target.files).forEach(file => {
            dataTransfer.items.add(file);
            const reader = new FileReader();
            reader.onload = function(event) {
                const div = document.createElement('div');
                div.className = 'position-relative';
                div.innerHTML = `
                    <img src="${event.target.result}" class="rounded-3 shadow-sm border" style="width: 85px; height: 85px; object-fit: cover;">
                    <button type="button" class="btn btn-danger btn-sm position-absolute top-0 start-100 translate-middle rounded-circle p-0 d-flex align-items-center justify-content-center shadow" style="width:22px; height:22px;" onclick="removeFile(this, '${file.name}')">
                        <span class="material-symbols-rounded" style="font-size:14px;">close</span>
                    </button>
                `;
                previewContainer.appendChild(div);
            };
            reader.readAsDataURL(file);
        });
        realInput.files = dataTransfer.files; // On met à jour le vrai input invisible
        proxyInput.value = ''; // On vide le faux input pour pouvoir recliquer
        updateBtnText();
    });

    function removeFile(btnElement, fileName) {
        btnElement.parentElement.remove();
        let newDataTransfer = new DataTransfer();
        Array.from(dataTransfer.files).forEach(file => { if (file.name !== fileName) newDataTransfer.items.add(file); });
        dataTransfer = newDataTransfer;
        realInput.files = dataTransfer.files;
        updateBtnText();
    }

    function updateBtnText() {
        if(dataTransfer.files.length > 0) {
            submitBtn.innerHTML = 'Analyser les photos <span class="material-symbols-rounded align-middle ms-2">arrow_forward</span>';
            submitBtn.classList.replace('btn-light', 'btn-primary');
        } else {
            submitBtn.innerHTML = 'Continuer sans photo <span class="material-symbols-rounded align-middle ms-2">arrow_forward</span>';
        }
    }
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
    const especes = <?php echo json_encode($especes, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    const techniques = <?php echo json_encode($techniques, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    let leurres = <?php echo json_encode($leurres, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    let appats = <?php echo json_encode($appats, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    const typesLeurre = <?php echo json_encode($types_leurre, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    const typesAppat = <?php echo json_encode($types_appat, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
</script>

<!-- 2. CHARGEMENT DU FICHIER EXTERNE AVANT SON UTILISATION -->
<script src="script/gestion_prise.js"></script>

<!-- 3. EXÉCUTION PRINCIPALE -->
<script>
    let catchIndex = 0;

    function addCatchCard(photoObj) {
        const i = catchIndex++;
        
        let isNoPhoto = (photoObj === 'no_photo');
        let photoPath = isNoPhoto ? 'no_photo' : photoObj.chemin;
        let captureTime = isNoPhoto ? '<?= date("H:i") ?>' : photoObj.heure;

        let photoHtml = !isNoPhoto 
            ? `<div class="position-relative mb-4"><img src="${photoPath}" class="rounded-4 shadow-sm w-100" style="height: 140px; object-fit: cover;"><input type="hidden" name="prises[${i}][photo]" value="${photoPath}"></div>`
            : `<div class="bg-light rounded-4 shadow-sm mb-4 d-flex align-items-center justify-content-center text-muted" style="height: 100px; width: 100%; border: 2px dashed #dee2e6;"><span class="material-symbols-rounded" style="font-size:40px;">no_photography</span></div>`;

        let speciesOptions = especes.map(e => `
            <a class="dropdown-item d-flex align-items-center espece-option rounded-3 py-2 mb-1" href="#" data-value="${e.ID_ESPECE}">
                ${e.ICONE_CHEMIN ? `<img src="${e.ICONE_CHEMIN}" style="width:35px; height:35px; object-fit:contain;" class="me-3">` : `<span class="material-symbols-rounded text-muted me-3" style="font-size:35px;">set_meal</span>`}
                <span class="fw-medium text-dark">${e.NOM_COM}</span>
            </a>
        `).join('');

        let techOptions = techniques.map(t => `<option value="${t.ID_TECHNIQUE}">${t.NOM_TECHNIQUE}</option>`).join('');

        let html = `
        <div class="card border border-light-subtle shadow-sm rounded-4 p-4 mb-4 catch-card position-relative bg-white">
            <button type="button" class="btn-close position-absolute top-0 end-0 m-3" onclick="this.closest('.catch-card').remove();"></button>
            
            ${photoHtml}

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
                    <input type="number" min="1" value="1" class="form-control bg-light border-0 p-3 rounded-4" name="prises[${i}][quantite]">
                </div>
                <div class="col-4">
                    <label class="form-label text-secondary small fw-bold text-uppercase">Taille (cm)</label>
                    <input type="number" step="0.5" class="form-control bg-light border-0 p-3 rounded-4" name="prises[${i}][taille]">
                </div>
                <div class="col-4">
                    <label class="form-label text-secondary small fw-bold text-uppercase">Poids (kg)</label>
                    <input type="number" step="0.01" class="form-control bg-light border-0 p-3 rounded-4" name="prises[${i}][poids]">
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
                    <input type="time" class="form-control bg-light border-0 p-3 rounded-4" name="prises[${i}][heure]" value="${captureTime}" required>
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
        
        document.getElementById('catches-container').insertAdjacentHTML('beforeend', html);
        
        // La fonction existe désormais au moment où elle est appelée !
        attachDropdownEvents();
    }

    const photosToLoad = <?php echo json_encode($photos); ?>;
    if(photosToLoad.length > 0) {
        photosToLoad.forEach(photo => addCatchCard(photo));
    } else {
        addCatchCard('no_photo'); 
    }

    document.getElementById('form-etape-3').addEventListener('submit', function(e) {
        let cards = document.querySelectorAll('.catch-card');
        if (cards.length === 0) {
            e.preventDefault();
            alert("Vous devez avoir au moins une prise pour enregistrer une session.");
            return;
        }
        
        let allValid = true;
        cards.forEach(card => {
            let input = card.querySelector('.hidden-espece-input');
            let btn = card.querySelector('.species-btn');
            
            if(!input.value) {
                allValid = false;
                btn.classList.remove('border-0', 'bg-light');
                btn.classList.add('border', 'border-danger', 'bg-danger-subtle');
            }
        });
        
        if(!allValid) {
            e.preventDefault();
            alert("Erreur : Veuillez sélectionner l'espèce pour chaque prise (encadré en rouge).");
        }
    });
</script>
<?php endif; ?>