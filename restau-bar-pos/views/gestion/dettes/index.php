<?php
/**
 * Ventes > Dettes : filtres (statut, échéance, période, établissement, responsable, servi par, devise, garant en attente),
 * totaux par devise (reste dû, dont en retard), ancienneté des dettes ouvertes (0–30, 31–60, plus de 60 jours depuis
 * la création), liste paginée. Les relances partent seules (Paramètres > Messagerie) : pas de bouton ici.
 * @var ?string $flash
 * @var array $periode
 * @var array $filtres
 * @var string $statutChoisi
 * @var list<array> $dettes
 * @var int $nombre
 * @var int $page
 * @var int $pages
 * @var array $totaux devises, tranches, nb
 * @var array<int, array> $devises
 * @var list<int> $devisesPresentes
 * @var array $etablissements
 * @var array $personnes responsables, servis
 * @var ?array $client
 */

use App\Controllers\Gestion\DetteAffichage as D;
use App\Core\View;
use App\Repositories\GestionDetteRepository;

$filtre = $filtres['etablissement'] || $filtres['responsable'] || $filtres['servi'] || $filtres['garant'] || $filtres['echeance']
    || $filtres['devise'] || $filtres['client'] || $statutChoisi !== 'ouverte' || $periode['cle'] !== 'tout';
$parDevise = D::ordonner($totaux['devises'], $devises);
$devisesTranches = [];
foreach ($totaux['tranches'] as $parTranche) {
    $devisesTranches += $parTranche;
}
$devisesTranches = array_keys(D::ordonner($devisesTranches, $devises));
?>
<link rel="stylesheet" href="/assets/css/gestion-tresorerie.css">
<link rel="stylesheet" href="/assets/css/gestion-dettes.css">

<div class="tre-entete">
    <div>
        <h1><?= e(t('gdettes.titre')) ?></h1>
        <p class="aide"><?= e(t('gdettes.aide')) ?></p>
    </div>
</div>

<?php if ($flash): ?>
    <p class="alerte" role="status"><?= e($flash) ?></p>
<?php endif; ?>

<?php if ($client): ?>
    <p class="det-filtre-client">
        <span><?= e(t('gdettes.filtre_client', ['nom' => $client['nom']])) ?></span>
        <a class="lien" href="/gestion/clients/<?= (int) $client['id'] ?>"><?= e(t('gdettes.voir_client')) ?></a>
        <a class="lien" href="<?= e(urlAvec(['client' => null, 'page' => null])) ?>"><?= e(t('gdettes.retirer_filtre_client')) ?></a>
    </p>
<?php endif; ?>

<section class="bloc tre-filtres" aria-label="<?= e(t('gdettes.filtres')) ?>">
    <nav class="tre-puces" aria-label="<?= e(t('gdettes.statut')) ?>">
        <?php foreach (array_merge(GestionDetteRepository::STATUTS, ['toutes']) as $s): ?>
            <a class="puce" href="<?= e(urlAvec(['statut' => $s === 'ouverte' ? null : $s, 'page' => null])) ?>"<?= $statutChoisi === $s ? ' aria-current="true"' : '' ?>><?= e(t('gdettes.statuts_' . $s)) ?></a>
        <?php endforeach; ?>
    </nav>
    <nav class="tre-puces" aria-label="<?= e(t('gdettes.col_echeance')) ?>">
        <a class="puce" href="<?= e(urlAvec(['echeance' => null, 'page' => null])) ?>"<?= !$filtres['echeance'] ? ' aria-current="true"' : '' ?>><?= e(t('gdettes.echeance_toutes')) ?></a>
        <a class="puce" href="<?= e(urlAvec(['echeance' => 'retard', 'page' => null])) ?>"<?= $filtres['echeance'] === 'retard' ? ' aria-current="true"' : '' ?>><?= e(t('gdettes.echeance_retard')) ?></a>
        <a class="puce" href="<?= e(urlAvec(['echeance' => 'a_venir', 'page' => null])) ?>"<?= $filtres['echeance'] === 'a_venir' ? ' aria-current="true"' : '' ?>><?= e(t('gdettes.echeance_a_venir')) ?></a>
    </nav>
    <?= View::rendre('gestion/tresorerie/_periode', ['periode' => $periode, 'periodes' => ['7j', 'mois', 'mois_precedent', 'annee', 'tout'], 'action' => '/gestion/dettes', 'avecDates' => false]) ?>
    <form class="tre-filtres__formulaire det-filtres" method="get" action="/gestion/dettes">
        <?php foreach (['statut' => $statutChoisi !== 'ouverte' ? $statutChoisi : null, 'echeance' => $filtres['echeance'], 'client' => $filtres['client'], 'periode' => $periode['cle'] !== 'perso' && $periode['cle'] !== 'tout' ? $periode['cle'] : null] as $nom => $valeur): ?>
            <?php if ($valeur): ?><input type="hidden" name="<?= e($nom) ?>" value="<?= e((string) $valeur) ?>"><?php endif; ?>
        <?php endforeach; ?>
        <div class="champ">
            <label for="filtre-du"><?= e(t('gdettes.creee_du')) ?></label>
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
            <label for="filtre-responsable"><?= e(t('gdettes.col_responsable')) ?></label>
            <select id="filtre-responsable" name="responsable">
                <option value=""><?= e(t('gdettes.tous_responsables')) ?></option>
                <?php foreach ($personnes['responsables'] as $p): ?>
                    <option value="<?= (int) $p['id'] ?>" <?= $filtres['responsable'] === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['nom']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="champ">
            <label for="filtre-servi"><?= e(t('gdettes.servi_par')) ?></label>
            <select id="filtre-servi" name="servi">
                <option value=""><?= e(t('gdettes.tous_serveurs')) ?></option>
                <?php foreach ($personnes['servis'] as $p): ?>
                    <option value="<?= (int) $p['id'] ?>" <?= $filtres['servi'] === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['nom']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php if (count($devisesPresentes) > 1): ?>
            <div class="champ">
                <label for="filtre-devise"><?= e(t('gestion.devise')) ?></label>
                <select id="filtre-devise" name="devise">
                    <option value=""><?= e(t('gdettes.toutes_devises')) ?></option>
                    <?php foreach ($devisesPresentes as $did): ?>
                        <option value="<?= (int) $did ?>" <?= $filtres['devise'] === $did ? 'selected' : '' ?>><?= e($devises[$did]['code']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>
        <label class="coche det-coche det-filtres__coche">
            <input type="checkbox" name="garant" value="1" <?= $filtres['garant'] ? 'checked' : '' ?>>
            <span><?= e(t('gdettes.filtre_garant')) ?></span>
        </label>
        <div class="tre-filtres__boutons">
            <button class="bouton bouton--secondaire" type="submit"><?= e(t('tresorerie.afficher')) ?></button>
            <?php if ($filtre): ?>
                <a class="lien" href="/gestion/dettes"><?= e(t('gventes.effacer_filtres')) ?></a>
            <?php endif; ?>
        </div>
    </form>
</section>

<section class="tre-totaux" aria-labelledby="det-totaux-titre">
    <h2 class="visuellement-cache" id="det-totaux-titre"><?= e(t('gdettes.totaux')) ?></h2>
    <?php if ($totaux['nb'] === 0): ?>
        <p class="bloc aide"><?= e(t('gdettes.aucune')) ?></p>
    <?php else: ?>
        <div class="chiffres det-chiffres">
            <div class="chiffre">
                <span class="chiffre__valeur" data-total="nb"><?= (int) $totaux['nb'] ?></span>
                <span class="chiffre__libelle"><?= e(t($totaux['nb'] > 1 ? 'gdettes.n_dettes' : 'gdettes.une_dette')) ?></span>
            </div>
            <?php foreach ($parDevise as $deviseId => $t): if ($t['reste'] <= 0 && $statutChoisi === 'ouverte') { continue; } ?>
                <div class="chiffre" data-devise="<?= e($devises[$deviseId]['code'] ?? '') ?>">
                    <span class="chiffre__valeur" data-total="reste-<?= (int) $deviseId ?>"><?= e(D::montant($t['reste'], $deviseId, $devises)) ?></span>
                    <span class="chiffre__libelle">
                        <?= e(t('gdettes.reste_en', ['code' => $devises[$deviseId]['code'] ?? ''])) ?>
                        <?php if ($t['retard'] > 0): ?><span class="det-retard" data-total="retard-<?= (int) $deviseId ?>"><?= e(t('gdettes.dont_retard', ['montant' => D::montant($t['retard'], $deviseId, $devises)])) ?></span><?php endif; ?>
                        <span class="tre-sous-texte"><?= e(t('gdettes.accorde_total', ['montant' => D::montant($t['montant'], $deviseId, $devises)])) ?></span>
                    </span>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if ($devisesTranches !== []): ?>
            <div class="bloc det-anciennete">
                <h3 class="det-sous-titre"><?= e(t('gdettes.anciennete')) ?></h3>
                <p class="aide"><?= e(t('gdettes.anciennete_aide')) ?></p>
                <table class="tableau det-tranches">
                    <thead>
                        <tr>
                            <th scope="col"><?= e(t('gdettes.tranche')) ?></th>
                            <?php foreach ($devisesTranches as $did): ?><th scope="col" class="nombre"><?= e($devises[$did]['code'] ?? '') ?></th><?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (GestionDetteRepository::TRANCHES as $tranche): ?>
                            <tr data-tranche="<?= e($tranche) ?>">
                                <th scope="row"><?= e(t('gdettes.tranche_' . $tranche)) ?></th>
                                <?php foreach ($devisesTranches as $did): $m = $totaux['tranches'][$tranche][$did] ?? 0.0; ?>
                                    <td class="nombre" data-devise="<?= e($devises[$did]['code'] ?? '') ?>"><span class="montant<?= $tranche === 'plus_60' && $m > 0 ? ' det-retard' : '' ?>"><?= e($m > 0 ? D::montant($m, $did, $devises) : '—') ?></span></td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</section>

<?php if ($dettes !== []): ?>
    <section class="bloc" aria-labelledby="det-liste-titre">
        <h2 id="det-liste-titre"><?= e(t($nombre > 1 ? 'gdettes.liste_n' : 'gdettes.liste_1', ['n' => $nombre])) ?></h2>
        <?= View::rendre('gestion/dettes/_liste', ['dettes' => $dettes, 'devises' => $devises, 'avecClient' => true, 'liens' => true]) ?>
        <?= View::rendre('gestion/dettes/_pages', ['page' => $page, 'pages' => $pages]) ?>
    </section>
<?php endif; ?>
