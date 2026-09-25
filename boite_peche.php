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

    // 1. GESTION DE LA SUPPRESSION
    // Suppression d'un leurre
    if (isset($_POST['delete_leurre']) && isset($_POST['id_leurre'])) {
        // A. On délie le leurre de toutes les prises historiques (on met à NULL)
        $stmtUpdate = $pdo->prepare("UPDATE PRISE SET ID_LEURRE = NULL WHERE ID_LEURRE = ?");
        $stmtUpdate->execute([$_POST['id_leurre']]);
        
        // B. On supprime définitivement le leurre
        $stmt = $pdo->prepare("DELETE FROM LEURRE WHERE ID_LEURRE = ? AND ID_UTILISATEUR = ?");
        $stmt->execute([$_POST['id_leurre'], $_SESSION['user_id']]);
        $message = "Leurre supprimé avec succès. Les anciennes prises liées ont été conservées.";
    }
    
    // Suppression d'un appât
    if (isset($_POST['delete_appat']) && isset($_POST['id_appat'])) {
        // A. On délie l'appât
        $stmtUpdate = $pdo->prepare("UPDATE PRISE SET ID_APPAT = NULL WHERE ID_APPAT = ?");
        $stmtUpdate->execute([$_POST['id_appat']]);
        
        // B. On supprime l'appât
        $stmt = $pdo->prepare("DELETE FROM APPAT WHERE ID_APPAT = ? AND ID_UTILISATEUR = ?");
        $stmt->execute([$_POST['id_appat'], $_SESSION['user_id']]);
        $message = "Appât supprimé avec succès. Les anciennes prises liées ont été conservées.";
    }

    // 2. RÉCUPÉRATION DES DONNÉES (Uniquement le matériel privé de l'utilisateur)
    // Récupération des leurres
    $stmtLeurres = $pdo->prepare("
        SELECT l.ID_LEURRE, l.NOM_LEURRE, l.GRAMMAGE, l.COLORIS, t.NOM_TYPE 
        FROM LEURRE l
        LEFT JOIN TYPE_LEURRE t ON l.ID_TYPE = t.ID_TYPE
        WHERE l.ID_UTILISATEUR = ?
        ORDER BY l.NOM_LEURRE ASC
    ");
    $stmtLeurres->execute([$_SESSION['user_id']]);
    $mes_leurres = $stmtLeurres->fetchAll(PDO::FETCH_ASSOC);

    // Récupération des appâts
    $stmtAppats = $pdo->prepare("
        SELECT a.ID_APPAT, a.NOM_APPAT, ta.NOM_TYPE_APPAT 
        FROM APPAT a
        LEFT JOIN TYPE_APPAT ta ON a.ID_TYPE_APPAT = ta.ID_TYPE_APPAT
        WHERE a.ID_UTILISATEUR = ?
        ORDER BY a.NOM_APPAT ASC
    ");
    $stmtAppats->execute([$_SESSION['user_id']]);
    $mes_appats = $stmtAppats->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Erreur de base de données : " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ma Boîte de Pêche - Mon Carnet</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,1,0" rel="stylesheet">
    <link href="css/style.css" rel="stylesheet">
    <style>
        .nav-pills .nav-link {
            color: #6c757d;
            border-radius: 20px;
            padding: 8px 20px;
            font-weight: 500;
        }
        .nav-pills .nav-link.active {
            background-color: #f39c12; /* Orange/Jaune rappelant la boîte */
            color: white;
            box-shadow: 0 4px 10px rgba(243, 156, 18, 0.3);
        }
        /* Bouton Flottant (FAB) */
        .btn-fab {
            position: fixed;
            bottom: 90px;
            right: 20px;
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: linear-gradient(135deg, #f39c12, #d35400);
            box-shadow: 0 6px 15px rgba(211, 84, 0, 0.4);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            transition: transform 0.2s ease;
        }
        .btn-fab:hover {
            transform: scale(1.05);
        }
    </style>
</head>
<body>

    <!-- En-tête -->
    <header class="custom-header text-white text-center py-4 shadow-sm mb-4 position-relative" style="background: linear-gradient(135deg, #f39c12 0%, #d35400 100%);">
        <a href="accueil.php" class="text-white position-absolute start-0 translate-middle-y ms-3 text-decoration-none" style="top: 50%;">
            <span class="material-symbols-rounded">arrow_back_ios_new</span>
        </a>
        <h1 class="h4 mb-0 fw-semibold d-flex align-items-center justify-content-center gap-2">
            <span class="material-symbols-rounded">inventory_2</span> Ma Boîte
        </h1>
    </header>

    <main class="container mb-5 pb-5">
        
        <?php if(isset($message)): ?>
            <div class="alert alert-success alert-dismissible fade show rounded-4 border-0 shadow-sm text-center" role="alert">
                <?= htmlspecialchars($message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <!-- Onglets Leurres / Appâts -->
        <ul class="nav nav-pills d-flex justify-content-center mb-4 gap-2" id="pills-tab" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="pills-leurres-tab" data-bs-toggle="pill" data-bs-target="#pills-leurres" type="button" role="tab" aria-selected="true">
                    Mes Leurres (<?= count($mes_leurres) ?>)
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="pills-appats-tab" data-bs-toggle="pill" data-bs-target="#pills-appats" type="button" role="tab" aria-selected="false">
                    Mes Appâts (<?= count($mes_appats) ?>)
                </button>
            </li>
        </ul>

        <!-- Contenu des onglets -->
        <div class="tab-content" id="pills-tabContent">
            
            <!-- ONGLET LEURRES -->
            <div class="tab-pane fade show active" id="pills-leurres" role="tabpanel" aria-labelledby="pills-leurres-tab">
                <?php if (empty($mes_leurres)): ?>
                    <div class="text-center text-muted mt-5">
                        <span class="material-symbols-rounded mb-2" style="font-size: 48px; opacity: 0.5;">fishing</span>
                        <p>Ta boîte à leurres est vide.<br>Clique sur le + pour en ajouter un.</p>
                    </div>
                <?php else: ?>
                    <div class="d-flex flex-column gap-3">
                        <?php foreach($mes_leurres as $leurre): ?>
                            <div class="card border-0 shadow-sm rounded-4">
                                <div class="card-body p-3 d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="mb-1 fw-bold text-dark"><?= htmlspecialchars($leurre['NOM_LEURRE']) ?></h6>
                                        <small class="text-muted d-block" style="font-size: 0.8rem;">
                                            <?= htmlspecialchars($leurre['NOM_TYPE_LEURRE'] ?? 'Non classé') ?> 
                                            <?php if(!empty($leurre['GRAMMAGE'])) echo ' • ' . htmlspecialchars($leurre['GRAMMAGE']) . 'g'; ?>
                                            <?php if(!empty($leurre['COLORIS'])) echo ' • ' . htmlspecialchars($leurre['COLORIS']); ?>
                                        </small>
                                    </div>
                                    <div class="d-flex gap-2">
                                        <!-- Bouton Modifier (Redirigera vers la future page d'édition) -->
                                        <a href="form_boite.php?action=edit&type=leurre&id=<?= $leurre['ID_LEURRE'] ?>" class="btn btn-sm btn-light text-primary rounded-circle d-flex align-items-center justify-content-center p-2">
                                            <span class="material-symbols-rounded" style="font-size: 20px;">edit</span>
                                        </a>
                                        <!-- Formulaire de Suppression -->
                                        <form method="POST" class="m-0" onsubmit="return confirm('Es-tu sûr de vouloir supprimer ce leurre ? Il disparaitra de ta boîte.');">
                                            <input type="hidden" name="id_leurre" value="<?= $leurre['ID_LEURRE'] ?>">
                                            <button type="submit" name="delete_leurre" class="btn btn-sm btn-light text-danger rounded-circle d-flex align-items-center justify-content-center p-2">
                                                <span class="material-symbols-rounded" style="font-size: 20px;">delete</span>
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- ONGLET APPÂTS -->
            <div class="tab-pane fade" id="pills-appats" role="tabpanel" aria-labelledby="pills-appats-tab">
                <?php if (empty($mes_appats)): ?>
                    <div class="text-center text-muted mt-5">
                        <span class="material-symbols-rounded mb-2" style="font-size: 48px; opacity: 0.5;">pest_control</span>
                        <p>Ta boîte à appâts est vide.<br>Clique sur le + pour en ajouter un.</p>
                    </div>
                <?php else: ?>
                    <div class="d-flex flex-column gap-3">
                        <?php foreach($mes_appats as $appat): ?>
                            <div class="card border-0 shadow-sm rounded-4">
                                <div class="card-body p-3 d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="mb-1 fw-bold text-dark"><?= htmlspecialchars($appat['NOM_APPAT']) ?></h6>
                                        <small class="text-muted d-block" style="font-size: 0.8rem;">
                                            <?= htmlspecialchars($appat['NOM_TYPE_APPAT'] ?? 'Non classé') ?>
                                        </small>
                                    </div>
                                    <div class="d-flex gap-2">
                                        <a href="form_boite.php?action=edit&type=appat&id=<?= $appat['ID_APPAT'] ?>" class="btn btn-sm btn-light text-primary rounded-circle d-flex align-items-center justify-content-center p-2">
                                            <span class="material-symbols-rounded" style="font-size: 20px;">edit</span>
                                        </a>
                                        <form method="POST" class="m-0" onsubmit="return confirm('Es-tu sûr de vouloir supprimer cet appât ?');">
                                            <input type="hidden" name="id_appat" value="<?= $appat['ID_APPAT'] ?>">
                                            <button type="submit" name="delete_appat" class="btn btn-sm btn-light text-danger rounded-circle d-flex align-items-center justify-content-center p-2">
                                                <span class="material-symbols-rounded" style="font-size: 20px;">delete</span>
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

        </div>

    </main>

    <!-- Bouton Flottant d'Ajout (Pointe vers form_boite.php) -->
    <a href="form_boite.php?action=add" class="btn-fab text-white text-decoration-none">
        <span class="material-symbols-rounded" style="font-size: 32px;">add</span>
    </a>

    <!-- Navbar de base (Conservée identique) -->
    <nav class="navbar fixed-bottom bg-white custom-navbar border-0">
        <div class="container-fluid d-flex justify-content-around align-items-end px-2">
            <a href="accueil.php" class="nav-item d-flex flex-column align-items-center">
                <span class="material-symbols-rounded">home</span>
                <span class="menu-text">Accueil</span>
            </a>
            <a href="nouvelle_session.php" class="btn-add-catch">
                <span class="material-symbols-rounded text-white" style="font-size: 36px;">phishing</span>
            </a>
            <a href="profil.php" class="nav-item d-flex flex-column align-items-center">
                <span class="material-symbols-rounded">person</span>
                <span class="menu-text">Profil</span>
            </a>
        </div>
    </nav>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>