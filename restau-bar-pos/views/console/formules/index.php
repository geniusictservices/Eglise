<?php
/**
 * Formules d'abonnement.
 * @var list<array> $formules
 */

use App\Console\Abonnement;
use App\Console\Affichage as A;

?>
<div class="con-entete">
    <div>
        <h1><?= e(t('console.formules.titre')) ?></h1>
        <p class="aide"><?= e(t('console.formules.aide')) ?></p>
    </div>
    <a class="bouton" href="/formules/nouvelle"><?= e(t('console.formule.nouvelle')) ?></a>
</div>
<ul class="con-cartes">
    <?php foreach ($formules as $f): ?>
        <li class="carte con-formule<?= $f['actif'] ? '' : ' con-formule--archivee' ?>" data-formule="<?= e($f['nom']) ?>">
            <div class="con-formule__tete">
                <h2><a class="lien con-lien-fort" href="/formules/<?= (int) $f['id'] ?>"><?= e($f['nom']) ?></a></h2>
                <?= $f['actif'] ? '' : '<span class="badge badge--neutre">' . e(t('console.formule.archivee')) . '</span>' ?>
            </div>
            <?php if ($f['description']): ?><p class="aide"><?= e($f['description']) ?></p><?php endif; ?>
            <p class="con-prix">
                <?php foreach (array_keys(Abonnement::PERIODES) as $p): if (Abonnement::prix($f, $p) === null) { continue; } ?>
                    <span><strong class="montant"><?= e(A::montant(Abonnement::prix($f, $p))) ?></strong> / <?= e(t('console.periode.' . $p)) ?></span>
                <?php endforeach; ?>
            </p>
            <p class="con-petit"><?= e(t('console.formule.limites', [
                'etablissements' => $f['max_etablissements'] ?? '∞', 'utilisateurs' => $f['max_utilisateurs'] ?? '∞', 'sms' => $f['sms_inclus'],
            ])) ?></p>
            <ul class="con-modules" aria-label="<?= e(t('console.formule.modules')) ?>">
                <?php foreach ($f['modules'] as $m): ?><li class="badge badge--glace"><?= e(t('module.' . $m . '.nom')) ?></li><?php endforeach; ?>
            </ul>
            <p class="aide con-petit"><?= e(t('console.formule.clients', ['n' => (int) $f['clients']])) ?></p>
        </li>
    <?php endforeach; ?>
</ul>
