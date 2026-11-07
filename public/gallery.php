<?php require __DIR__ . '/../app/bootstrap.php'; $pageTitle='The spaces'; require __DIR__ . '/../app/components/header.php'; ?>
<section class="page-intro container">
<p class="eyebrow">A visual journal</p>
<h1>In the details,<br>
<em>the quiet.</em>
</h1>
<p>Warm textures, quiet interiors, and the small rituals that inspire the Bella Vista experience.</p>
</section>
<section class="container gallery-section" aria-label="Spa inspiration gallery">
<div class="gallery-filters" role="group" aria-label="Filter photographs">
<button aria-pressed="true" data-filter="all">All moments <span>05</span>
</button>
<button aria-pressed="false" data-filter="spaces">The spaces</button>
<button aria-pressed="false" data-filter="rituals">The rituals</button>
</div>
<div class="gallery-grid">
<?php $photos=[['hero.jpg','spaces','01 / A slower arrival','A light-filled spa room with timber screens'],['detail.jpg','rituals','02 / The art of care','A restorative massage ritual'],['couples.jpg','spaces','03 / Time together','A calm spa setting for a shared pause'],['single.jpg','spaces','04 / Just for you','A private moment of spa relaxation'],['lounge.jpg','spaces','05 / Room to exhale','Natural materials in a tranquil wellness lounge']]; foreach($photos as [$file,$category,$caption,$alt]): ?>
<figure data-category="<?= e($category) ?>">
<button class="gallery-image" data-caption="<?= e($caption) ?>" aria-label="Enlarge: <?= e($alt) ?>">
<img src="<?= e(url('/assets/images/'.$file)) ?>" alt="<?= e($alt) ?>" loading="lazy" width="1000" height="1200">
<span aria-hidden="true">↗</span>
</button>
<figcaption>
<?= e($caption) ?>
</figcaption>
</figure>
<?php endforeach; ?>
</div>
<p class="image-disclosure">Atmosphere photography. Images do not show the Bella Vista property.</p>
</section>
<?php require __DIR__ . '/../app/components/footer.php'; ?>
