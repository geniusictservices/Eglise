<?php
/**
 * Paramètres > Utilisateurs : toutes les personnes, leurs rôles et leur état.
 * @var list<array> $personnes
 * @var ?string $flash
 */
use App\Core\View;
?>
<div class="uti-tete">
    <div>
        <h1><?= e(t('utilisateurs.titre')) ?></h1>
        <p class="aide"><?= e(t('utilisateurs.aide')) ?></p>
    </div>
    <a class="bouton" href="/gestion/utilisateurs/nouveau">+ <?= e(t('utilisateurs.nouveau')) ?></a>
</div>
<?= View::rendre('gestion/utilisateurs/_onglets', ['actif' => 'personnes']) ?>

<?php if ($flash): ?>
    <p class="alerte" role="status"><?= e($flash) ?></p>
<?php endif; ?>

<ul class="uti-liste">
    <?php foreach ($personnes as $p): ?>
        <li>
            <a class="uti-carte<?= $p['actif'] ? '' : ' uti-carte--inactive' ?>" href="/gestion/utilisateurs/<?= (int) $p['id'] ?>">
                <?= View::rendre('gestion/utilisateurs/_initiales', ['nom' => $p['nom']]) ?>
                <span class="uti-carte__texte">
                    <span class="uti-carte__nom"><?= e($p['nom']) ?></span>
                    <span class="uti-carte__info"><?= e(implode(' · ', array_filter([$p['poste'], afficherTelephone($p['telephone']), $p['email']]))) ?></span>
                    <span class="uti-carte__roles">
                        <?php foreach ($p['roles'] as $r): ?>
                            <span class="badge<?= $r['tout'] ? ' badge--lave' : '' ?>"><?= e(champ($r, 'nom')) ?><?= $r['etablissement'] ? ' · ' . e($r['etablissement']) : '' ?></span>
                        <?php endforeach; ?>
                        <?php if ($p['roles'] === []): ?><span class="badge badge--neutre"><?= e(t('utilisateurs.sans_role')) ?></span><?php endif; ?>
                    </span>
                </span>
                <span class="uti-carte__etat">
                    <?php if (!$p['actif']): ?><span class="badge badge--neutre"><?= e(t('utilisateurs.inactif')) ?></span><?php endif; ?>
                    <?php if ($p['pin_bloque_le'] !== null): ?><span class="badge badge--erreur"><?= e(t('utilisateurs.pin_bloque')) ?></span><?php endif; ?>
                    <?php if ($p['a_pin']): ?><span class="badge badge--glace"><?= e(t('utilisateurs.a_pin')) ?></span><?php endif; ?>
                    <?php if ($p['a_mot_de_passe']): ?><span class="badge badge--braise"><?= e(t('utilisateurs.a_mot_de_passe')) ?></span><?php endif; ?>
                </span>
            </a>
        </li>
    <?php endforeach; ?>
</ul>
