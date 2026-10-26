<?php
/**
 * Fiche d'une vente : le reçu (views/caisse/_recu.php, à imprimer en 80 mm), qui a vendu, où et sur quel appareil,
 * les paiements avec leur compte, les mouvements de trésorerie, et l'annulation (motif obligatoire).
 * @var ?string $flash
 * @var array $fiche vente, lignes, paiements, totaux, devises, etablissement, extra, mouvements
 * @var array<int, array> $devises
 * @var bool $peutAnnuler
 * @var bool $voirComptes
 * @var array<string, string> $erreurs
 * @var string $motif
 */

use App\Controllers\Gestion\TresorerieAffichage as A;
use App\Core\View;

$vente = $fiche['vente'];
$extra = $fiche['extra'];
$annulee = $vente['statut'] === 'annulee';
$montant = static fn ($m, $deviseId): string => isset($devises[(int) $deviseId]) ? A::montant($m, $devises[(int) $deviseId]) : (string) $m;
$type = static fn (string $t): string => t('tresorerie.type_' . $t);
$reference = null;
foreach ($devises as $d) {
    if (!empty($d['est_reference'])) {
        $reference = $d;
        break;
    }
}
$jeton = e(App\Core\Session::jetonCsrf());
$articles = array_sum(array_map(static fn (array $l) => (int) $l['quantite'], $fiche['lignes']));
?>
<link rel="stylesheet" href="/assets/css/gestion-tresorerie.css">
<link rel="stylesheet" href="/assets/css/gestion-ventes.css">

<p class="tre-retour ven-retour"><a class="lien" href="/gestion/ventes">← <?= e(t('gventes.titre')) ?></a></p>

<div class="tre-entete ven-entete">
    <div>
        <p class="surtitre"><?= e($vente['etablissement']) ?></p>
        <h1>
            <?= e(t('gventes.fiche_titre', ['numero' => $vente['numero']])) ?>
            <?php if ($annulee): ?>
                <span class="badge badge--erreur"><?= e(t('gventes.badge_annulee')) ?></span>
            <?php else: ?>
                <span class="badge badge--succes"><?= e(t('gventes.badge_payee')) ?></span>
            <?php endif; ?>
        </h1>
        <p class="aide"><?= e(A::dateHeure($vente['cree_le'])) ?> · <?= e(t($articles > 1 ? 'gventes.n_articles' : 'gventes.un_article', ['n' => $articles])) ?></p>
    </div>
    <div class="tre-entete__actions">
        <button class="bouton bouton--secondaire" type="button" data-imprimer hidden><?= e(t('gventes.imprimer_recu')) ?></button>
    </div>
</div>

<?php if ($flash): ?>
    <p class="alerte" role="status"><?= e($flash) ?></p>
<?php endif; ?>
<?php if (isset($erreurs['annulation'])): ?>
    <p class="alerte alerte--erreur" role="alert"><?= e($erreurs['annulation']) ?></p>
<?php endif; ?>

<div class="ven-detail">
    <div class="ven-detail__recu">
        <?= View::rendre('caisse/_recu', $fiche) ?>
    </div>

    <div class="ven-detail__infos">
        <?php if ($annulee): ?>
            <section class="ven-annulation-faite" aria-labelledby="ven-annulation-titre">
                <h2 id="ven-annulation-titre"><?= e(t('gventes.annulee_titre')) ?></h2>
                <dl class="ven-infos">
                    <div><dt><?= e(t('gventes.annulee_le')) ?></dt><dd><?= e(A::dateHeure($vente['annulee_le'])) ?></dd></div>
                    <div><dt><?= e(t('gventes.annulee_par')) ?></dt><dd><?= e($extra['annulee_par_nom'] ?? t('gventes.vendeur_inconnu')) ?></dd></div>
                    <div><dt><?= e(t('gventes.motif')) ?></dt><dd class="ven-motif"><?= e((string) $vente['motif_annulation']) ?></dd></div>
                </dl>
                <p class="aide"><?= e(t('gventes.annulee_tresorerie')) ?></p>
            </section>
        <?php endif; ?>

        <section class="bloc" aria-labelledby="ven-vente-titre">
            <h2 id="ven-vente-titre"><?= e(t('gventes.la_vente')) ?></h2>
            <dl class="ven-infos">
                <div>
                    <dt><?= e(t('gventes.vendeur')) ?></dt>
                    <dd><span class="ven-vendeur"><?= View::rendre('partials/_avatar', ['nom' => $vente['vendeur'] ?? '?', 'photo' => $extra['vendeur_avatar'], 'classe' => 'avatar--petit']) ?><span><?= e($vente['vendeur'] ?? t('gventes.vendeur_inconnu')) ?></span></span></dd>
                </div>
                <div><dt><?= e(t('gestion.etablissement')) ?></dt><dd><?= e($vente['etablissement']) ?></dd></div>
                <div><dt><?= e(t('gventes.zone')) ?></dt><dd><?= e($vente['zone_nom_fr'] !== null ? champ($vente, 'zone_nom') : t('gventes.sans_zone')) ?></dd></div>
                <?php if ($extra['grille_nom_fr'] !== null): ?>
                    <div><dt><?= e(t('gventes.grille')) ?></dt><dd><?= e(champ($extra, 'grille_nom')) ?></dd></div>
                <?php endif; ?>
                <div><dt><?= e(t('gventes.appareil')) ?></dt><dd><?= e($extra['appareil'] ?? t('gventes.appareil_personnel')) ?></dd></div>
                <div><dt><?= e(t('gventes.col_total')) ?></dt><dd class="montant"><strong><?= e($montant($vente['total'], $vente['devise_id'])) ?></strong></dd></div>
                <?php if ($reference && $vente['encaisse_reference'] !== null): ?>
                    <div><dt><?= e(t('gventes.equivalent_encaisse', ['code' => $reference['code']])) ?></dt><dd class="montant">≈ <?= e(A::montant($vente['encaisse_reference'], $reference)) ?></dd></div>
                <?php endif; ?>
            </dl>
        </section>

        <section class="bloc" aria-labelledby="ven-paiements-titre">
            <h2 id="ven-paiements-titre"><?= e(t('gventes.paiements')) ?></h2>
            <ul class="ven-paiements">
                <?php foreach ($fiche['paiements'] as $p): ?>
                    <li>
                        <span class="ven-paiements__compte">
                            <?= View::rendre('gestion/tresorerie/_icone', ['type' => $p['compte_type']]) ?>
                            <span>
                                <?php if ($voirComptes): ?>
                                    <a href="/gestion/tresorerie/comptes/<?= (int) $p['compte_id'] ?>"><?= e($p['compte']) ?></a>
                                <?php else: ?>
                                    <?= e($p['compte']) ?>
                                <?php endif; ?>
                                <span class="tre-sous-texte"><?= e($type($p['compte_type'])) ?></span>
                            </span>
                        </span>
                        <span class="ven-paiements__montant">
                            <strong class="montant"><?= e($montant($p['recu'], $p['devise_id'])) ?></strong>
                            <?php if ($reference && $p['contre_valeur'] !== null && (int) $p['devise_id'] !== (int) $reference['id']): ?>
                                <span class="tre-sous-texte montant">≈ <?= e(A::montant($p['contre_valeur'], $reference)) ?></span>
                            <?php endif; ?>
                        </span>
                    </li>
                <?php endforeach; ?>
                <?php if ((float) $vente['rendu'] > 0): ?>
                    <li class="ven-paiements__rendu">
                        <span class="ven-paiements__compte"><span><?= e(t('gventes.monnaie_rendue')) ?></span></span>
                        <span class="ven-paiements__montant"><strong class="montant">−<?= e($montant($vente['rendu'], $vente['rendu_devise_id'])) ?></strong></span>
                    </li>
                <?php endif; ?>
            </ul>
        </section>

        <?php if ($fiche['mouvements'] !== []): ?>
            <section class="bloc" aria-labelledby="ven-mouvements-titre">
                <h2 id="ven-mouvements-titre"><?= e(t('gventes.mouvements')) ?></h2>
                <p class="aide"><?= e(t('gventes.mouvements_aide')) ?></p>
                <ul class="ven-mouvements">
                    <?php foreach ($fiche['mouvements'] as $m): $entree = $m['sens'] === 'entree'; ?>
                        <li data-mouvement="<?= (int) $m['id'] ?>">
                            <span>
                                <?= e($m['compte_nom']) ?>
                                <span class="tre-sous-texte"><?= e(A::dateHeure($m['date_operation'])) ?> · <?= e((string) $m['libelle']) ?></span>
                            </span>
                            <strong class="montant<?= $entree ? ' tre-entree' : ' tre-negatif' ?>"><?= $entree ? '+' : '−' ?><?= e($montant($m['montant'], $m['devise_id'])) ?></strong>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>

        <?php if ($peutAnnuler): ?>
            <section class="bloc ven-annuler" aria-labelledby="ven-annuler-titre" id="annuler">
                <h2 id="ven-annuler-titre"><?= e(t('gventes.annuler_titre')) ?></h2>
                <p class="aide"><?= e(t('gventes.annuler_aide')) ?></p>
                <form method="post" action="/gestion/ventes/<?= (int) $vente['id'] ?>/annuler" class="ven-annuler__form" novalidate>
                    <input type="hidden" name="csrf" value="<?= $jeton ?>">
                    <div class="champ<?= isset($erreurs['motif']) ? ' champ--erreur' : '' ?>">
                        <label for="motif"><?= e(t('gventes.motif')) ?></label>
                        <textarea id="motif" name="motif" rows="3" maxlength="255" required
                                  aria-describedby="motif-aide<?= isset($erreurs['motif']) ? ' motif-erreur' : '' ?>"<?= isset($erreurs['motif']) ? ' aria-invalid="true"' : '' ?>><?= e($motif) ?></textarea>
                        <span class="champ__aide" id="motif-aide"><?= e(t('gventes.motif_aide')) ?></span>
                        <?php if (isset($erreurs['motif'])): ?><span class="champ__erreur" id="motif-erreur"><?= e($erreurs['motif']) ?></span><?php endif; ?>
                    </div>
                    <div class="champ<?= isset($erreurs['confirme']) ? ' champ--erreur' : '' ?>">
                        <label class="coche">
                            <input type="checkbox" name="confirme" value="1" required<?= isset($erreurs['confirme']) ? ' aria-invalid="true" aria-describedby="confirme-erreur"' : '' ?>>
                            <span><?= e(t('gventes.confirmer', ['numero' => $vente['numero'], 'montant' => $montant($vente['total'], $vente['devise_id'])])) ?></span>
                        </label>
                        <?php if (isset($erreurs['confirme'])): ?><span class="champ__erreur" id="confirme-erreur"><?= e($erreurs['confirme']) ?></span><?php endif; ?>
                    </div>
                    <div class="tre-actions">
                        <button class="bouton bouton--danger" type="submit"><?= e(t('gventes.annuler_bouton')) ?></button>
                    </div>
                </form>
            </section>
        <?php endif; ?>
    </div>
</div>

<script src="/assets/js/gestion-ventes.js" defer></script>
