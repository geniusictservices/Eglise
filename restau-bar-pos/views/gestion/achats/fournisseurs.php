<?php
/**
 * Achats > Fournisseurs : liste avec le type, le contact, la dette et la dernière réception.
 * @var list<array> $fournisseurs
 * @var array<int, array> $dettes fournisseur_id => dette
 * @var array<int, array> $devises
 * @var bool $peutModifier
 */

use App\Controllers\Gestion\TresorerieAffichage as A;
use App\Core\View;

$resteDu = static function (?array $d) use ($devises): string {
    if ($d === null) {
        return '';
    }
    $parties = [];
    foreach ($d['par_devise'] as $deviseId => $m) {
        if ($m > 0.0005 && isset($devises[$deviseId])) {
            $parties[] = A::montant($m, $devises[$deviseId]);
        }
    }
    return implode(' + ', $parties);
};
?>
<?= View::rendre('gestion/stock/_onglets', ['flash' => $flash ?? null]) ?>

<div class="tre-entete">
    <div>
        <h1><?= e(t('achats.fournisseurs_titre')) ?></h1>
        <p class="aide"><?= e(t('achats.fournisseurs_aide')) ?></p>
    </div>
    <?php if ($peutModifier): ?><div class="tre-entete__actions"><a class="bouton" href="/gestion/achats/fournisseurs/nouveau">+ <?= e(t('achats.nouveau_fournisseur')) ?></a></div><?php endif; ?>
</div>

<section class="bloc">
    <?php if ($fournisseurs === []): ?>
        <p class="aide"><?= e(t('achats.aucun_fournisseur')) ?></p>
    <?php else: ?>
        <ul class="stk-cartes">
            <?php foreach ($fournisseurs as $f): $d = $dettes[(int) $f['id']] ?? null; $reste = $resteDu($d); ?>
                <li>
                    <a class="stk-carte<?= $f['actif'] ? '' : ' stk-carte--inactive' ?>" href="/gestion/achats/fournisseurs/<?= (int) $f['id'] ?>" data-fournisseur="<?= (int) $f['id'] ?>">
                        <span class="stk-carte__nom"><?= App\Core\Icones::svg('etablissements', 20) ?><strong><?= e($f['nom']) ?></strong>
                            <span class="badge badge--neutre"><?= e(t('achats.type_' . $f['type'])) ?></span>
                            <?php if (!$f['actif']): ?><span class="badge badge--erreur"><?= e(t('stock.inactif')) ?></span><?php endif; ?></span>
                        <span class="tre-sous-texte"><?= e(implode(' · ', array_filter([$f['contact'], $f['telephone'] ? afficherTelephone($f['telephone']) : null]))) ?></span>
                        <span class="tre-sous-texte"><?= $f['derniere_reception'] ? e(t('achats.derniere_reception', ['date' => A::jour($f['derniere_reception']), 'n' => (int) $f['nb_receptions']])) : e(t('achats.jamais_recu')) ?></span>
                        <?php if ($reste !== ''): ?>
                            <span class="stk-carte__cout<?= $d['en_retard'] ? ' tre-alerte-texte' : '' ?>" data-dette><?= e(t('achats.reste_du', ['montant' => $reste])) ?><?= $d['en_retard'] ? ' · ' . e(t('achats.en_retard')) : '' ?></span>
                        <?php endif; ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
