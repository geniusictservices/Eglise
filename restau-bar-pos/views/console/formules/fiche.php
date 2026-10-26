<?php
/**
 * Formule : nom, modules inclus, limites, prix par période, SMS compris.
 * @var ?array $formule
 * @var array $saisie
 * @var array<string, string> $erreurs
 * @var list<string> $offre
 * @var list<string> $aVenir
 */

use App\Console\SessionConsole;

$csrf = SessionConsole::jetonCsrf();
$err = static fn (string $cle): string => isset($erreurs[$cle]) ? '<p class="champ__erreur" id="err-' . e($cle) . '">' . e($erreurs[$cle]) . '</p>' : '';
$inv = static fn (string $cle): string => isset($erreurs[$cle]) ? ' aria-invalid="true" aria-describedby="err-' . e($cle) . '"' : '';
$action = $formule ? '/formules/' . (int) $formule['id'] : '/formules/nouvelle';
?>
<p><a class="lien" href="/formules">← <?= e(t('console.formules.titre')) ?></a></p>
<h1><?= e($formule ? $formule['nom'] : t('console.formule.nouvelle')) ?></h1>
<?php if ($formule && (int) $formule['clients'] > 0): ?>
    <p class="aide"><?= e(t('console.formule.clients_effet', ['n' => (int) $formule['clients']])) ?></p>
<?php endif; ?>
<?php if ($erreurs !== []): ?><p class="alerte alerte--erreur" role="alert"><?= e(t('console.corriger')) ?></p><?php endif; ?>
<form method="post" action="<?= e($action) ?>" class="con-form" novalidate>
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <section class="bloc">
        <div class="con-grille">
            <div class="champ"><label for="nom"><?= e(t('console.formule.nom')) ?></label><input id="nom" name="nom" value="<?= e((string) $saisie['nom']) ?>"<?= $inv('nom') ?>><?= $err('nom') ?></div>
            <div class="champ"><label for="description"><?= e(t('console.formule.description')) ?></label><input id="description" name="description" value="<?= e((string) $saisie['description']) ?>"></div>
        </div>
    </section>
    <section class="bloc">
        <h2><?= e(t('console.formule.modules')) ?></h2>
        <p class="aide"><?= e(t('console.formule.modules_aide')) ?></p>
        <ul class="con-coches">
            <?php foreach ($offre as $code): ?>
                <li><label class="coche"><input type="checkbox" name="modules[]" value="<?= e($code) ?>"<?= in_array($code, $saisie['modules'], true) ? ' checked' : '' ?>> <?= e(\App\Console\Affichage::module($code)) ?><?= in_array($code, $aVenir, true) ? ' <span class="badge badge--neutre">' . e(t('modules.bientot')) . '</span>' : '' ?></label></li>
            <?php endforeach; ?>
        </ul>
    </section>
    <section class="bloc">
        <h2><?= e(t('console.formule.limites_titre')) ?></h2>
        <div class="con-grille">
            <div class="champ"><label for="max_etablissements"><?= e(t('console.formule.max_etablissements')) ?></label><input id="max_etablissements" name="max_etablissements" inputmode="numeric" value="<?= e((string) $saisie['max_etablissements']) ?>"<?= $inv('max_etablissements') ?>><p class="champ__aide"><?= e(t('console.formule.vide_illimite')) ?></p><?= $err('max_etablissements') ?></div>
            <div class="champ"><label for="max_utilisateurs"><?= e(t('console.formule.max_utilisateurs')) ?></label><input id="max_utilisateurs" name="max_utilisateurs" inputmode="numeric" value="<?= e((string) $saisie['max_utilisateurs']) ?>"<?= $inv('max_utilisateurs') ?>><p class="champ__aide"><?= e(t('console.formule.utilisateurs_aide')) ?></p><?= $err('max_utilisateurs') ?></div>
            <div class="champ"><label for="sms_inclus"><?= e(t('console.formule.sms_inclus')) ?></label><input id="sms_inclus" name="sms_inclus" inputmode="numeric" value="<?= e((string) $saisie['sms_inclus']) ?>"<?= $inv('sms_inclus') ?>><?= $err('sms_inclus') ?></div>
        </div>
    </section>
    <section class="bloc">
        <h2><?= e(t('console.formule.prix_titre')) ?></h2>
        <p class="aide"><?= e(t('console.formule.prix_aide')) ?></p>
        <div class="con-grille">
            <?php foreach (['mois', 'trimestre', 'annee'] as $p): ?>
                <div class="champ"><label for="prix_<?= $p ?>"><?= e(t('console.formule.prix_par', ['periode' => t('console.periode.' . $p)])) ?></label><input id="prix_<?= $p ?>" name="prix_<?= $p ?>" inputmode="decimal" value="<?= e((string) $saisie['prix_' . $p]) ?>"<?= $inv('prix_' . $p) ?>><?= $err('prix_' . $p) ?></div>
            <?php endforeach; ?>
        </div>
        <label class="coche"><input type="checkbox" name="archivee" value="1"<?= empty($saisie['actif']) ? ' checked' : '' ?>> <?= e(t('console.formule.archiver')) ?></label>
    </section>
    <p class="con-actions"><button class="bouton" type="submit"><?= e(t('console.enregistrer')) ?></button></p>
</form>
<?php if ($formule && (int) $formule['clients'] === 0): ?>
    <form method="post" action="/formules/<?= (int) $formule['id'] ?>/supprimer" class="con-actions">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <button class="bouton bouton--danger bouton--petit" type="submit"><?= e(t('console.formule.supprimer')) ?></button>
    </form>
<?php endif; ?>
