<?php
/**
 * Une ligne « article · format, quantité (et prix) » des transferts, commandes et réceptions.
 * Sert aussi de modèle au JavaScript (index « __i__ »).
 * @var string|int $i
 * @var array{conditionnement_id: ?int, quantite: string, prix?: string, commandee?: string, commande_ligne_id?: ?int} $ligne
 * @var array<int, array> $articles
 * @var array<string, string> $erreurs
 * @var bool $avecPrix
 * @var bool $achat
 * @var string $symbole symbole de la devise des prix
 */

use App\Core\View;

$prefixe = 'lignes[' . $i . ']';
$idBase = 'ligne-' . $i;
$erreurFormat = $erreurs['lignes-' . $i . '-format'] ?? null;
$erreurQuantite = $erreurs['lignes-' . $i . '-quantite'] ?? null;
$erreurPrix = $erreurs['lignes-' . $i . '-prix'] ?? null;
$numero = is_int($i) ? $i + 1 : '__n__';
?>
<fieldset class="stk-ligne<?= !empty($avecPrix) ? ' stk-ligne--prix' : '' ?>" data-ligne>
    <legend class="stk-ligne__titre"><?= e(t('stock.ligne_n', ['n' => $numero])) ?><?php if (($ligne['commandee'] ?? '') !== ''): ?> · <?= e(t('achats.commandee', ['quantite' => $ligne['commandee']])) ?><?php endif; ?></legend>
    <?php if (isset($ligne['commande_ligne_id']) && $ligne['commande_ligne_id'] !== null): ?>
        <input type="hidden" name="<?= $prefixe ?>[commande_ligne_id]" value="<?= (int) $ligne['commande_ligne_id'] ?>">
        <input type="hidden" name="<?= $prefixe ?>[commandee]" value="<?= e((string) ($ligne['commandee'] ?? '')) ?>">
    <?php endif; ?>
    <div class="stk-ligne__champs">
        <div class="champ stk-ligne__format">
            <label for="<?= $idBase ?>-format"><?= e(t('stock.article_format')) ?></label>
            <select id="<?= $idBase ?>-format" name="<?= $prefixe ?>[conditionnement_id]" data-ligne-format<?= $erreurFormat ? ' aria-invalid="true" aria-describedby="' . $idBase . '-erreur-format"' : '' ?>>
                <option value=""><?= e(t('stock.choisir_article')) ?></option>
                <?= View::rendre('gestion/stock/_options-formats', ['articles' => $articles, 'choisi' => $ligne['conditionnement_id'], 'achat' => !empty($achat)]) ?>
            </select>
        </div>
        <div class="champ stk-ligne__quantite">
            <label for="<?= $idBase ?>-quantite"><?= e(t('stock.quantite')) ?></label>
            <input id="<?= $idBase ?>-quantite" name="<?= $prefixe ?>[quantite]" inputmode="decimal" autocomplete="off" value="<?= e($ligne['quantite']) ?>" data-ligne-quantite<?= $erreurQuantite ? ' aria-invalid="true" aria-describedby="' . $idBase . '-erreur-quantite"' : '' ?>>
        </div>
        <?php if (!empty($avecPrix)): ?>
            <div class="champ stk-ligne__prix">
                <label for="<?= $idBase ?>-prix"><?= e(t('achats.prix_format')) ?></label>
                <div class="tre-montant">
                    <input id="<?= $idBase ?>-prix" name="<?= $prefixe ?>[prix]" inputmode="decimal" autocomplete="off" value="<?= e($ligne['prix'] ?? '') ?>" data-ligne-prix<?= $erreurPrix ? ' aria-invalid="true" aria-describedby="' . $idBase . '-erreur-prix"' : '' ?>>
                    <span class="tre-montant__devise" data-symbole><?= e($symbole ?? '') ?></span>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <p class="champ__aide stk-ligne__base" data-ligne-base aria-live="polite"></p>
    <?php if ($erreurFormat): ?><p class="champ__erreur" id="<?= $idBase ?>-erreur-format"><?= e($erreurFormat) ?></p><?php endif; ?>
    <?php if ($erreurQuantite): ?><p class="champ__erreur" id="<?= $idBase ?>-erreur-quantite"><?= e($erreurQuantite) ?></p><?php endif; ?>
    <?php if ($erreurPrix): ?><p class="champ__erreur" id="<?= $idBase ?>-erreur-prix"><?= e($erreurPrix) ?></p><?php endif; ?>
    <button type="button" class="lien stk-ligne__retirer" data-retirer-ligne hidden><?= e(t('stock.retirer_ligne')) ?></button>
</fieldset>
