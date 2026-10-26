<?php
/**
 * Création ou modification d'un fournisseur.
 * @var ?array $f
 * @var array $valeurs
 * @var array<string, string> $erreurs
 * @var array<int, array> $devises
 */

use App\Core\View;
use App\Repositories\AchatRepository;

$jeton = e(App\Core\Session::jetonCsrf());
$invalide = static fn (string $champ): string => isset($erreurs[$champ]) ? ' aria-invalid="true" aria-describedby="erreur-' . $champ . '"' : '';
$erreurChamp = static fn (string $champ): string => isset($erreurs[$champ]) ? '<p class="champ__erreur" id="erreur-' . $champ . '">' . e($erreurs[$champ]) . '</p>' : '';
$action = $f ? '/gestion/achats/fournisseurs/' . (int) $f['id'] . '/modifier' : '/gestion/achats/fournisseurs/nouveau';
?>
<?= View::rendre('gestion/stock/_onglets', []) ?>

<p class="tre-retour"><a class="lien" href="<?= $f ? '/gestion/achats/fournisseurs/' . (int) $f['id'] : '/gestion/achats/fournisseurs' ?>">← <?= e($f ? $f['nom'] : t('achats.fournisseurs_titre')) ?></a></p>
<h1><?= e($f ? t('achats.modifier_fournisseur') : t('achats.nouveau_fournisseur')) ?></h1>

<?= View::rendre('gestion/tresorerie/_erreurs', ['erreurs' => array_values($erreurs)]) ?>

<form method="post" action="<?= e($action) ?>" class="tre-formulaire" novalidate>
    <input type="hidden" name="csrf" value="<?= $jeton ?>">
    <section class="bloc">
        <div class="tre-paire">
            <div class="champ">
                <label for="nom"><?= e(t('achats.nom')) ?></label>
                <input id="nom" name="nom" maxlength="128" required value="<?= e($valeurs['nom']) ?>" placeholder="<?= e(t('achats.nom_exemple')) ?>"<?= $invalide('nom') ?>>
                <?= $erreurChamp('nom') ?>
            </div>
            <div class="champ">
                <label for="type"><?= e(t('achats.type')) ?></label>
                <select id="type" name="type"<?= $invalide('type') ?>>
                    <?php foreach (AchatRepository::TYPES_FOURNISSEUR as $type): ?>
                        <option value="<?= $type ?>" <?= $valeurs['type'] === $type ? 'selected' : '' ?>><?= e(t('achats.type_' . $type)) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= $erreurChamp('type') ?>
            </div>
        </div>
        <div class="tre-paire">
            <div class="champ">
                <label for="contact"><?= e(t('achats.contact')) ?> <span class="tre-facultatif"><?= e(t('tresorerie.facultatif')) ?></span></label>
                <input id="contact" name="contact" maxlength="128" value="<?= e($valeurs['contact']) ?>">
            </div>
            <div class="champ">
                <label for="telephone"><?= e(t('achats.telephone')) ?> <span class="tre-facultatif"><?= e(t('tresorerie.facultatif')) ?></span></label>
                <input id="telephone" name="telephone" type="tel" maxlength="32" value="<?= e($valeurs['telephone']) ?>" placeholder="+243 81 000 0000">
            </div>
        </div>
        <div class="tre-paire">
            <div class="champ">
                <label for="email"><?= e(t('achats.email')) ?> <span class="tre-facultatif"><?= e(t('tresorerie.facultatif')) ?></span></label>
                <input id="email" name="email" type="email" maxlength="128" value="<?= e($valeurs['email']) ?>"<?= $invalide('email') ?>>
                <?= $erreurChamp('email') ?>
            </div>
            <div class="champ">
                <label for="adresse"><?= e(t('achats.adresse')) ?> <span class="tre-facultatif"><?= e(t('tresorerie.facultatif')) ?></span></label>
                <input id="adresse" name="adresse" maxlength="255" value="<?= e($valeurs['adresse']) ?>">
            </div>
        </div>
        <div class="tre-paire">
            <div class="champ">
                <label for="delai_paiement"><?= e(t('achats.delai_paiement')) ?></label>
                <input id="delai_paiement" name="delai_paiement" inputmode="numeric" maxlength="3" value="<?= e($valeurs['delai_paiement']) ?>"<?= $invalide('delai_paiement') ?>>
                <?= $erreurChamp('delai_paiement') ?>
                <p class="champ__aide"><?= e(t('achats.delai_aide')) ?></p>
            </div>
            <div class="champ">
                <label for="devise_id"><?= e(t('achats.devise_habituelle')) ?></label>
                <select id="devise_id" name="devise_id"<?= $invalide('devise_id') ?>>
                    <option value=""><?= e(t('achats.devise_aucune')) ?></option>
                    <?php foreach ($devises as $d): ?><option value="<?= (int) $d['id'] ?>" <?= (int) $valeurs['devise_id'] === (int) $d['id'] ? 'selected' : '' ?>><?= e($d['code']) ?></option><?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="champ">
            <label for="notes"><?= e(t('stock.notes')) ?> <span class="tre-facultatif"><?= e(t('tresorerie.facultatif')) ?></span></label>
            <textarea id="notes" name="notes" rows="3" maxlength="2000"><?= e($valeurs['notes']) ?></textarea>
        </div>
        <?php if ($f): ?><label class="coche"><input type="checkbox" name="actif" value="1" <?= $valeurs['actif'] ? 'checked' : '' ?>> <?= e(t('achats.fournisseur_actif')) ?></label><?php endif; ?>
        <div class="tre-actions">
            <button class="bouton" type="submit"><?= e(t('stock.enregistrer')) ?></button>
            <a class="bouton bouton--secondaire" href="/gestion/achats/fournisseurs"><?= e(t('tresorerie.annuler')) ?></a>
        </div>
    </section>
</form>
