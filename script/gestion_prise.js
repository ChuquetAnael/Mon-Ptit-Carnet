let tackleModalInstance = null;
let currentCatchIndex = null;

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

function openTackleBox(index = null) {
    currentCatchIndex = index;
    renderTackleBox();
    if(!tackleModalInstance) tackleModalInstance = new bootstrap.Modal(document.getElementById('tackleBoxModal'));
    tackleModalInstance.show();
}

function selectTackle(type, id, nom, details) {
    const displayStr = details ? `${nom} (${details})` : nom;
    const suffix = currentCatchIndex !== null ? `-${currentCatchIndex}` : '';
    const btn = document.getElementById(`tackle-text${suffix}`);
    
    if (btn) {
        btn.innerText = displayStr;
        btn.classList.replace('text-muted', 'text-dark');
        btn.classList.add('fw-bold');
    }
    
    if(type === 'leurre') {
        document.getElementById(`hidden-leurre${suffix}`).value = id;
        document.getElementById(`hidden-appat${suffix}`).value = '';
    } else {
        document.getElementById(`hidden-appat${suffix}`).value = id;
        document.getElementById(`hidden-leurre${suffix}`).value = '';
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

function attachDropdownEvents() {
    document.querySelectorAll('.custom-select-espece:not(.initialized)').forEach(dropdown => {
        dropdown.classList.add('initialized');
        const btnText = dropdown.querySelector('.selected-espece-text');
        const btnElement = dropdown.querySelector('.species-btn');
        const hiddenInput = dropdown.querySelector('.hidden-espece-input');
        const searchInput = dropdown.querySelector('.search-espece');
        const options = dropdown.querySelectorAll('.espece-option');

        const menu = dropdown.querySelector('.dropdown-menu');
        if (menu) menu.addEventListener('click', e => e.stopPropagation());

        if (searchInput) {
            searchInput.addEventListener('input', function() {
                const term = this.value.toLowerCase().trim();
                options.forEach(opt => {
                    const text = opt.innerText.toLowerCase();
                    if (text.includes(term)) {
                        opt.style.setProperty('display', 'flex', 'important');
                    } else {
                        opt.style.setProperty('display', 'none', 'important');
                    }
                });
            });
        }

        options.forEach(opt => {
            opt.addEventListener('click', function(e) {
                e.preventDefault();
                hiddenInput.value = this.getAttribute('data-value');
                btnText.innerHTML = this.innerHTML;
                
                if (btnElement) {
                    btnElement.classList.remove('border', 'border-danger', 'bg-danger-subtle');
                    btnElement.classList.add('border-0', 'bg-light');
                    const bsDropdown = bootstrap.Dropdown.getInstance(btnElement) || new bootstrap.Dropdown(btnElement);
                    if (bsDropdown) bsDropdown.hide();
                }
            });
        });
    });
}

document.addEventListener('DOMContentLoaded', function() {
    attachDropdownEvents();
});