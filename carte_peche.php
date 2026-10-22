<?php
// Porte-carte de pêche : la carte s'affiche en un geste, même sans réseau (contrôle d'un garde-pêche).
require_once './includes/auth_bdd.php';
require_once './includes/carte_peche.php';

$pdo = db();
$id_user = (int) $_SESSION['user_id'];
$message = '';
$erreur = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $ancienne = carte_de($pdo, $id_user);

    if ($action === 'enregistrer') {
        $fichier = $_FILES['carte'] ?? null;
        if (!$fichier || ($fichier['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($fichier['tmp_name'])) {
            $erreur = "Choisis le fichier de ta carte (PDF ou photo).";
        } elseif ($fichier['size'] > CARTE_MAX_OCTETS) {
            $erreur = "Fichier trop lourd : 8 Mo maximum.";
        } else {
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($fichier['tmp_name']);
            $apercu = null;
            if ($mime === 'application/pdf') {
                $nom = carte_enregistrer_pdf($fichier['tmp_name'], $id_user);
                $type = 'pdf';
                if ($nom && !empty($_POST['apercu'])) $apercu = carte_enregistrer_apercu((string) $_POST['apercu'], $id_user);
            } else {
                $nom = carte_enregistrer_image($fichier['tmp_name'], $id_user);
                $type = 'image';
            }

            if (!$nom) {
                $erreur = "Ce fichier n'a pas pu être enregistré : envoie le PDF de ta carte ou une photo JPEG, PNG ou WebP.";
            } else {
                $fin = DateTime::createFromFormat('Y-m-d', (string) ($_POST['date_fin'] ?? ''));
                $federation = mb_substr(trim((string) ($_POST['federation'] ?? '')), 0, 100) ?: null;
                $pdo->prepare("
                    INSERT INTO carte_peche (ID_UTILISATEUR, FICHIER, TYPE_FICHIER, APERCU, QR_CONTENU, FEDERATION, DATE_FIN_VALIDITE, DATE_AJOUT)
                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE FICHIER = VALUES(FICHIER), TYPE_FICHIER = VALUES(TYPE_FICHIER), APERCU = VALUES(APERCU),
                        QR_CONTENU = VALUES(QR_CONTENU), FEDERATION = VALUES(FEDERATION), DATE_FIN_VALIDITE = VALUES(DATE_FIN_VALIDITE), DATE_AJOUT = NOW()
                ")->execute([$id_user, $nom, $type, $apercu, qr_contenu_valide($_POST['qr_contenu'] ?? null), $federation, $fin ? $fin->format('Y-m-d') : null]);
                if ($ancienne) carte_supprimer_fichiers($id_user, $ancienne); // l'ancienne carte ne reste pas sur le serveur
                header('Location: carte_peche.php?enregistree=1');
                exit();
            }
        }
    }

    if ($action === 'supprimer' && $ancienne) {
        $pdo->prepare("DELETE FROM carte_peche WHERE ID_UTILISATEUR = ?")->execute([$id_user]);
        carte_supprimer_fichiers($id_user, $ancienne);
        header('Location: carte_peche.php?supprimee=1');
        exit();
    }
}

$carte = carte_de($pdo, $id_user);
$remplacer = isset($_GET['remplacer']) || !$carte;
$version = $carte ? strtotime($carte['DATE_AJOUT']) : 0;
$a_apercu = $carte && ($carte['APERCU'] || $carte['TYPE_FICHIER'] === 'image');
$validite = null;
if ($carte && $carte['DATE_FIN_VALIDITE']) {
    $validite = $carte['DATE_FIN_VALIDITE'] >= date('Y-m-d') ? 'valide' : 'expiree';
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ma carte de pêche - Mon P'tit Carnet</title>
    <?php include './includes/head.php'; ?>
</head>
<body>
    <header class="custom-header text-white text-center py-3 mb-3 position-relative">
        <a href="accueil.php" class="text-white position-absolute start-0 top-50 translate-middle-y ms-2 text-decoration-none" aria-label="Retour à l'accueil">
            <span class="material-symbols-rounded">arrow_back_ios_new</span>
        </a>
        <h1 class="h4 mb-0 fw-bold">Ma carte de pêche</h1>
        <button type="button" class="btn btn-sm text-white position-absolute end-0 top-50 translate-middle-y me-2" data-action="theme" aria-label="Mode plein soleil">
            <span class="material-symbols-rounded">light_mode</span>
        </button>
    </header>

    <main class="container" style="max-width: 560px;">
        <?php if (isset($_GET['enregistree'])): ?><div class="alert alert-success rounded-4 border-0">Carte enregistrée. Elle reste affichable sans réseau sur ce téléphone.</div><?php endif; ?>
        <?php if (isset($_GET['supprimee'])): ?><div class="alert alert-success rounded-4 border-0">Carte supprimée du serveur et de ce téléphone.</div><?php endif; ?>
        <?php if ($erreur): ?><div class="alert alert-danger rounded-4 border-0"><?= e($erreur) ?></div><?php endif; ?>

        <?php if ($carte && !$remplacer): ?>
            <!-- AFFICHAGE POUR UN CONTRÔLE -->
            <div class="d-flex align-items-center justify-content-between mb-2">
                <?php if ($validite === 'valide'): ?>
                    <span class="carte-etat text-success"><span class="material-symbols-rounded" aria-hidden="true">verified</span>Valide jusqu'au <?= e(date('d/m/Y', strtotime($carte['DATE_FIN_VALIDITE']))) ?></span>
                <?php elseif ($validite === 'expiree'): ?>
                    <span class="carte-etat text-danger"><span class="material-symbols-rounded" aria-hidden="true">error</span>Expirée le <?= e(date('d/m/Y', strtotime($carte['DATE_FIN_VALIDITE']))) ?></span>
                <?php else: ?>
                    <span class="carte-etat"><span class="material-symbols-rounded" aria-hidden="true">badge</span><?= e($carte['FEDERATION'] ?: 'Carte de pêche') ?></span>
                <?php endif; ?>
                <span id="etat-hors-ligne" class="small text-muted"></span>
            </div>

            <?php if ($carte['QR_CONTENU']): ?>
                <div class="qr-geant mb-3" id="qr-geant" data-qr="<?= e($carte['QR_CONTENU']) ?>" aria-label="QR code de la carte de pêche"></div>
            <?php endif; ?>

            <div id="vue-carte" class="carte-peche-vue mb-3">
                <?php if ($a_apercu): ?>
                    <img src="carte_fichier.php?type=apercu&amp;v=<?= (int) $version ?>" alt="Ma carte de pêche" id="image-carte">
                <?php else: ?>
                    <p class="p-4 mb-0 text-center">Aperçu indisponible. <a href="carte_fichier.php?type=original&amp;v=<?= (int) $version ?>">Ouvrir le PDF</a></p>
                <?php endif; ?>
            </div>

            <div class="d-grid gap-2 mb-4">
                <button type="button" class="btn btn-primary btn-lg rounded-pill" id="btn-plein-ecran">
                    <span class="material-symbols-rounded align-middle me-1">fullscreen</span> Montrer en plein écran
                </button>
                <?php if ($carte['TYPE_FICHIER'] === 'pdf'): ?>
                    <a class="btn btn-outline-primary rounded-pill" href="carte_fichier.php?type=original&amp;v=<?= (int) $version ?>" id="lien-original">
                        <span class="material-symbols-rounded align-middle me-1">picture_as_pdf</span> Ouvrir le PDF original
                    </a>
                <?php endif; ?>
            </div>

            <p class="small text-muted">Monte la luminosité de l'écran au maximum avant de présenter le QR code. L'écran reste allumé tant que cette page est ouverte.</p>

            <div class="d-flex gap-2 mt-4">
                <a href="carte_peche.php?remplacer=1" class="btn btn-light rounded-pill flex-fill"><span class="material-symbols-rounded align-middle me-1">sync</span>Remplacer</a>
                <form method="POST" class="flex-fill" onsubmit="return confirm('Supprimer ta carte de pêche du serveur et de ce téléphone ?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="supprimer">
                    <button class="btn btn-light text-danger rounded-pill w-100"><span class="material-symbols-rounded align-middle me-1">delete</span>Supprimer</button>
                </form>
            </div>

        <?php else: ?>
            <!-- ENVOI DE LA CARTE -->
            <div class="card border-0 shadow-sm p-4 mb-4">
                <h2 class="h5 fw-bold mb-2"><?= $carte ? 'Remplacer ma carte' : 'Ajoute ta carte une fois, montre-la partout' ?></h2>
                <p class="text-muted">Télécharge le PDF de ta carte depuis le site de ta fédération (cartedepeche.fr) ou prends-la en photo. Elle restera affichable sans réseau, avec son QR code en grand.</p>
                <form method="POST" enctype="multipart/form-data" id="form-carte">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="enregistrer">
                    <input type="hidden" name="apercu" id="champ-apercu">
                    <input type="hidden" name="qr_contenu" id="champ-qr">

                    <label for="fichier-carte" class="btn btn-light w-100 p-4 mb-3 border-2" style="border-style: dashed;">
                        <span class="material-symbols-rounded d-block mb-1" style="font-size: 40px;">upload_file</span>
                        Choisir le PDF ou la photo
                        <input type="file" id="fichier-carte" name="carte" accept="application/pdf,image/jpeg,image/png,image/webp" class="d-none" required>
                    </label>
                    <div id="apercu-envoi" class="mb-3" hidden>
                        <canvas id="canvas-carte" class="w-100 rounded-3 border" style="max-height: 360px; object-fit: contain;"></canvas>
                        <p id="statut-qr" class="small mt-2 mb-0" role="status"></p>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-7">
                            <label class="form-label" for="federation">AAPPMA ou fédération</label>
                            <input type="text" class="form-control" id="federation" name="federation" maxlength="100" value="<?= e($carte['FEDERATION'] ?? '') ?>" placeholder="Facultatif">
                        </div>
                        <div class="col-5">
                            <label class="form-label" for="date_fin">Valable jusqu'au</label>
                            <input type="date" class="form-control" id="date_fin" name="date_fin" value="<?= e($carte['DATE_FIN_VALIDITE'] ?? date('Y') . '-12-31') ?>">
                        </div>
                    </div>
                    <button class="btn btn-primary btn-lg rounded-pill w-100" id="btn-envoyer">Enregistrer ma carte</button>
                    <?php if ($carte): ?><a href="carte_peche.php" class="btn btn-light rounded-pill w-100 mt-2">Annuler</a><?php endif; ?>
                </form>
            </div>
            <p class="small text-muted">Ta carte est une donnée personnelle : elle est stockée dans un espace privé du serveur, visible par toi seul, et effacée si tu la supprimes ou si tu supprimes ton compte. Voir les <a href="cgu.php">conditions d'utilisation</a>.</p>
        <?php endif; ?>
    </main>

    <?php include './includes/navbar.php'; ?>

    <script src="script/vendor/qrcode.js"></script>
    <script>
    (function () {
        // ------------------------------------------------ Affichage : QR code en grand, plein écran, écran allumé
        const qr = document.getElementById('qr-geant');
        if (qr && window.qrcode) {
            qrcode.stringToBytes = qrcode.stringToBytesFuncs['UTF-8'];
            const code = qrcode(0, 'M');
            code.addData(qr.dataset.qr);
            code.make();
            qr.innerHTML = code.createSvgTag({ cellSize: 8, margin: 2, scalable: true, alt: 'QR code de la carte de pêche' });
        }
        const pleinEcran = document.getElementById('btn-plein-ecran');
        if (pleinEcran) pleinEcran.addEventListener('click', () => {
            const cible = document.getElementById('qr-geant') || document.getElementById('vue-carte');
            if (cible.requestFullscreen) cible.requestFullscreen().catch(() => {});
            else if (cible.webkitRequestFullscreen) cible.webkitRequestFullscreen();
        });
        let verrou = null;
        async function garderEcranAllume() {
            try { if ('wakeLock' in navigator && document.visibilityState === 'visible' && document.getElementById('vue-carte')) verrou = await navigator.wakeLock.request('screen'); } catch (e) {}
        }
        garderEcranAllume();
        document.addEventListener('visibilitychange', garderEcranAllume);

        // Le PDF original est aussi gardé hors-ligne
        const original = document.getElementById('lien-original');
        if (original && navigator.onLine) fetch(original.href, { credentials: 'same-origin' }).catch(() => {});
        const etat = document.getElementById('etat-hors-ligne');
        if (etat && window.caches) {
            caches.open('mpc-carte').then(c => c.keys()).then(cles => {
                if (cles.some(r => r.url.includes('carte_fichier.php'))) etat.innerHTML = '<span class="material-symbols-rounded align-middle" style="font-size:18px">offline_pin</span> Disponible hors-ligne';
            }).catch(() => {});
        }

        // ------------------------------------------------ Envoi : aperçu du PDF et lecture du QR code dans le navigateur
        const champ = document.getElementById('fichier-carte');
        if (!champ) return;
        const canvas = document.getElementById('canvas-carte');
        const statut = document.getElementById('statut-qr');
        const PDFJS = 'https://cdn.jsdelivr.net/npm/pdfjs-dist@4.10.38/build/';
        const JSQR = 'https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js';

        function chargerScript(src) {
            return new Promise((ok, ko) => { const s = document.createElement('script'); s.src = src; s.onload = ok; s.onerror = ko; document.head.appendChild(s); });
        }
        let canvasDetection = null; // rendu haute définition de la page, pour chercher le QR code
        async function dessinerPdf(fichier) {
            const pdfjs = await import(PDFJS + 'pdf.min.mjs');
            pdfjs.GlobalWorkerOptions.workerSrc = PDFJS + 'pdf.worker.min.mjs';
            const doc = await pdfjs.getDocument({ data: await fichier.arrayBuffer(), isEvalSupported: false }).promise;
            const page = await doc.getPage(1);
            const base = page.getViewport({ scale: 1 });
            const vue = page.getViewport({ scale: Math.min(3, 1600 / base.width) });
            canvas.width = vue.width; canvas.height = vue.height;
            const ctx = canvas.getContext('2d');
            ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, canvas.width, canvas.height);
            await page.render({ canvasContext: ctx, viewport: vue }).promise;
            // Page entière en plus haute définition : un petit QR code reste lisible
            const vueHd = page.getViewport({ scale: Math.min(4, 2400 / base.width) });
            canvasDetection = document.createElement('canvas');
            canvasDetection.width = vueHd.width; canvasDetection.height = vueHd.height;
            const ctxHd = canvasDetection.getContext('2d');
            ctxHd.fillStyle = '#fff'; ctxHd.fillRect(0, 0, canvasDetection.width, canvasDetection.height);
            await page.render({ canvasContext: ctxHd, viewport: vueHd }).promise;
            rognerMarges();
        }
        // Une carte occupe souvent le haut d'une page A4 : on retire les grandes marges blanches
        function rognerMarges() {
            const ctx = canvas.getContext('2d');
            const { width: l, height: h } = canvas;
            const px = ctx.getImageData(0, 0, l, h).data;
            let haut = h, bas = 0, gauche = l, droite = 0;
            for (let y = 0; y < h; y += 2) {
                for (let x = 0; x < l; x += 2) {
                    const i = (y * l + x) * 4;
                    if (px[i] < 235 || px[i + 1] < 235 || px[i + 2] < 235) {
                        if (y < haut) haut = y; if (y > bas) bas = y;
                        if (x < gauche) gauche = x; if (x > droite) droite = x;
                    }
                }
            }
            if (bas <= haut || droite <= gauche) return;
            const marge = Math.round(l * 0.03);
            const x0 = Math.max(0, gauche - marge), y0 = Math.max(0, haut - marge);
            const lw = Math.min(l, droite + marge) - x0, lh = Math.min(h, bas + marge) - y0;
            const copie = ctx.getImageData(x0, y0, lw, lh);
            canvas.width = lw; canvas.height = lh;
            ctx.putImageData(copie, 0, 0);
        }
        async function dessinerImage(fichier) {
            const bmp = await createImageBitmap(fichier);
            const echelle = Math.min(1, 1600 / bmp.width);
            canvas.width = bmp.width * echelle; canvas.height = bmp.height * echelle;
            canvas.getContext('2d').drawImage(bmp, 0, 0, canvas.width, canvas.height);
            const echelleHd = Math.min(1, 2400 / Math.max(bmp.width, bmp.height));
            canvasDetection = document.createElement('canvas');
            canvasDetection.width = bmp.width * echelleHd; canvasDetection.height = bmp.height * echelleHd;
            canvasDetection.getContext('2d').drawImage(bmp, 0, 0, canvasDetection.width, canvasDetection.height);
        }

        // ------------------------------------------------ Lecture du QR code, quelle que soit son orientation
        // Le PDF de cartedepeche.fr place le QR code à l'envers : on essaie 0°, 180°, 90° et 270°,
        // en miroir, puis zone par zone agrandie (un petit QR code au milieu du texte), jusqu'à le trouver.
        async function decoderCanvas(c) {
            if ('BarcodeDetector' in window) {
                try {
                    const codes = await new BarcodeDetector({ formats: ['qr_code'] }).detect(c);
                    if (codes.length) return codes[0].rawValue;
                } catch (e) {}
            }
            if (!window.jsQR) await chargerScript(JSQR);
            const img = c.getContext('2d', { willReadFrequently: true }).getImageData(0, 0, c.width, c.height);
            const r = window.jsQR(img.data, img.width, img.height, { inversionAttempts: 'attemptBoth' });
            return r ? r.data : null;
        }
        function transformer(source, { angle = 0, miroir = false, zone = [0, 0, 1, 1], echelle = 1 }) {
            const [zx, zy, zl, zh] = zone;
            const sx = Math.round(source.width * zx), sy = Math.round(source.height * zy);
            const sl = Math.round(source.width * zl), sh = Math.round(source.height * zh);
            const k = Math.min(echelle, 2400 / Math.max(sl, sh));
            const l = Math.max(1, Math.round(sl * k)), h = Math.max(1, Math.round(sh * k));
            const quart = angle % 180 !== 0;
            const c = document.createElement('canvas');
            c.width = quart ? h : l; c.height = quart ? l : h;
            const ctx = c.getContext('2d', { willReadFrequently: true });
            ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, c.width, c.height);
            ctx.translate(c.width / 2, c.height / 2);
            ctx.rotate(angle * Math.PI / 180);
            if (miroir) ctx.scale(-1, 1);
            ctx.imageSmoothingEnabled = echelle <= 1;
            ctx.drawImage(source, sx, sy, sl, sh, -l / 2, -h / 2, l, h);
            return c;
        }
        const ESSAIS_QR = (() => {
            const liste = [0, 180, 90, 270].map(angle => ({ angle }));
            liste.push({ angle: 0, miroir: true }, { angle: 180, miroir: true });
            const zones = [[0, 0, .55, .55], [.45, 0, .55, .55], [0, .45, .55, .55], [.45, .45, .55, .55], [0, 0, 1, .5], [0, .5, 1, .5], [.2, .2, .6, .6]];
            zones.forEach(zone => [0, 180].forEach(angle => liste.push({ angle, zone, echelle: 2 })));
            return liste;
        })();
        async function lireQr() {
            const sources = [canvasDetection, canvas].filter(Boolean);
            for (const source of sources) {
                for (let i = 0; i < ESSAIS_QR.length; i++) {
                    if (i && i % 4 === 0) statut.textContent = `Recherche du QR code… (${Math.round(i / ESSAIS_QR.length * 100)} %)`;
                    const contenu = await decoderCanvas(transformer(source, ESSAIS_QR[i]));
                    if (contenu) return contenu;
                    await new Promise(r => setTimeout(r, 0)); // laisse la page respirer entre deux essais
                }
            }
            return null;
        }

        champ.addEventListener('change', async () => {
            const fichier = champ.files[0];
            canvasDetection = null;
            document.getElementById('champ-apercu').value = '';
            document.getElementById('champ-qr').value = '';
            if (!fichier) return;
            document.getElementById('apercu-envoi').hidden = false;
            statut.textContent = 'Lecture de la carte…';
            const envoyer = document.getElementById('btn-envoyer');
            envoyer.disabled = true;
            try {
                if (fichier.type === 'application/pdf' || /\.pdf$/i.test(fichier.name)) {
                    await dessinerPdf(fichier);
                    document.getElementById('champ-apercu').value = canvas.toDataURL('image/jpeg', 0.9);
                } else {
                    await dessinerImage(fichier);
                }
                const contenu = await lireQr();
                if (contenu) {
                    document.getElementById('champ-qr').value = contenu;
                    statut.innerHTML = '<span class="text-success fw-bold">QR code détecté</span> : il sera affiché en grand pour le contrôle.';
                } else {
                    statut.textContent = "Aucun QR code détecté : la carte sera affichée telle quelle.";
                }
            } catch (e) {
                statut.textContent = "Aperçu impossible ici (connexion nécessaire la première fois) : la carte sera quand même enregistrée.";
            }
            envoyer.disabled = false;
        });
        document.getElementById('form-carte').addEventListener('submit', () => {
            const b = document.getElementById('btn-envoyer');
            b.disabled = true;
            b.textContent = 'Enregistrement…';
        });
    })();
    </script>
</body>
</html>
