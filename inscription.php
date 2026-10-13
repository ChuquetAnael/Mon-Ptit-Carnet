<?php
// Page publique : un visiteur non connecté doit pouvoir créer son compte.
require_once './includes/bootstrap.php';

if (utilisateur_id()) {
    header('Location: accueil.php');
    exit();
}

$message_erreur = "";
$pseudo_saisi = "";
$email_saisi = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $pdo = db();
    $pseudo_saisi = trim((string) ($_POST['pseudo'] ?? ''));
    $email_saisi = trim((string) ($_POST['email'] ?? ''));
    $mot_de_passe = (string) ($_POST['mot_de_passe'] ?? '');

    if (mb_strlen($pseudo_saisi) < 2 || mb_strlen($pseudo_saisi) > 50) {
        $message_erreur = "Le pseudo doit contenir entre 2 et 50 caractères.";
    } elseif (!filter_var($email_saisi, FILTER_VALIDATE_EMAIL) || mb_strlen($email_saisi) > 255) {
        $message_erreur = "Adresse email invalide.";
    } elseif (mb_strlen($mot_de_passe) < 8) {
        $message_erreur = "Le mot de passe doit contenir au moins 8 caractères.";
    } elseif (empty($_POST['cgu_check'])) {
        $message_erreur = "Tu dois accepter les Conditions Générales d'Utilisation.";
    } else {
        $stmt_check = $pdo->prepare("SELECT ID_UTILISATEUR FROM utilisateur WHERE MAIL = :mail");
        $stmt_check->execute(['mail' => $email_saisi]);

        if ($stmt_check->fetch()) {
            $message_erreur = "Cette adresse email est déjà utilisée.";
        } else {
            try {
                $stmt_insert = $pdo->prepare("INSERT INTO utilisateur (PSEUDO, MAIL, MOT_DE_PASSE, DATE_CREATION) VALUES (:pseudo, :mail, :mdp, CURDATE())");
                $stmt_insert->execute([
                    'pseudo' => $pseudo_saisi,
                    'mail' => $email_saisi,
                    'mdp' => password_hash($mot_de_passe, PASSWORD_DEFAULT),
                ]);
                header('Location: connexion.php?inscrit=1');
                exit();
            } catch (PDOException $e) {
                // Doublon d'email inséré en parallèle (index UNIQUE sur MAIL)
                if ($e->getCode() === '23000') {
                    $message_erreur = "Cette adresse email est déjà utilisée.";
                } else {
                    throw $e;
                }
            }
        }
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
                <?= e($message_erreur) ?>
            </div>
        <?php endif; ?>

        <div class="card border-0 shadow-sm rounded-4 p-4 mb-5">
            <div class="text-center mb-4">
                <span class="material-symbols-rounded text-primary mb-2" style="font-size: 48px;">person_add</span>
                <h2 class="h5 fw-bold text-dark">Créez votre carnet</h2>
            </div>

            <form action="inscription.php" method="POST">
                <?= csrf_field() ?>
                <div class="mb-4">
                    <label class="form-label fw-medium text-secondary small text-uppercase">Pseudo</label>
                    <input type="text" class="form-control form-control-lg bg-light border-0" name="pseudo" required minlength="2" maxlength="50" value="<?= e($pseudo_saisi) ?>" placeholder="VotrePseudo">
                </div>
                <div class="mb-4">
                    <label class="form-label fw-medium text-secondary small text-uppercase">Email</label>
                    <input type="email" class="form-control form-control-lg bg-light border-0" name="email" required maxlength="255" autocomplete="email" value="<?= e($email_saisi) ?>" placeholder="VotreMail@Mail.fr">
                </div>
                <div class="mb-4">
                    <label class="form-label fw-medium text-secondary small text-uppercase">Mot de passe</label>
                    <input type="password" class="form-control form-control-lg bg-light border-0" name="mot_de_passe" required minlength="8" autocomplete="new-password">
                    <div class="form-text">8 caractères minimum.</div>
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