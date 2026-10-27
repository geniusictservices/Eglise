<?php
/**
 * @var ?string $erreur
 * @var string $email
 * @var bool $oublierCaisse juste après une déconnexion sur un appareil personnel
 */
$oublierCaisse ??= false;
?>
<section class="connexion">
    <p class="surtitre"><?= e(t('gestion.nom')) ?></p>
    <h1><?= e(t('gestion.connexion')) ?></h1>
    <?php if ($erreur): ?>
        <p class="alerte alerte--erreur" role="alert"><?= e($erreur) ?></p>
    <?php endif; ?>
    <form method="post" action="/gestion/connexion" class="formulaire">
        <input type="hidden" name="csrf" value="<?= e(App\Core\Session::jetonCsrf()) ?>">
        <label for="email"><?= e(t('gestion.identifiant')) ?></label>
        <input id="email" name="email" type="text" inputmode="email" required autocomplete="username" autocapitalize="none" spellcheck="false" value="<?= e($email) ?>">
        <label for="mot_de_passe"><?= e(t('gestion.mot_de_passe')) ?></label>
        <input id="mot_de_passe" name="mot_de_passe" type="password" required autocomplete="current-password">
        <button class="bouton" type="submit"><?= e(t('gestion.se_connecter')) ?></button>
    </form>
    <?php if ($oublierCaisse): ?>
        <?php /* Rempli par caisse-oubli.js s'il reste des ventes hors ligne à envoyer sur cet appareil */ ?>
        <p class="alerte alerte--erreur" id="caisse-oubli" role="alert" hidden
           data-une="<?= e(t('gestion.caisse_attente_une')) ?>" data-plusieurs="<?= e(t('gestion.caisse_attente')) ?>"></p>
        <script src="/assets/js/caisse-hors-ligne-base.js" defer></script>
        <script src="/assets/js/caisse-oubli.js" defer></script>
    <?php endif; ?>
</section>
