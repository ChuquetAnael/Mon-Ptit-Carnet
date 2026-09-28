<?php
session_start();

if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

require_once './bdd/env.php';
$message_erreur = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    try {
        $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $stmt = $pdo->prepare("SELECT * FROM UTILISATEUR WHERE MAIL = :mail");
        $stmt->execute(['mail' => $_POST['email']]);
        $utilisateur = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($utilisateur && password_verify($_POST['mot_de_passe'], $utilisateur['MOT_DE_PASSE'])) {
            $_SESSION['user_id'] = $utilisateur['ID_UTILISATEUR'];
            $_SESSION['user_pseudo'] = $utilisateur['PSEUDO'];
            
            header('Location: index.php');
            exit();
        } else {
            $message_erreur = "Email ou mot de passe incorrect.";
        }
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
    <title>Connexion - Mon Carnet de Pêche</title>
    
    <?php include './includes/head.php'; ?>
    
</head>
<body style="padding-bottom: 0;">

    <header class="custom-header text-white text-center py-4 shadow-sm mb-4">
        <h1 class="h4 mb-0 fw-semibold">Authentification</h1>
    </header>

    <main class="container">
        <?php if(!empty($message_erreur)): ?>
            <div class="alert alert-danger shadow-sm border-0 rounded-3 mb-4 text-center" role="alert">
                <?= htmlspecialchars($message_erreur) ?>
            </div>
        <?php endif; ?>

        <div class="card border-0 shadow-sm rounded-4 p-4 mb-5">
            <div class="text-center mb-4">
                <span class="material-symbols-rounded text-primary mb-2" style="font-size: 48px;">lock</span>
                <h2 class="h5 fw-bold text-dark">Connectez-vous</h2>
            </div>

            <form action="connexion.php" method="POST">
                <div class="mb-4">
                    <label class="form-label fw-medium text-secondary small text-uppercase">Email</label>
                    <input type="email" class="form-control form-control-lg bg-light border-0" name="email" required placeholder="VotreMail@Mail.fr">
                </div>
                <div class="mb-4">
                    <label class="form-label fw-medium text-secondary small text-uppercase">Mot de passe</label>
                    <input type="password" class="form-control form-control-lg bg-light border-0" name="mot_de_passe" required>
                </div>
                
                <div class="d-grid mt-5">
                    <button type="submit" class="btn btn-primary btn-lg rounded-pill fw-semibold shadow-sm custom-btn-submit">
                        Se connecter
                    </button>
                </div>

                <div class="text-center mt-4">
                    <p class="small text-secondary">Vous n'avez pas de compte ? <a href="inscription.php" class="text-primary fw-semibold text-decoration-none">Créer un profil</a></p>
                </div>
            </form>
        </div>
    </main>

</body>
</html>