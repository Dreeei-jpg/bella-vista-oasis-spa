<?php
require __DIR__ . '/../../app/bootstrap.php';
$user = require_role('customer', 'booking');
$answers = array_fill_keys(array_keys(HEALTH_QUESTIONS), '');
$answers['notes'] = '';
$errors = $_SESSION['booking_errors'] ?? [];
unset($_SESSION['booking_errors']);
if (!empty($_SESSION['booking_health_id'])) {
    $previous = health_declaration((int)$user['id'], (int)$_SESSION['booking_health_id']);
    if ($previous) $answers = array_replace($answers, json_decode($previous['responses_json'], true, 512, JSON_THROW_ON_ERROR));
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    foreach (array_keys($answers) as $key) $answers[$key] = post_string($key);
    $answers['consent'] = post_string('consent');
    $errors = validate_health($answers);
    if (!$errors) {
        $_SESSION['booking_health_id'] = save_health_declaration((int)$user['id'], $answers);
        $_SESSION['booking_request_key'] = bin2hex(random_bytes(32));
        redirect('/booking.php');
    }
    http_response_code(422);
}
$pageTitle = 'Before we begin';
require __DIR__ . '/../../app/components/header.php';
?>
<section class="container booking-heading">
    <p class="eyebrow">Your visit / 01 — A little understanding</p>
    <h1>Care starts<br>with <em>you.</em></h1>
    <p>Before choosing a treatment, tell us a little about your health. Your answers are saved with your booking so your spa team has the information you share.</p>
</section>
<div class="container booking-layout health-layout">
    <form method="post" class="booking-sheet" data-validate>
        <?= csrf_field() ?>
        <ol class="booking-steps" aria-label="Booking progress"><li aria-current="step"><span>01</span>Health declaration</li><li><span>02</span>Your treatment</li><li><span>03</span>Confirmation</li></ol>
        <h2>A thoughtful beginning.</h2>
        <p class="form-introduction">Are any of these relevant to you? Please answer every question. A “yes” answer is recorded and does not automatically approve or reject a treatment.</p>
        <?php form_errors($errors); ?>
        <div class="health-questions">
        <?php foreach (HEALTH_QUESTIONS as $key => $label): ?>
            <fieldset class="health-question">
                <legend><?= e($label) ?></legend>
                <?php foreach (['yes' => 'Yes', 'no' => 'No'] as $value => $text): ?>
                <label><input type="radio" name="<?= e($key) ?>" value="<?= e($value) ?>" required <?= $answers[$key] === $value ? 'checked' : '' ?>><span><?= e($text) ?></span></label>
                <?php endforeach; ?>
            </fieldset>
        <?php endforeach; ?>
        </div>
        <div class="field"><label for="notes">Anything else we should know? <span>Optional</span></label><textarea id="notes" name="notes" rows="4" maxlength="2000" aria-describedby="notes-help"><?= e($answers['notes']) ?></textarea><small id="notes-help">Additional notes, up to 2,000 characters. Share only information relevant to your treatment.</small></div>
        <label class="check-label"><input type="checkbox" name="consent" value="1" required <?= ($answers['consent'] ?? '') === '1' ? 'checked' : '' ?>><span>I confirm that I have disclosed relevant health information and consent to these answers being stored with my appointment.</span></label>
        <button class="button" type="submit">Save & choose a treatment <span>↗</span></button>
    </form>
    <aside class="booking-aside health-aside"><img src="<?= e(url('/assets/images/detail.jpg')) ?>" alt="A considered moment of spa care — illustrative photography" width="700" height="800"><p class="eyebrow">Personal care, from the beginning</p><h2>A moment<br>to <em>check in.</em></h2><p>This declaration collects information only. Please speak with the spa team about your answers before treatment.</p><p class="small-note">Your declaration is visible in your own booking details. Your answers are never included in the availability response.</p></aside>
</div>
<?php require __DIR__ . '/../../app/components/footer.php'; ?>
