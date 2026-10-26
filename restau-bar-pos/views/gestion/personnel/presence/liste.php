<?php
/**
 * Personnel > Présence > Pointages : toutes les présences d'une période, filtres, journal des corrections.
 * @var ?string $flash
 * @var array $periode
 * @var array $etablissements
 * @var ?array $etab
 * @var list<array> $employes
 * @var ?int $employe
 * @var list<array> $pointages
 * @var list<array> $corrections
 * @var bool $peutCorriger
 * @var ?array $porteeCorriger
 */

use App\Controllers\Gestion\PersonnelAffichage as P;
use App\Controllers\PointageController;
use App\Core\View;

$source = static fn (?string $s): string => $s ? t('pers.source_' . $s) : '';
$corrigeable = static fn (array $p): bool => $peutCorriger && ($porteeCorriger === null || in_array((int) $p['etablissement_id'], $porteeCorriger, true));
?>
<?= View::rendre('gestion/personnel/_onglets-presence', ['actif' => 'pointages']) ?>

<div class="tre-entete">
    <div>
        <h1><?= e(t('pers.pointages_titre')) ?></h1>
        <p class="aide"><?= e(t('pers.pointages_aide')) ?></p>
    </div>
    <?php if ($peutCorriger): ?>
        <div class="tre-entete__actions"><a class="bouton bouton--secondaire" href="/gestion/personnel/presence/pointages/nouveau<?= $employe ? '?employe=' . $employe : '' ?>">+ <?= e(t('pers.ajouter_pointage')) ?></a></div>
    <?php endif; ?>
</div>

<?php if ($flash): ?><p class="alerte" role="status"><?= e($flash) ?></p><?php endif; ?>

<section class="bloc tre-filtres">
    <?= View::rendre('gestion/tresorerie/_periode', ['periode' => $periode, 'periodes' => ['jour', '7j', 'mois', 'mois_precedent'], 'action' => '/gestion/personnel/presence/pointages',
        'garder' => ['employe' => $employe, 'etablissement' => $etab['id'] ?? null]]) ?>
    <?= View::rendre('gestion/personnel/_etablissements', ['etablissements' => $etablissements, 'etab' => $etab, 'tous' => true]) ?>
    <form method="get" action="/gestion/personnel/presence/pointages" class="per-filtres__formulaire">
        <?php foreach (['periode' => $periode['cle'] !== 'perso' ? $periode['cle'] : null, 'du' => $periode['cle'] === 'perso' ? $periode['du'] : null, 'au' => $periode['cle'] === 'perso' ? $periode['au'] : null, 'etablissement' => $etab['id'] ?? null] as $n => $v): if ($v === null) continue; ?>
            <input type="hidden" name="<?= $n ?>" value="<?= e((string) $v) ?>">
        <?php endforeach; ?>
        <div class="champ">
            <label for="filtre-employe"><?= e(t('pers.col_employe')) ?></label>
            <select id="filtre-employe" name="employe">
                <option value=""><?= e(t('pers.tous_employes')) ?></option>
                <?php foreach ($employes as $e): ?><option value="<?= (int) $e['id'] ?>" <?= $employe === (int) $e['id'] ? 'selected' : '' ?>><?= e($e['nom']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <button class="bouton bouton--secondaire" type="submit"><?= e(t('tresorerie.afficher')) ?></button>
    </form>
</section>

<section class="bloc" aria-labelledby="per-pointages">
    <h2 id="per-pointages"><?= e(t('pers.n_pointages', ['n' => count($pointages)])) ?></h2>
    <?php if ($pointages === []): ?>
        <p class="aide"><?= e(t('pers.aucun_pointage')) ?></p>
    <?php else: ?>
        <div class="defile">
            <table class="tableau per-tableau">
                <thead><tr>
                    <th scope="col"><?= e(t('pers.journee')) ?></th><th scope="col"><?= e(t('pers.col_employe')) ?></th>
                    <th scope="col"><?= e(t('pers.arrivee')) ?></th><th scope="col"><?= e(t('pers.depart')) ?></th>
                    <th scope="col" class="nombre"><?= e(t('pers.duree')) ?></th><th scope="col"><span class="visuellement-cache"><?= e(t('pers.actions')) ?></span></th>
                </tr></thead>
                <tbody>
                    <?php foreach ($pointages as $p): ?>
                        <tr id="pointage-<?= (int) $p['id'] ?>" data-pointage="<?= (int) $p['id'] ?>">
                            <td><?= e(P::jourCourt($p['journee'])) ?><span class="tre-sous-texte"><?= e($p['etablissement_nom']) ?></span></td>
                            <td><?= e($p['employe_nom']) ?></td>
                            <td><strong class="montant"><?= e(P::heure($p['arrivee'])) ?></strong><span class="tre-sous-texte"><?= e($source($p['arrivee_source']) . ($p['arrivee_distance'] !== null ? ' · ' . PointageController::distanceLisible((int) $p['arrivee_distance']) : '') . ($p['arrivee_valide_nom'] ? ' · ' . t('pers.valide_par', ['nom' => $p['arrivee_valide_nom']]) : '')) ?></span></td>
                            <td><?php if ($p['depart']): ?><strong class="montant"><?= e(P::heure($p['depart'])) ?></strong><span class="tre-sous-texte"><?= e($source($p['depart_source']) . ($p['depart_distance'] !== null ? ' · ' . PointageController::distanceLisible((int) $p['depart_distance']) : '') . ($p['depart_valide_nom'] ? ' · ' . t('pers.valide_par', ['nom' => $p['depart_valide_nom']]) : '')) ?></span><?php else: ?><span class="badge badge--braise"><?= e(t('pers.sans_depart')) ?></span><?php endif; ?></td>
                            <td class="nombre"><?= $p['depart'] ? e(P::duree((int) round((strtotime($p['depart']) - strtotime($p['arrivee'])) / 60))) : '—' ?><?php if ($p['corrections'] > 0): ?><span class="tre-sous-texte"><?= e(t('pers.corrige')) ?></span><?php endif; ?></td>
                            <td><?php if ($corrigeable($p)): ?><a class="lien" href="/gestion/personnel/presence/pointages/<?= (int) $p['id'] ?>"><?= e(t('pers.corriger')) ?></a><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php if ($corrections !== []): ?>
    <section class="bloc" aria-labelledby="per-corrections">
        <h2 id="per-corrections"><?= e(t('pers.corrections_recentes')) ?></h2>
        <?= View::rendre('gestion/personnel/presence/_corrections', ['corrections' => $corrections, 'avecNom' => true]) ?>
    </section>
<?php endif; ?>
