<?php
/**
 * Paramètres > Assistance : accord donné à la plateforme pour 24 heures, et historique.
 * @var ?string $flash
 * @var ?string $erreur
 * @var ?string $jusqua
 * @var ?string $par
 * @var bool $proprietaire
 * @var string $plateforme
 * @var list<array> $journal
 */

use App\Core\Plateforme;
use App\Core\Session;

?>
<link rel="stylesheet" href="/assets/css/plateforme.css">
<h1><?= e(t('assistance.titre')) ?></h1>
<p class="aide"><?= e(t('assistance.aide', ['plateforme' => $plateforme])) ?></p>

<?php if ($flash): ?>
    <p class="alerte" role="status"><?= e($flash) ?></p>
<?php endif; ?>
<?php if ($erreur): ?>
    <p class="alerte alerte--erreur" role="alert"><?= e($erreur) ?></p>
<?php endif; ?>

<section class="bloc plf-assistance" aria-labelledby="plf-assistance-titre">
    <h2 id="plf-assistance-titre"><?= e(t('assistance.etat')) ?></h2>
    <p class="plf-assistance__etat">
        <?php if ($jusqua): ?>
            <span class="badge badge--glace"><?= e(t('assistance.autorisee')) ?></span>
            <span data-assistance="autorisee"><?= e(t('assistance.jusqua', ['jusqua' => Plateforme::dateHeure($jusqua), 'par' => $par ?? '—'])) ?></span>
        <?php else: ?>
            <span class="badge badge--neutre"><?= e(t('assistance.non_autorisee')) ?></span>
            <span data-assistance="non"><?= e(t('assistance.non_autorisee_texte')) ?></span>
        <?php endif; ?>
    </p>
    <?php if ($proprietaire): ?>
        <form method="post" action="/gestion/assistance">
            <input type="hidden" name="csrf" value="<?= e(Session::jetonCsrf()) ?>">
            <?php if ($jusqua): ?>
                <input type="hidden" name="action" value="retirer">
                <button class="bouton bouton--secondaire" type="submit"><?= e(t('assistance.retirer')) ?></button>
            <?php else: ?>
                <input type="hidden" name="action" value="autoriser">
                <button class="bouton" type="submit"><?= e(t('assistance.autoriser')) ?></button>
            <?php endif; ?>
        </form>
    <?php else: ?>
        <p class="aide"><?= e(t('assistance.reserve_proprietaire')) ?></p>
    <?php endif; ?>
</section>

<section class="bloc" aria-labelledby="plf-historique-titre">
    <h2 id="plf-historique-titre"><?= e(t('assistance.historique')) ?></h2>
    <?php if ($journal === []): ?>
        <p class="aide"><?= e(t('journal.vide')) ?></p>
    <?php else: ?>
        <div class="defile">
            <table class="tableau">
                <thead><tr><th><?= e(t('journal.date')) ?></th><th><?= e(t('journal.action')) ?></th><th><?= e(t('journal.personne')) ?></th></tr></thead>
                <tbody>
                <?php foreach ($journal as $ligne): ?>
                    <tr>
                        <td class="montant"><?= e(Plateforme::dateHeure($ligne['cree_le'])) ?></td>
                        <td><?= e(t('journal.action_' . $ligne['action'])) ?></td>
                        <td><?= e($ligne['nom'] ?? '—') ?><?= $ligne['action'] === 'assistance_ouverte' && $ligne['cible'] ? ' · ' . e($ligne['cible']) : '' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
