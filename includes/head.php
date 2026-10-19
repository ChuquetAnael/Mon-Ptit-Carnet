<!-- Application installable (PWA) -->
<link rel="manifest" href="manifest.webmanifest">
<meta name="theme-color" content="#0e2a33">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<link rel="apple-touch-icon" href="asset/icones/icone-180.png">
<link rel="icon" type="image/png" sizes="32x32" href="asset/icones/icone-32.png">
<script>
    // Thème « plein soleil » appliqué avant l'affichage (pas de flash)
    try { if (localStorage.getItem('mpc-theme') === 'soleil') document.documentElement.dataset.theme = 'soleil'; } catch (e) {}
</script>

<!-- Bootstrap 5 CSS -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

<!-- Police très lisible en extérieur (Atkinson Hyperlegible) & Material Symbols -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Atkinson+Hyperlegible:wght@400;700&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,0..1,0&display=block" rel="stylesheet">

<!-- fichiers CSS externes -->
<link href="css/style.css" rel="stylesheet">
<link href="css/app.css?v=3" rel="stylesheet">
<script src="script/app.js?v=3" defer></script>
