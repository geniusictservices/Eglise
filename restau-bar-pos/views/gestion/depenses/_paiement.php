<?php
/**
 * Une ligne de paiement d'une dépense : moyen (grandes puces), compte du moyen, montant.
 * Sert aussi de modèle au JavaScript (index « __i__ »).
 * @var string|int $i index de la ligne
 * @var array{moyen: string, compte_id: ?int, montant: string} $ligne
 * @var array<int, array> $comptes
 * @var array<int, array> $devises
 * @var array<string, string> $erreurs
 */

use App\Core\View;

$prefixe = 'paiements[' . $i . ']';
$idBase = 'paiement-' . $i;
$erreurCompte = $erreurs['paiements-' . $i . '-compte'] ?? null;
$erreurMontant = $erreurs['paiements-' . $i . '-montant'] ?? null;
$compte = $ligne['compte_id'] !== null ? ($comptes[$ligne['compte_id']] ?? null) : null;
$symbole = $compte ? (string) ($devises[(int) $compte['devise_id']]['symbole'] ?? '') : '';
$numero = is_int($i) ? $i + 1 : '__n__';
?>
<fieldset class="tre-paiement" data-paiement>
    <legend class="tre-paiement__titre"><?= e(t('depenses.paiement_n', ['n' => $numero])) ?></legend>

    <div class="tre-moyens tre-moyens--compact" role="radiogroup" aria-label="<?= e(t('depenses.champ_moyen')) ?>">
        <?php foreach (App\Repositories\GestionTresorerieRepository::TYPES as $type): ?>
            <label class="tre-moyen">
                <input type="radio" name="<?= $prefixe ?>[moyen]" value="<?= e($type) ?>" data-moyen <?= $ligne['moyen'] === $type ? 'checked' : '' ?>>
                <span><?= View::rendre('gestion/tresorerie/_icone', ['type' => $type]) ?><?= e(t('tresorerie.type_' . $type)) ?></span>
            </label>
        <?php endforeach; ?>
    </div>

    <div class="tre-paiement__ligne">
        <div class="champ">
            <label for="<?= $idBase ?>-compte"><?= e(t('depenses.champ_compte')) ?></label>
            <select id="<?= $idBase ?>-compte" name="<?= $prefixe ?>[compte_id]" data-compte-paiement
                    <?= $erreurCompte ? 'aria-invalid="true" aria-describedby="' . $idBase . '-erreur-compte"' : '' ?>>
                <option value=""><?= e(t('tresorerie.choisir_compte')) ?></option>
                <?= View::rendre('gestion/tresorerie/_options-comptes', ['comptes' => $comptes, 'devises' => $devises, 'choisi' => $ligne['compte_id']]) ?>
            </select>
            <?php if ($erreurCompte): ?><p class="champ__erreur" id="<?= $idBase ?>-erreur-compte"><?= e($erreurCompte) ?></p><?php endif; ?>
        </div>
        <div class="champ">
            <label for="<?= $idBase ?>-montant"><?= e(t('depenses.champ_montant')) ?></label>
            <div class="tre-montant">
                <input id="<?= $idBase ?>-montant" name="<?= $prefixe ?>[montant]" inputmode="decimal" autocomplete="off" data-montant-paiement
                       value="<?= e($ligne['montant']) ?>"
                       aria-describedby="<?= $idBase ?>-solde<?= $erreurMontant ? ' ' . $idBase . '-erreur-montant' : '' ?>"
                       <?= $erreurMontant ? 'aria-invalid="true"' : '' ?>>
                <span class="tre-montant__devise" data-symbole-paiement><?= e($symbole) ?></span>
            </div>
            <?php if ($erreurMontant): ?><p class="champ__erreur" id="<?= $idBase ?>-erreur-montant"><?= e($erreurMontant) ?></p><?php endif; ?>
        </div>
    </div>
    <p class="champ__aide tre-paiement__solde" id="<?= $idBase ?>-solde" data-solde-paiement aria-live="polite"></p>
    <button type="button" class="lien tre-paiement__retirer" data-retirer hidden><?= e(t('depenses.retirer_paiement')) ?></button>
</fieldset>
