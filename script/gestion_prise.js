let tackleModalInstance = null;
let currentCatchIndex = null;

/** Échappe une valeur avant de l'insérer dans du HTML (noms saisis par l'utilisateur, ex: Shad 3"). */
function escapeHtml(valeur) {
    return String(valeur ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

/** Carte cliquable d'un élément de la boîte (les données passent par data-*, jamais dans du JS inline). */
function tackleCardHtml(type, id, nom, details) {
    return `
        <div class="col-6">
            <div class="card border border-primary-subtle h-100 shadow-sm tackle-item" role="button" tabindex="0"
                 data-type="${type}" data-id="${escapeHtml(id)}" data-nom="${escapeHtml(nom)}" data-details="${escapeHtml(details)}" style="cursor:pointer;">
                <div class="card-body p-2 text-center">
                    <span class="d-block fw-bold text-dark small">${escapeHtml(nom)}</span>
                    ${details ? `<span class="d-block text-muted" style="font-size:0.65rem;">${escapeHtml(details)}</span>` : ''}
                </div>
            </div>
        </div>`;
}

function renderTackleBox() {
    let htmlLeurres = '';
    typesLeurre.forEach(type => {
        const items = leurres.filter(l => l.ID_TYPE == type[0]);
        if (items.length > 0) {
            htmlLeurres += `<h6 class="fw-bold mt-4 mb-3 text-primary border-bottom pb-2">${escapeHtml(type[1])}</h6><div class="row g-2">`;
            items.forEach(l => {
                const details = [l.GRAMMAGE ? `${l.GRAMMAGE}g` : '', l.COLORIS || ''].filter(Boolean).join(' - ');
                htmlLeurres += tackleCardHtml('leurre', l.ID_LEURRE, l.NOM_LEURRE, details);
            });
            htmlLeurres += `</div>`;
        }
    });
    document.getElementById('list-leurres').innerHTML = htmlLeurres;

    let htmlAppats = '';
    typesAppat.forEach(type => {
        const items = appats.filter(a => a.ID_TYPE_APPAT == type[0]);
        if (items.length > 0) {
            htmlAppats += `<h6 class="fw-bold mt-4 mb-3 text-primary border-bottom pb-2">${escapeHtml(type[1])}</h6><div class="row g-2">`;
            items.forEach(a => { htmlAppats += tackleCardHtml('appat', a.ID_APPAT, a.NOM_APPAT, ''); });
            htmlAppats += `</div>`;
        }
    });
    document.getElementById('list-appats').innerHTML = htmlAppats;
}

// Délégation d'événement : un seul écouteur pour toutes les cartes de la boîte
document.addEventListener('click', function (e) {
    const card = e.target.closest('.tackle-item');
    if (card) selectTackle(card.dataset.type, card.dataset.id, card.dataset.nom, card.dataset.details);
});

function openTackleBox(index = null) {
    currentCatchIndex = index;
    renderTackleBox();
    if (!tackleModalInstance) tackleModalInstance = new bootstrap.Modal(document.getElementById('tackleBoxModal'));
    tackleModalInstance.show();
}

function selectTackle(type, id, nom, details) {
    const displayStr = details ? `${nom} (${details})` : nom;
    const suffix = currentCatchIndex !== null ? `-${currentCatchIndex}` : '';
    const btn = document.getElementById(`tackle-text${suffix}`);

    if (btn) {
        btn.textContent = displayStr;
        btn.classList.replace('text-muted', 'text-dark');
        btn.classList.add('fw-bold');
    }

    if (type === 'leurre') {
        document.getElementById(`hidden-leurre${suffix}`).value = id;
        document.getElementById(`hidden-appat${suffix}`).value = '';
    } else {
        document.getElementById(`hidden-appat${suffix}`).value = id;
        document.getElementById(`hidden-leurre${suffix}`).value = '';
    }
    tackleModalInstance.hide();
}

/** Ajoute des champs cachés au formulaire principal (valeurs affectées via .value : aucun risque d'injection). */
function addHiddenInputs(prefix, valeurs) {
    const container = document.getElementById('new-items-container');
    Object.entries(valeurs).forEach(([cle, valeur]) => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = `${prefix}[${cle}]`;
        input.value = valeur;
        container.appendChild(input);
    });
}

function saveNewLeurre() {
    const nom = document.getElementById('new-l-nom').value.trim();
    const type = document.getElementById('new-l-type').value;
    const poids = document.getElementById('new-l-poids').value;
    const couleur = document.getElementById('new-l-couleur').value.trim();
    if (!nom || !type) { alert("Veuillez renseigner le nom et le type du leurre."); return; }

    const tempId = 'new_l_' + Date.now();
    leurres.push({ ID_LEURRE: tempId, NOM_LEURRE: nom, ID_TYPE: type, GRAMMAGE: poids, COLORIS: couleur });
    addHiddenInputs(`new_leurres[${tempId}]`, { nom, type, poids, couleur });

    document.getElementById('form-new-leurre').reset();
    bootstrap.Collapse.getOrCreateInstance(document.getElementById('collapseNewLeurre')).hide();
    renderTackleBox();
}

function saveNewAppat() {
    const nom = document.getElementById('new-a-nom').value.trim();
    const type = document.getElementById('new-a-type').value;
    if (!nom || !type) { alert("Veuillez renseigner le nom et la catégorie."); return; }

    const tempId = 'new_a_' + Date.now();
    appats.push({ ID_APPAT: tempId, NOM_APPAT: nom, ID_TYPE_APPAT: type });
    addHiddenInputs(`new_appats[${tempId}]`, { nom, type });

    document.getElementById('form-new-appat').reset();
    bootstrap.Collapse.getOrCreateInstance(document.getElementById('collapseNewAppat')).hide();
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

        // Recherche insensible aux accents et à la casse ("ecrevisse" trouve "Écrevisse")
        const normaliser = t => t.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();

        if (searchInput) {
            searchInput.addEventListener('input', function () {
                const term = normaliser(this.value);
                // Pendant une recherche, l'accès rapide « espèces les plus pêchées » ferait doublon
                const favorites = dropdown.querySelector('.espece-favorites');
                if (favorites) favorites.style.display = term ? 'none' : '';
                options.forEach(opt => {
                    // data-nom : le nom seul (innerText contiendrait aussi le texte de l'icône "set_meal")
                    const visible = normaliser(opt.dataset.nom || opt.textContent).includes(term);
                    opt.style.setProperty('display', visible ? 'flex' : 'none', 'important');
                });
            });
        }

        options.forEach(opt => {
            opt.addEventListener('click', function (e) {
                e.preventDefault();
                hiddenInput.value = this.getAttribute('data-value');
                btnText.innerHTML = this.innerHTML; // contenu déjà échappé à la génération

                if (btnElement) {
                    btnElement.classList.remove('border', 'border-danger', 'bg-danger-subtle');
                    btnElement.classList.add('border-0', 'bg-light');
                    const bsDropdown = bootstrap.Dropdown.getOrCreateInstance(btnElement);
                    if (bsDropdown) bsDropdown.hide();
                }
            });
        });
    });
}

document.addEventListener('DOMContentLoaded', function () {
    attachDropdownEvents();
});
