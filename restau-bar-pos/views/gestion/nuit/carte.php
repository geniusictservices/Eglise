<?php
/**
 * Cashless > une carte : soldes par devise, statut (bloquer une carte perdue), porteur, historique complet.
 * @var array $carte @var list<array> $historique @var array<int, array> $devises @var ?string $flash
 */
$jeton = e(App\Core\Session::jetonCsrf());
$formateur = new App\Repositories\DeviseRepository();
$devisesCode = array_column($devises, null, 'code');
?>
<link rel="stylesheet" href="/assets/css/gestion-salle.css">
<link rel="stylesheet" href="/assets/css/gestion-nuit.css">
<div class="sal-entete">
    <div>
        <h1><?= e($carte['code']) ?></h1>
        <p class="aide"><?= e(t('gnuit.support_' . $carte['support'])) ?><?= $carte['porteur'] ? ' · ' . e($carte['porteur']) : '' ?> · <?= e(t('gnuit.creee_le', ['date' => date('d/m/Y', strtotime($carte['cree_le']))])) ?></p>
    </div>
    <a class="bouton bouton--secondaire" href="/gestion/cashless/imprimer?ids=<?= (int) $carte['id'] ?>"><?= e(t('nuit.c_carte_imprimer')) ?></a>
</div>
<?php if ($flash): ?><p class="alerte" role="status"><?= e($flash) ?></p><?php endif; ?>
<section class="bloc" aria-labelledby="carte-soldes">
    <h2 id="carte-soldes"><?= e(t('nuit.c_soldes')) ?></h2>
    <div class="chiffres">
        <?php foreach ($devises as $id => $d): if (!isset($carte['soldes'][$id])) { continue; } ?>
            <div class="chiffre"><span class="chiffre__libelle"><?= e($d['code']) ?></span><strong class="chiffre__valeur montant" data-solde="<?= e($d['code']) ?>"><?= e($formateur->formater((float) $carte['soldes'][$id], $d)) ?></strong></div>
        <?php endforeach; ?>
        <?php if ($carte['soldes'] === []): ?><p class="aide"><?= e(t('nuit.c_carte_vide')) ?></p><?php endif; ?>
    </div>
    <p><span class="badge <?= $carte['statut'] === 'active' ? 'badge--succes' : 'badge--erreur' ?>"><?= e($carte['statut'] === 'active' ? t('gnuit.carte_active') : t('nuit.c_carte_bloquee')) ?></span>
        <?php if ($carte['statut'] === 'bloquee'): ?><?= e(t('gnuit.bloquee_par', ['date' => date('d/m/Y H:i', strtotime($carte['bloquee_le'])), 'par' => $carte['bloquee_par_nom'] ?? '', 'motif' => $carte['motif_blocage'] ?? ''])) ?><?php endif; ?></p>
    <form class="sal-form" method="post" action="/gestion/cashless/<?= (int) $carte['id'] ?>/bloquer">
        <input type="hidden" name="csrf" value="<?= $jeton ?>">
        <input type="hidden" name="action" value="porteur">
        <label class="champ"><span><?= e(t('nuit.c_carte_porteur')) ?></span><input class="saisie" name="porteur" maxlength="128" value="<?= e($carte['porteur'] ?? '') ?>"></label>
        <div class="sal-form__actions"><button class="bouton bouton--secondaire" type="submit"><?= e(t('gsalle.enregistrer')) ?></button></div>
    </form>
    <form class="sal-form" method="post" action="/gestion/cashless/<?= (int) $carte['id'] ?>/bloquer">
        <input type="hidden" name="csrf" value="<?= $jeton ?>">
        <input type="hidden" name="bloquer" value="<?= $carte['statut'] === 'active' ? '1' : '0' ?>">
        <?php if ($carte['statut'] === 'active'): ?><label class="champ"><span><?= e(t('nuit.c_motif_blocage')) ?></span><input class="saisie" name="motif" maxlength="160"></label><?php endif; ?>
        <div class="sal-form__actions"><button class="bouton <?= $carte['statut'] === 'active' ? 'bouton--danger' : '' ?>" type="submit" id="carte-statut"><?= e($carte['statut'] === 'active' ? t('nuit.c_carte_bloquer') : t('nuit.c_carte_debloquer')) ?></button></div>
    </form>
    <p class="aide"><?= e(t('gnuit.remboursement_aide')) ?></p>
</section>
<section class="bloc" aria-labelledby="carte-historique">
    <h2 id="carte-historique"><?= e(t('nuit.c_carte_historique')) ?></h2>
    <div class="defile">
        <table class="tableau">
            <thead><tr><th><?= e(t('recu.date')) ?></th><th><?= e(t('gnuit.operation')) ?></th><th class="nombre"><?= e(t('gnuit.montant')) ?></th><th class="nombre"><?= e(t('nuit.c_carte_solde')) ?></th><th><?= e(t('gnuit.detail')) ?></th></tr></thead>
            <tbody>
                <?php foreach ($historique as $m): $d = $devisesCode[$m['devise_code']] ?? null; ?>
                    <tr><td><?= e(date('d/m/Y H:i', strtotime($m['cree_le']))) ?></td><td><?= e(t('gnuit.mouvement_' . $m['type'])) ?></td>
                        <td class="nombre"><?= e($d ? $formateur->formater((float) $m['montant'], $d) : $m['montant']) ?></td>
                        <td class="nombre"><?= e($d ? $formateur->formater((float) $m['solde_apres'], $d) : $m['solde_apres']) ?></td>
                        <td><?= $m['vente_numero'] ? '<a href="/gestion/ventes/' . (int) $m['vente_id'] . '">' . e($m['vente_numero']) . '</a> · ' : '' ?><?= e(implode(' · ', array_filter([$m['comptes'], $m['etablissement_nom'], $m['par_nom']]))) ?></td></tr>
                <?php endforeach; ?>
                <?php if ($historique === []): ?><tr><td colspan="5"><?= e(t('nuit.c_carte_vide')) ?></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
