<?php
/**
 * Caisse, fenêtre de la personne : pointage de secours (arrivée ou départ d'un employé sans téléphone), PIN de
 * l'employé validé par le PIN d'un gérant (personnel.presence). Sans JavaScript : envoi classique, retour à la caisse.
 * @var array $etab
 * @var array $personne
 */
if (!App\Core\Plateforme::moduleInclus('personnel')) {
    return;
}
$etat = App\Controllers\CaissePointageController::etat($etab, (int) $personne['id']);
if ($etat['employes'] === [] || $etat['valideurs'] === []) {
    return;
}
$moi = $etat['employe'] ? (int) $etat['employe']['id'] : 0;
?>
<link rel="stylesheet" href="/assets/css/caisse-pointage.css">
<details class="partager pointage-secours"<?= $etat['requis'] ? ' open' : '' ?>>
    <summary class="bouton bouton--secondaire" id="pointage-secours"><?= e(t('caisse_pointage.bouton')) ?></summary>
    <form method="post" action="/caisse/pointage" class="partager__form" id="form-pointage-secours" novalidate>
        <input type="hidden" name="csrf" value="<?= e(App\Core\Session::jetonCsrf()) ?>">
        <input type="hidden" name="etab" value="<?= e($etab['slug']) ?>">
        <p class="quitter__texte"><?= e(t('caisse_pointage.aide')) ?></p>
        <label for="pointage-employe"><?= e(t('caisse_pointage.employe')) ?></label>
        <select id="pointage-employe" name="employe_id" required>
            <option value=""><?= e(t('caisse_pointage.choisir_employe')) ?></option>
            <?php foreach ($etat['employes'] as $e): ?>
                <option value="<?= $e['id'] ?>" data-pin="<?= $e['pin'] ? '1' : '0' ?>" <?= $e['id'] === $moi ? 'selected' : '' ?>>
                    <?= e($e['nom'] . ' — ' . ($e['present'] ? t('caisse_pointage.depart_de', ['heure' => $e['depuis']]) : t('caisse_pointage.arrivee_de'))) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <label for="pointage-pin-employe"><?= e(t('caisse_pointage.pin_employe')) ?></label>
        <input id="pointage-pin-employe" name="pin_employe" type="password" inputmode="numeric" maxlength="6" autocomplete="off">
        <label for="pointage-valideur"><?= e(t('caisse_pointage.valideur')) ?></label>
        <select id="pointage-valideur" name="valideur_id" required>
            <option value=""><?= e(t('caisse_pointage.choisir_valideur')) ?></option>
            <?php foreach ($etat['valideurs'] as $v): ?><option value="<?= $v['id'] ?>"><?= e($v['nom']) ?></option><?php endforeach; ?>
        </select>
        <label for="pointage-pin-valideur"><?= e(t('caisse_pointage.pin_valideur')) ?></label>
        <input id="pointage-pin-valideur" name="pin_valideur" type="password" inputmode="numeric" maxlength="6" autocomplete="off">
        <p class="paiement-fenetre__erreur pointage-secours__message" id="pointage-message" role="alert" hidden></p>
        <button class="bouton" type="submit"><?= e(t('caisse_pointage.valider')) ?></button>
    </form>
</details>
<script src="/assets/js/caisse-pointage.js" defer></script>
