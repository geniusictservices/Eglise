<?php
/**
 * Écran d'accueil après la connexion : un module par tuile.
 *
 * @var array $utilisateur
 * @var ?string $flash
 * @var array<string, array> $modules
 */
use App\Core\Icones;
?>
<div class="modules-accueil">
    <p class="surtitre"><?= e(t('modules.titre')) ?></p>
    <h1><?= e(t('modules.bonjour', ['nom' => $utilisateur['nom']])) ?></h1>
    <p class="aide"><?= e(t('modules.choisir')) ?></p>

    <?php if ($flash): ?>
        <p class="alerte" role="status"><?= e($flash) ?></p>
    <?php endif; ?>

    <ul class="modules">
        <?php foreach ($modules as $code => $module): $nom = t('module.' . $code . '.nom'); ?>
            <li>
                <?php if ($module['lien'] !== null): ?>
                    <a class="module module--<?= e($code) ?>" href="<?= e($module['lien']) ?>">
                        <span class="module__icone"><?= Icones::svg($module['icone'], 26) ?></span>
                        <span class="module__nom"><?= e($nom) ?></span>
                        <span class="module__description"><?= e(t('module.' . $code . '.description')) ?></span>
                    </a>
                <?php else: ?>
                    <div class="module module--bientot" aria-disabled="true">
                        <span class="module__icone"><?= Icones::svg($module['icone'], 26) ?></span>
                        <span class="module__nom"><?= e($nom) ?> <span class="badge badge--neutre"><?= e(t('modules.bientot')) ?></span></span>
                        <span class="module__description"><?= e(t('module.' . $code . '.description')) ?></span>
                    </div>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
</div>
