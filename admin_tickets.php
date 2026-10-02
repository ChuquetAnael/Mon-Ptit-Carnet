<?php
session_start();
require_once './bdd/env.php';

// 1. VÉRIFICATION DES DROITS D'ADMINISTRATION
if (!isset($_SESSION['user_id'])) {
    header('Location: connexion.php');
    exit();
}

$message_succes = "";
$message_erreur = "";

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Traitement des actions (Fermer ou Supprimer un ticket)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['id_ticket'])) {
        $id_ticket = (int)$_POST['id_ticket'];
        
        if ($_POST['action'] === 'fermer') {
            $stmt = $pdo->prepare("UPDATE TICKET SET STATUT = 'Fermé' WHERE ID_TICKET = ?");
            $stmt->execute([$id_ticket]);
            $message_succes = "Le ticket #$id_ticket a été résolu et archivé avec succès.";
        } elseif ($_POST['action'] === 'supprimer') {
            $stmt = $pdo->prepare("DELETE FROM TICKET WHERE ID_TICKET = ?");
            $stmt->execute([$id_ticket]);
            $message_succes = "Le ticket #$id_ticket a été supprimé définitivement.";
        }
    }

    // 1. Récupération des tickets OUVERTS, avec le pseudo du compte lié (s'il existe)
    $stmt_ouverts = $pdo->query("
        SELECT t.*, u.PSEUDO 
        FROM TICKET t 
        LEFT JOIN UTILISATEUR u ON t.ID_UTILISATEUR = u.ID_UTILISATEUR 
        WHERE t.STATUT = 'Ouvert' 
        ORDER BY t.RAISON ASC, t.DATE_CREATION ASC
    ");
    $tickets_ouverts = $stmt_ouverts->fetchAll(PDO::FETCH_ASSOC);

    // Organisation (Groupement) des tickets ouverts par catégorie (raison) en PHP
    $tickets_par_categorie = [];
    $total_ouverts = 0;
    foreach ($tickets_ouverts as $ticket) {
        $tickets_par_categorie[$ticket['RAISON']][] = $ticket;
        $total_ouverts++;
    }

    // 2. Récupération des tickets FERMÉS (Archives)
    $stmt_fermes = $pdo->query("
        SELECT t.*, u.PSEUDO 
        FROM TICKET t 
        LEFT JOIN UTILISATEUR u ON t.ID_UTILISATEUR = u.ID_UTILISATEUR 
        WHERE t.STATUT = 'Fermé' 
        ORDER BY t.DATE_CREATION DESC
    ");
    $tickets_fermes = $stmt_fermes->fetchAll(PDO::FETCH_ASSOC);
    $total_fermes = count($tickets_fermes);

} catch (PDOException $e) {
    $message_erreur = "Erreur de base de données : " . $e->getMessage();
}

// Fonction utilitaire pour formater la date proprement
function formaterDate($date_str) {
    return date('d/m/Y à H:i', strtotime($date_str));
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Gestion des Tickets</title>
    
    <?php include './includes/head.php'; ?>
    
    <style>
        .ticket-card {
            border-left: 5px solid #0d6efd;
        }
        .ticket-card.closed {
            border-left: 5px solid #6c757d;
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
            <span class="material-symbols-rounded me-2">admin_panel_settings</span> Admin Support
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

        <!-- Onglets de navigation -->
        <ul class="nav nav-pills nav-fill mb-4 bg-white rounded-pill shadow-sm p-1" id="ticketTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active rounded-pill fw-bold" id="ouverts-tab" data-bs-toggle="tab" data-bs-target="#ouverts" type="button" role="tab">
                    En attente <span class="badge bg-danger ms-1 rounded-pill"><?= $total_ouverts ?></span>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link rounded-pill fw-bold" id="archives-tab" data-bs-toggle="tab" data-bs-target="#archives" type="button" role="tab">
                    Archives <span class="badge bg-secondary ms-1 rounded-pill"><?= $total_fermes ?></span>
                </button>
            </li>
        </ul>

        <!-- Contenu des onglets -->
        <div class="tab-content" id="ticketTabsContent">
            
            <!-- ONGLET 1 : TICKETS OUVERTS (PAR CATÉGORIE) -->
            <div class="tab-pane fade show active" id="ouverts" role="tabpanel">
                
                <?php if(empty($tickets_par_categorie)): ?>
                    <div class="text-center text-muted mt-5">
                        <span class="material-symbols-rounded mb-2" style="font-size: 60px; color: #a8b8d0;">celebration</span>
                        <h5 class="fw-bold text-dark">Aucun ticket en attente !</h5>
                        <p>Tout le monde est content, beau travail.</p>
                    </div>
                <?php else: ?>
                    
                    <div class="accordion" id="accordionCategories">
                        <?php 
                        $i = 0;
                        foreach($tickets_par_categorie as $categorie => $tickets): 
                            $i++;
                            $cat_id = "collapseCat" . $i;
                        ?>
                            <div class="accordion-item border-0 shadow-sm rounded-4 mb-3 overflow-hidden">
                                <h2 class="accordion-header">
                                    <button class="accordion-button <?= $i === 1 ? '' : 'collapsed' ?> bg-white fw-bold text-dark" type="button" data-bs-toggle="collapse" data-bs-target="#<?= $cat_id ?>">
                                        <?= htmlspecialchars($categorie) ?> 
                                        <span class="badge bg-primary ms-2 rounded-pill"><?= count($tickets) ?></span>
                                    </button>
                                </h2>
                                <div id="<?= $cat_id ?>" class="accordion-collapse collapse <?= $i === 1 ? 'show' : '' ?>" data-bs-parent="#accordionCategories">
                                    <div class="accordion-body bg-light">
                                        
                                        <?php foreach($tickets as $t): ?>
                                            <div class="card ticket-card border-0 shadow-sm rounded-4 mb-3">
                                                <div class="card-body p-3">
                                                    <div class="d-flex justify-content-between align-items-center mb-2 border-bottom pb-2">
                                                        <div>
                                                            <span class="badge bg-warning text-dark mb-1">Ticket #<?= $t['ID_TICKET'] ?></span>
                                                            <span class="d-block small text-muted"><span class="material-symbols-rounded align-middle" style="font-size: 14px;">calendar_month</span> <?= formaterDate($t['DATE_CREATION']) ?></span>
                                                        </div>
                                                        <div class="text-end">
                                                            <span class="d-block fw-bold text-dark small">
                                                                <span class="material-symbols-rounded align-middle" style="font-size: 14px;">person</span> 
                                                                <?= !empty($t['PSEUDO']) ? htmlspecialchars($t['PSEUDO']) : 'Anonyme' ?>
                                                            </span>
                                                            <a href="mailto:<?= htmlspecialchars($t['EMAIL']) ?>" class="small text-decoration-none"><?= htmlspecialchars($t['EMAIL']) ?></a>
                                                        </div>
                                                    </div>
                                                    <p class="text-dark mt-2 small" style="white-space: pre-wrap;"><?= htmlspecialchars($t['MESSAGE']) ?></p>
                                                    
                                                    <!-- Action : Fermer le ticket -->
                                                    <div class="text-end mt-3">
                                                        <form method="POST" action="admin_tickets.php" class="d-inline">
                                                            <input type="hidden" name="id_ticket" value="<?= $t['ID_TICKET'] ?>">
                                                            <input type="hidden" name="action" value="fermer">
                                                            <button type="submit" class="btn btn-sm btn-success rounded-pill fw-semibold px-3 d-flex align-items-center d-inline-flex" onclick="return confirm('Marquer ce ticket comme résolu ?');">
                                                                <span class="material-symbols-rounded me-1" style="font-size: 18px;">check</span> Résolu
                                                            </button>
                                                        </form>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>

                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- ONGLET 2 : ARCHIVES (TICKETS FERMÉS) -->
            <div class="tab-pane fade" id="archives" role="tabpanel">
                
                <?php if(empty($tickets_fermes)): ?>
                    <div class="text-center text-muted mt-5">
                        <span class="material-symbols-rounded mb-2" style="font-size: 60px; color: #a8b8d0;">inventory_2</span>
                        <p>Les archives sont vides.</p>
                    </div>
                <?php else: ?>
                    <div class="row g-3">
                        <?php foreach($tickets_fermes as $t): ?>
                            <div class="col-12">
                                <div class="card ticket-card closed border-0 shadow-sm rounded-4 bg-white">
                                    <div class="card-body p-3">
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <div>
                                                <span class="badge bg-secondary mb-1">Ticket #<?= $t['ID_TICKET'] ?> - Archivé</span>
                                                <span class="d-block text-muted small fw-bold"><?= htmlspecialchars($t['RAISON']) ?></span>
                                            </div>
                                            <span class="text-muted small"><span class="material-symbols-rounded align-middle" style="font-size: 14px;">calendar_month</span> <?= formaterDate($t['DATE_CREATION']) ?></span>
                                        </div>
                                        
                                        <div class="bg-light p-2 rounded-3 small text-muted mb-2 border">
                                            <?= htmlspecialchars($t['MESSAGE']) ?>
                                        </div>
                                        
                                        <!-- Action : Supprimer définitivement -->
                                        <div class="text-end">
                                            <form method="POST" action="admin_tickets.php" class="d-inline">
                                                <input type="hidden" name="id_ticket" value="<?= $t['ID_TICKET'] ?>">
                                                <input type="hidden" name="action" value="supprimer">
                                                <button type="submit" class="btn btn-sm btn-outline-danger rounded-pill fw-semibold px-3 d-flex align-items-center d-inline-flex" title="Supprimer définitivement" onclick="return confirm('Attention, cette action est irréversible. Supprimer ?');">
                                                    <span class="material-symbols-rounded me-1" style="font-size: 16px;">delete</span> Supprimer
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

            </div>
        </div>

    </main>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>