<?php
/**
 * Création ou modification d'une soirée : établissement (lounge ou nightclub), titre, thème, DJ et artistes,
 * ouverture et fermeture, budget (montant et devise), description.
 * @var ?array $ev
 * @var array $valeurs
 * @var array<string, string> $erreurs
 * @var array $etablissements
 * @var array<int, array> $devises
 */

use App\Core\View;

$action = $ev ? '/gestion/evenements/' . (int) $ev['id'] . '/modifier' : '/gestion/evenements/nouveau';
$invalide = static fn (string $champ): string => isset($erreurs[$champ]) ? ' aria-invalid="true" aria-describedby="erreur-' . $champ . '"' : '';
$erreurChamp = static fn (string $champ): string => isset($erreurs[$champ])
    ? '<p class="champ__erreur" id="erreur-' . $champ . '">' . e($erreurs[$champ]) . '</p>' : '';
?>
<link rel="stylesheet" href="/assets/css/gestion-tresorerie.css">
<link rel="stylesheet" href="/assets/css/gestion-rapports.css">

<p class="tre-retour"><a class="lien" href="<?= $ev ? '/gestion/evenements/' . (int) $ev['id'] : '/gestion/evenements' ?>">← <?= e($ev ? $ev['titre'] : t('evenements.titre')) ?></a></p>
<h1><?= e($ev ? t('evenements.modifier_titre', ['titre' => $ev['titre']]) : t('evenements.nouveau')) ?></h1>

<?= View::rendre('gestion/tresorerie/_erreurs', ['erreurs' => array_values($erreurs)]) ?>

<form method="post" action="<?= e($action) ?>" novalidate>
    <input type="hidden" name="csrf" value="<?= e(App\Core\Session::jetonCsrf()) ?>">
    <section class="bloc">
        <h2><?= e(t('evenements.section_soiree')) ?></h2>
        <div class="evt-formulaire">
            <div class="champ evt-large">
                <label for="titre"><?= e(t('evenements.titre_champ')) ?></label>
                <input id="titre" name="titre" maxlength="128" required value="<?= e($valeurs['titre']) ?>" placeholder="<?= e(t('evenements.titre_exemple')) ?>"<?= $invalide('titre') ?>>
                <?= $erreurChamp('titre') ?>
            </div>
            <div class="champ">
                <label for="etablissement_id"><?= e(t('gestion.etablissement')) ?></label>
                <select id="etablissement_id" name="etablissement_id"<?= $invalide('etablissement_id') ?>>
                    <?php foreach ($etablissements as $etab): ?>
                        <option value="<?= (int) $etab['id'] ?>"<?= (string) $valeurs['etablissement_id'] === (string) $etab['id'] ? ' selected' : '' ?>><?= e($etab['nom']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= $erreurChamp('etablissement_id') ?>
            </div>
            <div class="champ">
                <label for="theme"><?= e(t('evenements.theme')) ?> <span class="tre-facultatif"><?= e(t('tresorerie.facultatif')) ?></span></label>
                <input id="theme" name="theme" maxlength="255" value="<?= e($valeurs['theme']) ?>" placeholder="<?= e(t('evenements.theme_exemple')) ?>"<?= $invalide('theme') ?>>
                <?= $erreurChamp('theme') ?>
            </div>
            <div class="champ">
                <label for="debut"><?= e(t('evenements.debut')) ?></label>
                <input type="datetime-local" id="debut" name="debut" required value="<?= e($valeurs['debut']) ?>"<?= $invalide('debut') ?>>
                <?= $erreurChamp('debut') ?>
            </div>
            <div class="champ">
                <label for="fin"><?= e(t('evenements.fin')) ?></label>
                <input type="datetime-local" id="fin" name="fin" required value="<?= e($valeurs['fin']) ?>" aria-describedby="aide-fin<?= isset($erreurs['fin']) ? ' erreur-fin' : '' ?>"<?= isset($erreurs['fin']) ? ' aria-invalid="true"' : '' ?>>
                <p class="champ__aide" id="aide-fin"><?= e(t('evenements.fin_aide')) ?></p>
                <?= $erreurChamp('fin') ?>
            </div>
            <div class="champ evt-large">
                <label for="artistes"><?= e(t('evenements.artistes')) ?> <span class="tre-facultatif"><?= e(t('tresorerie.facultatif')) ?></span></label>
                <textarea id="artistes" name="artistes" rows="2" maxlength="500" placeholder="<?= e(t('evenements.artistes_exemple')) ?>"<?= $invalide('artistes') ?>><?= e($valeurs['artistes']) ?></textarea>
                <?= $erreurChamp('artistes') ?>
            </div>
            <div class="champ">
                <label for="budget"><?= e(t('evenements.budget')) ?> <span class="tre-facultatif"><?= e(t('tresorerie.facultatif')) ?></span></label>
                <input id="budget" name="budget" inputmode="decimal" value="<?= e($valeurs['budget']) ?>" aria-describedby="aide-budget<?= isset($erreurs['budget']) ? ' erreur-budget' : '' ?>"<?= isset($erreurs['budget']) ? ' aria-invalid="true"' : '' ?>>
                <p class="champ__aide" id="aide-budget"><?= e(t('evenements.budget_aide')) ?></p>
                <?= $erreurChamp('budget') ?>
            </div>
            <div class="champ">
                <label for="budget_devise_id"><?= e(t('evenements.budget_devise')) ?></label>
                <select id="budget_devise_id" name="budget_devise_id">
                    <?php foreach ($devises as $id => $devise): ?>
                        <option value="<?= (int) $id ?>"<?= (string) $valeurs['budget_devise_id'] === (string) $id ? ' selected' : '' ?>><?= e($devise['code'] . ' · ' . champ($devise, 'nom')) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="champ evt-large">
                <label for="description"><?= e(t('evenements.description')) ?> <span class="tre-facultatif"><?= e(t('tresorerie.facultatif')) ?></span></label>
                <textarea id="description" name="description" rows="4" maxlength="5000"<?= $invalide('description') ?>><?= e($valeurs['description']) ?></textarea>
                <?= $erreurChamp('description') ?>
            </div>
        </div>
        <div class="tre-actions">
            <button class="bouton" type="submit"><?= e($ev ? t('evenements.enregistrer_modifications') : t('evenements.creer')) ?></button>
            <a class="bouton bouton--secondaire" href="<?= $ev ? '/gestion/evenements/' . (int) $ev['id'] : '/gestion/evenements' ?>"><?= e(t('tresorerie.annuler')) ?></a>
        </div>
    </section>
</form>
