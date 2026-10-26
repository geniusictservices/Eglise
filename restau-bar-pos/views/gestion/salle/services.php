<?php
/**
 * Salle > Services horaires : réglages de chaque service, happy hour, fin de la journée d'exploitation.
 * @var ?string $flash
 * @var ?string $erreur
 * @var list<array> $etablissements
 * @var array $etab
 * @var list<array> $services
 * @var list<array> $happyHours
 * @var list<array> $categories catégories principales
 * @var list<array> $grilles
 * @var array $maintenant grille du moment (ServiceRepository::grille)
 * @var array $defauts modules par défaut du type de lieu
 * @var string $journee journée d'exploitation en cours
 * @var bool $peutModifier
 */
$jeton = e(App\Core\Session::jetonCsrf());
$jours = [1 => t('jour.1'), 2 => t('jour.2'), 3 => t('jour.3'), 4 => t('jour.4'), 5 => t('jour.5'), 6 => t('jour.6'), 7 => t('jour.7')];
$nomGrille = static function (?int $id) use ($grilles): string {
    foreach ($grilles as $g) {
        if ((int) $g['id'] === $id) {
            return champ($g, 'nom');
        }
    }
    return '';
};
$desactive = $peutModifier ? '' : ' disabled';
$sources = ['zone' => t('gsalle.source_zone'), 'happy_hour' => t('gsalle.source_happy'), 'service' => t('gsalle.source_service'), 'etablissement' => t('gsalle.source_etablissement')];
?>
<link rel="stylesheet" href="/assets/css/gestion-salle.css">
<div class="sal-entete">
    <div>
        <h1><?= e(t('gsalle.services_titre')) ?></h1>
        <p class="aide"><?= e(t('gsalle.services_aide')) ?></p>
    </div>
    <a class="bouton bouton--secondaire" href="/gestion/etablissements/<?= (int) $etab['id'] ?>#horaires"><?= e(t('gsalle.modifier_horaires')) ?></a>
</div>
<?php if ($flash): ?><p class="alerte" role="status"><?= e($flash) ?></p><?php endif; ?>
<?php if ($erreur): ?><p class="alerte alerte--erreur" role="alert"><?= e($erreur) ?></p><?php endif; ?>
<?= App\Core\View::rendre('gestion/salle/_etablissements', ['etablissements' => $etablissements, 'etab' => $etab, 'garder' => []]) ?>

<section class="bloc sal-maintenant" aria-labelledby="sal-maintenant-titre">
    <h2 id="sal-maintenant-titre"><?= e(t('gsalle.en_ce_moment')) ?></h2>
    <p id="sal-maintenant">
        <?= $maintenant['service'] ? e(t('gsalle.service_en_cours', ['nom' => champ($maintenant['service'], 'nom'), 'debut' => $maintenant['service']['debut'], 'fin' => $maintenant['service']['fin']])) : e(t('gsalle.aucun_service')) ?>
        · <?= e(t('gsalle.grille_appliquee', ['grille' => $nomGrille($maintenant['grille']), 'source' => $sources[$maintenant['source']]])) ?>
        · <?= e(t('gsalle.journee_en_cours', ['date' => date('d/m/Y', strtotime($journee))])) ?>
    </p>
    <p class="aide"><?= e(t('gsalle.ordre_grilles')) ?></p>
</section>

<section class="bloc" aria-labelledby="sal-services-titre">
    <h2 id="sal-services-titre"><?= e(t('gsalle.services_liste')) ?></h2>
    <?php if ($services === []): ?>
        <p class="aide"><?= e(t('gsalle.sans_horaires')) ?></p>
    <?php endif; ?>
    <?php foreach ($services as $i => $s): $r = $s['reglage']; $modules = $r ? array_intersect_key($r, $defauts) : $defauts; $visibles = $r['categories'] ?? []; ?>
        <form class="sal-service" method="post" action="/gestion/salle/services" id="service-<?= $i ?>">
            <input type="hidden" name="csrf" value="<?= $jeton ?>">
            <input type="hidden" name="etablissement" value="<?= (int) $etab['id'] ?>">
            <input type="hidden" name="nom_fr" value="<?= e($s['nom_fr']) ?>">
            <div class="sal-service__tete">
                <h3><?= e(champ($s, 'nom')) ?></h3>
                <p class="aide">
                    <?php if ($s['creneaux'] === []): ?><?= e(t('gsalle.service_orphelin')) ?>
                    <?php else: $parHeures = []; foreach ($s['creneaux'] as $c) { $parHeures[substr($c['ouverture'], 0, 5) . '–' . substr($c['fermeture'], 0, 5)][] = (int) $c['jour']; } ?>
                        <?= e(implode(' · ', array_map(static fn ($h, $j) => formatJours($j) . ' ' . $h, array_keys($parHeures), $parHeures))) ?>
                    <?php endif; ?>
                    <?= $r ? '' : ' · ' . e(t('gsalle.service_defauts')) ?>
                </p>
            </div>
            <label class="champ"><span><?= e(t('gsalle.grille')) ?></span>
                <select class="saisie" name="grille"<?= $desactive ?>>
                    <option value="0"><?= e(t('gsalle.grille_etablissement')) ?></option>
                    <?php foreach ($grilles as $g): ?><option value="<?= (int) $g['id'] ?>" <?= $r && (int) $r['grille_prix_id'] === (int) $g['id'] ? 'selected' : '' ?>><?= e(champ($g, 'nom')) ?></option><?php endforeach; ?>
                </select></label>
            <fieldset class="sal-cases">
                <legend><?= e(t('gsalle.modules')) ?></legend>
                <?php foreach (App\Repositories\ServiceRepository::MODULES as $m): ?>
                    <label class="coche"><input type="checkbox" name="modules[<?= $m ?>]" value="1" <?= !empty($modules[$m]) ? 'checked' : '' ?><?= $desactive ?>> <span><?= e(t('gsalle.module_' . $m)) ?></span></label>
                <?php endforeach; ?>
            </fieldset>
            <fieldset class="sal-cases">
                <legend><?= e(t('gsalle.categories_vendues')) ?></legend>
                <p class="aide"><?= e(t('gsalle.categories_aide')) ?></p>
                <?php foreach ($categories as $c): ?>
                    <label class="coche"><input type="checkbox" name="categories[]" value="<?= (int) $c['id'] ?>" <?= in_array((int) $c['id'], $visibles, true) ? 'checked' : '' ?><?= $desactive ?>> <span><?= e(champ($c, 'nom')) ?></span></label>
                <?php endforeach; ?>
            </fieldset>
            <?php if ($peutModifier): ?>
                <div class="sal-form__actions">
                    <button class="bouton" type="submit"><?= e(t('gsalle.enregistrer')) ?></button>
                    <?php if ($r && $s['creneaux'] === []): ?>
                        <button class="bouton bouton--danger" type="submit" formaction="/gestion/salle/services/<?= (int) $r['id'] ?>/supprimer"><?= e(t('gsalle.supprimer')) ?></button>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </form>
    <?php endforeach; ?>
</section>

<section class="bloc" aria-labelledby="sal-happy-titre">
    <h2 id="sal-happy-titre"><?= e(t('gsalle.happy_titre')) ?></h2>
    <p class="aide"><?= e(t('gsalle.happy_aide')) ?></p>
    <?php foreach (array_merge($happyHours, $peutModifier ? [null] : []) as $h): ?>
        <form class="sal-form sal-happy" method="post" action="/gestion/salle/services/happy-hours<?= $h ? '/' . (int) $h['id'] : '' ?>">
            <input type="hidden" name="csrf" value="<?= $jeton ?>">
            <input type="hidden" name="etablissement" value="<?= (int) $etab['id'] ?>">
            <?php if ($h === null): ?><h3 class="sal-happy__titre"><?= e(t('gsalle.happy_ajouter')) ?></h3><?php endif; ?>
            <label class="champ"><span><?= e(t('gsalle.nom_fr')) ?></span><input class="saisie" name="nom_fr" maxlength="64" required value="<?= e($h['nom_fr'] ?? 'Happy hour') ?>"<?= $desactive ?>></label>
            <label class="champ"><span><?= e(t('gsalle.nom_en')) ?></span><input class="saisie" name="nom_en" maxlength="64" value="<?= e($h['nom_en'] ?? 'Happy hour') ?>"<?= $desactive ?>></label>
            <label class="champ sal-champ--court"><span><?= e(t('gsalle.debut')) ?></span><input class="saisie" type="time" name="debut" required value="<?= e(substr($h['debut'] ?? '18:00', 0, 5)) ?>"<?= $desactive ?>></label>
            <label class="champ sal-champ--court"><span><?= e(t('gsalle.fin')) ?></span><input class="saisie" type="time" name="fin" required value="<?= e(substr($h['fin'] ?? '20:00', 0, 5)) ?>"<?= $desactive ?>></label>
            <label class="champ"><span><?= e(t('gsalle.grille')) ?></span>
                <select class="saisie" name="grille"<?= $desactive ?>><?php foreach ($grilles as $g): ?><option value="<?= (int) $g['id'] ?>" <?= $h && (int) $h['grille_prix_id'] === (int) $g['id'] ? 'selected' : '' ?>><?= e(champ($g, 'nom')) ?></option><?php endforeach; ?></select></label>
            <fieldset class="sal-cases sal-cases--jours">
                <legend><?= e(t('gsalle.jours')) ?></legend>
                <?php $choisis = $h ? array_map('intval', explode(',', $h['jours'])) : [1, 2, 3, 4, 5]; foreach ($jours as $n => $jour): ?>
                    <label class="coche"><input type="checkbox" name="jours[]" value="<?= $n ?>" <?= in_array($n, $choisis, true) ? 'checked' : '' ?><?= $desactive ?>> <span><?= e($jour) ?></span></label>
                <?php endforeach; ?>
            </fieldset>
            <?php if ($h !== null): ?><label class="coche"><input type="checkbox" name="actif" value="1" <?= $h['actif'] ? 'checked' : '' ?><?= $desactive ?>> <span><?= e(t('gsalle.actif')) ?></span></label><?php endif; ?>
            <?php if ($peutModifier): ?>
                <div class="sal-form__actions">
                    <button class="bouton" type="submit"><?= e(t($h ? 'gsalle.enregistrer' : 'gsalle.happy_ajouter')) ?></button>
                    <?php if ($h): ?><button class="bouton bouton--danger" type="submit" formaction="/gestion/salle/services/happy-hours/<?= (int) $h['id'] ?>/supprimer" formnovalidate><?= e(t('gsalle.supprimer')) ?></button><?php endif; ?>
                </div>
            <?php endif; ?>
        </form>
    <?php endforeach; ?>
</section>

<section class="bloc" aria-labelledby="sal-journee-titre">
    <h2 id="sal-journee-titre"><?= e(t('gsalle.journee_titre')) ?></h2>
    <p class="aide"><?= e(t('gsalle.journee_aide')) ?></p>
    <form class="sal-form" method="post" action="/gestion/salle/services/journee">
        <input type="hidden" name="csrf" value="<?= $jeton ?>">
        <input type="hidden" name="etablissement" value="<?= (int) $etab['id'] ?>">
        <label class="champ sal-champ--court"><span><?= e(t('gsalle.fin_journee')) ?></span><input class="saisie" type="time" name="fin_journee" max="12:00" required value="<?= e(substr(App\Core\Journee::fin($etab), 0, 5)) ?>"<?= $desactive ?>></label>
        <?php if ($peutModifier): ?><div class="sal-form__actions"><button class="bouton" type="submit"><?= e(t('gsalle.enregistrer')) ?></button></div><?php endif; ?>
    </form>
</section>
