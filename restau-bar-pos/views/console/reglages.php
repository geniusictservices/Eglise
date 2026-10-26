<?php
/**
 * Réglages de la plateforme.
 * @var array<string, string> $valeurs
 * @var array<string, string> $erreurs
 * @var ?string $dernierPassage
 * @var string $racine
 * @var string $php
 */

use App\Console\Affichage as A;
use App\Console\SessionConsole;

$err = static fn (string $cle): string => isset($erreurs[$cle]) ? '<p class="champ__erreur" id="err-' . e($cle) . '">' . e($erreurs[$cle]) . '</p>' : '';
$inv = static fn (string $cle): string => isset($erreurs[$cle]) ? ' aria-invalid="true" aria-describedby="err-' . e($cle) . '"' : '';
$champ = static fn (string $nom, string $cle, string $libelle, string $aide = '', string $type = 'text'): string =>
    '<div class="champ"><label for="' . e($nom) . '">' . e($libelle) . '</label><input id="' . e($nom) . '" name="' . e($nom) . '" type="' . e($type) . '" value="' . e($valeurs[$cle]) . '"' . $inv($nom) . '>'
    . ($aide !== '' ? '<p class="champ__aide">' . e($aide) . '</p>' : '') . $err($nom) . '</div>';
?>
<h1><?= e(t('console.reglages.titre')) ?></h1>
<p class="aide"><?= e(t('console.reglages.aide')) ?></p>
<?php if ($erreurs !== []): ?><p class="alerte alerte--erreur" role="alert"><?= e(t('console.corriger')) ?></p><?php endif; ?>
<form method="post" action="/reglages" class="con-form" novalidate>
    <input type="hidden" name="csrf" value="<?= e(SessionConsole::jetonCsrf()) ?>">
    <section class="bloc">
        <h2><?= e(t('console.reglages.plateforme')) ?></h2>
        <p class="aide"><?= e(t('console.reglages.plateforme_aide')) ?></p>
        <div class="con-grille">
            <?= $champ('plateforme_nom', 'plateforme.nom', t('console.reglages.nom')) ?>
            <?= $champ('plateforme_contact_telephone', 'plateforme.contact_telephone', t('console.reglages.telephone'), '', 'tel') ?>
            <?= $champ('plateforme_contact_email', 'plateforme.contact_email', t('console.reglages.email'), '', 'email') ?>
            <?= $champ('email_expediteur', 'email.expediteur', t('console.reglages.email_expediteur'), t('console.reglages.email_expediteur_aide'), 'email') ?>
        </div>
    </section>
    <section class="bloc">
        <h2><?= e(t('console.reglages.relances')) ?></h2>
        <p class="aide"><?= e(t('console.reglages.relances_aide')) ?></p>
        <label class="coche"><input type="checkbox" name="relances_actives" value="1"<?= $valeurs['relances.actives'] === '1' ? ' checked' : '' ?>> <?= e(t('console.reglages.relances_actives')) ?></label>
        <div class="con-grille">
            <?= $champ('relances_etapes', 'relances.etapes', t('console.reglages.etapes'), t('console.reglages.etapes_aide')) ?>
            <?= $champ('relances_heure', 'relances.heure', t('console.reglages.heure'), t('console.reglages.heure_aide')) ?>
        </div>
        <p class="aide con-petit"><?= e($dernierPassage ? t('console.tableau.dernier_passage', ['date' => A::dateHeure($dernierPassage)]) : t('console.tableau.jamais_passe')) ?></p>
        <p class="aide con-petit"><?= e(t('console.reglages.cron')) ?></p>
        <pre class="con-code" data-cron><?= e("20 * * * *  " . $php . ' ' . $racine . "/bin/console-relances.php\n5 * * * *  " . $php . ' ' . $racine . '/bin/relances.php --toutes') ?></pre>
    </section>
    <section class="bloc">
        <h2><?= e(t('console.reglages.installation')) ?></h2>
        <div class="con-grille">
            <?= $champ('installation_cpanel_prefixe', 'installation.cpanel_prefixe', t('console.reglages.prefixe'), t('console.reglages.prefixe_aide')) ?>
            <?= $champ('installation_db_hote', 'installation.db_hote', t('console.reglages.db_hote'), t('console.reglages.db_hote_aide')) ?>
        </div>
    </section>
    <p class="con-actions"><button class="bouton" type="submit"><?= e(t('console.enregistrer')) ?></button></p>
</form>
