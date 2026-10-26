<?php
/**
 * Ventes > Clients : recherche (nom, téléphone, e-mail), type, « avec dette », « en retard » ; encours par devise
 * (devise locale d'abord, dollar ensuite) dans les établissements de la personne ; pages de 50 clients.
 * @var ?string $flash
 * @var array $filtres q, type, avec_dette, retard
 * @var list<array> $clients
 * @var int $nombre
 * @var int $page
 * @var int $pages
 * @var array<int, float> $encoursTotal
 * @var array<int, array> $devises
 * @var bool $peutModifier
 */

use App\Controllers\Gestion\DetteAffichage as D;
use App\Controllers\Gestion\TresorerieAffichage as A;
use App\Repositories\GestionDetteRepository;

$filtre = $filtres['q'] !== '' || $filtres['type'] || $filtres['avec_dette'] || $filtres['retard'];
$encoursTotal = D::ordonner($encoursTotal, $devises);
?>
<link rel="stylesheet" href="/assets/css/gestion-tresorerie.css">
<link rel="stylesheet" href="/assets/css/gestion-dettes.css">

<div class="tre-entete">
    <div>
        <h1><?= e(t('gclients.titre')) ?></h1>
        <p class="aide"><?= e(t('gclients.aide')) ?></p>
    </div>
    <?php if ($peutModifier): ?>
        <div class="tre-entete__actions">
            <a class="bouton" href="/gestion/clients/nouveau">+ <?= e(t('gclients.nouveau')) ?></a>
        </div>
    <?php endif; ?>
</div>

<?php if ($flash): ?>
    <p class="alerte" role="status"><?= e($flash) ?></p>
<?php endif; ?>

<section class="bloc tre-filtres" aria-label="<?= e(t('gclients.filtres')) ?>">
    <nav class="tre-puces" aria-label="<?= e(t('gclients.filtre_encours')) ?>">
        <a class="puce" href="<?= e(urlAvec(['dette' => null, 'retard' => null, 'page' => null])) ?>"<?= !$filtres['avec_dette'] && !$filtres['retard'] ? ' aria-current="true"' : '' ?>><?= e(t('gclients.tous')) ?></a>
        <a class="puce" href="<?= e(urlAvec(['dette' => '1', 'retard' => null, 'page' => null])) ?>"<?= $filtres['avec_dette'] && !$filtres['retard'] ? ' aria-current="true"' : '' ?>><?= e(t('gclients.avec_dette')) ?></a>
        <a class="puce" href="<?= e(urlAvec(['retard' => '1', 'dette' => null, 'page' => null])) ?>"<?= $filtres['retard'] ? ' aria-current="true"' : '' ?>><?= e(t('gclients.en_retard')) ?></a>
    </nav>
    <form class="tre-filtres__formulaire det-filtres" method="get" action="/gestion/clients" role="search">
        <?php if ($filtres['avec_dette']): ?><input type="hidden" name="dette" value="1"><?php endif; ?>
        <?php if ($filtres['retard']): ?><input type="hidden" name="retard" value="1"><?php endif; ?>
        <div class="champ det-filtres__recherche">
            <label for="filtre-q"><?= e(t('gclients.rechercher')) ?></label>
            <input type="search" id="filtre-q" name="q" value="<?= e($filtres['q']) ?>" maxlength="64" placeholder="<?= e(t('gclients.rechercher_exemple')) ?>" autocomplete="off">
        </div>
        <div class="champ">
            <label for="filtre-type"><?= e(t('gclients.type')) ?></label>
            <select id="filtre-type" name="type">
                <option value=""><?= e(t('gclients.tous_types')) ?></option>
                <?php foreach (GestionDetteRepository::TYPES_CLIENT as $type): ?>
                    <option value="<?= e($type) ?>" <?= $filtres['type'] === $type ? 'selected' : '' ?>><?= e(t('gclients.type_' . $type)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="tre-filtres__boutons">
            <button class="bouton bouton--secondaire" type="submit"><?= e(t('tresorerie.afficher')) ?></button>
            <?php if ($filtre): ?>
                <a class="lien" href="/gestion/clients"><?= e(t('gventes.effacer_filtres')) ?></a>
            <?php endif; ?>
        </div>
    </form>
</section>

<section class="tre-totaux" aria-labelledby="det-totaux-titre">
    <h2 class="visuellement-cache" id="det-totaux-titre"><?= e(t('gclients.totaux')) ?></h2>
    <div class="chiffres det-chiffres">
        <div class="chiffre">
            <span class="chiffre__valeur" data-total="clients"><?= (int) $nombre ?></span>
            <span class="chiffre__libelle"><?= e(t($nombre > 1 ? 'gclients.n_clients' : 'gclients.un_client')) ?></span>
        </div>
        <?php foreach ($encoursTotal as $deviseId => $somme): ?>
            <div class="chiffre">
                <span class="chiffre__valeur" data-total="encours-<?= (int) $deviseId ?>"><?= e(D::montant($somme, $deviseId, $devises)) ?></span>
                <span class="chiffre__libelle"><?= e(t('gclients.encours_en', ['code' => $devises[$deviseId]['code'] ?? ''])) ?></span>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<?php if ($clients === []): ?>
    <p class="bloc aide"><?= e(t($filtre ? 'gclients.aucun_filtre' : 'gclients.aucun')) ?></p>
<?php else: ?>
    <section class="bloc" aria-labelledby="det-liste-titre">
        <h2 id="det-liste-titre"><?= e(t($nombre > 1 ? 'gclients.liste_n' : 'gclients.liste_1', ['n' => $nombre])) ?></h2>
        <div class="tre-defile">
            <table class="tableau det-liste det-liste--clients">
                <thead>
                    <tr>
                        <th scope="col"><?= e(t('gclients.col_client')) ?></th>
                        <th scope="col"><?= e(t('gclients.col_telephone')) ?></th>
                        <th scope="col"><?= e(t('gclients.type')) ?></th>
                        <th scope="col" class="nombre"><?= e(t('gclients.col_encours')) ?></th>
                        <th scope="col"><?= e(t('gclients.col_retard')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($clients as $c): ?>
                        <tr data-client="<?= (int) $c['id'] ?>">
                            <td class="det-cellule--titre">
                                <a class="det-lien-fort" href="/gestion/clients/<?= (int) $c['id'] ?>"><?= e($c['nom']) ?></a>
                                <?php if ($c['bloque']): ?><span class="badge badge--erreur"><?= e(t('gclients.badge_bloque')) ?></span><?php endif; ?>
                                <?php if (!$c['actif']): ?><span class="badge badge--neutre"><?= e(t('gclients.badge_inactif')) ?></span><?php endif; ?>
                            </td>
                            <td class="det-cellule--telephone">
                                <?php $tel = $c['type'] === 'personnel' ? $c['utilisateur_telephone'] : $c['telephone']; ?>
                                <?= $tel ? '<span class="montant">' . e(afficherTelephone($tel)) . '</span>' : '<span class="aide">' . e(t('gclients.sans_telephone')) . '</span>' ?>
                                <?php if ($c['type'] !== 'personnel' && $c['telephone'] && $c['canal'] === 'whatsapp'): ?><span class="badge badge--neutre"><?= e(t('messagerie.canal_whatsapp')) ?></span><?php endif; ?>
                            </td>
                            <td class="det-cellule--type"><span class="badge <?= $c['type'] === 'vip' ? 'badge--braise' : ($c['type'] === 'personnel' ? 'badge--glace' : 'badge--neutre') ?>"><?= e(t('gclients.type_' . $c['type'])) ?></span></td>
                            <td class="nombre det-cellule--montant">
                                <?php if ($c['encours'] === []): ?>
                                    <span class="aide">—</span>
                                <?php else: ?>
                                    <?php foreach (D::ordonner($c['encours'], $devises) as $deviseId => $reste): ?>
                                        <strong class="montant det-montant-ligne" data-encours="<?= e($devises[$deviseId]['code'] ?? (string) $deviseId) ?>"><?= e(D::montant($reste, $deviseId, $devises)) ?></strong>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </td>
                            <td class="det-cellule--retard">
                                <?php if ($c['retard']): ?>
                                    <span class="badge badge--erreur"><?= e(t('gclients.retard_depuis', ['date' => A::jour($c['retard'])])) ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= App\Core\View::rendre('gestion/dettes/_pages', ['page' => $page, 'pages' => $pages]) ?>
    </section>
<?php endif; ?>
