<?php
/**
 * Ajouter une présence oubliée ou corriger les heures d'une présence (motif obligatoire, inscrit au journal).
 * @var ?array $pointage
 * @var array $valeurs employe_id, etablissement_id, arrivee, depart, motif
 * @var array<string, string> $erreurs
 * @var list<array> $employes
 * @var list<array> $etablissements
 * @var list<array> $corrections
 */

use App\Controllers\Gestion\PersonnelAffichage as P;
use App\Core\View;

$jeton = e(App\Core\Session::jetonCsrf());
$invalide = static fn (string $champ): string => isset($erreurs[$champ]) ? ' aria-invalid="true" aria-describedby="erreur-' . $champ . '"' : '';
$erreur = static fn (string $champ): string => isset($erreurs[$champ]) ? '<p class="champ__erreur" id="erreur-' . $champ . '">' . e($erreurs[$champ]) . '</p>' : '';
?>
<?= View::rendre('gestion/personnel/_onglets-presence', ['actif' => 'pointages']) ?>
<p class="tre-retour"><a class="lien" href="/gestion/personnel/presence/pointages">← <?= e(t('pers.pointages_titre')) ?></a></p>
<h1><?= e($pointage ? t('pers.corriger_pointage') : t('pers.ajouter_pointage')) ?></h1>
<?php if ($pointage): ?><p class="aide"><?= e($pointage['employe_nom'] . ' · ' . $pointage['etablissement_nom'] . ' · ' . P::jourCourt($pointage['journee'])) ?></p><?php endif; ?>

<?= View::rendre('gestion/tresorerie/_erreurs', ['erreurs' => array_values($erreurs)]) ?>

<form method="post" action="<?= $pointage ? '/gestion/personnel/presence/pointages/' . (int) $pointage['id'] : '/gestion/personnel/presence/pointages/nouveau' ?>" class="tre-formulaire" novalidate>
    <input type="hidden" name="csrf" value="<?= $jeton ?>">
    <section class="bloc">
        <?php if (!$pointage): ?>
            <div class="tre-paire">
                <div class="champ">
                    <label for="employe_id"><?= e(t('pers.col_employe')) ?></label>
                    <select id="employe_id" name="employe_id"<?= $invalide('employe_id') ?>>
                        <option value=""><?= e(t('pers.choisir_employe')) ?></option>
                        <?php foreach ($employes as $e): ?><option value="<?= (int) $e['id'] ?>" <?= (int) $valeurs['employe_id'] === (int) $e['id'] ? 'selected' : '' ?>><?= e($e['nom']) ?></option><?php endforeach; ?>
                    </select>
                    <?= $erreur('employe_id') ?>
                </div>
                <div class="champ">
                    <label for="etablissement_id"><?= e(t('gestion.etablissement')) ?></label>
                    <select id="etablissement_id" name="etablissement_id"<?= $invalide('etablissement_id') ?>>
                        <?php foreach ($etablissements as $e): ?><option value="<?= (int) $e['id'] ?>" <?= (int) $valeurs['etablissement_id'] === (int) $e['id'] ? 'selected' : '' ?>><?= e($e['nom']) ?></option><?php endforeach; ?>
                    </select>
                    <?= $erreur('etablissement_id') ?>
                </div>
            </div>
        <?php endif; ?>
        <div class="tre-paire">
            <div class="champ">
                <label for="arrivee"><?= e(t('pers.arrivee')) ?></label>
                <input id="arrivee" name="arrivee" type="datetime-local" value="<?= e($valeurs['arrivee']) ?>"<?= $invalide('arrivee') ?>>
                <?= $erreur('arrivee') ?>
            </div>
            <div class="champ">
                <label for="depart"><?= e(t('pers.depart')) ?> <span class="tre-facultatif"><?= e(t('tresorerie.facultatif')) ?></span></label>
                <input id="depart" name="depart" type="datetime-local" value="<?= e($valeurs['depart']) ?>"<?= $invalide('depart') ?>>
                <?= $erreur('depart') ?>
            </div>
        </div>
        <div class="champ">
            <label for="motif"><?= e(t('pers.champ_motif')) ?></label>
            <input id="motif" name="motif" maxlength="255" value="<?= e($valeurs['motif']) ?>" placeholder="<?= e(t('pers.champ_motif_exemple')) ?>"<?= $invalide('motif') ?>>
            <?= $erreur('motif') ?>
        </div>
        <div class="tre-actions">
            <button class="bouton" type="submit"><?= e(t('pers.enregistrer')) ?></button>
            <a class="bouton bouton--secondaire" href="/gestion/personnel/presence/pointages"><?= e(t('tresorerie.annuler')) ?></a>
        </div>
    </section>
</form>

<?php if ($pointage): ?>
    <section class="bloc">
        <details class="tre-supprimer"<?= isset($erreurs['motif_suppression']) ? ' open' : '' ?>>
            <summary class="lien"><?= e(t('pers.supprimer_pointage')) ?></summary>
            <form method="post" action="/gestion/personnel/presence/pointages/<?= (int) $pointage['id'] ?>/supprimer" class="per-ligne-formulaire" novalidate>
                <input type="hidden" name="csrf" value="<?= $jeton ?>">
                <div class="champ">
                    <label for="motif-suppression"><?= e(t('pers.champ_motif')) ?></label>
                    <input id="motif-suppression" name="motif" maxlength="255"<?= $invalide('motif_suppression') ?>>
                    <?= $erreur('motif_suppression') ?>
                </div>
                <button class="bouton bouton--danger" type="submit"><?= e(t('pers.supprimer')) ?></button>
            </form>
        </details>
    </section>
    <?php if ($corrections !== []): ?>
        <section class="bloc"><h2><?= e(t('pers.historique')) ?></h2><?= View::rendre('gestion/personnel/presence/_corrections', ['corrections' => $corrections, 'avecNom' => false]) ?></section>
    <?php endif; ?>
<?php endif; ?>
