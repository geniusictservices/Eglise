<?php
/**
 * Écran refusé : la personne n'a pas la permission (rôles, Paramètres > Utilisateurs).
 * @var ?string $texte explication propre à l'écran (sinon : le rôle ne donne pas ce droit)
 */
?>
<p class="surtitre">403</p>
<h1><?= e(t('acces.interdit_titre')) ?></h1>
<p class="aide"><?= e($texte ?? t('acces.interdit_texte')) ?></p>
<p><a class="bouton" href="/gestion"><?= e(t('modules.retour_titre')) ?></a></p>
