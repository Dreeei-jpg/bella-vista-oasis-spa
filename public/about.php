<?php require __DIR__ . '/../app/bootstrap.php'; $pageTitle='Our story'; require __DIR__ . '/../app/components/header.php'; ?>
<section class="page-intro container about-intro">
<p class="eyebrow">The story of Bella Vista</p>
<h1>A place to pause.<br>A feeling to <em>keep.</em>
</h1>
<p>Wellness begins with the space to simply be.</p>
</section>
<div class="about-banner container">
<img src="<?= e(url('/assets/images/hero.jpg')) ?>" alt="Soft light falling across a peaceful spa interior" width="1600" height="850">
</div>
<section class="intro-section container">
<p class="eyebrow">A sanctuary, by nature</p>
<div>
<h2>For body.<br>For mind.<br>
<em>For you.</em>
</h2>
<div class="intro-body">
<p>Bella Vista Oasis Spa is a peaceful sanctuary dedicated to holistic wellness, physical rejuvenation, and deep relaxation.</p>
<p>Surrounded by nature and soothing aesthetics, it offers premium massage therapies, therapeutic treatments, and private lounge experiences designed to melt away stress and restore body and mind.</p>
</div>
</div>
</section>
<section class="story-section container" id="history">
<div>
<p class="eyebrow">Our beginnings</p>
<span class="story-year">2021</span>
<h2>Rooted in an estate.<br>Grown through <em>care.</em>
</h2>
</div>
<div>
<img src="<?= e(url('/assets/images/detail.jpg')) ?>" alt="A considered moment of therapeutic care" loading="lazy" width="800" height="650">
<p>Established in 2021 as a vital extension of the Bella Vista estate, the spa was created to provide guests with an unmatched healing touch.</p>
<p>Due to its high-demand therapeutic sessions and calming environment, it evolved into a premier standalone wellness center trusted by both local residents and tourists looking for professional relaxation therapies.</p>
<a class="text-link" href="<?= e(url('/services.php')) ?>">Find your moment of calm <span>↗</span>
</a>
</div>
</section>
<p class="container image-disclosure">Atmosphere photography. Images do not show the Bella Vista property.</p>
<?php require __DIR__ . '/../app/components/footer.php'; ?>
