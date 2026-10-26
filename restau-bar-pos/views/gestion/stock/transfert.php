<?php
/**
 * Fiche d'un transfert : trajet, lignes envoyées et reçues (écarts), qui a fait quoi ; confirmation de la
 * sortie (côté source), de la réception (côté destination, quantités reçues), annulation.
 * @var array $t
 * @var array<int, array> $articles
 * @var bool $peutSource
 * @var bool $peutDestination
 * @var array<string, string> $erreurs
 * @var array $saisies quantités reçues saisies (renvoi du formulaire)
 * @var string $note
 */

use App\Controllers\Gestion\StockAffichage as S;
use App\Controllers\Gestion\TresorerieAffichage as A;
use App\Core\View;

$jeton = e(App\Core\Session::jetonCsrf());
$badges = ['brouillon' => 'badge--neutre', 'expedie' => 'badge--braise', 'recu' => 'badge--succes', 'annule' => 'badge--erreur'];
$article = static fn (array $l): array => $articles[(int) $l['produit_id']] ?? ['unite_base' => $l['unite_base'], 'conditionnements' => []];
$formatDe = static fn (array $l): array => ['nom_fr' => $l['format_nom_fr'] ?? '', 'nom_en' => $l['format_nom_en'] ?? ''];
?>
<?= View::rendre('gestion/stock/_onglets', ['flash' => $flash ?? null]) ?>

<p class="tre-retour"><a class="lien" href="/gestion/stock/transferts">← <?= e(t('stock.transferts_titre')) ?></a></p>
<div class="tre-entete">
    <div>
        <h1 class="stk-titre-badge"><?= e($t['numero']) ?> <span class="badge <?= $badges[$t['statut']] ?>" data-statut="<?= e($t['statut']) ?>"><?= e(t('stock.statut_transfert_' . $t['statut'])) ?></span></h1>
        <p class="stk-trajet"><strong><?= e($t['source_nom']) ?></strong> <span class="tre-sous-texte"><?= e($t['source_etablissement'] ?? t('stock.central')) ?></span>
            <span aria-hidden="true" class="stk-fleche">→</span><span class="visuellement-cache"><?= e(t('stock.vers')) ?></span>
            <strong><?= e($t['destination_nom']) ?></strong> <span class="tre-sous-texte"><?= e($t['destination_etablissement'] ?? t('stock.central')) ?></span></p>
    </div>
</div>

<?= View::rendre('gestion/tresorerie/_erreurs', ['erreurs' => array_values($erreurs)]) ?>

<div class="stk-deux-colonnes">
    <section class="bloc" aria-labelledby="lignes-titre">
        <h2 id="lignes-titre"><?= e(t('stock.lignes_transfert')) ?></h2>
        <?php $recevoir = $t['statut'] === 'expedie' && $peutDestination; ?>
        <?php if ($recevoir): ?>
            <form method="post" action="/gestion/stock/transferts/<?= (int) $t['id'] ?>/recevoir" id="recevoir" novalidate>
            <input type="hidden" name="csrf" value="<?= $jeton ?>">
            <p class="aide"><?= e(t('stock.recevoir_aide')) ?></p>
        <?php endif; ?>
        <div class="tre-defile">
            <table class="tableau stk-table">
                <thead>
                    <tr>
                        <th scope="col"><?= e(t('stock.col_article')) ?></th>
                        <th scope="col" class="nombre"><?= e(t('stock.col_envoye')) ?></th>
                        <th scope="col" class="nombre"><?= e(t('stock.col_recu')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($t['lignes'] as $i => $l): $a = $article($l); $id = (int) $l['id'];
                        $ecart = $l['quantite_recue_base'] !== null ? round((float) $l['quantite_recue_base'] - (float) $l['quantite_base'], 3) : null; ?>
                        <tr data-ligne-transfert="<?= $id ?>">
                            <td><strong><?= e(champ($l, 'nom')) ?></strong></td>
                            <td class="nombre"><?= e(S::nombre($l['quantite']) . ' ' . S::format($formatDe($l), (float) $l['quantite'])) ?>
                                <span class="tre-sous-texte"><?= e(S::base($l['quantite_base'], $a)) ?></span></td>
                            <td class="nombre">
                                <?php if ($recevoir): ?>
                                    <label class="visuellement-cache" for="recue-<?= $id ?>"><?= e(t('stock.quantite_recue', ['article' => champ($l, 'nom')])) ?></label>
                                    <input class="saisie stk-saisie-courte" id="recue-<?= $id ?>" name="recues[<?= $id ?>]" inputmode="decimal"
                                           value="<?= e(isset($saisies[$id]) && is_string($saisies[$id]) ? $saisies[$id] : S::saisie($l['quantite'])) ?>"<?= isset($erreurs['recue-' . $id]) ? ' aria-invalid="true"' : '' ?>>
                                    <span class="tre-sous-texte"><?= e(S::format($formatDe($l), 2)) ?></span>
                                <?php elseif ($l['quantite_recue_base'] !== null): ?>
                                    <?= e(S::quantite($l['quantite_recue_base'], $a)) ?>
                                    <?php if ($ecart !== null && abs($ecart) > 0.0005): ?><span class="badge badge--erreur" data-ecart="<?= e((string) $ecart) ?>"><?= e(t('stock.ecart_de', ['ecart' => ($ecart > 0 ? '+' : '') . S::quantite($ecart, $a)])) ?></span><?php endif; ?>
                                <?php else: ?>—<?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($recevoir): ?>
            <div class="champ">
                <label for="note_reception"><?= e(t('stock.note_reception')) ?></label>
                <input id="note_reception" name="note_reception" maxlength="255" value="<?= e($note) ?>" placeholder="<?= e(t('stock.note_reception_exemple')) ?>"<?= isset($erreurs['note_reception']) ? ' aria-invalid="true" aria-describedby="erreur-note"' : '' ?>>
                <?php if (isset($erreurs['note_reception'])): ?><p class="champ__erreur" id="erreur-note"><?= e($erreurs['note_reception']) ?></p><?php endif; ?>
            </div>
            <div class="tre-actions"><button class="bouton" type="submit"><?= e(t('stock.confirmer_reception')) ?></button></div>
            </form>
        <?php endif; ?>
        <?php if ($t['note_reception']): ?><p class="stk-note-reception"><strong><?= e(t('stock.note_reception')) ?> :</strong> <?= e($t['note_reception']) ?></p><?php endif; ?>
    </section>

    <section class="bloc" aria-labelledby="suivi-titre">
        <h2 id="suivi-titre"><?= e(t('stock.suivi')) ?></h2>
        <ol class="stk-etapes">
            <li class="stk-etape stk-etape--faite"><strong><?= e(t('stock.etape_cree')) ?></strong><span class="tre-sous-texte"><?= e(($t['createur'] ?? '') . ' · ' . A::dateHeure($t['cree_le'])) ?></span></li>
            <li class="stk-etape<?= $t['expedie_le'] ? ' stk-etape--faite' : '' ?>"><strong><?= e(t('stock.etape_expedie')) ?></strong>
                <span class="tre-sous-texte"><?= $t['expedie_le'] ? e(($t['expediteur'] ?? '') . ' · ' . A::dateHeure($t['expedie_le'])) : e(t('stock.en_attente')) ?></span></li>
            <li class="stk-etape<?= $t['recu_le'] ? ' stk-etape--faite' : '' ?>"><strong><?= e(t('stock.etape_recu')) ?></strong>
                <span class="tre-sous-texte"><?= $t['recu_le'] ? e(($t['receveur'] ?? '') . ' · ' . A::dateHeure($t['recu_le'])) : e(t('stock.en_attente')) ?></span></li>
            <?php if ($t['annule_le']): ?><li class="stk-etape stk-etape--annulee"><strong><?= e(t('stock.etape_annule')) ?></strong><span class="tre-sous-texte"><?= e(($t['annuleur'] ?? '') . ' · ' . A::dateHeure($t['annule_le'])) ?></span></li><?php endif; ?>
        </ol>
        <?php if ($t['notes']): ?><p class="aide"><?= e($t['notes']) ?></p><?php endif; ?>
        <div class="tre-actions">
            <?php if ($t['statut'] === 'brouillon' && $peutSource): ?>
                <form method="post" action="/gestion/stock/transferts/<?= (int) $t['id'] ?>/expedier"><input type="hidden" name="csrf" value="<?= $jeton ?>"><button class="bouton" type="submit"><?= e(t('stock.confirmer_sortie')) ?></button></form>
            <?php endif; ?>
            <?php if (in_array($t['statut'], ['brouillon', 'expedie'], true) && $peutSource): ?>
                <details class="tre-supprimer">
                    <summary class="lien tre-lien-danger"><?= e(t('stock.annuler_transfert')) ?></summary>
                    <p><?= e(t($t['statut'] === 'expedie' ? 'stock.annuler_expedie_texte' : 'stock.annuler_brouillon_texte')) ?></p>
                    <form method="post" action="/gestion/stock/transferts/<?= (int) $t['id'] ?>/annuler"><input type="hidden" name="csrf" value="<?= $jeton ?>"><button class="bouton bouton--danger" type="submit"><?= e(t('stock.annuler_transfert_oui')) ?></button></form>
                </details>
            <?php endif; ?>
        </div>
        <?php if ($t['statut'] === 'expedie' && !$peutDestination): ?><p class="aide"><?= e(t('stock.attente_destination')) ?></p><?php endif; ?>
    </section>
</div>
