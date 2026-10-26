<?php
/**
 * Fiche d'un rôle : nom et permissions, groupées par module.
 * @var ?array $role
 * @var array $valeurs
 * @var list<string> $permissionsChoisies
 * @var array<string, string> $erreurs
 * @var array<string, list<string>> $groupes
 * @var callable $mesDroits permission => bool (droits de la personne qui modifie)
 * @var ?string $flash
 */
$jeton = e(App\Core\Session::jetonCsrf());
$fige = $role && $role['tout'];
$action = $role ? '/gestion/utilisateurs/roles/' . (int) $role['id'] : '/gestion/utilisateurs/roles/nouveau';
?>
<link rel="stylesheet" href="/assets/css/gestion-utilisateurs.css">
<p class="uti-retour"><a class="lien" href="/gestion/utilisateurs/roles">← <?= e(t('roles.titre')) ?></a></p>
<h1><?= e($role ? champ($role, 'nom') : t('roles.nouveau')) ?></h1>
<?php if ($role): ?><p class="aide"><?= e(t('roles.nb_personnes', ['n' => (int) $role['nb_personnes']])) ?></p><?php endif; ?>

<?php if ($flash): ?><p class="alerte" role="status"><?= e($flash) ?></p><?php endif; ?>
<?php foreach ($erreurs as $erreur): ?><p class="alerte alerte--erreur" role="alert"><?= e($erreur) ?></p><?php endforeach; ?>
<?php if ($fige): ?><p class="alerte"><?= e(t('roles.proprietaire_aide')) ?></p><?php endif; ?>

<form method="post" action="<?= e($action) ?>" class="uti-formulaire">
    <input type="hidden" name="csrf" value="<?= $jeton ?>">
    <fieldset class="uti-fige" <?= $fige ? 'disabled' : '' ?>>
        <section class="bloc">
            <div class="uti-grille">
                <div class="champ">
                    <label for="nom_fr"><?= e(t('roles.nom_fr')) ?></label>
                    <input id="nom_fr" name="nom_fr" required maxlength="80" value="<?= e($valeurs['nom_fr'] ?? '') ?>" lang="fr">
                </div>
                <div class="champ">
                    <label for="nom_en"><?= e(t('roles.nom_en')) ?> <span class="uti-facultatif"><?= e(t('utilisateurs.facultatif')) ?></span></label>
                    <input id="nom_en" name="nom_en" maxlength="80" value="<?= e($valeurs['nom_en'] ?? '') ?>" lang="en">
                </div>
                <div class="champ">
                    <label for="description_fr"><?= e(t('roles.description_fr')) ?> <span class="uti-facultatif"><?= e(t('utilisateurs.facultatif')) ?></span></label>
                    <input id="description_fr" name="description_fr" maxlength="255" value="<?= e($valeurs['description_fr'] ?? '') ?>" lang="fr">
                </div>
                <div class="champ">
                    <label for="description_en"><?= e(t('roles.description_en')) ?> <span class="uti-facultatif"><?= e(t('utilisateurs.facultatif')) ?></span></label>
                    <input id="description_en" name="description_en" maxlength="255" value="<?= e($valeurs['description_en'] ?? '') ?>" lang="en">
                </div>
            </div>
        </section>

        <section class="bloc">
            <h2><?= e(t('roles.permissions')) ?></h2>
            <p class="aide"><?= e(t('roles.permissions_aide')) ?></p>
            <div class="uti-permissions">
                <?php foreach ($groupes as $module => $codes): ?>
                    <fieldset class="uti-permissions__groupe">
                        <legend><?= e(t('module.' . $module . '.nom')) ?></legend>
                        <?php foreach ($codes as $code): $permis = $mesDroits($code); ?>
                            <label class="coche<?= $permis ? '' : ' uti-coche--interdite' ?>">
                                <input type="checkbox" name="permissions[]" value="<?= e($code) ?>"
                                    <?= $fige || in_array($code, $permissionsChoisies, true) ? 'checked' : '' ?>
                                    <?= $permis ? '' : 'disabled' ?>>
                                <span><?= e(t('perm.' . $code)) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </fieldset>
                <?php endforeach; ?>
            </div>
        </section>

        <div class="uti-actions">
            <button class="bouton" type="submit"><?= e($role ? t('utilisateurs.enregistrer') : t('roles.creer')) ?></button>
            <a class="bouton bouton--fantome" href="/gestion/utilisateurs/roles"><?= e(t('utilisateurs.annuler')) ?></a>
        </div>
    </fieldset>
</form>

<?php if ($role && !$fige && (int) $role['nb_personnes'] === 0): ?>
    <form method="post" action="/gestion/utilisateurs/roles/<?= (int) $role['id'] ?>/supprimer" class="bloc">
        <input type="hidden" name="csrf" value="<?= $jeton ?>">
        <p class="aide"><?= e(t('roles.supprimer_aide')) ?></p>
        <button class="bouton bouton--danger" type="submit"><?= e(t('roles.supprimer')) ?></button>
    </form>
<?php endif; ?>
