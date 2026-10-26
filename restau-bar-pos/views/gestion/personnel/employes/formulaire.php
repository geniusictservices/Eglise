<?php
/**
 * Création ou modification d'un employé : compte lié (facultatif), identité, établissements, contrat, salaire.
 * @var ?array $employe
 * @var array $valeurs
 * @var array<string, string> $erreurs
 * @var array $etablissements établissements où la personne gère le personnel
 * @var array $comptes comptes utilisateurs sans fiche employé
 * @var array<int, array> $devises
 * @var bool $peutSalaire
 */

use App\Core\View;
use App\Repositories\PersonnelRepository;

$invalide = static fn (string $champ): string => isset($erreurs[$champ]) ? ' aria-invalid="true" aria-describedby="erreur-' . $champ . '"' : '';
$erreur = static fn (string $champ): string => isset($erreurs[$champ]) ? '<p class="champ__erreur" id="erreur-' . $champ . '">' . e($erreurs[$champ]) . '</p>' : '';
$action = $employe ? '/gestion/personnel/employes/' . (int) $employe['id'] . '/modifier' : '/gestion/personnel/employes/nouveau';
?>
<link rel="stylesheet" href="/assets/css/gestion-tresorerie.css">
<link rel="stylesheet" href="/assets/css/gestion-personnel.css">

<p class="tre-retour"><a class="lien" href="<?= $employe ? '/gestion/personnel/employes/' . (int) $employe['id'] : '/gestion/personnel/employes' ?>">← <?= e($employe ? $employe['nom'] : t('pers.employes_titre')) ?></a></p>
<h1><?= e($employe ? t('pers.modifier_employe', ['nom' => $employe['nom']]) : t('pers.nouvel_employe')) ?></h1>

<?= View::rendre('gestion/tresorerie/_erreurs', ['erreurs' => array_values($erreurs)]) ?>

<form method="post" action="<?= e($action) ?>" class="tre-formulaire per-formulaire" novalidate>
    <input type="hidden" name="csrf" value="<?= e(App\Core\Session::jetonCsrf()) ?>">

    <section class="bloc">
        <h2><?= e(t('pers.section_identite')) ?></h2>
        <div class="champ">
            <label for="utilisateur_id"><?= e(t('pers.champ_compte')) ?> <span class="tre-facultatif"><?= e(t('tresorerie.facultatif')) ?></span></label>
            <select id="utilisateur_id" name="utilisateur_id"<?= $invalide('utilisateur_id') ?> data-compte-employe>
                <option value=""><?= e(t('pers.sans_compte_option')) ?></option>
                <?php foreach ($comptes as $c): ?>
                    <option value="<?= (int) $c['id'] ?>" data-nom="<?= e($c['nom']) ?>" data-poste="<?= e((string) $c['poste']) ?>" <?= (int) $valeurs['utilisateur_id'] === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['nom'] . ($c['telephone'] ? ' · ' . afficherTelephone($c['telephone']) : '')) ?></option>
                <?php endforeach; ?>
            </select>
            <p class="champ__aide"><?= e(t('pers.champ_compte_aide')) ?></p>
            <?= $erreur('utilisateur_id') ?>
        </div>
        <div class="tre-paire">
            <div class="champ">
                <label for="nom"><?= e(t('pers.champ_nom')) ?></label>
                <input id="nom" name="nom" maxlength="128" value="<?= e($valeurs['nom']) ?>" autocomplete="off"<?= $invalide('nom') ?>>
                <?= $erreur('nom') ?>
            </div>
            <div class="champ">
                <label for="poste"><?= e(t('pers.champ_poste')) ?></label>
                <input id="poste" name="poste" maxlength="80" value="<?= e($valeurs['poste']) ?>" placeholder="<?= e(t('pers.champ_poste_exemple')) ?>"<?= $invalide('poste') ?>>
                <?= $erreur('poste') ?>
            </div>
        </div>
        <div class="champ" data-telephone-employe>
            <label for="telephone"><?= e(t('pers.champ_telephone')) ?></label>
            <input id="telephone" name="telephone" type="tel" inputmode="tel" maxlength="32" value="<?= e($valeurs['telephone']) ?>" placeholder="+243 97 000 0000"<?= $invalide('telephone') ?>>
            <p class="champ__aide"><?= e(t('pers.champ_telephone_aide')) ?></p>
            <?= $erreur('telephone') ?>
        </div>
    </section>

    <section class="bloc">
        <h2><?= e(t('pers.section_contrat')) ?></h2>
        <div class="tre-paire">
            <div class="champ">
                <label for="etablissement_id"><?= e(t('pers.champ_etablissement')) ?></label>
                <select id="etablissement_id" name="etablissement_id"<?= $invalide('etablissement_id') ?>>
                    <?php foreach ($etablissements as $e): ?>
                        <option value="<?= (int) $e['id'] ?>" <?= (int) $valeurs['etablissement_id'] === (int) $e['id'] ? 'selected' : '' ?>><?= e($e['nom']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= $erreur('etablissement_id') ?>
            </div>
            <fieldset class="champ per-cases">
                <legend class="champ__libelle"><?= e(t('pers.champ_autres_etablissements')) ?></legend>
                <?php foreach ($etablissements as $e): ?>
                    <label class="coche"><input type="checkbox" name="etablissements[]" value="<?= (int) $e['id'] ?>" <?= in_array((int) $e['id'], array_map('intval', $valeurs['etablissements']), true) ? 'checked' : '' ?>> <?= e($e['nom']) ?></label>
                <?php endforeach; ?>
            </fieldset>
        </div>
        <div class="tre-paire">
            <div class="champ">
                <label for="contrat"><?= e(t('pers.champ_contrat')) ?></label>
                <select id="contrat" name="contrat"<?= $invalide('contrat') ?>>
                    <?php foreach (PersonnelRepository::CONTRATS as $c): ?>
                        <option value="<?= $c ?>" <?= $valeurs['contrat'] === $c ? 'selected' : '' ?>><?= e(t('pers.contrat_' . $c)) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= $erreur('contrat') ?>
            </div>
            <div class="champ">
                <label for="periode"><?= e(t('pers.champ_periode')) ?></label>
                <select id="periode" name="periode"<?= $invalide('periode') ?>>
                    <?php foreach (PersonnelRepository::PERIODES as $p): ?>
                        <option value="<?= $p ?>" <?= $valeurs['periode'] === $p ? 'selected' : '' ?>><?= e(t('pers.periode_' . $p)) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= $erreur('periode') ?>
            </div>
        </div>
        <div class="tre-paire">
            <div class="champ">
                <label for="debut"><?= e(t('pers.champ_debut')) ?></label>
                <input id="debut" name="debut" type="date" value="<?= e($valeurs['debut']) ?>"<?= $invalide('debut') ?>>
                <?= $erreur('debut') ?>
            </div>
            <div class="champ">
                <label for="fin"><?= e(t('pers.champ_fin')) ?> <span class="tre-facultatif"><?= e(t('tresorerie.facultatif')) ?></span></label>
                <input id="fin" name="fin" type="date" value="<?= e($valeurs['fin']) ?>"<?= $invalide('fin') ?>>
                <?= $erreur('fin') ?>
            </div>
        </div>
    </section>

    <?php if ($peutSalaire): ?>
        <section class="bloc">
            <h2><?= e(t('pers.section_salaire')) ?></h2>
            <p class="aide"><?= e(t('pers.salaire_aide')) ?></p>
            <div class="per-salaires">
                <?php foreach ($devises as $id => $d): $cle = 'salaires-' . $id; ?>
                    <div class="champ">
                        <label for="salaire-<?= (int) $id ?>"><?= e(t('pers.champ_salaire_en', ['code' => $d['code']])) ?></label>
                        <div class="tre-montant">
                            <input id="salaire-<?= (int) $id ?>" name="salaires[<?= (int) $id ?>]" inputmode="decimal" autocomplete="off" value="<?= e((string) ($valeurs['salaires'][$id] ?? '')) ?>"<?= $invalide($cle) ?>>
                            <span class="tre-montant__devise"><?= e($d['symbole']) ?></span>
                        </div>
                        <?= $erreur($cle) ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="tre-paire">
                <div class="champ">
                    <label for="commission_pct"><?= e(t('pers.champ_commission')) ?> <span class="tre-facultatif"><?= e(t('tresorerie.facultatif')) ?></span></label>
                    <div class="tre-montant">
                        <input id="commission_pct" name="commission_pct" inputmode="decimal" value="<?= e($valeurs['commission_pct']) ?>" placeholder="2"<?= $invalide('commission_pct') ?>>
                        <span class="tre-montant__devise">%</span>
                    </div>
                    <p class="champ__aide"><?= e(t('pers.champ_commission_aide')) ?></p>
                    <?= $erreur('commission_pct') ?>
                </div>
                <div class="champ">
                    <span class="champ__libelle"><?= e(t('pers.champ_pourboires')) ?></span>
                    <label class="coche"><input type="checkbox" name="pourboires" value="1" <?= $valeurs['pourboires'] ? 'checked' : '' ?>> <?= e(t('pers.champ_pourboires_oui')) ?></label>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <section class="bloc">
        <div class="champ">
            <label for="notes"><?= e(t('pers.champ_notes')) ?> <span class="tre-facultatif"><?= e(t('tresorerie.facultatif')) ?></span></label>
            <textarea id="notes" name="notes" rows="3" maxlength="5000"><?= e($valeurs['notes']) ?></textarea>
        </div>
        <div class="tre-actions">
            <button class="bouton" type="submit"><?= e(t('pers.enregistrer')) ?></button>
            <a class="bouton bouton--secondaire" href="<?= $employe ? '/gestion/personnel/employes/' . (int) $employe['id'] : '/gestion/personnel/employes' ?>"><?= e(t('tresorerie.annuler')) ?></a>
        </div>
    </section>
</form>
<script src="/assets/js/gestion-personnel.js" defer></script>
