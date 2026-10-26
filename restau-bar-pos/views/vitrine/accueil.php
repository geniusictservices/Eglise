<?php
/**
 * @var string $nomClient
 * @var array $etablissements
 */
$autreLangue = App\Core\I18n::langue() === 'fr' ? 'en' : 'fr';
?>
<header class="entete entete--accueil">
    <div class="entete__contenu enveloppe">
        <a class="langue langue--entete" href="<?= e(urlAvec(['lang' => $autreLangue])) ?>" lang="<?= e($autreLangue) ?>">
            <?= $autreLangue === 'en' ? 'English' : 'Français' ?>
        </a>
        <h1 class="entete__nom"><?= e($nomClient) ?></h1>
        <p class="entete__slogan"><?= e(t('vitrine.nos_etablissements')) ?></p>
    </div>
</header>

<main class="enveloppe liste-etablissements">
    <?php foreach ($etablissements as $etab): ?>
        <a class="carte-etab" href="/e/<?= e($etab['slug']) ?>"
           style="--accent-etab: <?= e(preg_match('/^#[0-9a-fA-F]{6}$/', (string) $etab['couleur']) ? $etab['couleur'] : '#1F6F78') ?>">
            <span class="carte-etab__type"><?= e(t('type.' . $etab['type'])) ?></span>
            <span class="carte-etab__nom"><?= e($etab['nom']) ?></span>
            <?php if (champ($etab, 'slogan') !== ''): ?>
                <span class="carte-etab__slogan"><?= e(champ($etab, 'slogan')) ?></span>
            <?php endif; ?>
            <span class="etat <?= $etab['etat']['ouvert'] ? 'etat--ouvert' : 'etat--ferme' ?>">
                <span class="etat__point" aria-hidden="true"></span>
                <?= e($etab['etat']['ouvert'] ? t('etat.ouvert_court') : t('etat.ferme')) ?>
            </span>
            <span class="carte-etab__action"><?= e(t('vitrine.voir_menu')) ?> →</span>
        </a>
    <?php endforeach; ?>
</main>
