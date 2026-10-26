<?php
/**
 * Paramètres > Utilisateurs > Rôles.
 * @var list<array> $roles
 * @var ?string $flash
 */
use App\Core\View;
?>
<div class="uti-tete">
    <div>
        <h1><?= e(t('roles.titre')) ?></h1>
        <p class="aide"><?= e(t('roles.aide')) ?></p>
    </div>
    <a class="bouton" href="/gestion/utilisateurs/roles/nouveau">+ <?= e(t('roles.nouveau')) ?></a>
</div>
<?= View::rendre('gestion/utilisateurs/_onglets', ['actif' => 'roles']) ?>
<?php if ($flash): ?>
    <p class="alerte" role="status"><?= e($flash) ?></p>
<?php endif; ?>
<ul class="uti-liste">
    <?php foreach ($roles as $role): ?>
        <li>
            <a class="uti-carte" href="/gestion/utilisateurs/roles/<?= (int) $role['id'] ?>">
                <span class="uti-carte__texte">
                    <span class="uti-carte__nom"><?= e(champ($role, 'nom')) ?></span>
                    <span class="uti-carte__info"><?= e((string) champ($role, 'description')) ?></span>
                </span>
                <span class="uti-carte__etat">
                    <span class="badge<?= $role['tout'] ? ' badge--lave' : '' ?>"><?= e($role['tout'] ? t('roles.tous_droits') : t('roles.nb_droits', ['n' => count($role['permissions'])])) ?></span>
                    <span class="badge badge--neutre"><?= e(t('roles.nb_personnes', ['n' => (int) $role['nb_personnes']])) ?></span>
                </span>
            </a>
        </li>
    <?php endforeach; ?>
</ul>
