<!-- MODALE FULLSCREEN : BOITE DE PÊCHE -->
    <div class="modal fade" id="tackleBoxModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-fullscreen-md-down modal-dialog-scrollable">
            <div class="modal-content border-0">
                <div class="modal-header border-0 shadow-sm bg-primary text-white pb-3 pt-4">
                    <h5 class="modal-title fw-bold"><span class="material-symbols-rounded align-middle me-2">phishing</span>Ma Boîte de Pêche</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fermer"></button>
                </div>
                <div class="modal-body bg-light pt-4">
                    <ul class="nav nav-pills mb-4 nav-fill" id="tackle-tab" role="tablist">
                        <li class="nav-item" role="presentation"><button class="nav-link active rounded-pill fw-medium" data-bs-toggle="pill" data-bs-target="#tab-leurres" type="button">Leurres</button></li>
                        <li class="nav-item" role="presentation"><button class="nav-link rounded-pill fw-medium" data-bs-toggle="pill" data-bs-target="#tab-appats" type="button">Appâts</button></li>
                    </ul>
                    <div class="tab-content" id="tackle-tabContent">
                        <div class="tab-pane fade show active" id="tab-leurres">
                            <button class="btn btn-outline-primary w-100 rounded-3 mb-4 fw-bold border-2 border-dashed py-3" type="button" data-bs-toggle="collapse" data-bs-target="#collapseNewLeurre">+ Créer un nouveau leurre perso</button>
                            <div class="collapse mb-4" id="collapseNewLeurre">
                                <div class="card card-body border-0 shadow-sm rounded-4">
                                    <form id="form-new-leurre">
                                        <input type="text" class="form-control bg-light border-0 mb-3" id="new-l-nom" placeholder="Nom (ex: Black Minnow)">
                                        <select class="form-select bg-light border-0 mb-3" id="new-l-type">
                                            <option value="" selected disabled>Type de leurre...</option>
                                            <?php foreach($types_leurre as$tl): ?><option value="<?= $tl[0] ?>"><?= htmlspecialchars($tl[1]) ?></option><?php endforeach; ?>
                                        </select>
                                        <div class="row g-2 mb-4">
                                            <div class="col-6"><input type="number" step="0.1" class="form-control bg-light border-0" id="new-l-poids" placeholder="Poids (g)"></div>
                                            <div class="col-6"><input type="text" class="form-control bg-light border-0" id="new-l-couleur" placeholder="Coloris"></div>
                                        </div>
                                        <button type="button" class="btn btn-primary rounded-pill w-100 fw-semibold" onclick="saveNewLeurre()">Ajouter à la boîte</button>
                                    </form>
                                </div>
                            </div>
                            <div id="list-leurres"></div>
                        </div>
                        <div class="tab-pane fade" id="tab-appats">
                            <button class="btn btn-outline-primary w-100 rounded-3 mb-4 fw-bold border-2 border-dashed py-3" type="button" data-bs-toggle="collapse" data-bs-target="#collapseNewAppat">+ Créer un nouvel appât perso</button>
                            <div class="collapse mb-4" id="collapseNewAppat">
                                <div class="card card-body border-0 shadow-sm rounded-4">
                                    <form id="form-new-appat">
                                        <input type="text" class="form-control bg-light border-0 mb-3" id="new-a-nom" placeholder="Nom de l'appât">
                                        <select class="form-select bg-light border-0 mb-4" id="new-a-type">
                                            <option value="" selected disabled>Catégorie...</option>
                                            <?php foreach($types_appat as$ta): ?><option value="<?= $ta[0] ?>"><?= htmlspecialchars($ta[1]) ?></option><?php endforeach; ?>
                                        </select>
                                        <button type="button" class="btn btn-primary rounded-pill w-100 fw-semibold" onclick="saveNewAppat()">Ajouter à la boîte</button>
                                    </form>
                                </div>
                            </div>
                            <div id="list-appats"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>