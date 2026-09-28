<?php
require_once './includes/auth_bdd.php';

$message_erreur = "";
$message_succes = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    try {
        $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $stmt_check = $pdo->prepare("SELECT ID_UTILISATEUR FROM UTILISATEUR WHERE MAIL = :mail");
        $stmt_check->execute(['mail' => $_POST['email']]);
        
        if ($stmt_check->fetch()) {
            $message_erreur = "Cette adresse email est déjà utilisée.";
        } else {
            $mot_de_passe_hache = password_hash($_POST['mot_de_passe'], PASSWORD_DEFAULT);
            $date_creation = date('Y-m-d'); // Date du jour pour la colonne DATE_CREATION

            $stmt_insert = $pdo->prepare("INSERT INTO UTILISATEUR (PSEUDO, MAIL, MOT_DE_PASSE, DATE_CREATION) VALUES (:pseudo, :mail, :mdp, :date_crea)");
            $stmt_insert->execute([
                'pseudo' => $_POST['pseudo'],
                'mail' => $_POST['email'],
                'mdp' => $mot_de_passe_hache,
                'date_crea' => $date_creation
            ]);

            $message_succes = "Votre compte a été créé avec succès ! Vous pouvez maintenant vous connecter.";
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
    <title>Inscription - Mon Carnet de Pêche</title>
    <?php include './includes/head.php'; ?>
</head>
<body style="padding-bottom: 0;">

    <header class="custom-header text-white text-center py-4 shadow-sm mb-4">
        <h1 class="h4 mb-0 fw-semibold">Nouveau Profil</h1>
    </header>

    <main class="container">
        <?php if(!empty($message_erreur)): ?>
            <div class="alert alert-danger shadow-sm border-0 rounded-3 mb-4 text-center" role="alert">
                <?= htmlspecialchars($message_erreur) ?>
            </div>
        <?php endif; ?>
        
        <?php if(!empty($message_succes)): ?>
            <div class="alert alert-success shadow-sm border-0 rounded-3 mb-4 text-center" role="alert">
                <?= htmlspecialchars($message_succes) ?>
            </div>
        <?php endif; ?>

        <div class="card border-0 shadow-sm rounded-4 p-4 mb-5">
            <div class="text-center mb-4">
                <span class="material-symbols-rounded text-primary mb-2" style="font-size: 48px;">person_add</span>
                <h2 class="h5 fw-bold text-dark">Créez votre carnet</h2>
            </div>

            <form action="inscription.php" method="POST">
                <div class="mb-4">
                    <label class="form-label fw-medium text-secondary small text-uppercase">Pseudo</label>
                    <input type="text" class="form-control form-control-lg bg-light border-0" name="pseudo" required placeholder="VotrePseudo">
                </div>
                <div class="mb-4">
                    <label class="form-label fw-medium text-secondary small text-uppercase">Email</label>
                    <input type="email" class="form-control form-control-lg bg-light border-0" name="email" required placeholder="VotreMail@Mail.fr">
                </div>
                <div class="mb-4">
                    <label class="form-label fw-medium text-secondary small text-uppercase">Mot de passe</label>
                    <input type="password" class="form-control form-control-lg bg-light border-0" name="mot_de_passe" required>
                </div>
                
                <!-- Ajout de la case à cocher pour les CGU -->
                <div class="form-check mt-4 mb-2">
                    <input class="form-check-input" type="checkbox" id="cgu_check" name="cgu_check" required>
                    <label class="form-check-label small text-secondary" for="cgu_check">
                        En créant un compte, j'accepte les <a href="cgu.php" target="_blank" class="text-primary fw-medium text-decoration-none">Conditions Générales d'Utilisation</a>
                    </label>
                </div>
                
                <div class="d-grid mt-4">
                    <button type="submit" class="btn btn-primary btn-lg rounded-pill fw-semibold shadow-sm custom-btn-submit">
                        S'inscrire
                    </button>
                </div>
            </form>
            
            <div class="text-center mt-4">
                <p class="small text-secondary">Déjà un compte ? <a href="connexion.php" class="text-primary fw-semibold text-decoration-none">Se connecter</a></p>
            </div>
        </div>
    </main>

</body>
</html>