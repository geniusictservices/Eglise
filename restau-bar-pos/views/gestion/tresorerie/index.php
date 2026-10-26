<?php
/**
 * Vue d'ensemble de la trésorerie : comptes groupés par type (caisse, mobile money, banque), le solde
 * de chaque devise d'un compte, totaux par devise et total général au taux du jour.
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
    $details = array_filter([$compte['operateur'], $compte['numero'], $compte['etablissement_nom'] ?? t('tresorerie.compte_commun')]);
    ob_start(); ?>
    <li>
        <a class="tre-compte" href="/gestion/tresorerie/comptes/<?= (int) $compte['id'] ?>">
            <span class="tre-compte__nom"><?= e($compte['nom']) ?></span>
            <?php if ($details): ?><span class="tre-compte__details"><?= e(implode(' · ', $details)) ?></span><?php endif; ?>
            <span class="tre-compte__soldes">
                <?php foreach ($compte['devises'] as $deviseId => $ligne): $devise = $devises[$deviseId] ?? null; if (!$devise) continue; ?>
                    <span class="tre-compte__solde montant<?= $ligne['solde'] < -0.0005 ? ' tre-negatif' : '' ?>" data-devise="<?= e($devise['code']) ?>">
                        <span class="visuellement-cache"><?= e($devise['code']) ?> :</span><?= e(A::montant($ligne['solde'], $devise)) ?>
                    </span>
                <?php endforeach; ?>
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
        <?= View::rendre('gestion/rapports/_exporter', ['titre' => t('tresorerie.titre'), 'periode' => A::jour(date('Y-m-d'))]) ?>
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
                        <?php $i = 0; foreach ($sousTotaux[$type] as $deviseId => $montant): if (!isset($devises[$deviseId])) continue; ?>
                            <?= $i++ > 0 ? '<span aria-hidden="true">·</span>' : '' ?>
                            <span<?= $montant < -0.0005 ? ' class="tre-negatif"' : '' ?>><?= e(A::montant($montant, $devises[$deviseId])) ?></span>
                        <?php endforeach; ?>
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
