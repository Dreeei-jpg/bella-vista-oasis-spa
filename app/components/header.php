<?php
$activePage = basename($_SERVER['SCRIPT_NAME']);
$signedIn = !empty($_SESSION['user_id']);
$adminNavigation=$signedIn&&($_SESSION['user_role']??'')==='admin';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#313B31">
    <meta name="description" content="Bella Vista Oasis Spa. Private massage rooms, couples suites, and shared moments of calm. A sanctuary for body and mind, established in 2021.">
    <title>
<?= e(($pageTitle ?? 'A little closer to yourself') . ' — Bella Vista Oasis Spa') ?>
</title>
    <link rel="icon" href="<?= e(url('/assets/images/mark.svg')) ?>" type="image/svg+xml">
    <script src="<?= e(url('/assets/js/theme.js')) ?>"></script>
    <link rel="stylesheet" href="<?= e(url('/assets/css/base.css')) ?>">
    <link rel="stylesheet" href="<?= e(url('/assets/css/pages.css')) ?>">
    <link rel="stylesheet" href="<?= e(url('/assets/css/booking.css')) ?>">
    <link rel="stylesheet" href="<?= e(url('/assets/css/management.css')) ?>">
    <link rel="stylesheet" href="<?= e(url('/assets/css/refinements.css')) ?>">
    <script src="<?= e(url('/assets/js/app.js')) ?>" defer>
</script>
    <?php foreach ($pageScripts ?? [] as $script): ?><script src="<?= e(url($script)) ?>" defer></script><?php endforeach; ?>
</head>
<body>
<a class="skip-link" href="#main">Skip to content</a>
<header class="site-header">
    <a class="brand" href="<?= e(url('/')) ?>" aria-label="Bella Vista Oasis Spa, home">
        <img src="<?= e(url('/assets/images/mark.svg')) ?>" width="60" height="68" alt="">
        <span>BELLA VISTA<small>O A S I S &nbsp; S P A</small>
</span>
    </a>
    <div class="header-controls">
    <button class="menu-toggle" aria-expanded="false" aria-controls="main-nav">Menu <span>＋</span>
</button>
    <nav id="main-nav" aria-label="Main navigation">
        <?php foreach (['services.php'=>'Treatments','about.php'=>'Our story','gallery.php'=>'The spaces'] as $file=>$label): ?>
        <a href="<?= e(url('/'.$file)) ?>" <?= $activePage === $file ? 'aria-current="page"' : '' ?>>
<?= e($label) ?>
</a>
        <?php endforeach; ?>
        <a href="<?= e(url('/auth/login.php')) ?>">
<?= $adminNavigation?'Administration':($signedIn ? 'My account' : 'Sign in') ?>
</a>
        <a class="nav-book" href="<?= e(url($adminNavigation?'/admin/appointments.php':'/booking.php')) ?>"><?= $adminNavigation?'Appointments':'Book a visit' ?> <span aria-hidden="true">↗</span>
</a>
    </nav>
    <button class="theme-toggle" type="button" aria-label="Dark mode" aria-pressed="false" data-theme-toggle hidden>
        <span class="theme-sun" aria-hidden="true">☀</span><span class="theme-moon" aria-hidden="true">☾</span>
    </button>
    </div>
</header>
<main id="main">
<?php if(isset($_SESSION['flash_success'])): ?><div class="container"><div class="notice" role="status"><?= e($_SESSION['flash_success']) ?></div></div><?php unset($_SESSION['flash_success']);endif; ?>
