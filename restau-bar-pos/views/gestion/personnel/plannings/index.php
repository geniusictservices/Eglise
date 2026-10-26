<?php
/**
 * Personnel > Plannings : semaine d'un établissement, employés × jours, avec le résultat de la présence
 * pour les journées passées ; ajout de créneaux sur plusieurs jours, copie de la semaine précédente.
 * @var ?string $flash
 * @var array $etablissements
 * @var ?array $etab
 * @var string $lundi
 * @var list<array> $employes
 * @var array<int, array<string, list<array>>> $grille
 * @var array $bilan
 * @var array<string, string> $feries
 * @var list<string> $services
 * @var list<string> $postes
 * @var bool $peutGerer
 * @var array<string, string> $erreurs
 * @var ?array $saisie
 */

use App\Controllers\Gestion\PersonnelAffichage as P;
use App\Core\View;

$jours = [];
for ($i = 0; $i < 7; $i++) {
    $jours[] = date('Y-m-d', strtotime($lundi . " +$i days"));
}
$jeton = e(App\Core\Session::jetonCsrf());
$invalide = static fn (string $champ): string => isset($erreurs[$champ]) ? ' aria-invalid="true" aria-describedby="erreur-' . $champ . '"' : '';
$erreur = static fn (string $champ): string => isset($erreurs[$champ]) ? '<p class="champ__erreur" id="erreur-' . $champ . '">' . e($erreurs[$champ]) . '</p>' : '';
$s = $saisie ?? [];
$choisis = array_map('strval', is_array($s['journees'] ?? null) ? $s['journees'] : []);
$aujourdhui = date('Y-m-d');
?>
<?= View::rendre('gestion/personnel/_onglets-plannings', ['actif' => 'semaine']) ?>

<div class="tre-entete">
    <div>
        <h1><?= e(t('pers.plannings_titre')) ?></h1>
        <p class="aide"><?= e(t('pers.plannings_aide')) ?></p>
    </div>
</div>
<?php if ($flash): ?><p class="alerte" role="status"><?= e($flash) ?></p><?php endif; ?>
<?= View::rendre('gestion/tresorerie/_erreurs', ['erreurs' => array_values($erreurs)]) ?>

<section class="bloc per-filtres">
    <?= View::rendre('gestion/personnel/_etablissements', ['etablissements' => $etablissements, 'etab' => $etab]) ?>
    <nav class="per-jours" aria-label="<?= e(t('pers.semaine')) ?>">
        <a class="bouton bouton--secondaire bouton--petit" href="<?= e(urlAvec(['semaine' => date('Y-m-d', strtotime($lundi . ' -7 days'))])) ?>" rel="prev">← <?= e(t('pers.semaine_precedente')) ?></a>
        <strong class="per-jours__titre"><?= e(t('pers.semaine_du', ['du' => P::jour($lundi), 'au' => P::jour(end($jours))])) ?></strong>
        <a class="bouton bouton--secondaire bouton--petit" href="<?= e(urlAvec(['semaine' => date('Y-m-d', strtotime($lundi . ' +7 days'))])) ?>" rel="next"><?= e(t('pers.semaine_suivante')) ?> →</a>
    </nav>
</section>

<?php if ($etab): ?>
<section class="bloc" aria-labelledby="per-grille">
    <h2 id="per-grille" class="visuellement-cache"><?= e(t('pers.semaine')) ?></h2>
    <?php if ($employes === []): ?>
        <p class="aide"><?= e(t('pers.aucun_employe_etab')) ?></p>
    <?php else: ?>
        <div class="defile">
            <table class="tableau per-semaine">
                <thead>
                    <tr>
                        <th scope="col"><?= e(t('pers.col_employe')) ?></th>
                        <?php foreach ($jours as $j): ?>
                            <th scope="col" class="<?= $j === $aujourdhui ? 'per-semaine__aujourdhui' : '' ?>"><?= e(P::jourCourt($j)) ?><?php if (isset($feries[$j])): ?><span class="badge badge--glace" title="<?= e($feries[$j]) ?>"><?= e(t('pers.ferie')) ?></span><?php endif; ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($employes as $e): $eid = (int) $e['id']; ?>
                        <tr>
                            <th scope="row"><?= e($e['nom']) ?><span class="tre-sous-texte"><?= e((string) $e['poste']) ?></span></th>
                            <?php foreach ($jours as $j): $jour = $bilan[$eid]['jours'][$j] ?? null; ?>
                                <td class="per-semaine__case<?= $j === $aujourdhui ? ' per-semaine__aujourdhui' : '' ?>" data-jour="<?= $j ?>" data-employe="<?= $eid ?>">
                                    <?php foreach ($grille[$eid][$j] ?? [] as $c): ?>
                                        <div class="per-creneau-carte">
                                            <span class="per-creneau-carte__heures"><?= e(P::heure($c['debut']) . '–' . P::heure($c['fin'])) ?></span>
                                            <span class="tre-sous-texte"><?= e(trim(($c['poste'] ? t('pers.poste_' . $c['poste']) : '') . ($c['service'] ? ' · ' . $c['service'] : ''), ' ·')) ?></span>
                                            <?php if ($peutGerer): ?>
                                                <form method="post" action="/gestion/personnel/plannings/<?= (int) $c['id'] ?>/supprimer" class="per-creneau-carte__retirer">
                                                    <input type="hidden" name="csrf" value="<?= $jeton ?>">
                                                    <button class="bouton-icone" type="submit" title="<?= e(t('pers.retirer_creneau')) ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M6.4 5 5 6.4 10.6 12 5 17.6 6.4 19l5.6-5.6 5.6 5.6 1.4-1.4-5.6-5.6L19 6.4 17.6 5 12 10.6z"/></svg><span class="visuellement-cache"><?= e(t('pers.retirer_creneau')) ?></span></button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                    <?php if ($jour !== null && $jour['statut'] !== 'a_venir'): ?><?= View::rendre('gestion/personnel/_statut-jour', ['jour' => $jour]) ?><?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php if ($peutGerer && $employes !== []): ?>
    <div class="det-colonnes">
        <section class="bloc" aria-labelledby="per-ajouter">
            <h2 id="per-ajouter"><?= e(t('pers.ajouter_creneau')) ?></h2>
            <form method="post" action="/gestion/personnel/plannings" class="tre-formulaire" novalidate>
                <input type="hidden" name="csrf" value="<?= $jeton ?>">
                <input type="hidden" name="etablissement_id" value="<?= (int) $etab['id'] ?>">
                <input type="hidden" name="semaine" value="<?= e($lundi) ?>">
                <div class="champ">
                    <label for="employe_id"><?= e(t('pers.col_employe')) ?></label>
                    <select id="employe_id" name="employe_id"<?= $invalide('employe_id') ?>>
                        <option value=""><?= e(t('pers.choisir_employe')) ?></option>
                        <?php foreach ($employes as $e): if (!$e['actif']) continue; ?><option value="<?= (int) $e['id'] ?>" <?= (string) ($s['employe_id'] ?? '') === (string) $e['id'] ? 'selected' : '' ?>><?= e($e['nom']) ?></option><?php endforeach; ?>
                    </select>
                    <?= $erreur('employe_id') ?>
                </div>
                <fieldset class="champ per-jours-coches"<?= isset($erreurs['journees']) ? ' aria-describedby="erreur-journees"' : '' ?>>
                    <legend class="champ__libelle"><?= e(t('pers.champ_journees')) ?></legend>
                    <?php foreach ($jours as $j): ?>
                        <label class="coche"><input type="checkbox" name="journees[]" value="<?= $j ?>" <?= in_array($j, $choisis, true) ? 'checked' : '' ?>> <?= e(P::jourCourt($j)) ?></label>
                    <?php endforeach; ?>
                    <?= $erreur('journees') ?>
                </fieldset>
                <div class="tre-paire">
                    <div class="champ">
                        <label for="debut"><?= e(t('pers.champ_heure_debut')) ?></label>
                        <input id="debut" name="debut" type="time" value="<?= e((string) ($s['debut'] ?? '18:00')) ?>"<?= $invalide('debut') ?>>
                        <?= $erreur('debut') ?>
                    </div>
                    <div class="champ">
                        <label for="fin"><?= e(t('pers.champ_heure_fin')) ?></label>
                        <input id="fin" name="fin" type="time" value="<?= e((string) ($s['fin'] ?? '23:59')) ?>"<?= $invalide('fin') ?>>
                        <p class="champ__aide"><?= e(t('pers.champ_heure_fin_aide')) ?></p>
                        <?= $erreur('fin') ?>
                    </div>
                </div>
                <div class="tre-paire">
                    <div class="champ">
                        <label for="poste"><?= e(t('pers.champ_poste_creneau')) ?></label>
                        <select id="poste" name="poste">
                            <option value=""><?= e(t('pers.aucun')) ?></option>
                            <?php foreach ($postes as $p): ?><option value="<?= $p ?>" <?= ($s['poste'] ?? '') === $p ? 'selected' : '' ?>><?= e(t('pers.poste_' . $p)) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="champ">
                        <label for="service"><?= e(t('pers.champ_service')) ?></label>
                        <input id="service" name="service" maxlength="64" list="services" value="<?= e((string) ($s['service'] ?? '')) ?>">
                        <datalist id="services"><?php foreach ($services as $sv): ?><option value="<?= e($sv) ?>"><?php endforeach; ?></datalist>
                    </div>
                </div>
                <div class="champ">
                    <label for="pause"><?= e(t('pers.champ_pause')) ?></label>
                    <div class="tre-montant"><input id="pause" name="pause" inputmode="numeric" value="<?= e((string) ($s['pause'] ?? '0')) ?>"<?= $invalide('pause') ?>><span class="tre-montant__devise">min</span></div>
                    <?= $erreur('pause') ?>
                </div>
                <div class="tre-actions"><button class="bouton" type="submit"><?= e(t('pers.ajouter')) ?></button></div>
            </form>
        </section>
        <section class="bloc" aria-labelledby="per-copier">
            <h2 id="per-copier"><?= e(t('pers.copier_semaine')) ?></h2>
            <p class="aide"><?= e(t('pers.copier_semaine_aide')) ?></p>
            <form method="post" action="/gestion/personnel/plannings/copier">
                <input type="hidden" name="csrf" value="<?= $jeton ?>">
                <input type="hidden" name="etablissement_id" value="<?= (int) $etab['id'] ?>">
                <input type="hidden" name="semaine" value="<?= e($lundi) ?>">
                <button class="bouton bouton--secondaire" type="submit"><?= e(t('pers.copier_semaine_oui')) ?></button>
            </form>
        </section>
    </div>
<?php endif; ?>
<?php endif; ?>
