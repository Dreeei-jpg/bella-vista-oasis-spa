<?php
require __DIR__ . '/../app/bootstrap.php';
$pageTitle = 'Treatments & private spaces';
$catalog = services();
require __DIR__ . '/../app/components/header.php';
?>
<section class="page-intro container">
<p class="eyebrow">The treatment collection</p>
<h1>Your space.<br>Your <em>pace.</em>
</h1>
<p>A quiet hour alone. Time set aside for two. A shared afternoon with your favorite people. Find the space that feels like you.</p>
</section>
<div class="container service-list">
<?php foreach ($catalog as $index => $service): $rooms=service_rooms((int)$service['id']); ?>
<article class="service-feature" id="<?= e($service['slug']) ?>">
<div class="service-photo">
<img src="<?= e(url('/assets/images/'.$service['image_filename'])) ?>" alt="<?= e($service['name']) ?> atmosphere — illustrative photography" width="900" height="1000" <?= $index ? 'loading="lazy"' : '' ?>>
<span class="image-number">0<?= $index+1 ?>
</span>
</div>
<div class="service-copy">
<p class="eyebrow">0<?= $index+1 ?> / <?= e(implode(' · ',array_column($rooms,'code'))) ?>
</p>
<h2>
<?= e($service['name']) ?>
</h2>
<p>
<?= e($service['description']) ?>
</p>
<dl class="service-facts">
<div>
<dt>Duration</dt>
<dd>
<?= e($service['duration_minutes']) ?> minutes</dd>
</div>
<div>
<dt>Capacity</dt>
<dd>
<?= $service['max_capacity'] > $service['base_capacity'] ? 'Up to '.$service['base_capacity'].' included · '.$service['max_capacity'].' maximum' : $service['max_capacity'].' '.($service['max_capacity']==1?'person':'persons') ?>
</dd>
</div>
<div>
<dt>Experience</dt>
<dd>
<?= money($service['base_price']) ?>
</dd>
</div>
</dl>
<?php if ((int)$service['extra_person_fee'] > 0): ?>
<div class="price-estimator" data-base="<?= e($service['base_price']) ?>" data-included="<?= e($service['base_capacity']) ?>" data-extra="<?= e($service['extra_person_fee']) ?>">
<label for="guests">Your group size</label>
<select id="guests" name="guests">
<?php for($n=1;$n<=(int)$service['max_capacity'];$n++): ?>
<option value="<?= $n ?>" <?= $n==4?'selected':'' ?>>
<?= $n ?> <?= $n==1?'person':'persons' ?>
</option>
<?php endfor; ?>
</select>
<output for="guests" aria-live="polite">
<?= money($service['base_price']) ?>
</output>
<p>
<?= money($service['extra_person_fee']) ?> per person beyond <?= e($service['base_capacity']) ?>. <?php foreach ([4,5,6] as $n): ?>
<?= $n ?> persons: <?= money(service_total($service,$n)) ?>
<?= $n < 6 ? ' · ' : '.' ?>
<?php endforeach; ?>
</p>
</div>
<?php endif; ?>
<a class="button" href="<?= e(url('/booking.php?service_id='.$service['id'])) ?>">Book now <span>↗</span>
</a>
</div>
</article>
<?php endforeach; ?>
</div>
<section class="visit-note container">
<p class="eyebrow">Before your visit</p>
<h2>A little preparation.<br>A more peaceful arrival.</h2>
<p>Open every day, 1:00 PM–9:00 PM. Please arrive 15 minutes before your appointment. Complete a health declaration when you reserve so our team can prepare for your visit.</p>
<a class="text-link" href="<?= e(url('/terms.php')) ?>">Read our visiting terms <span>↗</span>
</a>
</section>
<p class="container image-disclosure">Atmosphere photography. Images do not show the Bella Vista property.</p>
<?php require __DIR__ . '/../app/components/footer.php'; ?>
