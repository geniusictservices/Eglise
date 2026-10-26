<?php
/**
 * Ventes > Garanties : les ardoises dont la personne connectée est garante, en attente de sa réponse (client, montant,
 * qui a servi, établissement) ; Accepter (elle devient responsable) ou Refuser (le serveur reste responsable).
 * Puis ses dernières réponses.
 * @var ?string $flash
 * @var ?string $erreur
 * @var list<array> $garanties
 * @var list<array> $repondues
 * @var array<int, array> $devises
 */

use App\Controllers\Gestion\DetteAffichage as D;
use App\Controllers\Gestion\TresorerieAffichage as A;

$jeton = e(App\Core\Session::jetonCsrf());
?>
<link rel="stylesheet" href="/assets/css/gestion-tresorerie.css">
<link rel="stylesheet" href="/assets/css/gestion-dettes.css">

<div class="tre-entete">
    <div>
        <h1><?= e(t('garanties.titre')) ?></h1>
        <p class="aide"><?= e(t('garanties.aide')) ?></p>
    </div>
</div>

<?php if ($flash): ?>
    <p class="alerte" role="status"><?= e($flash) ?></p>
<?php endif; ?>
<?php if ($erreur): ?>
    <p class="alerte alerte--erreur" role="alert"><?= e($erreur) ?></p>
<?php endif; ?>

<?php if ($garanties === []): ?>
    <p class="bloc aide" data-garanties="0"><?= e(t('garanties.aucune')) ?></p>
<?php else: ?>
    <ul class="det-garanties" data-garanties="<?= count($garanties) ?>">
        <?php foreach ($garanties as $g): ?>
            <li class="det-garantie" data-dette="<?= (int) $g['id'] ?>">
                <div class="det-garantie__corps">
                    <p class="surtitre"><?= e($g['etablissement_nom']) ?> · <?= e(A::dateHeure($g['cree_le'])) ?></p>
                    <h2 class="det-garantie__client"><?= e($g['client_nom']) ?></h2>
                    <p class="det-garantie__montant"><strong class="montant"><?= e(D::montant($g['montant'], $g['devise_id'], $devises)) ?></strong>
                        <?php if ((float) $g['reste'] < (float) $g['montant']): ?><span class="tre-sous-texte"><?= e(t('garanties.reste', ['montant' => D::montant($g['reste'], $g['devise_id'], $devises)])) ?></span><?php endif; ?></p>
                    <p class="aide"><?= e(t('garanties.servi_par', ['nom' => $g['servi_nom'] ?? '—'])) ?> · <?= e(t('gdettes.echeance_le', ['date' => A::jour($g['echeance'])])) ?></p>
                    <?php if ($g['note']): ?><p class="aide det-texte-long"><?= e($g['note']) ?></p><?php endif; ?>
                </div>
                <form method="post" action="/gestion/garanties/<?= (int) $g['id'] ?>" class="det-garantie__actions">
                    <input type="hidden" name="csrf" value="<?= $jeton ?>">
                    <button class="bouton" type="submit" name="reponse" value="accepter"><?= e(t('garanties.accepter')) ?></button>
                    <button class="bouton bouton--secondaire" type="submit" name="reponse" value="refuser"><?= e(t('garanties.refuser')) ?></button>
                </form>
                <p class="aide det-garantie__explication"><?= e(t('garanties.explication', ['servi' => $g['servi_nom'] ?? '—'])) ?></p>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<?php if ($repondues !== []): ?>
    <section class="bloc" aria-labelledby="det-repondues-titre">
        <h2 id="det-repondues-titre"><?= e(t('garanties.repondues')) ?></h2>
        <ul class="det-lignes">
            <?php foreach ($repondues as $g): ?>
                <li data-dette="<?= (int) $g['id'] ?>">
                    <span>
                        <strong><?= e($g['client_nom']) ?></strong>
                        <span class="badge <?= $g['garant_statut'] === 'accepte' ? 'badge--succes' : 'badge--neutre' ?>"><?= e(t('gdettes.garant_' . $g['garant_statut'])) ?></span>
                        <span class="tre-sous-texte"><?= e($g['etablissement_nom']) ?> · <?= e(t('garanties.servi_par', ['nom' => $g['servi_nom'] ?? '—'])) ?> · <?= e(t('gdettes.repondu_le', ['date' => A::dateHeure($g['garant_repondu_le'])])) ?></span>
                    </span>
                    <strong class="montant"><?= e(D::montant($g['montant'], $g['devise_id'], $devises)) ?></strong>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endif; ?>
