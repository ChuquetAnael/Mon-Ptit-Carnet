<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: connexion.php');
    exit();
}

require_once './bdd/env.php';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 1. TRAITEMENT DU FORMULAIRE (Envoi des données)
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action_post = $_POST['action_form'] ?? '';
        $item_id = $_POST['item_id'] ?? null;
        $choix_type = $_POST['choix_type'] ?? 'leurre'; // 'leurre' ou 'appat'
        
        $nom = trim($_POST['nom'] ?? '');
        $id_categorie = $_POST['id_categorie'] ?? null;
        
        if (!empty($nom) && !empty($id_categorie)) {
            if ($choix_type === 'leurre') {
                $grammage = !empty($_POST['grammage']) ? str_replace(',', '.', $_POST['grammage']) : null;
                $coloris = trim($_POST['coloris'] ?? '');

                if ($action_post === 'add') {
                    $stmt = $pdo->prepare("INSERT INTO LEURRE (ID_UTILISATEUR, ID_TYPE, NOM_LEURRE, GRAMMAGE, COLORIS) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$_SESSION['user_id'], $id_categorie, $nom, $grammage, $coloris]);
                } elseif ($action_post === 'edit' && $item_id) {
                    $stmt = $pdo->prepare("UPDATE LEURRE SET ID_TYPE = ?, NOM_LEURRE = ?, GRAMMAGE = ?, COLORIS = ? WHERE ID_LEURRE = ? AND ID_UTILISATEUR = ?");
                    $stmt->execute([$id_categorie, $nom, $grammage, $coloris, $item_id, $_SESSION['user_id']]);
                }
            } elseif ($choix_type === 'appat') {
                if ($action_post === 'add') {
                    $stmt = $pdo->prepare("INSERT INTO APPAT (ID_UTILISATEUR, ID_TYPE_APPAT, NOM_APPAT) VALUES (?, ?, ?)");
                    $stmt->execute([$_SESSION['user_id'], $id_categorie, $nom]);
                } elseif ($action_post === 'edit' && $item_id) {
                    $stmt = $pdo->prepare("UPDATE APPAT SET ID_TYPE_APPAT = ?, NOM_APPAT = ? WHERE ID_APPAT = ? AND ID_UTILISATEUR = ?");
                    $stmt->execute([$id_categorie, $nom, $item_id, $_SESSION['user_id']]);
                }
            }
            // Redirection après succès
            header('Location: boite_peche.php');
            exit();
        } else {
            $erreur = "Le nom et la catégorie sont obligatoires.";
        }
    }

    // 2. PRÉPARATION DE L'AFFICHAGE (Mode Ajout ou Modification)
    $action = $_GET['action'] ?? 'add';
    $type_item = $_GET['type'] ?? 'leurre';
    $id = $_GET['id'] ?? null;
    $item_data = null;

    if ($action === 'edit' && $id) {
        if ($type_item === 'leurre') {
            $stmt = $pdo->prepare("SELECT ID_LEURRE as id, NOM_LEURRE as nom, ID_TYPE as id_cat, GRAMMAGE as grammage, COLORIS as coloris FROM LEURRE WHERE ID_LEURRE = ? AND ID_UTILISATEUR = ?");
        } else {
            $stmt = $pdo->prepare("SELECT ID_APPAT as id, NOM_APPAT as nom, ID_TYPE_APPAT as id_cat FROM APPAT WHERE ID_APPAT = ? AND ID_UTILISATEUR = ?");
        }
        $stmt->execute([$id, $_SESSION['user_id']]);
        $item_data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$item_data) {
            die("Élément introuvable ou non autorisé.");
        }
    }

    // 3. CHARGEMENT DES CATÉGORIES POUR LES MENUS DÉROULANTS
    $stmtTypesLeurres = $pdo->query("SELECT ID_TYPE, NOM_TYPE FROM TYPE_LEURRE ORDER BY NOM_TYPE ASC");
    $types_leurres = $stmtTypesLeurres->fetchAll(PDO::FETCH_ASSOC);

    $stmtTypesAppats = $pdo->query("SELECT ID_TYPE_APPAT, NOM_TYPE_APPAT FROM TYPE_APPAT ORDER BY NOM_TYPE_APPAT ASC");
    $types_appats = $stmtTypesAppats->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Erreur de base de données : " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $action === 'add' ? 'Ajouter' : 'Modifier' ?> - Ma Boîte de Pêche</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,1,0" rel="stylesheet">
    <link href="css/style.css" rel="stylesheet">
</head>
<body class="bg-light">

    <!-- En-tête -->
    <header class="custom-header text-white text-center py-4 shadow-sm mb-4 position-relative" style="background: linear-gradient(135deg, #f39c12 0%, #d35400 100%); border-bottom-left-radius: 20px; border-bottom-right-radius: 20px;">
        <a href="boite_peche.php" class="text-white position-absolute start-0 translate-middle-y ms-3 text-decoration-none" style="top: 50%;">
            <span class="material-symbols-rounded">arrow_back_ios_new</span>
        </a>
        <h1 class="h4 mb-0 fw-semibold">
            <?= $action === 'add' ? 'Nouveau matériel' : 'Modifier l\'élément' ?>
        </h1>
    </header>

    <main class="container mb-5 pb-4">
        
        <?php if(isset($erreur)): ?>
            <div class="alert alert-danger rounded-4 border-0 shadow-sm text-center" role="alert">
                <?= htmlspecialchars($erreur) ?>
            </div>
        <?php endif; ?>

        <div class="card border-0 shadow-sm rounded-4 p-4">
            <form action="" method="POST" id="formBoite">
                <input type="hidden" name="action_form" value="<?= $action ?>">
                <input type="hidden" name="item_id" value="<?= $item_data['id'] ?? '' ?>">
                
                <!-- Sélecteur Leurre / Appât (Seulement visible en mode Ajout) -->
                <?php if ($action === 'add'): ?>
                    <div class="d-flex justify-content-center mb-4">
                        <div class="btn-group w-100 shadow-sm" role="group">
                            <input type="radio" class="btn-check" name="choix_type" id="btnradio1" value="leurre" autocomplete="off" checked>
                            <label class="btn btn-outline-warning rounded-start-pill py-2 fw-medium" for="btnradio1">Leurre</label>

                            <input type="radio" class="btn-check" name="choix_type" id="btnradio2" value="appat" autocomplete="off">
                            <label class="btn btn-outline-warning rounded-end-pill py-2 fw-medium" for="btnradio2">Appât</label>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- En mode édition, on fixe le type invisiblement -->
                    <input type="hidden" name="choix_type" id="choix_type_hidden" value="<?= $type_item ?>">
                    <div class="text-center mb-4">
                        <span class="badge bg-warning bg-opacity-10 text-warning px-3 py-2 rounded-pill fw-bold fs-6">
                            Modification d'un <?= $type_item === 'leurre' ? 'Leurre' : 'Appât' ?>
                        </span>
                    </div>
                <?php endif; ?>

                <!-- Nom de l'élément -->
                <div class="mb-4">
                    <label for="nom" class="form-label fw-medium text-secondary">Nom du modèle</label>
                    <input type="text" class="form-control form-control-lg bg-light border-0 rounded-3" id="nom" name="nom" 
                           placeholder="Ex: Black Minnow 120, Maïs doux..." 
                           value="<?= htmlspecialchars($item_data['nom'] ?? '') ?>" required>
                </div>

                <!-- Catégorie (Listes dynamiques) -->
                <div class="mb-4">
                    <label for="id_categorie" class="form-label fw-medium text-secondary">Catégorie</label>
                    
                    <!-- Select pour les Leurres -->
                    <select class="form-select form-select-lg bg-light border-0 rounded-3" id="select_categorie_leurre" name="id_categorie_leurre" <?= ($action === 'add' || $type_item === 'leurre') ? 'required' : '' ?>>
                        <option value="" selected disabled>Choisir un type de leurre...</option>
                        <?php foreach($types_leurres as $type): ?>
                            <option value="<?= $type['ID_TYPE'] ?>" <?= (isset($item_data['id_cat']) && $item_data['id_cat'] == $type['ID_TYPE'] && $type_item === 'leurre') ? 'selected' : '' ?>>
                                <?= htmlspecialchars($type['NOM_TYPE']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <!-- Select pour les Appâts (Caché par défaut si ajout, ou si on édite un leurre) -->
                    <select class="form-select form-select-lg bg-light border-0 rounded-3" id="select_categorie_appat" name="id_categorie_appat" style="display: none;">
                        <option value="" selected disabled>Choisir un type d'appât...</option>
                        <?php foreach($types_appats as $type): ?>
                            <option value="<?= $type['ID_TYPE_APPAT'] ?>" <?= (isset($item_data['id_cat']) && $item_data['id_cat'] == $type['ID_TYPE_APPAT'] && $type_item === 'appat') ? 'selected' : '' ?>>
                                <?= htmlspecialchars($type['NOM_TYPE_APPAT']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    
                    <!-- Champ caché final qui sera envoyé en POST -->
                    <input type="hidden" name="id_categorie" id="final_id_categorie" value="<?= $item_data['id_cat'] ?? '' ?>">
                </div>

                <!-- Section Spécifique LEURRE (Grammage et Coloris) -->
                <div id="section_leurre">
                    <div class="row g-3 mb-4">
                        <div class="col-6">
                            <label for="grammage" class="form-label fw-medium text-secondary d-flex align-items-center">
                                <span class="material-symbols-rounded fs-6 me-1">scale</span> Poids (g)
                            </label>
                            <input type="number" step="0.1" class="form-control form-control-lg bg-light border-0 rounded-3" id="grammage" name="grammage" 
                                   placeholder="Ex: 12.5" value="<?= htmlspecialchars($item_data['grammage'] ?? '') ?>">
                        </div>
                        <div class="col-6">
                            <label for="coloris" class="form-label fw-medium text-secondary d-flex align-items-center">
                                <span class="material-symbols-rounded fs-6 me-1">palette</span> Coloris
                            </label>
                            <input type="text" class="form-control form-control-lg bg-light border-0 rounded-3" id="coloris" name="coloris" 
                                   placeholder="Ex: Firetiger" value="<?= htmlspecialchars($item_data['coloris'] ?? '') ?>">
                        </div>
                    </div>
                </div>

                <div class="d-grid mt-5">
                    <button type="submit" class="btn btn-lg rounded-pill fw-semibold text-white shadow" style="background: linear-gradient(135deg, #f39c12, #d35400); border: none;">
                        <span class="material-symbols-rounded align-middle me-1">save</span> 
                        <?= $action === 'add' ? 'Ajouter à ma boîte' : 'Enregistrer les modifications' ?>
                    </button>
                </div>
            </form>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Script d'interaction dynamique -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const radioLeurre = document.getElementById('btnradio1');
            const radioAppat = document.getElementById('btnradio2');
            
            const selectLeurre = document.getElementById('select_categorie_leurre');
            const selectAppat = document.getElementById('select_categorie_appat');
            const finalIdCategorie = document.getElementById('final_id_categorie');
            const sectionLeurre = document.getElementById('section_leurre');
            
            // Si on est en mode édition, on force l'état via la variable cachée
            const modeEditionType = document.getElementById('choix_type_hidden');
            let currentType = modeEditionType ? modeEditionType.value : 'leurre';

            function updateUI() {
                if (radioLeurre && radioLeurre.checked) currentType = 'leurre';
                if (radioAppat && radioAppat.checked) currentType = 'appat';

                if (currentType === 'leurre') {
                    selectLeurre.style.display = 'block';
                    selectLeurre.required = true;
                    selectAppat.style.display = 'none';
                    selectAppat.required = false;
                    sectionLeurre.style.display = 'block';
                } else {
                    selectLeurre.style.display = 'none';
                    selectLeurre.required = false;
                    selectAppat.style.display = 'block';
                    selectAppat.required = true;
                    sectionLeurre.style.display = 'none';
                }
                updateFinalCategory();
            }

            function updateFinalCategory() {
                if (currentType === 'leurre') {
                    finalIdCategorie.value = selectLeurre.value;
                } else {
                    finalIdCategorie.value = selectAppat.value;
                }
            }

            // Écouteurs pour le switch Leurre/Appât (Mode ajout)
            if (radioLeurre && radioAppat) {
                radioLeurre.addEventListener('change', updateUI);
                radioAppat.addEventListener('change', updateUI);
            }

            // Mettre à jour l'ID final à chaque changement des listes déroulantes
            selectLeurre.addEventListener('change', updateFinalCategory);
            selectAppat.addEventListener('change', updateFinalCategory);

            // Initialisation au chargement
            updateUI();
        });
    </script>
</body>
</html>