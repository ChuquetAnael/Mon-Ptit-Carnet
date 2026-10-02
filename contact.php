<?php
session_start();
require_once './bdd/env.php';

$message_erreur = "";
$message_succes = "";

// Si l'utilisateur est connecté, on peut récupérer ses infos en base pour pré-remplir l'email
$email_pre_rempli = "";
if (isset($_SESSION['user_id'])) {
    try {
        $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $pass);
        $stmt = $pdo->prepare("SELECT MAIL FROM UTILISATEUR WHERE ID_UTILISATEUR = :id");
        $stmt->execute(['id' => $_SESSION['user_id']]);
        $user_data = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user_data) {
            $email_pre_rempli = $user_data['MAIL'];
        }
    } catch (PDOException $e) {} // On ignore l'erreur silencieusement ici
}

// Traitement du formulaire
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    try {
        $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $id_user = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
        $email = htmlspecialchars($_POST['email']);
        $raison = htmlspecialchars($_POST['raison']);
        $message_texte = htmlspecialchars($_POST['message']);
        $date_creation = date('Y-m-d H:i:s');

        // Insertion du ticket dans la base de données
        $stmt = $pdo->prepare("INSERT INTO TICKET (ID_UTILISATEUR, EMAIL, RAISON, MESSAGE, DATE_CREATION) VALUES (:id_user, :email, :raison, :message, :date_crea)");
        $stmt->execute([
            'id_user' => $id_user,
            'email' => $email,
            'raison' => $raison,
            'message' => $message_texte,
            'date_crea' => $date_creation
        ]);

        $message_succes = "Votre demande a bien été envoyée. Nous vous répondrons rapidement !";
    } catch (PDOException $e) {
        $message_erreur = "Erreur de base de données : " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contact - Mon Carnet de Pêche</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Google Fonts & Material Symbols -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,1,0" rel="stylesheet">
    
    <!-- Ton fichier CSS -->
    <link href="css/style.css" rel="stylesheet">
</head>
<body class="bg-light pb-5">

    <!-- En-tête avec bouton retour -->
    <header class="custom-header text-white text-center py-4 shadow-sm mb-4 position-relative">
        <a href="javascript:history.back()" class="text-white position-absolute start-0 translate-middle-y ms-3 text-decoration-none" style="top: 50%;">
            <span class="material-symbols-rounded">arrow_back_ios_new</span>
        </a>
        <h1 class="h4 mb-0 fw-semibold">Contact & Support</h1>
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

        <div class="card border-0 shadow-sm rounded-4 p-4 mb-5 bg-white">
            <div class="text-center mb-4">
                <span class="material-symbols-rounded text-primary mb-2" style="font-size: 48px;">support_agent</span>
                <h2 class="h5 fw-bold text-dark">Créer un ticket</h2>
                <p class="text-muted small">Une question, un bug ou une suggestion ? Écrivez-nous !</p>
            </div>

            <form action="contact.php" method="POST">
                
                <!-- Email -->
                <div class="mb-4">
                    <label class="form-label fw-medium text-secondary small text-uppercase">Adresse Email</label>
                    <input type="email" class="form-control form-control-lg bg-light border-0" name="email" value="<?= htmlspecialchars($email_pre_rempli) ?>" required placeholder="votre@email.fr">
                </div>

                <!-- Raison du ticket (Select) -->
                <div class="mb-4">
                    <label class="form-label fw-medium text-secondary small text-uppercase">Raison du contact</label>
                    <select class="form-select form-select-lg bg-light border-0" name="raison" required>
                        <option value="" selected disabled>Choisissez un motif...</option>
                        <option value="Problème technique (Bug)">Problème technique (Bug)</option>
                        <option value="Problème avec mon compte">Problème avec mon compte</option>
                        <option value="Suggestion d'amélioration">Suggestion d'amélioration</option>
                        <option value="Autre demande">Autre demande</option>
                    </select>
                </div>

                <!-- Message -->
                <div class="mb-4">
                    <label class="form-label fw-medium text-secondary small text-uppercase">Votre message</label>
                    <textarea class="form-control form-control-lg bg-light border-0" name="message" rows="5" required placeholder="Détaillez votre demande ici..."></textarea>
                </div>
                
                <div class="d-grid mt-4">
                    <button type="submit" class="btn btn-primary btn-lg rounded-pill fw-semibold shadow-sm custom-btn-submit d-flex justify-content-center align-items-center">
                        <span class="material-symbols-rounded me-2" style="font-size: 20px;">send</span>
                        Envoyer le ticket
                    </button>
                </div>

            </form>
        </div>
    </main>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>