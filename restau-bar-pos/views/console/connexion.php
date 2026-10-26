<?php
/**
 * @var ?string $erreur
 * @var string $email
 */
?>
<section class="connexion">
    <p class="surtitre"><?= e(t('console.nom')) ?></p>
    <h1><?= e(t('console.connexion.titre')) ?></h1>
    <p class="aide"><?= e(t('console.connexion.aide')) ?></p>
    <?php if ($erreur): ?>
        <p class="alerte alerte--erreur" role="alert"><?= e($erreur) ?></p>
    <?php endif; ?>
    <form method="post" action="/connexion" class="formulaire">
        <input type="hidden" name="csrf" value="<?= e(App\Console\SessionConsole::jetonCsrf()) ?>">
        <label for="email"><?= e(t('gestion.email')) ?></label>
        <input id="email" name="email" type="email" required autocomplete="username" autocapitalize="none" spellcheck="false" value="<?= e($email) ?>">
        <label for="mot_de_passe"><?= e(t('gestion.mot_de_passe')) ?></label>
        <input id="mot_de_passe" name="mot_de_passe" type="password" required autocomplete="current-password">
        <button class="bouton" type="submit"><?= e(t('gestion.se_connecter')) ?></button>
    </form>
</section>
