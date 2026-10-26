<?php
/**
 * @var ?string $flash
 * @var array $compte
 * @var string[] $langues
 * @var array $saisieProfil
 * @var array<string, string> $erreursProfil
 * @var array<string, string> $erreursMotDePasse
 * @var int $longueurMin
 */

use App\Controllers\Gestion\DeviseAffichage;

$jeton = e(App\Core\Session::jetonCsrf());
$invalide = static fn (array $erreurs, string $champ): string => isset($erreurs[$champ])
    ? 'aria-invalid="true" aria-describedby="erreur-' . $champ . '"'
    : '';
$erreurChamp = static fn (array $erreurs, string $champ): string => isset($erreurs[$champ])
    ? '<p class="dev-erreur-champ" id="erreur-' . $champ . '">' . e($erreurs[$champ]) . '</p>'
    : '';
?>
<link rel="stylesheet" href="/assets/css/gestion-devises.css">

<h1><?= e(t('compte.titre')) ?></h1>
<p class="aide"><?= e(t('compte.aide')) ?></p>

<?php if ($flash): ?>
    <p class="alerte" role="status"><?= e($flash) ?></p>
<?php endif; ?>

<div class="cpt-grille">
    <section class="bloc" aria-labelledby="cpt-profil">
        <h2 id="cpt-profil"><?= e(t('compte.profil')) ?></h2>
        <dl class="cpt-infos">
            <dt><?= e(t('compte.email')) ?></dt>
            <dd><?= e($compte['email']) ?></dd>
            <dt><?= e(t('compte.derniere_connexion')) ?></dt>
            <dd class="nombre"><?= $compte['derniere_connexion'] ? e(DeviseAffichage::dateHeureBase($compte['derniere_connexion'])) : e(t('compte.jamais')) ?></dd>
            <dt><?= e(t('compte.cree_le')) ?></dt>
            <dd class="nombre"><?= e(DeviseAffichage::dateHeureBase($compte['cree_le'])) ?></dd>
        </dl>

        <form method="post" action="/gestion/compte" class="formulaire cpt-formulaire">
            <input type="hidden" name="csrf" value="<?= $jeton ?>">
            <div class="champ">
                <label for="nom"><?= e(t('compte.nom')) ?></label>
                <input id="nom" name="nom" required maxlength="128" autocomplete="name" value="<?= e($saisieProfil['nom']) ?>" <?= $invalide($erreursProfil, 'nom') ?>>
                <p class="aide"><?= e(t('compte.nom_aide')) ?></p>
                <?= $erreurChamp($erreursProfil, 'nom') ?>
            </div>
            <fieldset class="dev-groupe">
                <legend><?= e(t('compte.langue')) ?></legend>
                <div class="dev-choix cpt-langues">
                    <?php foreach ($langues as $code): ?>
                        <label class="dev-choix__option">
                            <input type="radio" name="langue" value="<?= e($code) ?>" <?= $saisieProfil['langue'] === $code ? 'checked' : '' ?>>
                            <span lang="<?= e($code) ?>"><?= e(t('compte.langue_' . $code)) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <p class="aide"><?= e(t('compte.langue_aide')) ?></p>
                <?= $erreurChamp($erreursProfil, 'langue') ?>
            </fieldset>
            <button class="bouton" type="submit"><?= e(t('compte.enregistrer')) ?></button>
        </form>
    </section>

    <section class="bloc" aria-labelledby="cpt-mdp" id="mot-de-passe">
        <h2 id="cpt-mdp"><?= e(t('compte.mot_de_passe')) ?></h2>
        <form method="post" action="/gestion/compte/mot-de-passe#mot-de-passe" class="formulaire cpt-formulaire">
            <input type="hidden" name="csrf" value="<?= $jeton ?>">
            <input type="email" name="identifiant" value="<?= e($compte['email']) ?>" autocomplete="username" hidden>
            <div class="champ">
                <label for="actuel"><?= e(t('compte.actuel')) ?></label>
                <input type="password" id="actuel" name="actuel" required autocomplete="current-password" <?= $invalide($erreursMotDePasse, 'actuel') ?>>
                <?= $erreurChamp($erreursMotDePasse, 'actuel') ?>
            </div>
            <div class="champ">
                <label for="nouveau"><?= e(t('compte.nouveau')) ?></label>
                <input type="password" id="nouveau" name="nouveau" required minlength="<?= $longueurMin ?>" autocomplete="new-password" <?= $invalide($erreursMotDePasse, 'nouveau') ?>>
                <p class="aide"><?= e(t('compte.nouveau_aide', ['n' => $longueurMin])) ?></p>
                <?= $erreurChamp($erreursMotDePasse, 'nouveau') ?>
            </div>
            <div class="champ">
                <label for="confirmation"><?= e(t('compte.confirmation')) ?></label>
                <input type="password" id="confirmation" name="confirmation" required minlength="<?= $longueurMin ?>" autocomplete="new-password" <?= $invalide($erreursMotDePasse, 'confirmation') ?>>
                <?= $erreurChamp($erreursMotDePasse, 'confirmation') ?>
            </div>
            <button class="bouton" type="submit"><?= e(t('compte.changer')) ?></button>
        </form>
    </section>
</div>
