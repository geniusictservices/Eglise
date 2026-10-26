<?php
/**
 * Vue d'ensemble de la trésorerie : comptes groupés par moyen, soldes, totaux par devise.
 * @var ?string $flash
 * @var array<int, array> $devises
 * @var ?array $reference
 * @var ?array $locale
 * @var array<string, array> $groupes type => comptes actifs
 * @var array<string, array<int, float>> $sousTotaux type => devise_id => solde
 * @var array $inactifs
 * @var array<int, float> $totaux devise_id => solde (locale, dollar, autres)
 * @var ?float $totalReference
 * @var ?float $totalLocale
 * @var string[] $sansTaux
 * @var int $nbComptes
 */

use App\Controllers\Gestion\TresorerieAffichage as A;
use App\Core\View;

$ligneCompte = static function (array $compte) use ($devises): string {
    $devise = $devises[(int) $compte['devise_id']] ?? null;
    $details = array_filter([$compte['operateur'], $compte['numero'], $compte['etablissement_nom'] ?? null]);
    $solde = (float) $compte['solde'];
    ob_start(); ?>
    <li>
        <a class="tre-compte" href="/gestion/tresorerie/comptes/<?= (int) $compte['id'] ?>">
            <span class="tre-compte__nom">
                <?= e($compte['nom']) ?>
                <?php if ($devise): ?><span class="badge badge--neutre"><?= e($devise['code']) ?></span><?php endif; ?>
            </span>
            <?php if ($details): ?><span class="tre-compte__details"><?= e(implode(' · ', $details)) ?></span><?php endif; ?>
            <span class="tre-compte__solde montant<?= $solde < -0.0005 ? ' tre-negatif' : '' ?>">
                <?= $devise ? e(A::montant($solde, $devise)) : '' ?>
            </span>
        </a>
    </li>
    <?php return (string) ob_get_clean();
};
?>
<link rel="stylesheet" href="/assets/css/gestion-tresorerie.css">

<div class="tre-entete">
    <div>
        <h1><?= e(t('tresorerie.titre')) ?></h1>
        <p class="aide"><?= e(t('tresorerie.aide')) ?></p>
    </div>
    <?php if ($nbComptes > 0): ?>
        <div class="tre-entete__actions">
            <a class="bouton" href="/gestion/depenses/nouvelle">+ <?= e(t('depenses.nouvelle')) ?></a>
            <a class="bouton bouton--secondaire" href="/gestion/tresorerie/transferts#nouveau-transfert"><?= e(t('tresorerie.action_transfert')) ?></a>
        </div>
    <?php endif; ?>
</div>


<?php if ($flash): ?>
    <p class="alerte" role="status"><?= e($flash) ?></p>
<?php endif; ?>

<?php if ($nbComptes === 0): ?>
    <section class="bloc">
        <h2><?= e(t('tresorerie.premiers_pas')) ?></h2>
        <p><?= e(t('tresorerie.premiers_pas_texte')) ?></p>
        <p><a class="bouton" href="/gestion/tresorerie/comptes/nouveau">+ <?= e(t('tresorerie.nouveau_compte')) ?></a></p>
    </section>
<?php else: ?>

    <section class="tre-totaux" aria-labelledby="tre-totaux-titre">
        <h2 class="visuellement-cache" id="tre-totaux-titre"><?= e(t('tresorerie.totaux')) ?></h2>
        <div class="chiffres">
            <?php foreach ($totaux as $deviseId => $montant): $devise = $devises[$deviseId] ?? null; if (!$devise) continue; ?>
                <div class="chiffre">
                    <span class="chiffre__valeur<?= $montant < -0.0005 ? ' tre-negatif' : '' ?>"><?= e(A::montant($montant, $devise)) ?></span>
                    <span class="chiffre__libelle"><?= e(t('tresorerie.total_devise', ['code' => $devise['code'], 'nom' => champ($devise, 'nom')])) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
        <?php if ($reference && $totalReference !== null && count($totaux) > 0): ?>
            <p class="tre-total-general">
                <?php /* Devise locale d'abord, l'équivalent en dollars (référence) ensuite */ ?>
                <?php if ($totalLocale !== null && $locale && (int) $locale['id'] !== (int) $reference['id']): ?>
                    <?= e(t('tresorerie.total_general', ['code' => $locale['code']])) ?>
                    <strong class="montant"><?= e(A::montant($totalLocale, $locale)) ?></strong>
                    <span class="tre-total-general__equivalent montant">≈ <?= e(A::montant($totalReference, $reference)) ?></span>
                <?php else: ?>
                    <?= e(t('tresorerie.total_general', ['code' => $reference['code']])) ?>
                    <strong class="montant"><?= e(A::montant($totalReference, $reference)) ?></strong>
                <?php endif; ?>
            </p>
            <?php if ($sansTaux): ?>
                <p class="aide"><?= e(t('tresorerie.total_sans_taux', ['codes' => implode(', ', $sansTaux)])) ?></p>
            <?php endif; ?>
        <?php endif; ?>
    </section>

    <?php foreach ($groupes as $type => $comptes): ?>
        <section class="bloc tre-groupe" id="type-<?= e($type) ?>" aria-labelledby="tre-groupe-<?= e($type) ?>">
            <div class="tre-groupe__titre">
                <h2 id="tre-groupe-<?= e($type) ?>"><?= View::rendre('gestion/tresorerie/_icone', ['type' => $type]) ?> <?= e(t('tresorerie.type_' . $type)) ?></h2>
                <?php if (!empty($sousTotaux[$type])): ?>
                    <p class="tre-groupe__totaux montant">
                        <?= e(implode(' · ', array_map(static fn ($id, $m) => A::montant($m, $devises[$id]), array_keys($sousTotaux[$type]), $sousTotaux[$type]))) ?>
                    </p>
                <?php endif; ?>
            </div>
            <?php if ($comptes === []): ?>
                <p class="aide"><?= e(t('tresorerie.groupe_vide_' . $type)) ?></p>
            <?php else: ?>
                <ul class="tre-comptes">
                    <?php foreach ($comptes as $compte): ?><?= $ligneCompte($compte) ?><?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <p class="tre-groupe__ajouter"><a class="lien" href="/gestion/tresorerie/comptes/nouveau?type=<?= e($type) ?>">+ <?= e(t('tresorerie.ajouter_compte_' . $type)) ?></a></p>
        </section>
    <?php endforeach; ?>

    <?php if ($inactifs !== []): ?>
        <details class="bloc tre-inactifs">
            <summary><?= e(t('tresorerie.comptes_desactives', ['n' => count($inactifs)])) ?></summary>
            <ul class="tre-comptes">
                <?php foreach ($inactifs as $compte): ?><?= $ligneCompte($compte) ?><?php endforeach; ?>
            </ul>
        </details>
    <?php endif; ?>

    <p class="tre-pied">
        <a class="bouton bouton--secondaire" href="/gestion/tresorerie/comptes/nouveau">+ <?= e(t('tresorerie.nouveau_compte')) ?></a>
        <a class="lien" href="/gestion/devises"><?= e(t('tresorerie.lien_taux')) ?></a>
    </p>
<?php endif; ?>
