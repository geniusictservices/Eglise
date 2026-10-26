<?php
/**
 * Une ligne de paiement d'une dépense : compte (groupés par type : caisse, mobile money, banque),
 * devise (parmi celles du compte) et montant. Sert aussi de modèle au JavaScript (index « __i__ »).
 * @var string|int $i index de la ligne
 * @var array{compte_id: ?int, devise_id: ?int, montant: string} $ligne
 * @var array<int, array> $comptes
 * @var array<int, array> $devises
 * @var array<string, string> $erreurs
 */

use App\Core\View;

$prefixe = 'paiements[' . $i . ']';
$idBase = 'paiement-' . $i;
$erreurCompte = $erreurs['paiements-' . $i . '-compte'] ?? null;
$erreurDevise = $erreurs['paiements-' . $i . '-devise'] ?? null;
$erreurMontant = $erreurs['paiements-' . $i . '-montant'] ?? null;
$compte = $ligne['compte_id'] !== null ? ($comptes[$ligne['compte_id']] ?? null) : null;
$devise = $ligne['devise_id'] !== null ? ($devises[$ligne['devise_id']] ?? null) : null;
$numero = is_int($i) ? $i + 1 : '__n__';
?>
<fieldset class="tre-paiement" data-paiement>
    <legend class="tre-paiement__titre"><?= e(t('depenses.paiement_n', ['n' => $numero])) ?></legend>

    <div class="tre-paiement__ligne">
        <div class="champ tre-paiement__compte">
            <label for="<?= $idBase ?>-compte"><?= e(t('depenses.champ_compte')) ?></label>
            <select id="<?= $idBase ?>-compte" name="<?= $prefixe ?>[compte_id]" data-compte-paiement
                    <?= $erreurCompte ? 'aria-invalid="true" aria-describedby="' . $idBase . '-erreur-compte"' : '' ?>>
                <option value=""><?= e(t('tresorerie.choisir_compte')) ?></option>
                <?= View::rendre('gestion/tresorerie/_options-comptes', ['comptes' => $comptes, 'devises' => $devises, 'choisi' => $ligne['compte_id']]) ?>
            </select>
        </div>
        <div class="champ tre-champ-devise">
            <label for="<?= $idBase ?>-devise"><?= e(t('depenses.champ_devise')) ?></label>
            <select id="<?= $idBase ?>-devise" name="<?= $prefixe ?>[devise_id]" data-devise-paiement
                    <?= $erreurDevise ? 'aria-invalid="true" aria-describedby="' . $idBase . '-erreur-devise"' : '' ?>>
                <?= View::rendre('gestion/tresorerie/_options-devises', ['compte' => $compte, 'devises' => $devises, 'choisie' => $ligne['devise_id']]) ?>
            </select>
        </div>
        <div class="champ tre-paiement__montant">
            <label for="<?= $idBase ?>-montant"><?= e(t('depenses.champ_montant')) ?></label>
            <div class="tre-montant">
                <input id="<?= $idBase ?>-montant" name="<?= $prefixe ?>[montant]" inputmode="decimal" autocomplete="off" data-montant-paiement
                       value="<?= e($ligne['montant']) ?>"
                       aria-describedby="<?= $idBase ?>-solde<?= $erreurMontant ? ' ' . $idBase . '-erreur-montant' : '' ?>"
                       <?= $erreurMontant ? 'aria-invalid="true"' : '' ?>>
                <span class="tre-montant__devise" data-symbole-paiement><?= e($devise['symbole'] ?? '') ?></span>
            </div>
        </div>
    </div>
    <?php if ($erreurCompte): ?><p class="champ__erreur" id="<?= $idBase ?>-erreur-compte"><?= e($erreurCompte) ?></p><?php endif; ?>
    <?php if ($erreurDevise): ?><p class="champ__erreur" id="<?= $idBase ?>-erreur-devise"><?= e($erreurDevise) ?></p><?php endif; ?>
    <?php if ($erreurMontant): ?><p class="champ__erreur" id="<?= $idBase ?>-erreur-montant"><?= e($erreurMontant) ?></p><?php endif; ?>
    <p class="champ__aide tre-paiement__solde" id="<?= $idBase ?>-solde" data-solde-paiement aria-live="polite"></p>
    <button type="button" class="lien tre-paiement__retirer" data-retirer hidden><?= e(t('depenses.retirer_paiement')) ?></button>
</fieldset>
