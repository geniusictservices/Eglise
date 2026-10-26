<?php /** Bouton de bascule jour / nuit (géré par /assets/js/theme.js). @var string $classe */ ?>
<button type="button" class="bouton-icone <?= e($classe ?? '') ?>" data-bascule-theme aria-pressed="false" title="<?= e(t('theme.nuit')) ?>">
    <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm0 2v16a8 8 0 0 1 0-16Z"/></svg>
    <span class="visuellement-cache"><?= e(t('theme.nuit')) ?></span>
</button>
