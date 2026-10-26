<?php
/**
 * Saisie ou modification d'une dépense.
 * @var ?array $depense null pour une nouvelle dépense
 * @var array $valeurs date, categorie_id, etablissement_id, libelle, beneficiaire, notes
 * @var array $lignes paiements saisis : compte_id, devise_id, montant
 * @var array<string, string> $erreurs
 * @var string[] $avertissements
 * @var array<int, array> $comptes
 * @var array<int, array> $devises
 * @var ?array $reference
 * @var array $categories
 * @var array $etablissements
 * @var int $maxPaiements
 * @var array $evenements soirées proposées (module Événements)
 */

use App\Controllers\Gestion\TresorerieAffichage as A;
use App\Core\View;

$jeton = e(App\Core\Session::jetonCsrf());
$action = $depense ? '/gestion/depenses/' . (int) $depense['id'] : '/gestion/depenses/nouvelle';
$invalide = static fn (string $champ): string => isset($erreurs[$champ]) ? ' aria-invalid="true" aria-describedby="erreur-' . $champ . '"' : '';
$erreurChamp = static fn (string $champ): string => isset($erreurs[$champ])
    ? '<p class="champ__erreur" id="erreur-' . $champ . '">' . e($erreurs[$champ]) . '</p>'
    : '';
$devisesJs = array_map([A::class, 'deviseJs'], $devises);
// Ordre d'affichage des devises (locale, dollar, autres) pour le total
$ordreDevises = array_keys($devises);
?>
<link rel="stylesheet" href="/assets/css/gestion-tresorerie.css">

<p class="tre-retour"><a class="lien" href="/gestion/depenses">← <?= e(t('depenses.titre')) ?></a></p>
<h1><?= e($depense ? t('depenses.modifier_titre') : t('depenses.nouvelle')) ?></h1>
<?php if ($depense && $depense['auteur']): ?>
    <p class="aide"><?= e(t('depenses.saisie_par', ['nom' => $depense['auteur'], 'date' => A::dateHeure($depense['cree_le'])])) ?></p>
<?php endif; ?>

<?= View::rendre('gestion/tresorerie/_erreurs', ['erreurs' => array_values($erreurs)]) ?>

<?php if ($comptes === []): ?>
    <section class="bloc">
        <p><?= e(t('depenses.aucun_compte')) ?></p>
        <p><a class="bouton" href="/gestion/tresorerie/comptes/nouveau">+ <?= e(t('tresorerie.nouveau_compte')) ?></a></p>
    </section>
<?php else: ?>
<form method="post" action="<?= e($action) ?>" class="tre-formulaire" id="formulaire-depense" novalidate
      data-devises="<?= e((string) json_encode($devisesJs)) ?>"
      data-ordre="<?= e(implode(',', $ordreDevises)) ?>"
      data-reference="<?= $reference ? (int) $reference['id'] : '' ?>"
      data-max="<?= (int) $maxPaiements ?>"
      data-texte-solde="<?= e(t('transferts.solde_js')) ?>"
      data-texte-decouvert="<?= e(t('transferts.decouvert_js')) ?>">
    <input type="hidden" name="csrf" value="<?= $jeton ?>">
    <?php // Touche Entrée : enregistrer (le premier bouton d'envoi du formulaire), pas « Ajouter un paiement » ?>
    <button type="submit" class="visuellement-cache" tabindex="-1" aria-hidden="true"><?= e(t('depenses.enregistrer')) ?></button>

    <?= View::rendre('gestion/tresorerie/_avertissements', ['avertissements' => $avertissements]) ?>

    <section class="bloc">
        <h2><?= e(t('depenses.section_depense')) ?></h2>
        <div class="champ">
            <label for="libelle"><?= e(t('depenses.champ_libelle')) ?></label>
            <input id="libelle" name="libelle" maxlength="255" required value="<?= e($valeurs['libelle']) ?>"
                   placeholder="<?= e(t('depenses.champ_libelle_exemple')) ?>"<?= $invalide('libelle') ?>>
            <?= $erreurChamp('libelle') ?>
        </div>
        <div class="tre-paire">
            <div class="champ">
                <label for="categorie_id"><?= e(t('depenses.champ_categorie')) ?></label>
                <select id="categorie_id" name="categorie_id" required<?= $invalide('categorie_id') ?>>
                    <option value=""><?= e(t('depenses.choisir_categorie')) ?></option>
                    <?php foreach ($categories as $categorie): ?>
                        <option value="<?= (int) $categorie['id'] ?>" <?= (int) $valeurs['categorie_id'] === (int) $categorie['id'] ? 'selected' : '' ?>><?= e(champ($categorie, 'nom')) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= $erreurChamp('categorie_id') ?>
            </div>
            <div class="champ">
                <label for="etablissement_id"><?= e(t('gestion.etablissement')) ?></label>
                <select id="etablissement_id" name="etablissement_id"<?= $invalide('etablissement_id') ?>>
                    <option value=""><?= e(t('depenses.commune_tous')) ?></option>
                    <?php foreach ($etablissements as $etab): ?>
                        <option value="<?= (int) $etab['id'] ?>" <?= (string) $valeurs['etablissement_id'] === (string) $etab['id'] ? 'selected' : '' ?>><?= e($etab['nom']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= $erreurChamp('etablissement_id') ?>
            </div>
        </div>
        <div class="tre-paire">
            <div class="champ">
                <label for="date"><?= e(t('tresorerie.champ_date')) ?></label>
                <input type="datetime-local" id="date" name="date" value="<?= e($valeurs['date']) ?>"<?= $invalide('date') ?>>
                <?= $erreurChamp('date') ?>
            </div>
            <div class="champ">
                <label for="beneficiaire"><?= e(t('depenses.champ_beneficiaire')) ?> <span class="tre-facultatif"><?= e(t('tresorerie.facultatif')) ?></span></label>
                <input id="beneficiaire" name="beneficiaire" maxlength="128" value="<?= e($valeurs['beneficiaire']) ?>"
                       placeholder="<?= e(t('depenses.champ_beneficiaire_exemple')) ?>"<?= $invalide('beneficiaire') ?>>
                <?= $erreurChamp('beneficiaire') ?>
            </div>
        </div>
        <?php if (!empty($evenements)): // Module Événements : coût d'une soirée ?>
            <div class="champ">
                <label for="evenement_id"><?= e(t('evenements.champ_depense')) ?> <span class="tre-facultatif"><?= e(t('tresorerie.facultatif')) ?></span></label>
                <select id="evenement_id" name="evenement_id" aria-describedby="aide-evenement<?= isset($erreurs['evenement_id']) ? ' erreur-evenement_id' : '' ?>"<?= isset($erreurs['evenement_id']) ? ' aria-invalid="true"' : '' ?>>
                    <option value=""><?= e(t('evenements.champ_depense_aucune')) ?></option>
                    <?php foreach ($evenements as $ev): ?>
                        <option value="<?= (int) $ev['id'] ?>" <?= (string) ($valeurs['evenement_id'] ?? '') === (string) $ev['id'] ? 'selected' : '' ?>><?= e(A::jour($ev['debut']) . ' · ' . $ev['titre'] . ' · ' . $ev['etablissement_nom']) ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="champ__aide" id="aide-evenement"><?= e(t('evenements.champ_depense_aide')) ?></p>
                <?= $erreurChamp('evenement_id') ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="bloc" id="paiements" aria-labelledby="tre-paiements-titre">
        <h2 id="tre-paiements-titre"><?= e(t('depenses.section_paiement')) ?></h2>
        <p class="aide"><?= e(t('depenses.paiement_aide')) ?></p>
        <?= $erreurChamp('paiements') ?>

        <div class="tre-paiements" data-paiements>
            <?php foreach (array_values($lignes) as $i => $ligne): ?>
                <?= View::rendre('gestion/depenses/_paiement', ['i' => $i, 'ligne' => $ligne, 'comptes' => $comptes, 'devises' => $devises, 'erreurs' => $erreurs]) ?>
            <?php endforeach; ?>
        </div>

        <template id="modele-paiement">
            <?= View::rendre('gestion/depenses/_paiement', ['i' => '__i__', 'ligne' => ['compte_id' => null, 'devise_id' => null, 'montant' => ''], 'comptes' => $comptes, 'devises' => $devises, 'erreurs' => []]) ?>
        </template>

        <?php if (count($lignes) < $maxPaiements): ?>
            <button class="bouton bouton--secondaire tre-ajouter-paiement" type="submit" name="action" value="ajouter_ligne" formnovalidate data-ajouter-paiement>
                + <?= e(t('depenses.ajouter_paiement')) ?>
            </button>
        <?php endif; ?>

        <div class="tre-resume" data-resume aria-live="polite" hidden>
            <span class="tre-resume__libelle"><?= e(t('depenses.total')) ?></span>
            <strong class="tre-resume__montants montant" data-resume-montants></strong>
            <span class="tre-resume__equivalent montant" data-resume-equivalent></span>
        </div>
    </section>

    <section class="bloc">
        <div class="champ">
            <label for="notes"><?= e(t('depenses.champ_notes')) ?> <span class="tre-facultatif"><?= e(t('tresorerie.facultatif')) ?></span></label>
            <textarea id="notes" name="notes" rows="3" maxlength="5000"><?= e($valeurs['notes']) ?></textarea>
        </div>
        <div class="tre-actions">
            <button class="bouton" type="submit"><?= e($depense ? t('depenses.enregistrer_modifications') : t('depenses.enregistrer')) ?></button>
            <a class="bouton bouton--secondaire" href="/gestion/depenses"><?= e(t('tresorerie.annuler')) ?></a>
        </div>
    </section>
</form>

<?php if ($depense): ?>
    <section class="bloc">
        <details class="tre-supprimer">
            <summary class="lien"><?= e(t('depenses.supprimer')) ?></summary>
            <p><?= e(t('depenses.supprimer_texte')) ?></p>
            <form method="post" action="/gestion/depenses/<?= (int) $depense['id'] ?>/supprimer">
                <input type="hidden" name="csrf" value="<?= $jeton ?>">
                <button class="bouton bouton--danger" type="submit"><?= e(t('depenses.supprimer_oui')) ?></button>
            </form>
        </details>
    </section>
<?php endif; ?>
<?php endif; ?>

<script src="/assets/js/gestion-tresorerie.js" defer></script>
