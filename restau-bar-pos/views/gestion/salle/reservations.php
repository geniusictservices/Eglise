<?php
/**
 * Salle > Réservations d'une journée d'exploitation, et le formulaire d'ajout (ou de modification).
 * @var ?string $flash
 * @var list<array> $etablissements
 * @var array $etab
 * @var string $jour
 * @var list<array> $reservations
 * @var list<array> $tables
 * @var ?array $modifiee
 * @var ?array $saisie erreurs et valeurs d'une saisie refusée
 * @var bool $peutModifier
 */
use App\Core\Journee;

$jeton = e(App\Core\Session::jetonCsrf());
$valeurs = $saisie['valeurs'] ?? ($modifiee ? [
    'jour' => Journee::du($modifiee['debut'], $etab), 'heure' => date('H:i', strtotime($modifiee['debut'])), 'nom' => $modifiee['nom'],
    'telephone' => afficherTelephone($modifiee['telephone']), 'personnes' => $modifiee['personnes'], 'table_id' => $modifiee['table_id'], 'notes' => $modifiee['notes'],
] : ['jour' => $jour, 'heure' => '20:00', 'personnes' => 2]);
$badges = ['prevue' => 'badge--glace', 'arrivee' => 'badge--succes', 'annulee' => 'badge--neutre', 'absente' => 'badge--erreur'];
$veille = date('Y-m-d', strtotime($jour . ' -1 day'));
$lendemain = date('Y-m-d', strtotime($jour . ' +1 day'));
$personnes = array_sum(array_map(static fn (array $r) => in_array($r['statut'], ['prevue', 'arrivee'], true) ? (int) $r['personnes'] : 0, $reservations));
?>
<link rel="stylesheet" href="/assets/css/gestion-salle.css">
<div class="sal-entete">
    <div>
        <h1><?= e(t('gsalle.resa_titre')) ?></h1>
        <p class="aide"><?= e(t('gsalle.resa_aide', ['fin' => substr(Journee::fin($etab), 0, 5)])) ?></p>
    </div>
</div>
<?php if ($flash): ?><p class="alerte" role="status"><?= e($flash) ?></p><?php endif; ?>
<?= App\Core\View::rendre('gestion/salle/_etablissements', ['etablissements' => $etablissements, 'etab' => $etab, 'garder' => ['jour' => $jour]]) ?>

<nav class="sal-jours" aria-label="<?= e(t('gsalle.journee')) ?>">
    <a class="bouton bouton--secondaire" href="?etablissement=<?= (int) $etab['id'] ?>&amp;jour=<?= $veille ?>">← <?= e(t('gsalle.veille')) ?></a>
    <form method="get" action="/gestion/salle/reservations" class="sal-jour">
        <input type="hidden" name="etablissement" value="<?= (int) $etab['id'] ?>">
        <label class="visuellement-cache" for="resa-jour"><?= e(t('gsalle.journee')) ?></label>
        <input class="saisie" id="resa-jour" type="date" name="jour" value="<?= e($jour) ?>" onchange="this.form.submit()">
        <noscript><button class="bouton bouton--secondaire" type="submit"><?= e(t('gsalle.afficher')) ?></button></noscript>
    </form>
    <a class="bouton bouton--secondaire" href="?etablissement=<?= (int) $etab['id'] ?>&amp;jour=<?= $lendemain ?>"><?= e(t('gsalle.lendemain')) ?> →</a>
</nav>

<section class="bloc" aria-labelledby="resa-liste-titre">
    <h2 id="resa-liste-titre"><?= e(t('gsalle.resa_du', ['date' => date('d/m/Y', strtotime($jour)), 'n' => count($reservations), 'personnes' => $personnes])) ?></h2>
    <?php if ($reservations === []): ?>
        <p class="aide"><?= e(t('gsalle.resa_aucune')) ?></p>
    <?php else: ?>
        <ul class="sal-resas">
            <?php foreach ($reservations as $r): ?>
                <li class="sal-resa sal-resa--<?= e($r['statut']) ?>" id="resa-<?= (int) $r['id'] ?>">
                    <span class="sal-resa__heure"><?= e(date('H:i', strtotime($r['debut']))) ?></span>
                    <div class="sal-resa__texte">
                        <strong><?= e($r['nom']) ?></strong> · <?= e(t('gsalle.resa_personnes', ['n' => (int) $r['personnes']])) ?>
                        <?= $r['table_nom'] ? ' · ' . e(t('note.table', ['nom' => $r['table_nom']])) : '' ?>
                        <span class="badge <?= $badges[$r['statut']] ?>"><?= e(t('gsalle.resa_' . $r['statut'])) ?></span>
                        <br><small><?= $r['telephone'] ? '<a class="lien" href="tel:' . e($r['telephone']) . '">' . e(afficherTelephone($r['telephone'])) . '</a>' : '' ?><?= $r['notes'] ? ' · ' . e($r['notes']) : '' ?></small>
                    </div>
                    <?php if ($peutModifier): ?>
                        <div class="sal-resa__actions">
                            <a class="bouton bouton--secondaire bouton--petit" href="?etablissement=<?= (int) $etab['id'] ?>&amp;jour=<?= e($jour) ?>&amp;modifier=<?= (int) $r['id'] ?>#resa-form"><?= e(t('gsalle.modifier')) ?></a>
                            <?php foreach ($r['statut'] === 'prevue' ? ['arrivee', 'absente', 'annulee'] : ['prevue'] as $statut): ?>
                                <form method="post" action="/gestion/salle/reservations/<?= (int) $r['id'] ?>/statut">
                                    <input type="hidden" name="csrf" value="<?= $jeton ?>">
                                    <input type="hidden" name="statut" value="<?= e($statut) ?>">
                                    <button class="bouton bouton--secondaire bouton--petit" type="submit"><?= e(t('gsalle.resa_passer_' . $statut)) ?></button>
                                </form>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<?php if ($peutModifier): ?>
    <section class="bloc" id="resa-form" aria-labelledby="resa-form-titre">
        <h2 id="resa-form-titre"><?= e(t($modifiee ? 'gsalle.resa_modifier' : 'gsalle.resa_ajouter')) ?></h2>
        <?php foreach ($saisie['erreurs'] ?? [] as $erreur): ?><p class="alerte alerte--erreur" role="alert"><?= e($erreur) ?></p><?php endforeach; ?>
        <form class="sal-form" method="post" action="/gestion/salle/reservations<?= $modifiee ? '/' . (int) $modifiee['id'] : '' ?>">
            <input type="hidden" name="csrf" value="<?= $jeton ?>">
            <input type="hidden" name="etablissement" value="<?= (int) $etab['id'] ?>">
            <label class="champ"><span><?= e(t('gsalle.resa_nom')) ?></span><input class="saisie" name="nom" maxlength="80" required value="<?= e($valeurs['nom'] ?? '') ?>"></label>
            <label class="champ"><span><?= e(t('gsalle.resa_telephone')) ?></span><input class="saisie" name="telephone" type="tel" maxlength="32" value="<?= e($valeurs['telephone'] ?? '') ?>" placeholder="+243 97 000 0000"></label>
            <label class="champ sal-champ--court"><span><?= e(t('gsalle.resa_nb')) ?></span><input class="saisie" name="personnes" type="number" min="1" max="200" value="<?= (int) ($valeurs['personnes'] ?? 2) ?>"></label>
            <label class="champ"><span><?= e(t('gsalle.journee')) ?></span><input class="saisie" name="jour" type="date" required value="<?= e($valeurs['jour'] ?? $jour) ?>"></label>
            <label class="champ sal-champ--court"><span><?= e(t('gsalle.resa_heure')) ?></span><input class="saisie" name="heure" type="time" required value="<?= e($valeurs['heure'] ?? '20:00') ?>"></label>
            <label class="champ"><span><?= e(t('gsalle.resa_table')) ?></span>
                <select class="saisie" name="table_id">
                    <option value="0"><?= e(t('gsalle.resa_sans_table')) ?></option>
                    <?php foreach ($tables as $tb): ?>
                        <option value="<?= (int) $tb['id'] ?>" <?= (int) ($valeurs['table_id'] ?? 0) === (int) $tb['id'] ? 'selected' : '' ?>><?= e($tb['nom']) ?><?= $tb['zone_nom_fr'] ? ' · ' . e(champ($tb, 'zone_nom')) : '' ?> (<?= e(t('note.places', ['n' => (int) $tb['places']])) ?>)</option>
                    <?php endforeach; ?>
                </select></label>
            <label class="champ champ--large"><span><?= e(t('gsalle.resa_notes')) ?></span><input class="saisie" name="notes" maxlength="255" value="<?= e($valeurs['notes'] ?? '') ?>" placeholder="<?= e(t('gsalle.resa_notes_exemple')) ?>"></label>
            <div class="sal-form__actions">
                <button class="bouton" type="submit"><?= e(t($modifiee ? 'gsalle.enregistrer' : 'gsalle.resa_ajouter')) ?></button>
                <?php if ($modifiee): ?><a class="lien" href="?etablissement=<?= (int) $etab['id'] ?>&amp;jour=<?= e($jour) ?>"><?= e(t('caisse.annuler')) ?></a><?php endif; ?>
            </div>
        </form>
    </section>
<?php endif; ?>
