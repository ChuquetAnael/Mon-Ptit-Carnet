<?php
require_once './includes/bootstrap.php';

if (utilisateur_id()) {
    header('Location: accueil.php');
    exit();
}

$message_erreur = "";
$message_succes = isset($_GET['inscrit']) ? "Compte créé ! Tu peux maintenant te connecter." : "";
$email_saisi = "";

const TENTATIVES_MAX = 5;          // échecs autorisés...
const FENETRE_TENTATIVES = 15;     // ...sur cette durée (minutes)

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $pdo = db();
    $email_saisi = trim((string) ($_POST['email'] ?? ''));
    $mot_de_passe = (string) ($_POST['mot_de_passe'] ?? '');
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    // Anti force brute : on compte les échecs récents pour ce compte et cette IP
    $stmtEchecs = $pdo->prepare("
        SELECT COUNT(*) FROM tentative_connexion
        WHERE (MAIL = ? OR IP = ?) AND DATE_TENTATIVE > (NOW() - INTERVAL " . FENETRE_TENTATIVES . " MINUTE)
    ");
    $stmtEchecs->execute([$email_saisi, $ip]);

    if ($stmtEchecs->fetchColumn() >= TENTATIVES_MAX) {
        $message_erreur = "Trop de tentatives. Réessaie dans " . FENETRE_TENTATIVES . " minutes.";
    } else {
        $stmt = $pdo->prepare("SELECT ID_UTILISATEUR, PSEUDO, MOT_DE_PASSE FROM utilisateur WHERE MAIL = :mail");
        $stmt->execute(['mail' => $email_saisi]);
        $utilisateur = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($utilisateur && password_verify($mot_de_passe, $utilisateur['MOT_DE_PASSE'])) {
            // Mise à niveau transparente du hash si l'algorithme par défaut évolue
            if (password_needs_rehash($utilisateur['MOT_DE_PASSE'], PASSWORD_DEFAULT)) {
                $pdo->prepare("UPDATE utilisateur SET MOT_DE_PASSE = ? WHERE ID_UTILISATEUR = ?")
                    ->execute([password_hash($mot_de_passe, PASSWORD_DEFAULT), $utilisateur['ID_UTILISATEUR']]);
            }
            $pdo->prepare("DELETE FROM tentative_connexion WHERE MAIL = ?")->execute([$email_saisi]);
            connecter_utilisateur($utilisateur);
            header('Location: accueil.php');
            exit();
        }

        $pdo->prepare("INSERT INTO tentative_connexion (IP, MAIL, DATE_TENTATIVE) VALUES (?, ?, NOW())")->execute([$ip, $email_saisi]);
        if (random_int(1, 20) === 1) { // purge occasionnelle des vieilles tentatives
            $pdo->exec("DELETE FROM tentative_connexion WHERE DATE_TENTATIVE < (NOW() - INTERVAL 1 DAY)");
        }
        $message_erreur = "Email ou mot de passe incorrect.";
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
                <?= e($message_erreur) ?>
            </div>
        <?php endif; ?>
        <?php if(!empty($message_succes)): ?>
            <div class="alert alert-success shadow-sm border-0 rounded-3 mb-4 text-center" role="alert">
                <?= e($message_succes) ?>
            </div>
        <?php endif; ?>

        <div class="card border-0 shadow-sm rounded-4 p-4 mb-5">
            <div class="text-center mb-4">
                <span class="material-symbols-rounded text-primary mb-2" style="font-size: 48px;">lock</span>
                <h2 class="h5 fw-bold text-dark">Connectez-vous</h2>
            </div>

            <form action="connexion.php" method="POST">
                <?= csrf_field() ?>
                <div class="mb-4">
                    <label class="form-label fw-medium text-secondary small text-uppercase">Email</label>
                    <input type="email" class="form-control form-control-lg bg-light border-0" name="email" required autocomplete="email" value="<?= e($email_saisi) ?>" placeholder="VotreMail@Mail.fr">
                </div>
                <div class="mb-4">
                    <label class="form-label fw-medium text-secondary small text-uppercase">Mot de passe</label>
                    <input type="password" class="form-control form-control-lg bg-light border-0" name="mot_de_passe" required autocomplete="current-password">
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