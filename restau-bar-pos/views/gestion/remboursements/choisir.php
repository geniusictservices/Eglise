<?php
/**
 * Nouveau remboursement, étape 1 : chercher le client (nom ou téléphone). Les clients qui doivent quelque chose
 * viennent d'abord, avec leur encours.
 * @var string $q
 * @var list<array> $resultats
 * @var array<int, array> $devises
 * @var bool $inconnu un client demandé dans l'adresse n'existe pas
 */

use App\Controllers\Gestion\DetteAffichage as D;
use App\Controllers\Gestion\TresorerieAffichage as A;
?>
<link rel="stylesheet" href="/assets/css/gestion-tresorerie.css">
<link rel="stylesheet" href="/assets/css/gestion-dettes.css">

<p class="tre-retour"><a class="lien" href="/gestion/remboursements">← <?= e(t('gremb.titre')) ?></a></p>
<h1><?= e(t('gremb.nouveau')) ?></h1>
<p class="aide"><?= e(t('gremb.choisir_aide')) ?></p>

<?php if ($inconnu): ?>
    <p class="alerte alerte--erreur" role="alert"><?= e(t('gremb.erreur_client_inconnu')) ?></p>
<?php endif; ?>

<section class="bloc">
    <form method="get" action="/gestion/remboursements/nouveau" class="det-recherche" role="search">
        <div class="champ">
            <label for="q"><?= e(t('gremb.chercher_client')) ?></label>
            <input type="search" id="q" name="q" value="<?= e($q) ?>" maxlength="64" minlength="2" required autofocus autocomplete="off"
                   placeholder="<?= e(t('gclients.rechercher_exemple')) ?>" aria-describedby="q-aide">
            <p class="champ__aide" id="q-aide"><?= e(t('gremb.chercher_aide')) ?></p>
        </div>
        <button class="bouton" type="submit"><?= e(t('gremb.chercher')) ?></button>
    </form>

    <?php if ($q !== ''): ?>
        <?php if ($resultats === []): ?>
            <p class="aide det-resultats-vide"><?= e(mb_strlen($q) < 2 ? t('gremb.chercher_aide') : t('gremb.aucun_client', ['q' => $q])) ?></p>
        <?php else: ?>
            <ul class="det-resultats" aria-label="<?= e(t('gremb.resultats')) ?>">
                <?php foreach ($resultats as $c): $doit = $c['encours'] !== []; ?>
                    <li>
                        <?php if ($doit): ?>
                            <a class="det-resultat" href="/gestion/remboursements/nouveau?client=<?= (int) $c['id'] ?>" data-client="<?= (int) $c['id'] ?>">
                        <?php else: ?>
                            <div class="det-resultat det-resultat--rien" data-client="<?= (int) $c['id'] ?>">
                        <?php endif; ?>
                            <span class="det-resultat__nom"><?= e($c['nom']) ?>
                                <?php if ($c['type'] === 'personnel'): ?><span class="badge badge--glace"><?= e(t('gclients.type_personnel')) ?></span><?php endif; ?>
                                <span class="tre-sous-texte montant"><?= e($c['telephone'] ? afficherTelephone($c['telephone']) : t('gclients.sans_telephone')) ?></span>
                            </span>
                            <span class="det-resultat__encours">
                                <?php if ($doit): ?>
                                    <strong class="montant"><?= e(D::montants($c['encours'], $devises)) ?></strong>
                                    <?php if ($c['retard']): ?><span class="det-retard tre-sous-texte"><?= e(t('gclients.retard_depuis', ['date' => A::jour($c['retard'])])) ?></span><?php endif; ?>
                                <?php else: ?>
                                    <span class="aide"><?= e(t('gremb.rien_a_rembourser_court')) ?></span>
                                <?php endif; ?>
                            </span>
                        <?php if ($doit): ?></a><?php else: ?></div><?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    <?php endif; ?>
</section>
