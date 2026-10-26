<?php
/**
 * Salle > Plan de salle : éditeur au glisser-déposer (gestion-salle.js) et formulaire de chaque table.
 * @var ?string $flash
 * @var ?string $erreur
 * @var list<array> $etablissements
 * @var array $etab
 * @var list<array> $zones
 * @var int $zone zone affichée (0 : tables sans zone)
 * @var list<array> $tables tables de la zone affichée
 * @var list<array> $toutes
 * @var array<int, array> $etat statut de chaque table (libre, occupee, addition, reservee)
 * @var bool $peutModifier
 */
use App\Repositories\SalleRepository;

$jeton = e(App\Core\Session::jetonCsrf());
$sansZone = array_filter($toutes, static fn (array $t) => $t['zone_id'] === null) !== [] || $zones === [];
$formes = ['carree' => t('gsalle.forme_carree'), 'ronde' => t('gsalle.forme_ronde'), 'rectangle' => t('gsalle.forme_rectangle'), 'banquette' => t('gsalle.forme_banquette')];
$statuts = ['libre' => t('note.libre'), 'occupee' => t('note.occupee'), 'addition' => t('note.addition_demandee'), 'reservee' => t('note.reservee')];
?>
<link rel="stylesheet" href="/assets/css/gestion-salle.css">
<div class="sal-entete">
    <div>
        <h1><?= e(t('gsalle.plan_titre')) ?></h1>
        <p class="aide"><?= e(t($peutModifier ? 'gsalle.plan_aide' : 'gsalle.plan_aide_lecture')) ?></p>
    </div>
    <a class="bouton bouton--secondaire" href="/caisse?e=<?= e($etab['slug']) ?>"><?= e(t('gsalle.ouvrir_caisse')) ?></a>
</div>
<?php if ($flash): ?><p class="alerte" role="status"><?= e($flash) ?></p><?php endif; ?>
<?php if ($erreur): ?><p class="alerte alerte--erreur" role="alert"><?= e($erreur) ?></p><?php endif; ?>

<?= App\Core\View::rendre('gestion/salle/_etablissements', ['etablissements' => $etablissements, 'etab' => $etab, 'garder' => []]) ?>
<nav class="sal-puces" aria-label="<?= e(t('gestion.zone')) ?>">
    <?php foreach ($zones as $z): ?>
        <a class="puce" href="?etablissement=<?= (int) $etab['id'] ?>&amp;zone=<?= (int) $z['id'] ?>"<?= $zone === (int) $z['id'] ? ' aria-current="true"' : '' ?>><?= e(champ($z, 'nom')) ?></a>
    <?php endforeach; ?>
    <?php if ($sansZone): ?>
        <a class="puce" href="?etablissement=<?= (int) $etab['id'] ?>&amp;zone=0"<?= $zone === 0 ? ' aria-current="true"' : '' ?>><?= e($zones === [] ? t('note.salle') : t('note.sans_zone')) ?></a>
    <?php endif; ?>
</nav>

<section class="bloc sal-plan-bloc" aria-labelledby="sal-plan-titre">
    <h2 id="sal-plan-titre" class="visuellement-cache"><?= e(t('gsalle.plan_titre')) ?></h2>
    <div class="sal-plan" id="sal-plan" data-colonnes="<?= SalleRepository::COLONNES ?>" data-rangees="<?= SalleRepository::RANGEES ?>"
         data-csrf="<?= $jeton ?>" data-modifier="<?= $peutModifier ? '1' : '0' ?>" data-enregistre="<?= e(t('gsalle.positions_enregistrees')) ?>"
         data-erreur="<?= e(t('gsalle.positions_erreur')) ?>" style="--colonnes: <?= SalleRepository::COLONNES ?>; --rangees: <?= SalleRepository::RANGEES ?>">
        <?php foreach ($tables as $t): $statut = $etat[(int) $t['id']]['statut'] ?? 'libre'; ?>
            <button type="button" class="sal-table sal-table--<?= e($t['forme']) ?> sal-table--<?= e($statut) ?>" id="plan-table-<?= (int) $t['id'] ?>"
                    data-id="<?= (int) $t['id'] ?>" data-x="<?= (int) $t['x'] ?>" data-y="<?= (int) $t['y'] ?>" data-l="<?= (int) $t['largeur'] ?>" data-h="<?= (int) $t['hauteur'] ?>"
                    style="--x: <?= (int) $t['x'] ?>; --y: <?= (int) $t['y'] ?>; --l: <?= (int) $t['largeur'] ?>; --h: <?= (int) $t['hauteur'] ?>"
                    aria-label="<?= e(t('gsalle.table_aria', ['nom' => $t['nom'], 'places' => (int) $t['places'], 'statut' => $statuts[$statut]])) ?>"
                    aria-describedby="sal-plan-clavier">
                <span class="sal-table__nom"><?= e($t['nom']) ?></span>
                <span class="sal-table__places"><?= e(t('note.places', ['n' => (int) $t['places']])) ?></span>
                <span class="sal-table__statut"><?= e($statuts[$statut]) ?></span>
                <?php if ($peutModifier): ?><span class="sal-table__poignee" aria-hidden="true"></span><?php endif; ?>
            </button>
        <?php endforeach; ?>
    </div>
    <?php if ($tables === []): ?><p class="aide"><?= e(t('gsalle.zone_vide')) ?></p><?php endif; ?>
    <p class="aide" id="sal-plan-clavier"><?= e(t($peutModifier ? 'gsalle.clavier' : 'gsalle.plan_aide_lecture')) ?></p>
    <p class="sal-etat" id="sal-plan-etat" role="status" aria-live="polite"></p>
</section>

<?php if ($peutModifier): ?>
    <section class="bloc" aria-labelledby="sal-ajouter-titre">
        <h2 id="sal-ajouter-titre"><?= e(t('gsalle.ajouter')) ?></h2>
        <form class="sal-form" method="post" action="/gestion/salle/tables">
            <input type="hidden" name="csrf" value="<?= $jeton ?>">
            <input type="hidden" name="etablissement" value="<?= (int) $etab['id'] ?>">
            <label class="champ"><span><?= e(t('gsalle.nom')) ?></span><input class="saisie" name="nom" maxlength="32" required placeholder="<?= e(t('gsalle.nom_exemple')) ?>"></label>
            <label class="champ"><span><?= e(t('gsalle.places')) ?></span><input class="saisie" name="places" type="number" min="1" max="50" value="4" inputmode="numeric"></label>
            <label class="champ"><span><?= e(t('gsalle.forme')) ?></span>
                <select class="saisie" name="forme"><?php foreach ($formes as $code => $libelle): ?><option value="<?= e($code) ?>"><?= e($libelle) ?></option><?php endforeach; ?></select></label>
            <label class="champ"><span><?= e(t('gestion.zone')) ?></span>
                <select class="saisie" name="zone_id">
                    <?php foreach ($zones as $z): ?><option value="<?= (int) $z['id'] ?>" <?= $zone === (int) $z['id'] ? 'selected' : '' ?>><?= e(champ($z, 'nom')) ?></option><?php endforeach; ?>
                    <option value="0" <?= $zone === 0 ? 'selected' : '' ?>><?= e(t('note.sans_zone')) ?></option>
                </select></label>
            <button class="bouton" type="submit"><?= e(t('gsalle.ajouter_bouton')) ?></button>
        </form>
    </section>
<?php endif; ?>

<?php if ($tables !== []): ?>
    <section class="bloc" aria-labelledby="sal-tables-titre">
        <h2 id="sal-tables-titre"><?= e(t('gsalle.tables_zone', ['n' => count($tables)])) ?></h2>
        <ul class="sal-tables">
            <?php foreach ($tables as $t): ?>
                <li class="sal-ligne" id="table-<?= (int) $t['id'] ?>">
                    <?php if ($peutModifier): ?>
                        <details class="sal-details">
                        <summary class="sal-details__resume"><strong><?= e($t['nom']) ?></strong> · <?= e(t('note.places', ['n' => (int) $t['places']])) ?> · <?= e($formes[$t['forme']]) ?></summary>
                        <form class="sal-form sal-form--table" method="post" action="/gestion/salle/tables/<?= (int) $t['id'] ?>" data-table="<?= (int) $t['id'] ?>">
                            <input type="hidden" name="csrf" value="<?= $jeton ?>">
                            <label class="champ"><span><?= e(t('gsalle.nom')) ?></span><input class="saisie" name="nom" maxlength="32" required value="<?= e($t['nom']) ?>"></label>
                            <label class="champ"><span><?= e(t('gsalle.places')) ?></span><input class="saisie" name="places" type="number" min="1" max="50" value="<?= (int) $t['places'] ?>"></label>
                            <label class="champ"><span><?= e(t('gsalle.forme')) ?></span>
                                <select class="saisie" name="forme"><?php foreach ($formes as $code => $libelle): ?><option value="<?= e($code) ?>" <?= $t['forme'] === $code ? 'selected' : '' ?>><?= e($libelle) ?></option><?php endforeach; ?></select></label>
                            <label class="champ"><span><?= e(t('gestion.zone')) ?></span>
                                <select class="saisie" name="zone_id">
                                    <?php foreach ($zones as $z): ?><option value="<?= (int) $z['id'] ?>" <?= (int) $t['zone_id'] === (int) $z['id'] ? 'selected' : '' ?>><?= e(champ($z, 'nom')) ?></option><?php endforeach; ?>
                                    <option value="0" <?= $t['zone_id'] === null ? 'selected' : '' ?>><?= e(t('note.sans_zone')) ?></option>
                                </select></label>
                            <label class="champ sal-champ--court"><span><?= e(t('gsalle.colonne')) ?></span><input class="saisie" name="x" type="number" min="0" max="<?= SalleRepository::COLONNES - 1 ?>" value="<?= (int) $t['x'] ?>" data-champ="x"></label>
                            <label class="champ sal-champ--court"><span><?= e(t('gsalle.rangee')) ?></span><input class="saisie" name="y" type="number" min="0" max="<?= SalleRepository::RANGEES - 1 ?>" value="<?= (int) $t['y'] ?>" data-champ="y"></label>
                            <label class="champ sal-champ--court"><span><?= e(t('gsalle.largeur')) ?></span><input class="saisie" name="largeur" type="number" min="1" max="<?= SalleRepository::COLONNES ?>" value="<?= (int) $t['largeur'] ?>" data-champ="l"></label>
                            <label class="champ sal-champ--court"><span><?= e(t('gsalle.hauteur')) ?></span><input class="saisie" name="hauteur" type="number" min="1" max="<?= SalleRepository::RANGEES ?>" value="<?= (int) $t['hauteur'] ?>" data-champ="h"></label>
                            <div class="sal-form__actions">
                                <button class="bouton" type="submit"><?= e(t('gsalle.enregistrer')) ?></button>
                                <button class="bouton bouton--danger" type="submit" formaction="/gestion/salle/tables/<?= (int) $t['id'] ?>/retirer" formnovalidate><?= e(t('gsalle.retirer')) ?></button>
                            </div>
                        </form>
                        </details>
                    <?php else: ?>
                        <p><strong><?= e($t['nom']) ?></strong> · <?= e(t('note.places', ['n' => (int) $t['places']])) ?> · <?= e($formes[$t['forme']]) ?></p>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endif; ?>
<script src="/assets/js/gestion-salle.js" defer></script>
