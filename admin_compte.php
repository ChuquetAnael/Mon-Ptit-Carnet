<?php
session_start();
require_once './bdd/env.php';

// 1. VÉRIFICATION DES DROITS D'ADMINISTRATION
if (!isset($_SESSION['user_id'])) {
    header('Location: connexion.php');
    exit();
}

$is_admin = false;
$message_succes = "";
$message_erreur = "";

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // L'utilisateur connecté est-il dans la table ADMIN ?
    $stmt_check = $pdo->prepare("SELECT 1 FROM ADMIN WHERE ID_UTILISATEUR = ?");
    $stmt_check->execute([$_SESSION['user_id']]);
    if (!$stmt_check->fetch()) {
        // Tentative d'accès frauduleuse
        header('Location: accueil.php');
        exit();
    }
    
    // 2. TRAITEMENT DES ACTIONS (POST)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['id_cible'])) {
        $id_cible = (int)$_POST['id_cible'];
        
        // Empêcher l'admin de se supprimer ou de se rétrograder lui-même
        if ($id_cible === $_SESSION['user_id']) {
            $message_erreur = "Action refusée : Vous ne pouvez pas modifier votre propre compte administrateur ici.";
        } else {
            if ($_POST['action'] === 'promouvoir') {
                $stmt = $pdo->prepare("INSERT IGNORE INTO ADMIN (ID_UTILISATEUR, DATE_PROMOTION) VALUES (?, NOW())");
                $stmt->execute([$id_cible]);
                $message_succes = "L'utilisateur #$id_cible a été promu Administrateur.";
                
            } elseif ($_POST['action'] === 'retrograder') {
                $stmt = $pdo->prepare("DELETE FROM ADMIN WHERE ID_UTILISATEUR = ?");
                $stmt->execute([$id_cible]);
                $message_succes = "Les droits d'administration de l'utilisateur #$id_cible ont été révoqués.";
                
            } elseif ($_POST['action'] === 'supprimer') {
                // La suppression d'un compte demande de supprimer ses dépendances d'abord (ou d'avoir configuré le ON DELETE CASCADE dans la BDD)
                // Par sécurité, on le fait manuellement dans le bon ordre
                $pdo->beginTransaction();
                $pdo->prepare("DELETE FROM PRISE WHERE ID_SESSION IN (SELECT ID_SESSION FROM SESSION_P WHERE ID_UTILISATEUR = ?)")->execute([$id_cible]);
                $pdo->prepare("DELETE FROM SESSION_P WHERE ID_UTILISATEUR = ?")->execute([$id_cible]);
                $pdo->prepare("DELETE FROM SPOT WHERE ID_UTILISATEUR = ?")->execute([$id_cible]);
                $pdo->prepare("DELETE FROM LEURRE WHERE ID_UTILISATEUR = ?")->execute([$id_cible]);
                $pdo->prepare("DELETE FROM APPAT WHERE ID_UTILISATEUR = ?")->execute([$id_cible]);
                $pdo->prepare("DELETE FROM ADMIN WHERE ID_UTILISATEUR = ?")->execute([$id_cible]);
                $pdo->prepare("DELETE FROM UTILISATEUR WHERE ID_UTILISATEUR = ?")->execute([$id_cible]);
                $pdo->commit();
                $message_succes = "Le compte utilisateur #$id_cible et toutes ses données ont été supprimés définitivement.";
            }
        }
    }

    // 3. RÉCUPÉRATION DE TOUS LES UTILISATEURS AVEC LEURS STATISTIQUES GLOBALLES
    // On fait un LEFT JOIN sur ADMIN pour savoir si la personne a les droits
    // On fait des sous-requêtes pour compter le nombre de sessions et de prises
    $query = "
        SELECT 
            u.ID_UTILISATEUR, 
            u.PSEUDO, 
            u.MAIL, 
            u.DATE_CREATION, 
            u.PDP_CHEMIN,
            (a.ID_UTILISATEUR IS NOT NULL) as est_admin,
            (SELECT COUNT(*) FROM SESSION_P s WHERE s.ID_UTILISATEUR = u.ID_UTILISATEUR) as nb_sessions,
            (SELECT COUNT(*) FROM PRISE p JOIN SESSION_P s ON p.ID_SESSION = s.ID_SESSION WHERE s.ID_UTILISATEUR = u.ID_UTILISATEUR) as nb_prises
        FROM UTILISATEUR u
        LEFT JOIN ADMIN a ON u.ID_UTILISATEUR = a.ID_UTILISATEUR
        ORDER BY u.DATE_CREATION DESC
    ";
    
    $utilisateurs = $pdo->query($query)->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); }
    $message_erreur = "Erreur de base de données : " . $e->getMessage();
}

function formaterDate($date_str) {
    return date('d/m/Y', strtotime($date_str));
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Gestion des Comptes</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,1,0" rel="stylesheet">
    <link href="css/style.css" rel="stylesheet">
    
    <style>
        .user-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            object-fit: cover;
            background-color: #e9ecef;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid white;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        .admin-badge {
            position: absolute;
            bottom: -5px;
            right: -5px;
            background: #ffc107;
            color: #000;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid white;
        }
    </style>
</head>
<body class="bg-light pb-5">

    <!-- En-tête -->
    <header class="custom-header text-white text-center py-4 shadow-sm mb-4 position-relative">
        <a href="admin.php" class="text-white position-absolute start-0 translate-middle-y ms-3 text-decoration-none" style="top: 50%;">
            <span class="material-symbols-rounded">arrow_back_ios_new</span>
        </a>
        <h1 class="h4 mb-0 fw-semibold d-flex align-items-center justify-content-center">
            <span class="material-symbols-rounded me-2">manage_accounts</span> Utilisateurs
        </h1>
    </header>

    <main class="container">

        <!-- Messages de notification -->
        <?php if(!empty($message_erreur)): ?>
            <div class="alert alert-danger shadow-sm border-0 rounded-3 mb-4 text-center" role="alert">
                <?= $message_erreur ?>
            </div>
        <?php endif; ?>
        <?php if(!empty($message_succes)): ?>
            <div class="alert alert-success shadow-sm border-0 rounded-3 mb-4 text-center" role="alert">
                <span class="material-symbols-rounded align-middle me-1">check_circle</span>
                <?= $message_succes ?>
            </div>
        <?php endif; ?>

        <div class="d-flex justify-content-between align-items-center mb-4">
            <h5 class="fw-bold text-dark mb-0">Comptes enregistrés (<span class="text-primary"><?= count($utilisateurs) ?></span>)</h5>
        </div>

        <div class="row g-3">
            <?php foreach($utilisateurs as $u): ?>
                <div class="col-12 col-md-6 col-lg-4">
                    <div class="card border-0 shadow-sm rounded-4 bg-white h-100 <?= $u['est_admin'] ? 'border border-warning border-2' : '' ?>">
                        <div class="card-body p-3">
                            
                            <div class="d-flex align-items-center mb-3">
                                <!-- Avatar -->
                                <div class="position-relative me-3">
                                    <?php if(!empty($u['PDP_CHEMIN'])): ?>
                                        <img src="<?= htmlspecialchars($u['PDP_CHEMIN']) ?>" class="user-avatar" alt="Avatar">
                                    <?php else: ?>
                                        <div class="user-avatar text-muted">
                                            <span class="material-symbols-rounded">person</span>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if($u['est_admin']): ?>
                                        <div class="admin-badge" title="Administrateur">
                                            <span class="material-symbols-rounded" style="font-size: 14px;">star</span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Infos textuelles -->
                                <div class="flex-grow-1 overflow-hidden">
                                    <h6 class="fw-bold text-dark mb-0 text-truncate d-flex align-items-center">
                                        <?= htmlspecialchars($u['PSEUDO']) ?>
                                        <?php if($u['ID_UTILISATEUR'] === $_SESSION['user_id']): ?>
                                            <span class="badge bg-secondary ms-2 small">Vous</span>
                                        <?php endif; ?>
                                    </h6>
                                    <small class="text-muted text-truncate d-block">
                                        <a href="mailto:<?= htmlspecialchars($u['MAIL']) ?>" class="text-decoration-none text-muted"><?= htmlspecialchars($u['MAIL']) ?></a>
                                    </small>
                                </div>
                            </div>

                            <!-- Statistiques rapides du pêcheur -->
                            <div class="d-flex bg-light rounded-3 p-2 mb-3 text-center small border">
                                <div class="col-4 border-end">
                                    <span class="d-block fw-bold text-primary"><?= $u['nb_sessions'] ?></span>
                                    <span class="text-muted" style="font-size: 0.7rem;">Sorties</span>
                                </div>
                                <div class="col-4 border-end">
                                    <span class="d-block fw-bold text-success"><?= $u['nb_prises'] ?></span>
                                    <span class="text-muted" style="font-size: 0.7rem;">Prises</span>
                                </div>
                                <div class="col-4">
                                    <span class="d-block fw-bold text-dark"><?= formaterDate($u['DATE_CREATION']) ?></span>
                                    <span class="text-muted" style="font-size: 0.7rem;">Inscrit le</span>
                                </div>
                            </div>

                            <!-- Actions -->
                            <?php if($u['ID_UTILISATEUR'] !== $_SESSION['user_id']): ?>
                                <div class="d-flex gap-2">
                                    <!-- Gestion des droits -->
                                    <form method="POST" action="admin_compte.php" class="flex-grow-1">
                                        <input type="hidden" name="id_cible" value="<?= $u['ID_UTILISATEUR'] ?>">
                                        <?php if($u['est_admin']): ?>
                                            <input type="hidden" name="action" value="retrograder">
                                            <button type="submit" class="btn btn-sm btn-outline-warning w-100 rounded-pill fw-medium d-flex justify-content-center align-items-center" onclick="return confirm('Retirer les droits administrateur de cet utilisateur ?');">
                                                <span class="material-symbols-rounded me-1" style="font-size: 16px;">remove_moderator</span> Rétrograder
                                            </button>
                                        <?php else: ?>
                                            <input type="hidden" name="action" value="promouvoir">
                                            <button type="submit" class="btn btn-sm btn-outline-primary w-100 rounded-pill fw-medium d-flex justify-content-center align-items-center" onclick="return confirm('Donner tous les droits administrateur à cet utilisateur ?');">
                                                <span class="material-symbols-rounded me-1" style="font-size: 16px;">admin_panel_settings</span> Promouvoir
                                            </button>
                                        <?php endif; ?>
                                    </form>
                                    
                                    <!-- Suppression -->
                                    <form method="POST" action="admin_compte.php">
                                        <input type="hidden" name="id_cible" value="<?= $u['ID_UTILISATEUR'] ?>">
                                        <input type="hidden" name="action" value="supprimer">
                                        <button type="submit" class="btn btn-sm btn-danger rounded-pill px-3" title="Supprimer le compte" onclick="return confirm('ATTENTION : Voulez-vous vraiment supprimer ce compte et TOUTES ses sessions de pêche définitivement ?');">
                                            <span class="material-symbols-rounded align-middle" style="font-size: 18px;">delete_forever</span>
                                        </button>
                                    </form>
                                </div>
                            <?php else: ?>
                                <div class="text-center text-muted small fst-italic mt-2">
                                    C'est votre compte. Vous ne pouvez pas vous modifier ici.
                                </div>
                            <?php endif; ?>

                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>