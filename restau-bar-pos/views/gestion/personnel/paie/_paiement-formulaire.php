<?php
/**
 * Formulaire de paiement (salaire ou avance) : lignes compte + devise + montant, comme les dépenses
 * (même JavaScript : gestion-tresorerie.js, qui cherche #formulaire-depense), avertissement de découvert.
 * @var string $action
 * @var list<array> $lignes
 * @var array<int, array> $comptes
 * @var array<int, array> $devises
 * @var ?array $reference
 * @var array<string, string> $erreurs
 * @var list<string> $avertissements
 * @var string $boutonTexte
 * @var string $champsAvant HTML des champs placés avant les paiements (employé, date…)
 */

use App\Controllers\Gestion\PersonnelPaiementSaisie;
use App\Controllers\Gestion\TresorerieAffichage as A;
use App\Core\View;

$erreurChamp = static fn (string $champ): string => isset($erreurs[$champ]) ? '<p class="champ__erreur" id="erreur-' . $champ . '">' . e($erreurs[$champ]) . '</p>' : '';
?>
<form method="post" action="<?= e($action) ?>" class="tre-formulaire" id="formulaire-depense" novalidate
      data-devises="<?= e((string) json_encode(array_map([A::class, 'deviseJs'], $devises))) ?>"
      data-ordre="<?= e(implode(',', array_keys($devises))) ?>"
      data-reference="<?= $reference ? (int) $reference['id'] : '' ?>"
      data-max="<?= PersonnelPaiementSaisie::MAX_LIGNES ?>"
      data-texte-solde="<?= e(t('transferts.solde_js')) ?>"
      data-texte-decouvert="<?= e(t('transferts.decouvert_js')) ?>">
    <input type="hidden" name="csrf" value="<?= e(App\Core\Session::jetonCsrf()) ?>">
    <button type="submit" class="visuellement-cache" tabindex="-1" aria-hidden="true"><?= e($boutonTexte) ?></button>
    <?= View::rendre('gestion/tresorerie/_avertissements', ['avertissements' => $avertissements]) ?>
    <?= $champsAvant ?>
    <?= $erreurChamp('paiements') ?>
    <div class="tre-paiements" data-paiements>
        <?php foreach (array_values($lignes) as $i => $ligne): ?>
            <?= View::rendre('gestion/depenses/_paiement', ['i' => $i, 'ligne' => $ligne, 'comptes' => $comptes, 'devises' => $devises, 'erreurs' => $erreurs]) ?>
        <?php endforeach; ?>
    </div>
    <template id="modele-paiement">
        <?= View::rendre('gestion/depenses/_paiement', ['i' => '__i__', 'ligne' => PersonnelPaiementSaisie::LIGNE_VIDE, 'comptes' => $comptes, 'devises' => $devises, 'erreurs' => []]) ?>
    </template>
    <?php if (count($lignes) < PersonnelPaiementSaisie::MAX_LIGNES): ?>
        <button class="bouton bouton--secondaire tre-ajouter-paiement" type="submit" name="action" value="ajouter_ligne" formnovalidate data-ajouter-paiement>+ <?= e(t('depenses.ajouter_paiement')) ?></button>
    <?php endif; ?>
    <div class="tre-resume" data-resume aria-live="polite" hidden>
        <span class="tre-resume__libelle"><?= e(t('depenses.total')) ?></span>
        <strong class="tre-resume__montants montant" data-resume-montants></strong>
        <span class="tre-resume__equivalent montant" data-resume-equivalent></span>
    </div>
    <div class="tre-actions"><button class="bouton" type="submit"><?= e($boutonTexte) ?></button></div>
</form>
<script src="/assets/js/gestion-tresorerie.js" defer></script>
