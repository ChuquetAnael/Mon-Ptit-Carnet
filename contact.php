<?php
require_once './includes/bootstrap.php';

const RAISONS_CONTACT = ['Problème technique (Bug)', 'Problème avec mon compte', "Suggestion d'amélioration", 'Autre demande'];
const DELAI_ENTRE_TICKETS = 60; // secondes (anti-spam)

$message_erreur = "";
$message_succes = "";

// Si l'utilisateur est connecté, on pré-remplit l'email
$email_pre_rempli = "";
if (utilisateur_id()) {
    $stmt = db()->prepare("SELECT MAIL FROM utilisateur WHERE ID_UTILISATEUR = :id");
    $stmt->execute(['id' => utilisateur_id()]);
    $email_pre_rempli = (string) $stmt->fetchColumn();
}

// Traitement du formulaire
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = trim((string) ($_POST['email'] ?? ''));
    $raison = (string) ($_POST['raison'] ?? '');
    $message_texte = trim((string) ($_POST['message'] ?? ''));

    if (!empty($_POST['site_web'])) {
        // Champ piège invisible rempli : robot. On fait comme si tout allait bien.
        $message_succes = "Votre demande a bien été envoyée. Nous vous répondrons rapidement !";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 255) {
        $message_erreur = "Adresse email invalide.";
    } elseif (!in_array($raison, RAISONS_CONTACT, true)) {
        $message_erreur = "Choisissez un motif de contact.";
    } elseif (mb_strlen($message_texte) < 10 || mb_strlen($message_texte) > 5000) {
        $message_erreur = "Le message doit contenir entre 10 et 5000 caractères.";
    } elseif (!empty($_SESSION['dernier_ticket']) && $_SESSION['dernier_ticket'] > time() - DELAI_ENTRE_TICKETS) {
        $message_erreur = "Merci de patienter une minute avant d'envoyer une nouvelle demande.";
    } else {
        // Stockage du texte BRUT : l'échappement se fait à l'affichage (évite le double encodage &amp;#039;)
        $stmt = db()->prepare("INSERT INTO ticket (ID_UTILISATEUR, EMAIL, RAISON, MESSAGE, DATE_CREATION) VALUES (:id_user, :email, :raison, :message, NOW())");
        $stmt->execute([
            'id_user' => utilisateur_id(),
            'email' => $email,
            'raison' => $raison,
            'message' => $message_texte,
        ]);
        $_SESSION['dernier_ticket'] = time();
        $message_succes = "Votre demande a bien été envoyée. Nous vous répondrons rapidement !";
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
                <?= e($message_erreur) ?>
            </div>
        <?php endif; ?>
        
        <?php if(!empty($message_succes)): ?>
            <div class="alert alert-success shadow-sm border-0 rounded-3 mb-4 text-center" role="alert">
                <span class="material-symbols-rounded align-middle me-1">check_circle</span>
                <?= e($message_succes) ?>
            </div>
        <?php endif; ?>

        <div class="card border-0 shadow-sm rounded-4 p-4 mb-5 bg-white">
            <div class="text-center mb-4">
                <span class="material-symbols-rounded text-primary mb-2" style="font-size: 48px;">support_agent</span>
                <h2 class="h5 fw-bold text-dark">Créer un ticket</h2>
                <p class="text-muted small">Une question, un bug ou une suggestion ? Écrivez-nous !</p>
            </div>

            <form action="contact.php" method="POST">
                <?= csrf_field() ?>
                <!-- Champ piège anti-robots : invisible pour les humains -->
                <div style="position:absolute; left:-10000px;" aria-hidden="true">
                    <label>Site web <input type="text" name="site_web" tabindex="-1" autocomplete="off"></label>
                </div>
                
                <!-- Email -->
                <div class="mb-4">
                    <label class="form-label fw-medium text-secondary small text-uppercase">Adresse Email</label>
                    <input type="email" class="form-control form-control-lg bg-light border-0" name="email" value="<?= e($email_pre_rempli) ?>" required maxlength="255" placeholder="votre@email.fr">
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
                    <textarea class="form-control form-control-lg bg-light border-0" name="message" rows="5" required minlength="10" maxlength="5000" placeholder="Détaillez votre demande ici..."></textarea>
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