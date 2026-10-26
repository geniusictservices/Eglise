<?php
/**
 * Paramètres > Utilisateurs > Appareils de caisse partagée.
 * @var list<array> $appareils
 * @var ?string $flash
 */
use App\Core\View;
$jeton = e(App\Core\Session::jetonCsrf());
$date = static fn (?string $d): string => $d ? date('d/m/Y H:i', strtotime($d)) : '—';
?>
<div class="uti-tete">
    <div>
        <h1><?= e(t('appareils.titre')) ?></h1>
        <p class="aide"><?= e(t('appareils.aide')) ?></p>
    </div>
</div>
<?= View::rendre('gestion/utilisateurs/_onglets', ['actif' => 'appareils']) ?>
<?php if ($flash): ?><p class="alerte" role="status"><?= e($flash) ?></p><?php endif; ?>

<?php if ($appareils === []): ?>
    <p class="bloc aide"><?= e(t('appareils.aucun')) ?></p>
<?php else: ?>
    <ul class="uti-liste">
        <?php foreach ($appareils as $a): ?>
            <li class="uti-carte uti-carte--appareil<?= $a['actif'] ? '' : ' uti-carte--inactive' ?>">
                <span class="uti-carte__texte">
                    <span class="uti-carte__nom"><?= e($a['nom']) ?></span>
                    <span class="uti-carte__info"><?= e($a['etablissement']) ?> · <?= e(t('appareils.autorise', ['nom' => $a['autorise_par'] ?? '—', 'date' => $date($a['cree_le'])])) ?></span>
                    <span class="uti-carte__info"><?= e(t('appareils.dernier_usage', ['date' => $date($a['dernier_usage'])])) ?><?= (int) $a['pin_echecs'] > 0 ? ' · ' . e(t('appareils.echecs', ['n' => (int) $a['pin_echecs']])) : '' ?></span>
                </span>
                <span class="uti-carte__etat">
                    <?php if ($a['actif']): ?>
                        <form method="post" action="/gestion/utilisateurs/appareils/<?= (int) $a['id'] ?>/retirer">
                            <input type="hidden" name="csrf" value="<?= $jeton ?>">
                            <button class="bouton bouton--petit bouton--secondaire" type="submit"><?= e(t('appareils.retirer')) ?></button>
                        </form>
                    <?php else: ?>
                        <span class="badge badge--neutre"><?= e(t('appareils.retire_court')) ?></span>
                    <?php endif; ?>
                </span>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
<p class="aide"><?= e(t('appareils.comment')) ?></p>
