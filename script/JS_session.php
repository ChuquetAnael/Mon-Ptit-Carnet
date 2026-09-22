<!-- SCRIPT ÉTAPE 1 : AJOUT ASYNCHRONE DE PHOTOS (RÉPARÉ) -->
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
        realInput.files = dataTransfer.files; // On remplit le VRAI champ caché
        proxyInput.value = ''; // On vide le faux champ pour pouvoir recliquer
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

<!-- SCRIPT ÉTAPE 2 : LEAFLET ET SPOTS -->
<?php if ($etape === 2): ?>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
    function toggleNewSpot() {
        const select = document.getElementById('spot-select');
        const panel = document.getElementById('new-spot-panel');
        document.getElementById('is_new_spot').value = (select.value === 'new') ? '1' : '0';
        panel.style.display = (select.value === 'new') ? 'block' : 'none';
    }

    function toggleCapotBtn() {
        const isCapot = document.getElementById('is_capot').checked;
        const btn = document.getElementById('btn-submit-step2');
        if (isCapot) {
            btn.innerHTML = 'Enregistrer la session <span class="material-symbols-rounded align-middle ms-2">check_circle</span>';
            btn.classList.remove('btn-primary', 'custom-btn-submit');
            btn.classList.add('btn-secondary');
        } else {
            btn.innerHTML = 'Continuer <span class="material-symbols-rounded align-middle ms-2">arrow_forward</span>';
            btn.classList.remove('btn-secondary');
            btn.classList.add('btn-primary', 'custom-btn-submit');
        }
    }

    document.addEventListener("DOMContentLoaded", function() {
        let latInput = document.getElementById('input_lat');
        let lngInput = document.getElementById('input_lng');
        let initLat = parseFloat(latInput.value);
        let initLng = parseFloat(lngInput.value);
        
        let defaultView = (!isNaN(initLat) && initLat !== 0) ? [initLat, initLng] : [46.603354, 1.888334];
        let zoom = (!isNaN(initLat) && initLat !== 0) ? 14 : 5;
        let map = L.map('map').setView(defaultView, zoom);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '© OpenStreetMap' }).addTo(map);

        let marker = null;

        function fetchWeather(lat, lng) {
            latInput.value = lat.toFixed(6); lngInput.value = lng.toFixed(6);
            fetch(`https://api.open-meteo.com/v1/forecast?latitude=${lat}&longitude=${lng}&current=temperature_2m,surface_pressure,wind_speed_10m`)
            .then(response => response.json())
            .then(data => {
                if(data.current) {
                    document.getElementById('weather-card').style.display = 'block';
                    document.getElementById('ui_temp').innerText = data.current.temperature_2m;
                    document.getElementById('ui_press').innerText = data.current.surface_pressure;
                    document.getElementById('ui_wind').innerText = data.current.wind_speed_10m;
                    document.getElementById('input_temp').value = data.current.temperature_2m;
                    document.getElementById('input_press').value = data.current.surface_pressure;
                    document.getElementById('input_wind').value = data.current.wind_speed_10m;
                }
            }).catch(error => console.log(error));
        }

        if (!isNaN(initLat) && initLat !== 0) {
            marker = L.marker([initLat, initLng], {draggable: true}).addTo(map);
            fetchWeather(initLat, initLng);
            marker.on('dragend', function() { fetchWeather(marker.getLatLng().lat, marker.getLatLng().lng); });
        }

        map.on('click', function(e) {
            if (marker) marker.setLatLng(e.latlng);
            else {
                marker = L.marker(e.latlng, {draggable: true}).addTo(map);
                marker.on('dragend', function() { fetchWeather(marker.getLatLng().lat, marker.getLatLng().lng); });
            }
            fetchWeather(e.latlng.lat, e.latlng.lng);
        });
    });
</script>
<?php endif; ?>

<!-- SCRIPT ÉTAPE 3 : PWA AVANCÉE -->
<?php if ($etape === 3): ?>
<script>
    const especes = <?php echo json_encode($especes, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    const techniques = <?php echo json_encode($techniques, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    let leurres = <?php echo json_encode($leurres, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    let appats = <?php echo json_encode($appats, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    const typesLeurre = <?php echo json_encode($types_leurre, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    const typesAppat = <?php echo json_encode($types_appat, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    
    let catchIndex = 0;
    let currentCatchIndex = null;
    let tackleModalInstance = null;

    function addCatchCard(photoPath) {
        const i = catchIndex++;
        let photoHtml = photoPath !== 'no_photo' 
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
                <div class="col-6"><label class="form-label text-secondary small fw-bold text-uppercase">Taille (cm)</label><input type="number" step="0.5" class="form-control bg-light border-0 p-3 rounded-4" name="prises[${i}][taille]" placeholder="0.0"></div>
                <div class="col-6"><label class="form-label text-secondary small fw-bold text-uppercase">Poids (kg)</label><input type="number" step="0.01" class="form-control bg-light border-0 p-3 rounded-4" name="prises[${i}][poids]" placeholder="0.00"></div>
            </div>

            <div class="mb-4">
                <label class="form-label text-secondary small fw-bold text-uppercase d-block">Appât / Leurre utilisé</label>
                <button type="button" class="btn bg-light w-100 rounded-4 d-flex align-items-center justify-content-between p-3 border-0" onclick="openTackleBox(${i})">
                    <span id="tackle-text-${i}" class="text-truncate text-muted" style="max-width:85%;">Choisir dans la boîte...</span>
                    <span class="material-symbols-rounded text-primary">phishing</span>
                </button>
                <input type="hidden" name="prises[${i}][id_leurre]" id="hidden-leurre-${i}">
                <input type="hidden" name="prises[${i}][id_appat]" id="hidden-appat-${i}">
            </div>

            <div class="mb-3">
                <label class="form-label text-secondary small fw-bold text-uppercase">Technique / Animation</label>
                <select class="form-select bg-light border-0 p-3 rounded-4" name="prises[${i}][id_technique]">
                    <option value="" selected>Non précisée</option>
                    ${techOptions}
                </select>
            </div>

            <div class="form-check form-switch mt-4 bg-light p-3 rounded-4 d-flex align-items-center">
                <input class="form-check-input ms-0 me-3" type="checkbox" name="prises[${i}][relache]" id="relache${i}" checked style="transform: scale(1.3);">
                <label class="form-check-label fw-bold text-success mb-0" for="relache${i}">Poisson relâché (No-Kill)</label>
            </div>
        </div>`;
        
        document.getElementById('catches-container').insertAdjacentHTML('beforeend', html);
        attachDropdownEvents();
    }

    const photosToLoad = <?php echo json_encode($photos); ?>;
    if(photosToLoad.length > 0) {
        photosToLoad.forEach(photo => addCatchCard(photo));
    } else {
        addCatchCard('no_photo'); 
    }

    function attachDropdownEvents() {
        document.querySelectorAll('.custom-select-espece:not(.initialized)').forEach(dropdown => {
            dropdown.classList.add('initialized');
            const btnText = dropdown.querySelector('.selected-espece-text');
            const btnElement = dropdown.querySelector('.species-btn');
            const hiddenInput = dropdown.querySelector('.hidden-espece-input');
            const searchInput = dropdown.querySelector('.search-espece');
            const options = dropdown.querySelectorAll('.espece-option');

            dropdown.querySelector('.dropdown-menu').addEventListener('click', e => e.stopPropagation());

            searchInput.addEventListener('input', function() {
                const term = this.value.toLowerCase().trim();
                options.forEach(opt => {
                    const text = opt.innerText.toLowerCase();
                    if (text.includes(term)) {
                        opt.classList.remove('d-none');
                        opt.classList.add('d-flex');
                    } else {
                        opt.classList.remove('d-flex');
                        opt.classList.add('d-none');
                    }
                });
            });

            options.forEach(opt => {
                opt.addEventListener('click', function(e) {
                    e.preventDefault();
                    hiddenInput.value = this.getAttribute('data-value');
                    btnText.innerHTML = this.innerHTML;
                    
                    btnElement.classList.remove('border', 'border-danger', 'bg-danger-subtle');
                    btnElement.classList.add('border-0', 'bg-light');
                    
                    const bsDropdown = bootstrap.Dropdown.getInstance(btnElement) || new bootstrap.Dropdown(btnElement);
                    bsDropdown.hide();
                });
            });
        });
    }

    function renderTackleBox() {
        let htmlLeurres = '';
        typesLeurre.forEach(type => {
            let items = leurres.filter(l => l.ID_TYPE == type[0]);
            if(items.length > 0) {
                htmlLeurres += `<h6 class="fw-bold mt-4 mb-3 text-primary border-bottom pb-2">${type[1]}</h6><div class="row g-2">`;
                items.forEach(l => {
                    let desc = l.GRAMMAGE ? `${l.GRAMMAGE}g` : '';
                    let col = l.COLORIS ? `${l.COLORIS}` : '';
                    let details = [desc, col].filter(Boolean).join(' - ');
                    htmlLeurres += `
                    <div class="col-6">
                        <div class="card border border-primary-subtle h-100 shadow-sm" onclick="selectTackle('leurre', '${l.ID_LEURRE}', '${l.NOM_LEURRE.replace(/'/g, "\\'")}', '${details}')" style="cursor:pointer;">
                            <div class="card-body p-2 text-center">
                                <span class="d-block fw-bold text-dark small">${l.NOM_LEURRE}</span>
                                <span class="d-block text-muted" style="font-size:0.65rem;">${details}</span>
                            </div>
                        </div>
                    </div>`;
                });
                htmlLeurres += `</div>`;
            }
        });
        document.getElementById('list-leurres').innerHTML = htmlLeurres;

        let htmlAppats = '';
        typesAppat.forEach(type => {
            let items = appats.filter(a => a.ID_TYPE_APPAT == type[0]);
            if(items.length > 0) {
                htmlAppats += `<h6 class="fw-bold mt-4 mb-3 text-primary border-bottom pb-2">${type[1]}</h6><div class="row g-2">`;
                items.forEach(a => {
                    htmlAppats += `
                    <div class="col-6">
                        <div class="card border border-primary-subtle h-100 shadow-sm" onclick="selectTackle('appat', '${a.ID_APPAT}', '${a.NOM_APPAT.replace(/'/g, "\\'")}', '')" style="cursor:pointer;">
                            <div class="card-body p-2 text-center">
                                <span class="d-block fw-bold text-dark small">${a.NOM_APPAT}</span>
                            </div>
                        </div>
                    </div>`;
                });
                htmlAppats += `</div>`;
            }
        });
        document.getElementById('list-appats').innerHTML = htmlAppats;
    }

    function openTackleBox(index) {
        currentCatchIndex = index;
        renderTackleBox();
        if(!tackleModalInstance) tackleModalInstance = new bootstrap.Modal(document.getElementById('tackleBoxModal'));
        tackleModalInstance.show();
    }

    function selectTackle(type, id, nom, details) {
        const displayStr = details ? `${nom} (${details})` : nom;
        const btn = document.getElementById(`tackle-text-${currentCatchIndex}`);
        btn.innerText = displayStr;
        btn.classList.replace('text-muted', 'text-dark');
        btn.classList.add('fw-bold');
        
        if(type === 'leurre') {
            document.getElementById(`hidden-leurre-${currentCatchIndex}`).value = id;
            document.getElementById(`hidden-appat-${currentCatchIndex}`).value = '';
        } else {
            document.getElementById(`hidden-appat-${currentCatchIndex}`).value = id;
            document.getElementById(`hidden-leurre-${currentCatchIndex}`).value = '';
        }
        tackleModalInstance.hide();
    }

    function saveNewLeurre() {
        let nom = document.getElementById('new-l-nom').value;
        let type = document.getElementById('new-l-type').value;
        let poids = document.getElementById('new-l-poids').value;
        let couleur = document.getElementById('new-l-couleur').value;
        if(!nom || !type) { alert("Veuillez renseigner le nom et le type du leurre."); return; }
        
        let tempId = 'new_l_' + Date.now();
        leurres.push({ ID_LEURRE: tempId, NOM_LEURRE: nom, ID_TYPE: type, GRAMMAGE: poids, COLORIS: couleur });
        
        document.getElementById('new-items-container').insertAdjacentHTML('beforeend', `
            <input type="hidden" name="new_leurres[${tempId}][nom]" value="${nom}">
            <input type="hidden" name="new_leurres[${tempId}][type]" value="${type}">
            <input type="hidden" name="new_leurres[${tempId}][poids]" value="${poids}">
            <input type="hidden" name="new_leurres[${tempId}][couleur]" value="${couleur}">
        `);
        
        document.getElementById('form-new-leurre').reset();
        bootstrap.Collapse.getInstance(document.getElementById('collapseNewLeurre')).hide();
        renderTackleBox();
    }

    function saveNewAppat() {
        let nom = document.getElementById('new-a-nom').value;
        let type = document.getElementById('new-a-type').value;
        if(!nom || !type) { alert("Veuillez renseigner le nom et la catégorie."); return; }
        
        let tempId = 'new_a_' + Date.now();
        appats.push({ ID_APPAT: tempId, NOM_APPAT: nom, ID_TYPE_APPAT: type });
        
        document.getElementById('new-items-container').insertAdjacentHTML('beforeend', `
            <input type="hidden" name="new_appats[${tempId}][nom]" value="${nom}">
            <input type="hidden" name="new_appats[${tempId}][type]" value="${type}">
        `);
        
        document.getElementById('form-new-appat').reset();
        bootstrap.Collapse.getInstance(document.getElementById('collapseNewAppat')).hide();
        renderTackleBox();
    }

    // VALIDATION VISUELLE AVANT ENVOI FINAL
    document.getElementById('submit-final').addEventListener('click', function(e) {
        let cards = document.querySelectorAll('.catch-card');
        if (cards.length === 0) {
            alert("Vous devez avoir au moins une prise pour valider la session.");
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
            alert("Erreur : Veuillez sélectionner l'espèce pour chaque prise (encadré en rouge).");
        } else {
            document.getElementById('form-etape-3').submit();
        }
    });
</script>
<?php endif; ?>