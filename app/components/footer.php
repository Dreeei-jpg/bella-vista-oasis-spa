</main>
<footer class="site-footer">
    <div class="footer-top container">
        <div>
<p class="eyebrow">Make room for yourself</p>
<h2>A slower kind<br>of afternoon.</h2>
<a class="text-link light" href="<?= e(url('/services.php')) ?>">Find your treatment <span>↗</span>
</a>
</div>
        <div class="footer-details">
<a class="brand" href="<?= e(url('/')) ?>" aria-label="Bella Vista Oasis Spa, home"><img src="<?= e(url('/assets/images/mark.svg')) ?>" width="58" height="66" alt=""><span>BELLA VISTA<small>O A S I S &nbsp; S P A</small></span></a>
<p class="eyebrow">Your time, restored</p>
<p>Monday – Sunday<br>1:00 PM – 9:00 PM</p>
<p class="muted">Please arrive 15 minutes<br>before your appointment.</p>
</div>
        <div class="footer-links">
<a href="<?= e(url('/about.php')) ?>">Our story</a>
<a href="<?= e(url('/gallery.php')) ?>">The spaces</a>
<a href="<?= e(url('/terms.php')) ?>">Terms & conditions</a>
<?php if (!empty($_SESSION['user_id'])): ?>
<a href="<?= e(url('/auth/login.php')) ?>"><?= ($_SESSION['user_role']??'')==='admin'?'Management Dashboard':'My account' ?></a>
<?php else: ?><a href="<?= e(url('/auth/register.php')) ?>">Create an account</a><?php endif; ?>
</div>
    </div>
    <div class="footer-bottom container">
<span>© <?= date('Y') ?> Bella Vista Oasis Spa</span>
<span>Rooted in care. Since 2021.</span>
<a href="#main">Back to top ↑</a>
</div>
</footer>
<dialog id="lightbox" aria-label="Gallery photograph">
<button class="lightbox-close" aria-label="Close photograph">Close ×</button>
<img src="<?= e(url('/assets/images/hero.jpg')) ?>" alt="">
<p>
</p>
</dialog>
</body>
</html>
