<?php
require_once __DIR__ . '/config.php';
$brandHref = $brandHref ?? (function_exists('isAdmin') && isAdmin() ? 'admin.php' : 'home.php');
?>
<a href="<?= htmlspecialchars($brandHref) ?>" class="brand">
    <svg class="brand-icon brand-logo" viewBox="0 0 48 48" role="img" aria-label="<?= htmlspecialchars(SYSTEM_ACRONYM) ?> logo">
        <defs>
            <linearGradient id="amdpms-g" x1="0" y1="0" x2="1" y2="1">
                <stop offset="0" style="stop-color: var(--logo-g1)"/>
                <stop offset="1" style="stop-color: var(--logo-g2)"/>
            </linearGradient>
        </defs>
        <rect x="2" y="2" width="44" height="44" rx="12" fill="url(#amdpms-g)"/>
        <rect x="20.5" y="10" width="7" height="28" rx="3.5" fill="#ffffff"/>
        <rect x="10" y="20.5" width="28" height="7" rx="3.5" fill="#ffffff"/>
        <polyline points="11.5,24 18.5,24 21,18.5 23.5,29.5 26,22.5 28.5,24 36.5,24" fill="none" stroke="var(--logo-line, rgba(255,255,255,0.95))" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
    </svg>
    <div class="brand-text brand-text--system">
        <h1><?= SYSTEM_NAME_LINE1 ?></h1>
        <span><?= SYSTEM_NAME_LINE2 ?></span>
    </div>
</a>
