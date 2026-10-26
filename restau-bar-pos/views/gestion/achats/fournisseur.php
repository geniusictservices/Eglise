<?php
/**
 * Fiche d'un fournisseur : coordonnées, reste dû par devise et échéances, emballages dus, dernières
 * réceptions et prix d'achat.
 * @var array $f
 * @var ?array $dette
 * @var ?array $emballages solde des emballages
 * @var array<int, array> $catalogueEmballages
 * @var list<array> $receptions
 * @var list<array> $prix
 * @var array<int, array> $devises
 * @var bool $peutModifier
 * @var bool $peutRecevoir
 */

use App\Controllers\Gestion\StockAffichage as S;
use App\Controllers\Gestion\TresorerieAffichage as A;
use App\Core\View;

$montant = static fn ($m, $deviseId): string => isset($devises[(int) $deviseId]) ? A::montant($m, $devises[(int) $deviseId]) : (string) $m;
?>
<?= View::rendre('gestion/stock/_onglets', ['flash' => $flash ?? null]) ?>

<p class="tre-retour"><a class="lien" href="/gestion/achats/fournisseurs">← <?= e(t('achats.fournisseurs_titre')) ?></a></p>
<div class="tre-entete">
    <div>
        <p class="surtitre"><?= e(t('achats.type_' . $f['type'])) ?></p>
        <h1><?= e($f['nom']) ?><?php if (!$f['actif']): ?> <span class="badge badge--erreur"><?= e(t('stock.inactif')) ?></span><?php endif; ?></h1>
        <p class="aide"><?= e(implode(' · ', array_filter([$f['contact'], $f['telephone'] ? afficherTelephone($f['telephone']) : null, $f['email'], $f['adresse']]))) ?></p>
        <p class="aide"><?= e(t('achats.delai_texte', ['n' => (int) $f['delai_paiement']])) ?><?= $f['devise_code'] ? ' · ' . e($f['devise_code']) : '' ?></p>
    </div>
    <div class="tre-entete__actions">
        <?php if ($peutRecevoir): ?><a class="bouton" href="/gestion/achats/receptions/nouvelle?fournisseur=<?= (int) $f['id'] ?>">+ <?= e(t('achats.nouvelle_reception')) ?></a><?php endif; ?>
        <?php if ($peutModifier): ?><a class="bouton bouton--secondaire" href="/gestion/achats/fournisseurs/<?= (int) $f['id'] ?>/modifier"><?= e(t('achats.modifier_fournisseur')) ?></a><?php endif; ?>
    </div>
</div>

<div class="chiffres stk-chiffres">
    <div class="chiffre">
        <span class="chiffre__valeur" data-reste-fournisseur><?php
            $parties = [];
            foreach ($dette['par_devise'] ?? [] as $deviseId => $m) {
                if ($m > 0.0005) {
                    $parties[] = $montant($m, $deviseId);
                }
            }
            echo e($parties ? implode(' + ', $parties) : '0'); ?></span>
        <span class="chiffre__libelle"><?= e(t('achats.reste_du_titre')) ?><?= !empty($dette['en_retard']) ? ' · ' . e(t('achats.n_en_retard', ['n' => $dette['en_retard']])) : '' ?></span>
    </div>
    <div class="chiffre">
        <span class="chiffre__valeur stk-chiffre-texte"><?php
            $liste = [];
            foreach ($emballages['emballages'] ?? [] as $id => $q) {
                $liste[] = S::nombre($q) . ' × ' . (isset($catalogueEmballages[$id]) ? champ($catalogueEmballages[$id], 'nom') : '?');
            }
            echo e($liste ? implode(' · ', $liste) : '0'); ?></span>
        <span class="chiffre__libelle"><?= e(t('achats.emballages_dus')) ?></span>
    </div>
</div>

<?php if ($f['notes']): ?><p class="bloc aide stk-notes"><?= nl2br(e($f['notes'])) ?></p><?php endif; ?>

<section class="bloc" aria-labelledby="f-receptions">
    <h2 id="f-receptions"><?= e(t('achats.dernieres_receptions')) ?></h2>
    <?php if ($receptions === []): ?><p class="aide"><?= e(t('achats.jamais_recu')) ?></p><?php else: ?>
        <div class="tre-defile">
            <table class="tableau stk-table">
                <thead><tr><th scope="col"><?= e(t('stock.col_numero')) ?></th><th scope="col"><?= e(t('stock.lieu')) ?></th><th scope="col" class="nombre"><?= e(t('achats.total')) ?></th><th scope="col"><?= e(t('achats.paiement')) ?></th></tr></thead>
                <tbody>
                    <?php foreach ($receptions as $r): ?>
                        <tr><td><a class="stk-numero" href="/gestion/achats/receptions/<?= (int) $r['id'] ?>"><?= e($r['numero']) ?></a><span class="tre-sous-texte"><?= e(A::dateHeure($r['date_reception'])) ?></span></td>
                            <td><?= e($r['lieu_nom']) ?></td>
                            <td class="nombre"><?= e($montant($r['total'], $r['devise_id'])) ?></td>
                            <td><?= View::rendre('gestion/achats/_etat-paiement', ['r' => $r, 'devises' => $devises]) ?></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php if ($prix !== []): ?>
    <section class="bloc" aria-labelledby="f-prix">
        <h2 id="f-prix"><?= e(t('achats.prix_titre')) ?></h2>
        <div class="tre-defile">
            <table class="tableau stk-table">
                <thead><tr><th scope="col"><?= e(t('stock.col_date')) ?></th><th scope="col"><?= e(t('stock.col_article')) ?></th><th scope="col" class="nombre"><?= e(t('achats.prix_format')) ?></th></tr></thead>
                <tbody>
                    <?php foreach ($prix as $p): ?>
                        <tr><td class="tre-journal__date"><?= e(A::jour($p['date_reception'])) ?></td>
                            <td><?= e(champ($p, 'nom')) ?> <span class="tre-sous-texte"><?= e(champ(['nom_fr' => (string) $p['format_nom_fr'], 'nom_en' => (string) $p['format_nom_en']], 'nom')) ?></span></td>
                            <td class="nombre"><?= e($montant($p['prix_unitaire'], $p['devise_id'])) ?></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>
