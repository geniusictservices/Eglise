<?php
/**
 * Mon compte (super admin).
 * @var array $compte
 * @var list<array> $admins
 * @var array $erreurs
 * @var int $longueurMin
 */

use App\Console\Affichage as A;

$err = static fn (string $cle): string => isset($erreurs[$cle]) ? '<p class="champ__erreur" id="err-' . e($cle) . '">' . e($erreurs[$cle]) . '</p>' : '';
?>
<h1><?= e(t('console.compte.titre')) ?></h1>
<p class="aide"><?= e($compte['nom']) ?> · <?= e($compte['email']) ?> · <?= e(t('console.compte.derniere', ['date' => A::dateHeure($compte['derniere_connexion'])])) ?></p>
<div class="con-colonnes">
    <section class="bloc" aria-labelledby="con-mdp">
        <h2 id="con-mdp"><?= e(t('console.compte.changer')) ?></h2>
        <form method="post" action="/compte" class="con-form" novalidate>
            <input type="hidden" name="csrf" value="<?= e(App\Console\SessionConsole::jetonCsrf()) ?>">
            <div class="champ"><label for="actuel"><?= e(t('console.compte.actuel')) ?></label><input id="actuel" name="actuel" type="password" autocomplete="current-password"<?= isset($erreurs['actuel']) ? ' aria-invalid="true" aria-describedby="err-actuel"' : '' ?>><?= $err('actuel') ?></div>
            <div class="champ"><label for="nouveau"><?= e(t('console.compte.nouveau')) ?></label><input id="nouveau" name="nouveau" type="password" autocomplete="new-password" minlength="<?= $longueurMin ?>"<?= isset($erreurs['nouveau']) ? ' aria-invalid="true" aria-describedby="err-nouveau"' : '' ?>><p class="champ__aide"><?= e(t('console.compte.regle', ['n' => $longueurMin])) ?></p><?= $err('nouveau') ?></div>
            <div class="champ"><label for="confirmation"><?= e(t('console.compte.confirmation')) ?></label><input id="confirmation" name="confirmation" type="password" autocomplete="new-password"<?= isset($erreurs['confirmation']) ? ' aria-invalid="true" aria-describedby="err-confirmation"' : '' ?>><?= $err('confirmation') ?></div>
            <div class="con-actions"><button class="bouton" type="submit"><?= e(t('console.compte.changer')) ?></button></div>
        </form>
    </section>
    <section class="bloc" aria-labelledby="con-admins">
        <h2 id="con-admins"><?= e(t('console.compte.admins')) ?></h2>
        <p class="aide"><?= e(t('console.compte.admins_aide')) ?></p>
        <ul class="con-liste">
            <?php foreach ($admins as $a): ?>
                <li><strong><?= e($a['nom']) ?></strong> <span class="aide"><?= e($a['email']) ?></span> <?= $a['actif'] ? '' : '<span class="badge badge--neutre">' . e(t('console.compte.inactif')) . '</span>' ?></li>
            <?php endforeach; ?>
        </ul>
    </section>
</div>
