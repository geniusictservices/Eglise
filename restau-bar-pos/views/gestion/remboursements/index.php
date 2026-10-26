<?php
/**
 * Ventes > Remboursements : filtres (période, établissement, type de compte, utilisateur, statut), totaux des
 * remboursements valides par devise et par type de compte, équivalent en devise de référence, liste paginée.
 * @var ?string $flash
 * @var array $periode
 * @var array $filtres
 * @var list<array> $remboursements
 * @var int $nombre
 * @var int $page
 * @var int $pages
 * @var array $totaux nb, devises, reference, annules
 * @var array<int, array> $devises
 * @var ?array $reference
 * @var array $etablissements
 * @var array $encaisseurs
 * @var ?array $client
 * @var bool $peutSaisir
 */

use App\Controllers\Gestion\DetteAffichage as D;
use App\Controllers\Gestion\TresorerieAffichage as A;
use App\Core\View;
use App\Repositories\GestionTresorerieRepository;

$types = GestionTresorerieRepository::TYPES;
$filtre = $filtres['etablissement'] || $filtres['utilisateur'] || $filtres['moyen'] || $filtres['statut'] || $filtres['client'] || $periode['cle'] !== 'mois';
$parDevise = D::ordonner($totaux['devises'], $devises);
?>
<link rel="stylesheet" href="/assets/css/gestion-tresorerie.css">
<link rel="stylesheet" href="/assets/css/gestion-dettes.css">

<div class="tre-entete">
    <div>
        <h1><?= e(t('gremb.titre')) ?></h1>
        <p class="aide"><?= e(t('gremb.aide')) ?></p>
    </div>
    <?php if ($peutSaisir): ?>
        <div class="tre-entete__actions">
            <a class="bouton" href="/gestion/remboursements/nouveau">+ <?= e(t('gremb.nouveau')) ?></a>
        </div>
    <?php endif; ?>
</div>

<?php if ($flash): ?>
    <p class="alerte" role="status"><?= e($flash) ?></p>
<?php endif; ?>

<?php if ($client): ?>
    <p class="det-filtre-client">
        <span><?= e(t('gdettes.filtre_client', ['nom' => $client['nom']])) ?></span>
        <a class="lien" href="<?= e(urlAvec(['client' => null, 'page' => null])) ?>"><?= e(t('gdettes.retirer_filtre_client')) ?></a>
    </p>
<?php endif; ?>

<section class="bloc tre-filtres" aria-label="<?= e(t('gremb.filtres')) ?>">
    <?= View::rendre('gestion/tresorerie/_periode', ['periode' => $periode, 'periodes' => ['jour', '7j', 'mois', 'mois_precedent', 'tout'], 'action' => '/gestion/remboursements', 'avecDates' => false]) ?>
    <nav class="tre-puces" aria-label="<?= e(t('gventes.filtre_moyen')) ?>">
        <a class="puce" href="<?= e(urlAvec(['moyen' => null, 'page' => null])) ?>"<?= !$filtres['moyen'] ? ' aria-current="true"' : '' ?>><?= e(t('gventes.tous_moyens')) ?></a>
        <?php foreach ($types as $t): ?>
            <a class="puce" href="<?= e(urlAvec(['moyen' => $t, 'page' => null])) ?>"<?= $filtres['moyen'] === $t ? ' aria-current="true"' : '' ?>><?= View::rendre('gestion/tresorerie/_icone', ['type' => $t]) ?><?= e(t('tresorerie.type_' . $t)) ?></a>
        <?php endforeach; ?>
    </nav>
    <form class="tre-filtres__formulaire det-filtres" method="get" action="/gestion/remboursements">
        <?php foreach (['moyen' => $filtres['moyen'], 'client' => $filtres['client'], 'periode' => $periode['cle'] !== 'perso' ? $periode['cle'] : null] as $nom => $valeur): ?>
            <?php if ($valeur): ?><input type="hidden" name="<?= e($nom) ?>" value="<?= e((string) $valeur) ?>"><?php endif; ?>
        <?php endforeach; ?>
        <div class="champ">
            <label for="filtre-du"><?= e(t('tresorerie.du')) ?></label>
            <input type="date" id="filtre-du" name="du" value="<?= e($periode['cle'] === 'perso' ? (string) $periode['du'] : '') ?>">
        </div>
        <div class="champ">
            <label for="filtre-au"><?= e(t('tresorerie.au')) ?></label>
            <input type="date" id="filtre-au" name="au" value="<?= e($periode['cle'] === 'perso' ? (string) $periode['au'] : '') ?>">
        </div>
        <?php if (count($etablissements) > 1): ?>
            <div class="champ">
                <label for="filtre-etablissement"><?= e(t('gestion.etablissement')) ?></label>
                <select id="filtre-etablissement" name="etablissement">
                    <option value=""><?= e(t('gventes.tous_etablissements')) ?></option>
                    <?php foreach ($etablissements as $etab): ?>
                        <option value="<?= (int) $etab['id'] ?>" <?= $filtres['etablissement'] === (int) $etab['id'] ? 'selected' : '' ?>><?= e($etab['nom']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>
        <div class="champ">
            <label for="filtre-utilisateur"><?= e(t('gremb.encaisse_par')) ?></label>
            <select id="filtre-utilisateur" name="utilisateur">
                <option value=""><?= e(t('gremb.tout_le_monde')) ?></option>
                <?php foreach ($encaisseurs as $u): ?>
                    <option value="<?= (int) $u['id'] ?>" <?= $filtres['utilisateur'] === (int) $u['id'] ? 'selected' : '' ?>><?= e($u['nom']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="champ">
            <label for="filtre-statut"><?= e(t('gventes.statut')) ?></label>
            <select id="filtre-statut" name="statut">
                <option value=""><?= e(t('gremb.tous_statuts')) ?></option>
                <option value="valide" <?= $filtres['statut'] === 'valide' ? 'selected' : '' ?>><?= e(t('gremb.statut_valide')) ?></option>
                <option value="annule" <?= $filtres['statut'] === 'annule' ? 'selected' : '' ?>><?= e(t('gremb.statut_annule')) ?></option>
            </select>
        </div>
        <div class="tre-filtres__boutons">
            <button class="bouton bouton--secondaire" type="submit"><?= e(t('tresorerie.afficher')) ?></button>
            <?php if ($filtre): ?>
                <a class="lien" href="/gestion/remboursements"><?= e(t('gventes.effacer_filtres')) ?></a>
            <?php endif; ?>
        </div>
    </form>
</section>

<section class="tre-totaux" aria-labelledby="det-totaux-titre">
    <h2 class="visuellement-cache" id="det-totaux-titre"><?= e(t('gremb.totaux')) ?></h2>
    <?php if ($totaux['nb'] === 0): ?>
        <p class="bloc aide"><?= e(t('gremb.aucun_valide')) ?></p>
    <?php else: ?>
        <div class="chiffres det-chiffres">
            <div class="chiffre">
                <span class="chiffre__valeur" data-total="nb"><?= (int) $totaux['nb'] ?></span>
                <span class="chiffre__libelle"><?= e(t($totaux['nb'] > 1 ? 'gremb.n_valides' : 'gremb.un_valide')) ?></span>
            </div>
            <?php foreach ($parDevise as $deviseId => $parType): ?>
                <div class="chiffre" data-devise="<?= e($devises[$deviseId]['code'] ?? '') ?>">
                    <span class="chiffre__valeur" data-total="devise-<?= (int) $deviseId ?>"><?= e(D::montant(array_sum($parType), $deviseId, $devises)) ?></span>
                    <span class="chiffre__libelle"><?= e(t('gremb.recu_en', ['code' => $devises[$deviseId]['code'] ?? ''])) ?>
                        <?php foreach (array_intersect($types, array_keys($parType)) as $t): ?>
                            <span class="tre-sous-texte" data-type="<?= e($t) ?>"><?= e(t('tresorerie.type_' . $t)) ?> : <?= e(D::montant($parType[$t], $deviseId, $devises)) ?></span>
                        <?php endforeach; ?>
                    </span>
                </div>
            <?php endforeach; ?>
            <?php if ($reference): ?>
                <div class="chiffre">
                    <span class="chiffre__valeur" data-total="reference">≈ <?= e(A::montant($totaux['reference'], $reference)) ?></span>
                    <span class="chiffre__libelle"><?= e(t('gventes.equivalent_encaisse', ['code' => $reference['code']])) ?></span>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <?php if ($totaux['annules'] > 0 && $filtres['statut'] !== 'annule'): ?>
        <p class="aide det-pied"><a class="lien" href="<?= e(urlAvec(['statut' => 'annule', 'page' => null])) ?>"><?= e(t($totaux['annules'] > 1 ? 'gremb.n_annules' : 'gremb.un_annule', ['n' => $totaux['annules']])) ?></a></p>
    <?php endif; ?>
</section>

<?php if ($remboursements !== []): ?>
    <section class="bloc" aria-labelledby="det-liste-titre">
        <h2 id="det-liste-titre"><?= e(t($nombre > 1 ? 'gremb.liste_n' : 'gremb.liste_1', ['n' => $nombre])) ?></h2>
        <div class="tre-defile">
            <table class="tableau det-liste det-liste--remb">
                <thead>
                    <tr>
                        <th scope="col"><?= e(t('gremb.col_remboursement')) ?></th>
                        <th scope="col"><?= e(t('gdettes.col_client')) ?></th>
                        <th scope="col"><?= e(t('gremb.col_compte')) ?></th>
                        <th scope="col"><?= e(t('gremb.encaisse_par')) ?></th>
                        <th scope="col" class="nombre"><?= e(t('gdettes.col_montant')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($remboursements as $r): $annule = $r['statut'] === 'annule'; ?>
                        <tr data-remboursement="<?= (int) $r['id'] ?>" class="<?= $annule ? 'det-ligne--close' : '' ?>">
                            <td class="det-cellule--titre">
                                <a class="det-lien-fort" href="/gestion/remboursements/<?= (int) $r['id'] ?>"><?= e(t('gremb.fiche_titre', ['id' => $r['id']])) ?></a>
                                <?php if ($annule): ?><span class="badge badge--erreur"><?= e(t('gremb.badge_annule')) ?></span><?php endif; ?>
                                <span class="tre-sous-texte"><?= e(A::dateHeure($r['cree_le'])) ?> · <?= e($r['etablissement_nom']) ?></span>
                            </td>
                            <td class="det-cellule--client"><a class="lien" href="/gestion/clients/<?= (int) $r['client_id'] ?>"><?= e($r['client_nom']) ?></a>
                                <span class="tre-sous-texte"><?= e(t((int) $r['nb_dettes'] > 1 ? 'gremb.n_dettes' : 'gremb.une_dette', ['n' => (int) $r['nb_dettes']])) ?></span></td>
                            <td class="det-cellule--compte"><span class="det-compte"><?= View::rendre('gestion/tresorerie/_icone', ['type' => $r['compte_type']]) ?><span><?= e($r['compte_nom']) ?></span></span></td>
                            <td class="det-cellule--personne"><?= e($r['utilisateur_nom'] ?? t('gventes.vendeur_inconnu')) ?></td>
                            <td class="nombre det-cellule--reste"><strong class="montant<?= $annule ? ' det-barre' : '' ?>"><?= e(D::montant($r['montant'], $r['devise_id'], $devises)) ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= View::rendre('gestion/dettes/_pages', ['page' => $page, 'pages' => $pages]) ?>
    </section>
<?php elseif ($filtre || $totaux['annules'] > 0): ?>
    <p class="bloc aide"><?= e(t('gremb.aucun')) ?></p>
<?php endif; ?>
