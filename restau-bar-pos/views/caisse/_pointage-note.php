<?php
/**
 * Caisse : rappel du pointage (module Personnel et paie). Si l'établissement exige le pointage pour ouvrir la caisse
 * et que la personne n'a pas pointé son arrivée, la caisse refuse d'encaisser ; message du pointage de secours.
 * @var array $etab
 * @var array $personne
 */
if (!App\Core\Plateforme::moduleInclus('personnel')) {
    return;
}
App\Core\Session::demarrer();
$message = $_SESSION['caisse_pointage'] ?? null;
unset($_SESSION['caisse_pointage']);
$etat = App\Controllers\CaissePointageController::etat($etab, (int) $personne['id']);
if ($message === null && !$etat['obligatoire']) {
    return;
}
?>
<link rel="stylesheet" href="/assets/css/caisse-pointage.css">
<?php if ($message): ?>
    <p class="note pointage-note pointage-note--<?= $message['ok'] ? 'ok' : 'erreur' ?>" role="status"><?= e($message['message']) ?></p>
<?php endif; ?>
<?php if ($etat['requis']): ?>
    <p class="note pointage-note pointage-note--requis" id="pointage-requis" role="alert"><?= e(t('caisse_pointage.requis', ['etablissement' => $etab['nom']])) ?></p>
<?php elseif ($etat['present'] !== null && $etat['obligatoire']): ?>
    <p class="note pointage-note"><?= e(t('caisse_pointage.present_depuis', ['heure' => date('H:i', strtotime($etat['present']['arrivee']))])) ?></p>
<?php endif; ?>
