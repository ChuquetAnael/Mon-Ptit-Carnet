<?php
// Barre de navigation du bas : les actions fréquentes sont dans la zone du pouce.
$page_courante = basename($_SERVER['SCRIPT_NAME'] ?? '');
$actif = fn(array $pages) => in_array($page_courante, $pages, true) ? ' aria-current="page"' : '';
?>
<nav class="barre-pouce" aria-label="Navigation principale">
    <a href="accueil.php"<?= $actif(['accueil.php']) ?>>
        <span class="material-symbols-rounded" aria-hidden="true">home</span>Accueil
    </a>
    <a href="conditions.php"<?= $actif(['conditions.php', 'meteo.php']) ?>>
        <span class="material-symbols-rounded" aria-hidden="true">insights</span>Conditions
    </a>
    <a href="nouvelle_session.php" class="bouee" aria-label="Nouvelle prise">
        <span class="material-symbols-rounded" aria-hidden="true">add</span>Prise
    </a>
    <a href="carte_peche.php"<?= $actif(['carte_peche.php']) ?>>
        <span class="material-symbols-rounded" aria-hidden="true">badge</span>Ma carte
    </a>
    <a href="profil.php"<?= $actif(['profil.php', 'parametre.php', 'modifier_profil.php', 'statistiques.php', 'mes_spots.php', 'boite_peche.php']) ?>>
        <span class="material-symbols-rounded" aria-hidden="true">person</span>Profil
    </a>
</nav>
