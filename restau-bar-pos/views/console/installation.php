<?php
/**
 * Installation de la console depuis le navigateur (tant qu'aucun super admin n'existe).
 * @var string $etat non_configuree | vide | sans_admin
 * @var bool $prouve
 * @var ?string $code
 * @var bool $jetonPossible
 * @var array $erreurs
 * @var array $saisie
 * @var ?string $contenu
 * @var string $racine
 */

use App\Console\SessionConsole;

$csrf = SessionConsole::jetonCsrf();
$erreur = static fn (string $cle): string => isset($erreurs[$cle]) && is_string($erreurs[$cle]) ? '<p class="champ__erreur" id="err-' . e($cle) . '">' . e($erreurs[$cle]) . '</p>' : '';
$erreurAdmin = static fn (string $cle): string => isset($erreurs['admin'][$cle]) ? '<p class="champ__erreur" id="err-admin-' . e($cle) . '">' . e($erreurs['admin'][$cle]) . '</p>' : '';
$fichierPreuve = 'storage/installation-' . $code . '.txt';
?>
<section class="con-installation">
    <p class="surtitre"><?= e(t('console.nom')) ?></p>
    <h1><?= e(t('console.installation.titre')) ?></h1>
    <p class="aide"><?= e(t('console.installation.aide')) ?></p>
    <?php if (!empty($flash)): ?>
        <p class="alerte" role="status"><?= e($flash['texte']) ?></p>
    <?php endif; ?>

    <ol class="con-etapes">
        <li class="con-etape" aria-current="<?= $etat === 'non_configuree' ? 'step' : 'false' ?>">
            <h2><?= e(t('console.installation.etape_base')) ?></h2>
            <?php if ($etat !== 'non_configuree'): ?>
                <p><span class="badge badge--succes"><?= e(t('console.installation.fait')) ?></span> <?= e(t('console.installation.base_ok')) ?></p>
            <?php endif; ?>
        </li>
        <li class="con-etape" aria-current="<?= $etat !== 'non_configuree' ? 'step' : 'false' ?>">
            <h2><?= e(t('console.installation.etape_admin')) ?></h2>
        </li>
    </ol>

    <form method="post" action="/installation" class="bloc con-form" novalidate>
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="etape" value="<?= $etat === 'non_configuree' ? 'base' : 'admin' ?>">

        <?php if (!$prouve): ?>
            <fieldset class="con-groupe">
                <legend><?= e(t('console.installation.preuve_titre')) ?></legend>
                <?php if ($code !== null): ?>
                    <p><?= t('console.installation.preuve_texte', ['fichier' => '<code data-preuve>' . e($fichierPreuve) . '</code>', 'dossier' => '<code>' . e($racine) . '/storage</code>']) ?></p>
                <?php else: ?>
                    <p class="alerte alerte--erreur"><?= e(t('console.installation.storage_ferme')) ?></p>
                <?php endif; ?>
                <?php if ($jetonPossible): ?>
                    <div class="champ">
                        <label for="jeton"><?= e(t('console.installation.jeton')) ?></label>
                        <input id="jeton" name="jeton" type="password" autocomplete="off">
                        <p class="champ__aide"><?= e(t('console.installation.jeton_aide')) ?></p>
                    </div>
                <?php endif; ?>
                <?= $erreur('preuve') ?>
            </fieldset>
        <?php endif; ?>

        <?php if ($etat === 'non_configuree'): ?>
            <fieldset class="con-groupe">
                <legend><?= e(t('console.installation.base_titre')) ?></legend>
                <p class="aide"><?= e(t('console.installation.base_aide')) ?></p>
                <div class="con-grille">
                    <div class="champ"><label for="db_hote"><?= e(t('console.db.hote')) ?></label><input id="db_hote" name="db_hote" value="<?= e($saisie['hote'] ?? 'localhost') ?>"></div>
                    <div class="champ"><label for="db_port"><?= e(t('console.db.port')) ?></label><input id="db_port" name="db_port" inputmode="numeric" value="<?= e((string) ($saisie['port'] ?? '3306')) ?>"></div>
                    <div class="champ"><label for="db_nom"><?= e(t('console.db.nom')) ?></label><input id="db_nom" name="db_nom" required value="<?= e($saisie['nom'] ?? '') ?>" placeholder="moncompte_rbpconsole" autocapitalize="none" spellcheck="false"></div>
                    <div class="champ"><label for="db_utilisateur"><?= e(t('console.db.utilisateur')) ?></label><input id="db_utilisateur" name="db_utilisateur" required value="<?= e($saisie['utilisateur'] ?? '') ?>" autocapitalize="none" spellcheck="false"></div>
                    <div class="champ"><label for="db_mot_de_passe"><?= e(t('console.db.mot_de_passe')) ?></label><input id="db_mot_de_passe" name="db_mot_de_passe" type="password" autocomplete="new-password"></div>
                </div>
                <?= $erreur('base') ?>
            </fieldset>
            <?php if ($contenu !== null): ?>
                <div class="alerte alerte--erreur" role="alert">
                    <p><?= e(t('console.installation.coller', ['fichier' => 'config/console.php'])) ?></p>
                    <pre class="con-code" data-config><?= e($contenu) ?></pre>
                </div>
            <?php endif; ?>
            <button class="bouton" type="submit"><?= e(t('console.installation.tester_ecrire')) ?></button>
        <?php else: ?>
            <fieldset class="con-groupe">
                <legend><?= e(t('console.installation.admin_titre')) ?></legend>
                <?= isset($erreurs['admin']['general']) ? '<p class="alerte alerte--erreur" role="alert">' . e($erreurs['admin']['general']) . '</p>' : '' ?>
                <div class="con-grille">
                    <div class="champ"><label for="nom"><?= e(t('console.compte.nom')) ?></label><input id="nom" name="nom" required value="<?= e($saisie['nom'] ?? '') ?>" autocomplete="name"><?= $erreurAdmin('nom') ?></div>
                    <div class="champ"><label for="email"><?= e(t('gestion.email')) ?></label><input id="email" name="email" type="email" required value="<?= e($saisie['email'] ?? '') ?>" autocomplete="username"><?= $erreurAdmin('email') ?></div>
                    <div class="champ"><label for="mot_de_passe"><?= e(t('gestion.mot_de_passe')) ?></label><input id="mot_de_passe" name="mot_de_passe" type="password" required minlength="12" autocomplete="new-password"><p class="champ__aide"><?= e(t('console.compte.regle', ['n' => 12])) ?></p><?= $erreurAdmin('mot_de_passe') ?></div>
                    <div class="champ"><label for="confirmation"><?= e(t('console.compte.confirmation')) ?></label><input id="confirmation" name="confirmation" type="password" required autocomplete="new-password"><?= $erreurAdmin('confirmation') ?></div>
                </div>
            </fieldset>
            <button class="bouton" type="submit"><?= e(t('console.installation.creer')) ?></button>
        <?php endif; ?>
    </form>
</section>
