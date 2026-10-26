<?php
/**
 * Fiche d'une réception : lignes (commandé, reçu, écart, prix), emballages, justificatif, paiements
 * (compte + devise, part couverte), reste dû et échéance ; formulaire de paiement (achats.payer).
 * @var array $r
 * @var array<int, array> $articles
 * @var array<int, array> $devises
 * @var array<int, array> $comptes
 * @var array<int, array> $devisesTresorerie
 * @var bool $peutPayer
 * @var bool $voirComptes
 * @var list<array> $paiements saisies du formulaire de paiement
 * @var array<string, string> $erreurs
 * @var string[] $avertissements
 * @var int $maxPaiements
 */

use App\Controllers\Gestion\StockAffichage as S;
use App\Controllers\Gestion\TresorerieAffichage as A;
use App\Core\View;

$jeton = e(App\Core\Session::jetonCsrf());
$devise = $devises[(int) $r['devise_id']] ?? null;
$m = static fn ($montant, $deviseId = null): string => isset($devises[(int) ($deviseId ?? $r['devise_id'])]) ? A::montant($montant, $devises[(int) ($deviseId ?? $r['devise_id'])]) : (string) $montant;
$article = static fn (array $l): array => $articles[(int) $l['produit_id']] ?? ['unite_base' => $l['unite_base'], 'conditionnements' => []];
$formatDe = static fn (array $l): array => ['nom_fr' => (string) $l['format_nom_fr'], 'nom_en' => (string) $l['format_nom_en']];
$devisesJs = array_map([A::class, 'deviseJs'], $devisesTresorerie);
// Paiements regroupés (un paiement = une ou plusieurs lignes)
$groupes = [];
foreach ($r['paiements'] as $p) {
    $groupes[(int) $p['paiement_id']][] = $p;
}
?>
<?= View::rendre('gestion/stock/_onglets', ['flash' => $flash ?? null]) ?>

<p class="tre-retour"><a class="lien" href="/gestion/achats/receptions">← <?= e(t('achats.receptions_titre')) ?></a></p>
<div class="tre-entete">
    <div>
        <h1 class="stk-titre-badge"><?= e($r['numero']) ?> <?= View::rendre('gestion/achats/_etat-paiement', ['r' => $r, 'devises' => $devises]) ?></h1>
        <p class="aide"><a href="/gestion/achats/fournisseurs/<?= (int) $r['fournisseur_id'] ?>"><?= e($r['fournisseur']) ?></a> · <?= e($r['lieu_nom'] . ' · ' . ($r['etablissement_nom'] ?? t('stock.central'))) ?> · <?= e(A::dateHeure($r['date_reception'])) ?></p>
        <p class="aide"><?= $r['sans_facture'] ? e(t('achats.sans_facture_court')) : e(t('achats.facture_n', ['numero' => (string) $r['facture_numero']])) ?>
            <?php if ($r['commande_numero']): ?> · <a href="/gestion/achats/commandes/<?= (int) $r['commande_id'] ?>"><?= e($r['commande_numero']) ?></a><?php endif; ?>
            · <?= e(t('achats.recu_par', ['nom' => (string) $r['auteur']])) ?></p>
    </div>
</div>

<div class="chiffres stk-chiffres">
    <div class="chiffre"><span class="chiffre__valeur" data-total-reception><?= e($m($r['total'])) ?></span><span class="chiffre__libelle"><?= e(t('achats.total')) ?><?= (float) $r['montant_consigne'] > 0 ? ' · ' . e(t('achats.dont_consigne', ['montant' => $m($r['montant_consigne'])])) : '' ?></span></div>
    <div class="chiffre"><span class="chiffre__valeur" data-paye><?= e($m($r['paye'])) ?></span><span class="chiffre__libelle"><?= e(t('achats.deja_paye')) ?></span></div>
    <div class="chiffre"><span class="chiffre__valeur<?= $r['reste'] > 0 ? ' tre-alerte-texte' : '' ?>" data-reste><?= e($m($r['reste'])) ?></span><span class="chiffre__libelle"><?= e(t('achats.reste_a_payer')) ?><?= $r['echeance'] ? ' · ' . e(t('achats.echeance_le', ['date' => A::jour($r['echeance'])])) : '' ?></span></div>
</div>

<?= View::rendre('gestion/tresorerie/_erreurs', ['erreurs' => array_values($erreurs)]) ?>

<div class="stk-deux-colonnes">
    <div>
        <section class="bloc" aria-labelledby="r-lignes">
            <h2 id="r-lignes"><?= e(t('achats.lignes_reception')) ?></h2>
            <div class="tre-defile">
                <table class="tableau stk-table">
                    <thead><tr><th scope="col"><?= e(t('stock.col_article')) ?></th><th scope="col" class="nombre"><?= e(t('achats.col_recu')) ?></th><th scope="col" class="nombre"><?= e(t('achats.prix_format')) ?></th><th scope="col" class="nombre"><?= e(t('achats.montant')) ?></th></tr></thead>
                    <tbody>
                        <?php foreach ($r['lignes'] as $l): $a = $article($l);
                            $ecart = $l['quantite_commandee'] !== null ? round((float) $l['quantite'] - (float) $l['quantite_commandee'], 3) : null; ?>
                            <tr data-ligne-reception="<?= (int) $l['id'] ?>">
                                <td><strong><?= e(champ($l, 'nom')) ?></strong>
                                    <?php if ($l['quantite_commandee'] !== null): ?><span class="tre-sous-texte"><?= e(t('achats.commandee', ['quantite' => S::nombre($l['quantite_commandee'])])) ?></span><?php endif; ?></td>
                                <td class="nombre"><?= e(S::nombre($l['quantite']) . ' ' . S::format($formatDe($l), (float) $l['quantite'])) ?>
                                    <span class="tre-sous-texte"><?= e(S::base($l['quantite_base'], $a)) ?></span>
                                    <?php if ($ecart !== null && abs($ecart) > 0.0005): ?><span class="badge badge--erreur" data-ecart="<?= e((string) $ecart) ?>"><?= e(t('stock.ecart_de', ['ecart' => ($ecart > 0 ? '+' : '') . S::nombre($ecart)])) ?></span><?php endif; ?></td>
                                <td class="nombre"><?= e($m($l['prix_unitaire'])) ?></td>
                                <td class="nombre"><strong><?= e($m($l['montant'])) ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <?php if ($r['emballages'] !== []): ?>
            <section class="bloc" aria-labelledby="r-emb">
                <h2 id="r-emb"><?= e(t('achats.section_emballages')) ?></h2>
                <ul class="stk-liste-lignes">
                    <?php foreach ($r['emballages'] as $e): ?>
                        <li><span><span class="badge badge--neutre"><?= e(t('emballages.type_' . $e['type'])) ?></span> <?= e(champ($e, 'nom')) ?></span>
                            <span><?= e(S::nombre(abs((float) $e['quantite']))) ?><?= $e['valeur'] !== null ? ' · ' . e($m($e['valeur'], $e['devise_id'])) : '' ?></span></li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>
        <?php if ($r['justificatif'] || $r['notes']): ?>
            <section class="bloc" aria-labelledby="r-just">
                <h2 id="r-just"><?= e(t('achats.justificatif')) ?></h2>
                <?php if ($r['justificatif'] && ($url = urlImageSure($r['justificatif']))): ?><a href="<?= e($url) ?>" target="_blank" rel="noopener"><img class="stk-justificatif" src="<?= e($url) ?>" alt="<?= e(t('achats.justificatif_alt', ['numero' => $r['numero']])) ?>" loading="lazy"></a><?php endif; ?>
                <?php if ($r['notes']): ?><p><?= nl2br(e($r['notes'])) ?></p><?php endif; ?>
            </section>
        <?php endif; ?>
    </div>

    <div>
        <section class="bloc" aria-labelledby="r-paiements">
            <h2 id="r-paiements"><?= e(t('achats.paiements')) ?></h2>
            <?php if ($groupes === []): ?><p class="aide"><?= e(t('achats.aucun_paiement')) ?></p><?php endif; ?>
            <?php foreach ($groupes as $pid => $lignesPaiement): $premier = $lignesPaiement[0]; ?>
                <div class="stk-paiement" data-paiement-fournisseur="<?= (int) $pid ?>">
                    <p class="stk-paiement__tete"><strong><?= e(A::dateHeure($premier['date_paiement'])) ?></strong> <span class="tre-sous-texte"><?= e((string) $premier['auteur']) ?></span></p>
                    <ul class="stk-liste-lignes">
                        <?php foreach ($lignesPaiement as $p): ?>
                            <li><span><?= View::rendre('gestion/tresorerie/_icone', ['type' => $p['compte_type']]) ?>
                                    <?php if ($voirComptes): ?><a href="/gestion/tresorerie/comptes/<?= (int) $p['compte_id'] ?>"><?= e($p['compte_nom']) ?></a><?php else: ?><?= e($p['compte_nom']) ?><?php endif; ?></span>
                                <span class="montant"><strong><?= e($m($p['montant'], $p['devise_id'])) ?></strong><?php if ((int) $p['devise_id'] !== (int) $r['devise_id']): ?> <span class="tre-sous-texte">= <?= e($m($p['contre_valeur'])) ?></span><?php endif; ?></span></li>
                        <?php endforeach; ?>
                    </ul>
                    <?php if ($premier['notes']): ?><p class="aide"><?= e($premier['notes']) ?></p><?php endif; ?>
                    <?php if ($peutPayer): ?>
                        <details class="tre-supprimer">
                            <summary class="lien tre-lien-danger"><?= e(t('achats.supprimer_paiement')) ?></summary>
                            <p><?= e(t('achats.supprimer_paiement_texte')) ?></p>
                            <form method="post" action="/gestion/achats/paiements/<?= (int) $pid ?>/supprimer"><input type="hidden" name="csrf" value="<?= $jeton ?>"><button class="bouton bouton--danger" type="submit"><?= e(t('achats.supprimer_paiement_oui')) ?></button></form>
                        </details>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </section>

        <?php if ($peutPayer && $r['reste'] > 0.0005): ?>
            <section class="bloc" id="payer" aria-labelledby="r-payer">
                <h2 id="r-payer"><?= e(t('achats.payer_titre', ['montant' => $m($r['reste'])])) ?></h2>
                <p class="aide"><?= e(t('achats.paiement_aide')) ?></p>
                <form method="post" action="/gestion/achats/receptions/<?= (int) $r['id'] ?>/payer" novalidate data-formulaire-paiements
                      data-devises="<?= e((string) json_encode($devisesJs)) ?>" data-max-paiements="<?= (int) $maxPaiements ?>"
                      data-texte-solde="<?= e(t('transferts.solde_js')) ?>" data-texte-decouvert="<?= e(t('transferts.decouvert_js')) ?>">
                    <input type="hidden" name="csrf" value="<?= $jeton ?>">
                    <button type="submit" class="visuellement-cache" tabindex="-1" aria-hidden="true"><?= e(t('achats.enregistrer_paiement')) ?></button>
                    <?= View::rendre('gestion/tresorerie/_avertissements', ['avertissements' => $avertissements]) ?>
                    <div class="tre-paiements" data-paiements>
                        <?php foreach (array_values($paiements) as $i => $ligne): ?>
                            <?= View::rendre('gestion/depenses/_paiement', ['i' => $i, 'ligne' => $ligne, 'comptes' => $comptes, 'devises' => $devisesTresorerie, 'erreurs' => $erreurs]) ?>
                        <?php endforeach; ?>
                    </div>
                    <template data-modele-paiement>
                        <?= View::rendre('gestion/depenses/_paiement', ['i' => '__i__', 'ligne' => ['compte_id' => null, 'devise_id' => null, 'montant' => ''], 'comptes' => $comptes, 'devises' => $devisesTresorerie, 'erreurs' => []]) ?>
                    </template>
                    <button class="bouton bouton--secondaire tre-ajouter-paiement" type="submit" name="action" value="ajouter_paiement" formnovalidate data-ajouter-paiement>+ <?= e(t('depenses.ajouter_paiement')) ?></button>
                    <div class="tre-paire stk-paire-paiement">
                        <div class="champ"><label for="date-paiement"><?= e(t('tresorerie.champ_date')) ?> <span class="tre-facultatif"><?= e(t('tresorerie.facultatif')) ?></span></label><input type="datetime-local" id="date-paiement" name="date" value="<?= e(is_string($_POST['date'] ?? null) ? $_POST['date'] : '') ?>"></div>
                        <div class="champ"><label for="notes-paiement"><?= e(t('stock.notes')) ?> <span class="tre-facultatif"><?= e(t('tresorerie.facultatif')) ?></span></label><input id="notes-paiement" name="notes" maxlength="255" value="<?= e(is_string($_POST['notes'] ?? null) ? $_POST['notes'] : '') ?>"></div>
                    </div>
                    <div class="tre-actions"><button class="bouton" type="submit"><?= e(t('achats.enregistrer_paiement')) ?></button></div>
                </form>
            </section>
        <?php endif; ?>
    </div>
</div>
<script src="/assets/js/gestion-stock.js" defer></script>
