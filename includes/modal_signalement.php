<?php
// Fenêtre de signalement d'une image (photo de prise, photo de profil, bannière).
// Les boutons qui l'ouvrent portent data-type et data-cible (ou data-photo).
?>
<div class="modal fade" id="modalSignalement" tabindex="-1" aria-labelledby="modalSignalementLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow">
            <form method="POST" action="signaler.php">
                <?= csrf_field() ?>
                <input type="hidden" name="type" id="signalement-type">
                <input type="hidden" name="id_photo" id="signalement-photo">
                <input type="hidden" name="id_cible" id="signalement-cible">
                <input type="hidden" name="retour" value="<?= e($url_retour ?? 'accueil.php') ?>">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title fw-bold d-flex align-items-center" id="modalSignalementLabel">
                        <span class="material-symbols-rounded text-danger me-2">flag</span> Signaler une image
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
                </div>
                <div class="modal-body">
                    <label class="form-label small fw-medium text-secondary">Motif</label>
                    <select name="motif" class="form-select bg-light border-0 mb-3" required>
                        <?php foreach (MOTIFS_SIGNALEMENT as $motif): ?>
                            <option value="<?= e($motif) ?>"><?= e($motif) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label class="form-label small fw-medium text-secondary">Précision (optionnel)</label>
                    <textarea name="commentaire" class="form-control bg-light border-0" rows="2" maxlength="255"></textarea>
                    <p class="small text-muted mt-3 mb-0">Un administrateur va examiner l'image. Une photo de prise signalée est masquée en attendant sa décision.</p>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-danger rounded-pill px-4 fw-semibold">Signaler</button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
    document.getElementById('modalSignalement').addEventListener('show.bs.modal', function (event) {
        const bouton = event.relatedTarget;
        document.getElementById('signalement-type').value = bouton?.dataset.type || '';
        document.getElementById('signalement-photo').value = bouton?.dataset.photo || '';
        document.getElementById('signalement-cible').value = bouton?.dataset.cible || '';
    });
</script>
