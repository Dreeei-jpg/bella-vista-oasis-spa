<?php
require __DIR__ . '/../app/bootstrap.php';
$pageTitle = 'A little closer to yourself';
$catalog = services();
require __DIR__ . '/../app/components/header.php';
?>
<section class="hero container">
    <div class="hero-copy">
<p class="eyebrow">
<span class="tiny-line">
</span> A sanctuary for the senses</p>
<h1>Come back<br>to <em>yourself.</em>
</h1>
<p class="hero-description">Leave the everyday at the door.<br>Find a quieter rhythm at Bella Vista Oasis Spa.</p>
<a class="button" href="<?= e(url('/services.php')) ?>">Explore our treatments <span>↗</span>
</a>
<div class="hero-note">
<span class="little-sun" aria-hidden="true">✳</span>
<span>Thoughtfully held.<br>Unhurried by nature.</span>
</div>
</div>
    <div class="hero-photo">
<img src="<?= e(url('/assets/images/hero.jpg')) ?>" alt="A light-filled treatment room with timber screens and natural textures" fetchpriority="high" width="1400" height="1700">
<div class="photo-label">
<span>THE ART OF SLOWING DOWN</span>
<span>A QUIETER RHYTHM</span>
</div>
<span class="vertical-note">BELLA VISTA &nbsp; — &nbsp; EST. 2021</span>
</div>
    <div class="hero-bottom">
<span>PRIVATE SPACES. PERSONAL CARE.</span>
<a href="#welcome">A moment to discover ↓</a>
<span>OPEN DAILY · 1 PM — 9 PM</span>
</div>
</section>
<section class="intro-section container" id="welcome">
<p class="eyebrow">01 / The Bella Vista way</p>
<div>
<h2>Space to breathe.<br>Care you can <em>feel.</em>
</h2>
<div class="intro-body">
<p>A peaceful sanctuary dedicated to holistic wellness, physical rejuvenation, and deep relaxation. Surrounded by nature and soothing aesthetics, every visit is a return to balance.</p>
<p>From premium massage therapies to private lounge experiences, we make space for what matters: a rested body, a quieter mind, and a little time for you.</p>
</div>
<a class="text-link" href="<?= e(url('/about.php')) ?>">Discover our story <span>↗</span>
</a>
</div>
</section>
<section class="treatments-section container">
<div class="section-heading">
<div>
<p class="eyebrow">02 / Find your own rhythm</p>
<h2>Ways to <em>unwind.</em>
</h2>
</div>
<a class="text-link" href="<?= e(url('/services.php')) ?>">All treatments <span>↗</span>
</a>
</div>
<div class="treatment-grid">
<?php foreach ($catalog as $index => $service): ?>
<article class="treatment-card">
<a class="image-link" href="<?= e(url('/services.php#'.$service['slug'])) ?>">
<img src="<?= e(url('/assets/images/'.$service['image_filename'])) ?>" alt="<?= e($service['name']) ?> — illustrative spa photography" loading="lazy" width="700" height="800">
<span class="image-number">0<?= $index+1 ?>
</span>
<span class="image-arrow" aria-hidden="true">↗</span>
</a>
<p class="eyebrow">
<?= e($service['duration_minutes']) ?> MINUTES <span> / </span>
<?= $service['max_capacity']==1 ? 'JUST FOR YOU' : ($service['max_capacity']==2 ? 'TIME FOR TWO' : 'BETTER TOGETHER') ?>
</p>
<h3>
<a href="<?= e(url('/services.php#'.$service['slug'])) ?>">
<?= e($service['name']) ?>
</a>
</h3>
<p class="price">
<?= money($service['base_price']) ?>
<small> / <?= $service['base_capacity'] == 1 ? 'person' : 'up to '.$service['base_capacity'].' persons' ?>
</small>
</p>
</article>
<?php endforeach; ?>
</div>
</section>
<section class="experience">
<div class="experience-photo">
<img src="<?= e(url('/assets/images/detail.jpg')) ?>" alt="A quiet massage ritual with hot stones and warm textiles" loading="lazy" width="1000" height="1100">
</div>
<div class="experience-copy">
<p class="eyebrow">03 / Small details, deeper calm</p>
<h2>Nothing to do.<br>Nowhere to be.<br>
<em>Simply here.</em>
</h2>
<p>A room that is yours. A treatment with intention. The simple pleasure of being cared for.</p>
<div class="experience-lines">
<p>
<span>01</span>Private rooms, personal moments</p>
<p>
<span>02</span>Thoughtful therapeutic care</p>
<p>
<span>03</span>Space for solitude or togetherness</p>
</div>
<a class="text-link light" href="<?= e(url('/gallery.php')) ?>">Step inside <span>↗</span>
</a>
</div>
</section>
<section class="history-preview container">
<p class="eyebrow">Rooted in care / Since 2021</p>
<h2>From a quiet corner<br>of the estate,<br>to a sanctuary <em>of its own.</em>
</h2>
<div>
<p>Established as an extension of the Bella Vista estate, our spa grew into a standalone wellness center, trusted by local residents and travelers alike.</p>
<a class="text-link" href="<?= e(url('/about.php#history')) ?>">The story so far <span>↗</span>
</a>
</div>
</section>
<section class="gallery-preview container">
<div class="section-heading">
<p class="eyebrow">A glimpse of the quiet</p>
<a class="text-link" href="<?= e(url('/gallery.php')) ?>">Explore the gallery <span>↗</span>
</a>
</div>
<a href="<?= e(url('/gallery.php')) ?>">
<img src="<?= e(url('/assets/images/lounge.jpg')) ?>" alt="A tranquil lounge with organic textures" loading="lazy" width="1600" height="800">
</a>
<p class="image-disclosure">Atmosphere photography. Images do not show the Bella Vista property.</p>
</section>
<?php require __DIR__ . '/../app/components/footer.php'; ?>
