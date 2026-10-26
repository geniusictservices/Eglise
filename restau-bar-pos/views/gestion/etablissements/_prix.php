<?php
/**
 * Champs de la section « Prix et devises » (création et fiche).
 * @var array $prix ['grille_prix_id', 'devises', 'devise_affichage_id']
 * @var array $grilles
 * @var array $devises devises actives indexées par id
 * @var array $erreurs indexées par champ
 */
$choisies = array_map('intval', $prix['devises'] ?? []);
?>
<?php if ($grilles === []): ?>
    <p class="alerte alerte--erreur"><?= e(t('etab.aucune_grille')) ?> <a class="lien" href="/gestion/grilles"><?= e(t('grilles.titre')) ?></a></p>
<?php else: ?>
    <div class="champ">
        <label for="etb-grille"><?= e(t('etab.grille')) ?></label>
        <select id="etb-grille" name="grille_prix_id" required<?= isset($erreurs['grille_prix_id']) ? ' aria-invalid="true"' : '' ?> aria-describedby="etb-grille-aide">
            <?php foreach ($grilles as $grille): ?>
                <option value="<?= (int) $grille['id'] ?>" <?= (int) ($prix['grille_prix_id'] ?? 0) === (int) $grille['id'] ? 'selected' : '' ?>><?= e(champ($grille, 'nom')) ?></option>
            <?php endforeach; ?>
        </select>
        <?php if (isset($erreurs['grille_prix_id'])): ?><p class="etb-erreur-champ"><?= e($erreurs['grille_prix_id']) ?></p><?php endif; ?>
        <p class="aide" id="etb-grille-aide"><?= e(t('etab.grille_aide')) ?> <a class="lien" href="/gestion/grilles"><?= e(t('etab.gerer_grilles')) ?></a></p>
    </div>
<?php endif; ?>

<?php if ($devises === []): ?>
    <p class="alerte alerte--erreur"><?= e(t('etab.aucune_devise')) ?></p>
<?php else: ?>
    <fieldset class="etb-cases etb-cases--ligne" data-devises>
        <legend><?= e(t('etab.devises')) ?></legend>
        <?php foreach ($devises as $did => $devise): ?>
            <label class="etb-case">
                <input type="checkbox" name="devises[]" value="<?= (int) $did ?>" data-code="<?= e($devise['code']) ?>" <?= in_array((int) $did, $choisies, true) ? 'checked' : '' ?>>
                <span><strong><?= e($devise['code']) ?></strong> <span class="etb-facultatif"><?= e(champ($devise, 'nom')) ?></span>
                    <?php if ($devise['taux'] === null): ?><span class="pastille pastille--alerte"><?= e(t('gestion.taux_manquant')) ?></span><?php endif; ?></span>
            </label>
        <?php endforeach; ?>
        <?php if (isset($erreurs['devises'])): ?><p class="etb-erreur-champ"><?= e($erreurs['devises']) ?></p><?php endif; ?>
        <p class="aide"><?= e(t('etab.devises_aide')) ?></p>
    </fieldset>

    <div class="champ">
        <label for="etb-devise-affichage"><?= e(t('etab.devise_affichage')) ?></label>
        <select id="etb-devise-affichage" name="devise_affichage_id" data-devise-affichage aria-describedby="etb-devise-affichage-aide">
            <?php foreach ($devises as $did => $devise): ?>
                <option value="<?= (int) $did ?>" <?= (int) ($prix['devise_affichage_id'] ?? 0) === (int) $did ? 'selected' : '' ?>><?= e($devise['code'] . ' · ' . champ($devise, 'nom')) ?></option>
            <?php endforeach; ?>
        </select>
        <p class="aide" id="etb-devise-affichage-aide"><?= e(t('etab.devise_affichage_aide')) ?></p>
    </div>
<?php endif; ?>
