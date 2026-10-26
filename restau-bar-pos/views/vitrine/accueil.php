<?php
/**
 * @var string $nomClient
 * @var array $etablissements
 */
$autreLangue = App\Core\I18n::langue() === 'fr' ? 'en' : 'fr';
?>
<header class="entete entete--accueil">
    <div class="entete__contenu enveloppe">
        <div class="entete__outils">
            <a class="langue" href="<?= e(urlAvec(['lang' => $autreLangue])) ?>" lang="<?= e($autreLangue) ?>">
                <?= $autreLangue === 'en' ? 'English' : 'Français' ?>
            </a>
            <?= App\Core\View::rendre('layouts/_bouton-theme') ?>
        </div>
        <h1 class="entete__nom"><?= e($nomClient) ?></h1>
        <p class="entete__slogan"><?= e(t('vitrine.nos_etablissements')) ?></p>
    </div>
</header>

<main class="enveloppe liste-etablissements">
    <?php foreach ($etablissements as $etab): ?>
        <a class="carte-etab" href="/e/<?= e($etab['slug']) ?>"
           style="--accent-etab: <?= e(couleurValide($etab['couleur']) ?? '#FF5A1F') ?>">
            <span class="carte-etab__type"><?= e(t('type.' . $etab['type'])) ?><?= $etab['ville'] ? ' · ' . e($etab['ville']) : '' ?></span>
            <span class="carte-etab__nom"><?= e($etab['nom']) ?></span>
            <?php if (champ($etab, 'slogan') !== ''): ?>
                <span class="carte-etab__slogan"><?= e(champ($etab, 'slogan')) ?></span>
            <?php endif; ?>
            <span class="etat <?= $etab['etat']['ouvert'] ? 'etat--ouvert' : 'etat--ferme' ?>">
                <span class="etat__point" aria-hidden="true"></span>
                <?= e($etab['etat']['ouvert'] ? t('etat.ouvert_court') : t('etat.ferme')) ?>
            </span>
            <span class="carte-etab__action"><?= e(t('vitrine.voir_menu')) ?> <span aria-hidden="true">→</span></span>
        </a>
    <?php endforeach; ?>
</main>
