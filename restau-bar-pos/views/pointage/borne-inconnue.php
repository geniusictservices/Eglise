<?php /** Navigateur qui n'est pas (ou plus) une borne de pointage autorisée. */ ?>
<main class="pointage__page">
    <section class="pointage__carte">
        <h1 class="titre-3"><?= e(t('pointage.borne_inconnue_titre')) ?></h1>
        <p><?= e(t('pointage.borne_inconnue_texte')) ?></p>
        <p><a class="bouton bouton--large" href="/gestion/personnel/presence/bornes"><?= e(t('pointage.borne_inconnue_lien')) ?></a></p>
    </section>
</main>
