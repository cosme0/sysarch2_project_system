<?php
require_once __DIR__ . '/config.php';
$brandHref = $brandHref ?? 'home.php';
?>
<a href="<?= htmlspecialchars($brandHref) ?>" class="brand">
    <div class="brand-icon" aria-hidden="true">&#9881;</div>
    <div class="brand-text brand-text--system">
        <h1><?= SYSTEM_NAME_LINE1 ?></h1>
        <span><?= SYSTEM_NAME_LINE2 ?></span>
    </div>
</a>
