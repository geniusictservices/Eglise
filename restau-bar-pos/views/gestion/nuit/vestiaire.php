<?php
/**
 * Nuit > Vestiaire : frais de dépôt (vides = gratuit), tickets d'une soirée, objets encore au vestiaire.
 * @var list<array> $etablissements @var array $etab @var array<int, float> $frais @var array<int, array> $acceptees @var string $journee
 * @var list<array> $tickets @var list<array> $deposes @var bool $peutModifier @var ?string $flash @var ?string $erreur
 */
use App\Core\View;

$jeton = e(App\Core\Session::jetonCsrf());
?>
<link rel="stylesheet" href="/assets/css/gestion-salle.css">
<link rel="stylesheet" href="/assets/css/gestion-nuit.css">
<div class="sal-entete">
    <div>
        <h1><?= e(t('gnuit.vestiaire_titre')) ?></h1>
        <p class="aide"><?= e(t('gnuit.vestiaire_aide')) ?></p>
    </div>
    <a class="bouton" href="/caisse/vestiaire?e=<?= e($etab['slug']) ?>"><?= e(t('gnuit.ouvrir_vestiaire')) ?></a>
</div>
<?php if ($flash): ?><p class="alerte" role="status"><?= e($flash) ?></p><?php endif; ?>
<?php if ($erreur): ?><p class="alerte alerte--erreur" role="alert"><?= e($erreur) ?></p><?php endif; ?>
<?= View::rendre('gestion/salle/_etablissements', ['etablissements' => $etablissements, 'etab' => $etab, 'garder' => []]) ?>
<section class="bloc" aria-labelledby="nuit-frais">
    <h2 id="nuit-frais"><?= e(t('nuit.vestiaire_frais')) ?></h2>
    <p class="aide"><?= e($frais === [] ? t('gnuit.vestiaire_gratuit') : t('gnuit.vestiaire_payant', ['montant' => View::rendre('gestion/nuit/_prix', ['montants' => $frais, 'devises' => $acceptees])])) ?></p>
    <?php if ($peutModifier): ?>
        <form class="sal-form" method="post" action="/gestion/nuit/vestiaire/frais">
            <input type="hidden" name="csrf" value="<?= $jeton ?>">
            <input type="hidden" name="etablissement" value="<?= (int) $etab['id'] ?>">
            <?= View::rendre('gestion/nuit/_montants', ['acceptees' => $acceptees, 'valeurs' => $frais, 'id' => 'frais', 'desactive' => false]) ?>
            <div class="sal-form__actions"><button class="bouton" type="submit"><?= e(t('gsalle.enregistrer')) ?></button></div>
        </form>
    <?php endif; ?>
</section>
<section class="bloc" aria-labelledby="nuit-deposes">
    <h2 id="nuit-deposes"><?= e(t('gnuit.encore_au_vestiaire', ['n' => count($deposes)])) ?></h2>
    <div class="defile">
        <table class="tableau">
            <thead><tr><th><?= e(t('gnuit.numero')) ?></th><th><?= e(t('gnuit.description')) ?></th><th><?= e(t('gnuit.nuit')) ?></th><th><?= e(t('gnuit.depose')) ?></th></tr></thead>
            <tbody>
                <?php foreach ($deposes as $d): ?>
                    <tr><td><strong><?= (int) $d['numero'] ?></strong></td><td><?= e($d['description'] ?? '—') ?></td><td><?= e(date('d/m/Y', strtotime($d['journee']))) ?></td><td><?= e(date('H:i', strtotime($d['depose_le']))) ?> · <?= e($d['depose_par_nom'] ?? '') ?></td></tr>
                <?php endforeach; ?>
                <?php if ($deposes === []): ?><tr><td colspan="4"><?= e(t('nuit.c_vestiaire_aucun')) ?></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<section class="bloc" aria-labelledby="nuit-tickets">
    <h2 id="nuit-tickets"><?= e(t('gnuit.nuit_du', ['date' => date('d/m/Y', strtotime($journee))])) ?></h2>
    <form class="sal-jour" method="get" action="/gestion/nuit/vestiaire">
        <input type="hidden" name="etablissement" value="<?= (int) $etab['id'] ?>">
        <label class="champ"><span><?= e(t('gnuit.nuit')) ?></span><input class="saisie" type="date" name="journee" value="<?= e($journee) ?>"></label>
        <button class="bouton bouton--secondaire" type="submit"><?= e(t('tresorerie.afficher')) ?></button>
    </form>
    <div class="defile">
        <table class="tableau">
            <thead><tr><th><?= e(t('gnuit.numero')) ?></th><th><?= e(t('gnuit.description')) ?></th><th><?= e(t('gnuit.depose')) ?></th><th><?= e(t('gnuit.rendu')) ?></th><th><?= e(t('gnuit.vente')) ?></th></tr></thead>
            <tbody>
                <?php foreach ($tickets as $tk): ?>
                    <tr><td><strong><?= (int) $tk['numero'] ?></strong></td><td><?= e($tk['description'] ?? '—') ?></td>
                        <td><?= e(date('H:i', strtotime($tk['depose_le']))) ?> · <?= e($tk['depose_par_nom'] ?? '') ?></td>
                        <td><?= $tk['rendu_le'] ? e(date('H:i', strtotime($tk['rendu_le']))) . ' · ' . e($tk['rendu_par_nom'] ?? '') : '<span class="badge badge--braise">' . e(t('gnuit.au_vestiaire')) . '</span>' ?></td>
                        <td><?= $tk['vente_numero'] ? '<a href="/gestion/ventes/' . (int) $tk['vente_id'] . '">' . e($tk['vente_numero']) . '</a>' : e(t('nuit.gratuit')) ?></td></tr>
                <?php endforeach; ?>
                <?php if ($tickets === []): ?><tr><td colspan="5"><?= e(t('gnuit.aucun_ticket')) ?></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
