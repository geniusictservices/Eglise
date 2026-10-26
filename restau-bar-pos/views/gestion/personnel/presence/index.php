<?php
/**
 * Personnel > Présence : tableau d'une journée d'exploitation dans un établissement (planning et pointages).
 * @var ?string $flash
 * @var array $etablissements
 * @var ?array $etab
 * @var string $jour
 * @var list<array{employe: array, jour: ?array}> $lignes
 * @var bool $peutCorriger
 * @var bool $peutGerer
 * @var bool $sansPosition
 * @var bool $sansBorne
 */

use App\Controllers\Gestion\PersonnelAffichage as P;
use App\Controllers\PointageController;
use App\Core\View;

$precedent = date('Y-m-d', strtotime($jour . ' -1 day'));
$suivant = date('Y-m-d', strtotime($jour . ' +1 day'));
$compte = ['present' => 0, 'retard' => 0, 'absent' => 0, 'planifies' => 0];
foreach ($lignes as $l) {
    $j = $l['jour'];
    if ($j === null) { continue; }
    $compte['planifies'] += $j['creneaux'] ? 1 : 0;
    $compte['present'] += $j['en_cours'] ? 1 : 0;
    $compte['retard'] += $j['retard'] > 0 ? 1 : 0;
    $compte['absent'] += $j['statut'] === 'absent' ? 1 : 0;
}
$source = static fn (?string $s): string => $s ? t('pers.source_' . $s) : '';
?>
<?= View::rendre('gestion/personnel/_onglets-presence', ['actif' => 'jour']) ?>

<div class="tre-entete">
    <div>
        <h1><?= e(t('pers.presence_titre')) ?></h1>
        <p class="aide"><?= e(t('pers.presence_aide')) ?></p>
    </div>
    <?php if ($peutCorriger): ?>
        <div class="tre-entete__actions"><a class="bouton bouton--secondaire" href="/gestion/personnel/presence/pointages/nouveau?jour=<?= e($jour) ?>&amp;etablissement=<?= (int) $etab['id'] ?>">+ <?= e(t('pers.ajouter_pointage')) ?></a></div>
    <?php endif; ?>
</div>

<?php if ($flash): ?><p class="alerte" role="status"><?= e($flash) ?></p><?php endif; ?>
<?php if ($etab && ($sansPosition || $sansBorne)): ?>
    <div class="tre-avertissement per-reglage-manquant" role="note">
        <p class="tre-avertissement__titre"><?= e(t('pers.a_regler')) ?></p>
        <ul>
            <?php if ($sansPosition): ?><li><?= e(t('pers.a_regler_position', ['etablissement' => $etab['nom']])) ?> <a class="lien" href="/gestion/personnel/presence/reglages?etablissement=<?= (int) $etab['id'] ?>"><?= e(t('pers.onglet_reglages')) ?></a></li><?php endif; ?>
            <?php if ($sansBorne): ?><li><?= e(t('pers.a_regler_borne')) ?> <a class="lien" href="/gestion/personnel/presence/bornes"><?= e(t('pers.onglet_bornes')) ?></a></li><?php endif; ?>
        </ul>
    </div>
<?php endif; ?>

<section class="bloc per-filtres">
    <?= View::rendre('gestion/personnel/_etablissements', ['etablissements' => $etablissements, 'etab' => $etab]) ?>
    <nav class="per-jours" aria-label="<?= e(t('pers.journee')) ?>">
        <a class="bouton bouton--secondaire bouton--petit" href="<?= e(urlAvec(['jour' => $precedent])) ?>" rel="prev">← <span class="visuellement-cache"><?= e(t('pers.jour_precedent')) ?></span><?= e(P::jourCourt($precedent)) ?></a>
        <form method="get" action="/gestion/personnel/presence" class="per-jours__choix">
            <?php if ($etab): ?><input type="hidden" name="etablissement" value="<?= (int) $etab['id'] ?>"><?php endif; ?>
            <label class="visuellement-cache" for="jour"><?= e(t('pers.journee')) ?></label>
            <input type="date" id="jour" name="jour" value="<?= e($jour) ?>">
            <button class="bouton bouton--secondaire bouton--petit" type="submit"><?= e(t('tresorerie.afficher')) ?></button>
        </form>
        <a class="bouton bouton--secondaire bouton--petit" href="<?= e(urlAvec(['jour' => $suivant])) ?>" rel="next"><?= e(P::jourCourt($suivant)) ?> →<span class="visuellement-cache"><?= e(t('pers.jour_suivant')) ?></span></a>
    </nav>
</section>

<div class="chiffres per-chiffres">
    <div class="chiffre"><span class="chiffre__valeur" data-compte="planifies"><?= $compte['planifies'] ?></span><span class="chiffre__libelle"><?= e(t('pers.compte_planifies')) ?></span></div>
    <div class="chiffre"><span class="chiffre__valeur" data-compte="present"><?= $compte['present'] ?></span><span class="chiffre__libelle"><?= e(t('pers.compte_presents')) ?></span></div>
    <div class="chiffre"><span class="chiffre__valeur" data-compte="retard"><?= $compte['retard'] ?></span><span class="chiffre__libelle"><?= e(t('pers.compte_retards')) ?></span></div>
    <div class="chiffre"><span class="chiffre__valeur" data-compte="absent"><?= $compte['absent'] ?></span><span class="chiffre__libelle"><?= e(t('pers.compte_absents')) ?></span></div>
</div>

<section class="bloc" aria-labelledby="per-jour-titre">
    <h2 id="per-jour-titre"><?= e(t('pers.journee_du', ['jour' => P::jourCourt($jour)])) ?></h2>
    <?php if ($lignes === []): ?>
        <p class="aide"><?= e(t('pers.aucun_employe_etab')) ?></p>
    <?php else: ?>
        <ul class="per-presences">
            <?php foreach ($lignes as $l): $e = $l['employe']; $j = $l['jour']; ?>
                <li class="per-presence" data-employe="<?= (int) $e['id'] ?>">
                    <div class="per-presence__qui">
                        <?= View::rendre('partials/_avatar', ['nom' => $e['nom'], 'photo' => $e['avatar'] ?? null, 'classe' => 'avatar--petit']) ?>
                        <span><a class="det-lien-fort" href="/gestion/personnel/employes/<?= (int) $e['id'] ?>"><?= e($e['nom']) ?></a><span class="tre-sous-texte"><?= e((string) $e['poste']) ?></span></span>
                    </div>
                    <div class="per-presence__prevu">
                        <?php foreach ($j['creneaux'] ?? [] as $c): ?>
                            <span class="per-creneau"><?= e(P::heure($c['debut']) . ' – ' . P::heure($c['fin'])) ?><?= $c['poste'] ? ' · ' . e(t('pers.poste_' . $c['poste'])) : '' ?></span>
                        <?php endforeach; ?>
                        <?php if (empty($j['creneaux'])): ?><span class="tre-sous-texte"><?= e(t('pers.non_planifie')) ?></span><?php endif; ?>
                    </div>
                    <div class="per-presence__reel">
                        <?php foreach ($j['pointages'] ?? [] as $p): ?>
                            <span class="per-pointage">
                                <?php if ($peutCorriger): ?><a class="lien" href="/gestion/personnel/presence/pointages/<?= (int) $p['id'] ?>"><?php endif; ?>
                                <?= e(P::heure($p['arrivee'])) ?> → <?= e($p['depart'] ? P::heure($p['depart']) : '…') ?>
                                <?php if ($peutCorriger): ?></a><?php endif; ?>
                                <span class="tre-sous-texte"><?= e($source($p['arrivee_source']) . ($p['arrivee_distance'] !== null ? ' · ' . PointageController::distanceLisible((int) $p['arrivee_distance']) : '')) ?></span>
                            </span>
                        <?php endforeach; ?>
                    </div>
                    <div class="per-presence__statut"><?= View::rendre('gestion/personnel/_statut-jour', ['jour' => $j]) ?></div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
