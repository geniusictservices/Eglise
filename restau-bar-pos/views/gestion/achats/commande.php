<?php
/**
 * Fiche d'un bon de commande : lignes (commandé, reçu, reste), réceptions liées, actions
 * (modifier le brouillon, marquer envoyée, recevoir, annuler) et texte à envoyer au fournisseur.
 * @var array $c
 * @var array<int, array> $articles
 * @var array<int, array> $devises
 * @var bool $peutCommander
 * @var bool $peutRecevoir
 */

use App\Controllers\Gestion\StockAffichage as S;
use App\Controllers\Gestion\TresorerieAffichage as A;
use App\Core\View;

$jeton = e(App\Core\Session::jetonCsrf());
$csrf = '<input type="hidden" name="csrf" value="' . $jeton . '">';
$badges = ['brouillon' => 'badge--neutre', 'envoyee' => 'badge--glace', 'partielle' => 'badge--braise', 'recue' => 'badge--succes', 'annulee' => 'badge--erreur'];
$devise = $devises[(int) $c['devise_id']] ?? null;
$formatDe = static fn (array $l): array => ['nom_fr' => (string) $l['format_nom_fr'], 'nom_en' => (string) $l['format_nom_en']];
$total = 0.0;
// Texte du bon, à copier dans un SMS ou un message WhatsApp
$texte = t('achats.bon_texte_tete', ['numero' => $c['numero'], 'lieu' => $c['lieu_nom'] . ($c['etablissement_nom'] ? ' (' . $c['etablissement_nom'] . ')' : '')]);
foreach ($c['lignes'] as $l) {
    $texte .= "\n- " . S::nombre($l['quantite']) . ' ' . S::format($formatDe($l), (float) $l['quantite']) . ' ' . champ($l, 'nom');
}
$ouverte = in_array($c['statut'], ['brouillon', 'envoyee', 'partielle'], true);
?>
<?= View::rendre('gestion/stock/_onglets', ['flash' => $flash ?? null]) ?>

<p class="tre-retour"><a class="lien" href="/gestion/achats/commandes">← <?= e(t('achats.commandes_titre')) ?></a></p>
<div class="tre-entete">
    <div>
        <h1 class="stk-titre-badge"><?= e($c['numero']) ?> <span class="badge <?= $badges[$c['statut']] ?>" data-statut="<?= e($c['statut']) ?>"><?= e(t('achats.statut_' . $c['statut'])) ?></span></h1>
        <p class="aide"><a href="/gestion/achats/fournisseurs/<?= (int) $c['fournisseur_id'] ?>"><?= e($c['fournisseur']) ?></a> · <?= e(t('achats.livraison_a', ['lieu' => $c['lieu_nom'] . ' · ' . ($c['etablissement_nom'] ?? t('stock.central'))])) ?></p>
        <p class="aide"><?= e(t('achats.commandee_le', ['date' => A::jour($c['date_commande'])])) ?><?= $c['date_prevue'] ? ' · ' . e(t('achats.prevue_le', ['date' => A::jour($c['date_prevue'])])) : '' ?><?= $c['auteur'] ? ' · ' . e($c['auteur']) : '' ?></p>
    </div>
    <div class="tre-entete__actions">
        <?php if ($ouverte && $peutRecevoir && $c['statut'] !== 'brouillon'): ?><a class="bouton" href="/gestion/achats/receptions/nouvelle?commande=<?= (int) $c['id'] ?>"><?= e(t('achats.recevoir_commande')) ?></a><?php endif; ?>
        <?php if ($c['statut'] === 'brouillon' && $peutCommander): ?>
            <form method="post" action="/gestion/achats/commandes/<?= (int) $c['id'] ?>/envoyer"><?= $csrf ?><button class="bouton" type="submit"><?= e(t('achats.marquer_envoyee')) ?></button></form>
            <a class="bouton bouton--secondaire" href="/gestion/achats/commandes/<?= (int) $c['id'] ?>/modifier"><?= e(t('achats.modifier')) ?></a>
        <?php endif; ?>
    </div>
</div>

<div class="stk-deux-colonnes">
    <section class="bloc" aria-labelledby="c-lignes">
        <h2 id="c-lignes"><?= e(t('achats.lignes_commande')) ?></h2>
        <div class="tre-defile">
            <table class="tableau stk-table">
                <thead><tr><th scope="col"><?= e(t('stock.col_article')) ?></th><th scope="col" class="nombre"><?= e(t('achats.col_commande')) ?></th><th scope="col" class="nombre"><?= e(t('achats.col_recu')) ?></th><th scope="col" class="nombre"><?= e(t('achats.prix_format')) ?></th></tr></thead>
                <tbody>
                    <?php foreach ($c['lignes'] as $l): $recue = (float) $l['quantite_recue']; $total += (float) $l['quantite'] * (float) $l['prix_unitaire']; ?>
                        <tr data-ligne-commande="<?= (int) $l['id'] ?>">
                            <td><strong><?= e(champ($l, 'nom')) ?></strong></td>
                            <td class="nombre"><?= e(S::nombre($l['quantite']) . ' ' . S::format($formatDe($l), (float) $l['quantite'])) ?></td>
                            <td class="nombre"><?= e(S::nombre($recue)) ?><?php if ($recue > 0 && abs($recue - (float) $l['quantite']) > 0.0005): ?> <span class="badge badge--braise"><?= e(t('achats.reste_a_recevoir', ['n' => S::nombre(max(0, (float) $l['quantite'] - $recue))])) ?></span><?php endif; ?></td>
                            <td class="nombre"><?= $l['prix_unitaire'] !== null && $devise ? e(A::montant($l['prix_unitaire'], $devise)) : '—' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <?php if ($total > 0 && $devise): ?><tfoot><tr><th scope="row" colspan="3"><?= e(t('achats.montant_prevu')) ?></th><td class="nombre"><strong><?= e(A::montant($total, $devise)) ?></strong></td></tr></tfoot><?php endif; ?>
            </table>
        </div>
        <?php if ($c['notes']): ?><p class="aide"><?= nl2br(e($c['notes'])) ?></p><?php endif; ?>
    </section>
    <div>
        <section class="bloc" aria-labelledby="c-texte">
            <h2 id="c-texte"><?= e(t('achats.bon_a_envoyer')) ?></h2>
            <label class="visuellement-cache" for="bon-texte"><?= e(t('achats.bon_a_envoyer')) ?></label>
            <textarea id="bon-texte" class="saisie stk-bon" rows="6" readonly><?= e($texte) ?></textarea>
            <p class="aide"><?= e(t('achats.bon_aide')) ?></p>
        </section>
        <?php if ($c['receptions'] !== []): ?>
            <section class="bloc" aria-labelledby="c-receptions">
                <h2 id="c-receptions"><?= e(t('achats.receptions_liees')) ?></h2>
                <ul class="stk-liste-lignes">
                    <?php foreach ($c['receptions'] as $r): ?><li><a href="/gestion/achats/receptions/<?= (int) $r['id'] ?>"><?= e($r['numero']) ?></a><span><?= e(A::dateHeure($r['date_reception'])) ?></span></li><?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>
        <?php if ($ouverte && $peutCommander): ?>
            <section class="bloc">
                <details class="tre-supprimer">
                    <summary class="lien tre-lien-danger"><?= e(t('achats.annuler_commande')) ?></summary>
                    <p><?= e(t('achats.annuler_commande_texte')) ?></p>
                    <form method="post" action="/gestion/achats/commandes/<?= (int) $c['id'] ?>/annuler"><?= $csrf ?><button class="bouton bouton--danger" type="submit"><?= e(t('achats.annuler_commande_oui')) ?></button></form>
                </details>
            </section>
        <?php endif; ?>
    </div>
</div>
