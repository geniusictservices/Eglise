<?php
/**
 * Grilles de prix.
 * @var ?string $flash
 * @var array $grilles avec nb_etablissements, nb_zones, nb_prix
 * @var array $nouvelle
 * @var array $saisies
 * @var array $erreurs
 */
use App\Repositories\GestionEtablissementRepository;

$jeton = e(App\Core\Session::jetonCsrf());
$champs = static function (array $valeurs, array $erreursGrille, string $prefixe): string {
    $commun = ['valeurs' => $valeurs, 'erreurs' => $erreursGrille, 'prefixeId' => $prefixe];
    return '<div class="etb-trio">'
        . App\Core\View::rendre('gestion/etablissements/_champ', $commun + ['nom' => 'nom_fr', 'libelle' => t('grilles.nom_fr'), 'max' => 64, 'requis' => true, 'langue' => 'fr', 'exemple' => t('grilles.nom_exemple_fr'), 'attributs' => 'data-code-source="prix|price"'])
        . App\Core\View::rendre('gestion/etablissements/_champ', $commun + ['nom' => 'nom_en', 'libelle' => t('grilles.nom_en'), 'max' => 64, 'facultatif' => true, 'langue' => 'en', 'exemple' => t('grilles.nom_exemple_en')])
        . App\Core\View::rendre('gestion/etablissements/_champ', $commun + ['nom' => 'code', 'libelle' => t('grilles.code'), 'max' => 32, 'exemple' => 'nuit', 'aide' => t('grilles.code_aide'), 'attributs' => 'pattern="[a-z0-9]+(-[a-z0-9]+)*" autocapitalize="off" spellcheck="false" data-code' . (($valeurs['code'] ?? '') === '' ? ' data-code-auto' : '')])
        . '</div>';
};
?>
<link rel="stylesheet" href="/assets/css/gestion-etablissements.css">

<p class="etb-retour"><a class="lien" href="/gestion/etablissements">← <?= e(t('etab.titre')) ?></a></p>
<h1><?= e(t('grilles.titre')) ?></h1>
<p class="aide"><?= e(t('grilles.aide')) ?></p>

<?php if ($flash): ?>
    <p class="alerte" role="status"><?= e($flash) ?></p>
<?php endif; ?>

<div class="etb-zones etb-grilles">
    <?php foreach ($grilles as $grille):
        $gid = (int) $grille['id'];
        $valeurs = $saisies[$gid] ?? $grille;
        $erreursGrille = $erreurs['grille-' . $gid] ?? [];
        $utilisee = GestionEtablissementRepository::grilleUtilisee($grille);
        $usages = [];
        foreach (['nb_etablissements' => 'grilles.usage_etablissements', 'nb_zones' => 'grilles.usage_zones', 'nb_prix' => 'grilles.usage_prix'] as $colonne => $cle) {
            if ((int) $grille[$colonne] > 0) {
                $usages[] = t($cle, ['n' => (int) $grille[$colonne]]);
            }
        }
        ?>
        <article class="etb-zone" id="grille-<?= $gid ?>">
            <div class="etb-zone__titre">
                <h2><?= e(champ($grille, 'nom')) ?> <span class="etb-code"><?= e($grille['code']) ?></span></h2>
                <span class="etb-facultatif"><?= e($usages === [] ? t('grilles.inutilisee') : implode(' · ', $usages)) ?></span>
            </div>
            <?= App\Core\View::rendre('gestion/etablissements/_erreurs', ['erreurs' => array_values($erreursGrille)]) ?>
            <details class="etb-depliant"<?= isset($erreursGrille['nom_fr']) || isset($erreursGrille['code']) ? ' open' : '' ?>>
                <summary class="bouton bouton--secondaire"><?= e(t('grilles.modifier')) ?></summary>
                <form class="formulaire etb-formulaire" method="post" action="/gestion/grilles/<?= $gid ?>#grille-<?= $gid ?>" data-suivi>
                    <input type="hidden" name="csrf" value="<?= $jeton ?>">
                    <?= $champs($valeurs, $erreursGrille, 'g' . $gid . '-') ?>
                    <div class="etb-actions">
                        <button class="bouton" type="submit"><?= e(t('grilles.enregistrer')) ?></button>
                        <span class="etb-modifie" hidden><?= e(t('etab.non_enregistre')) ?></span>
                    </div>
                </form>
            </details>
            <?php if ($utilisee): ?>
                <p class="aide etb-note"><?= e(t('grilles.suppression_impossible')) ?></p>
            <?php else: ?>
                <details class="etb-confirmer">
                    <summary class="bouton bouton--secondaire"><?= e(t('grilles.supprimer')) ?></summary>
                    <div class="etb-confirmer__corps">
                        <p><?= e(t('grilles.supprimer_texte', ['nom' => champ($grille, 'nom')])) ?></p>
                        <form method="post" action="/gestion/grilles/<?= $gid ?>/supprimer">
                            <input type="hidden" name="csrf" value="<?= $jeton ?>">
                            <button class="bouton etb-bouton-danger" type="submit"><?= e(t('grilles.supprimer_oui')) ?></button>
                        </form>
                    </div>
                </details>
            <?php endif; ?>
        </article>
    <?php endforeach; ?>

    <article class="etb-zone etb-zone--nouvelle" id="nouvelle-grille">
        <h2><?= e(t('grilles.nouvelle')) ?></h2>
        <?php $erreursNouvelle = $erreurs['nouvelle'] ?? []; ?>
        <?= App\Core\View::rendre('gestion/etablissements/_erreurs', ['erreurs' => array_values($erreursNouvelle)]) ?>
        <form class="formulaire etb-formulaire" method="post" action="/gestion/grilles#nouvelle-grille" data-suivi>
            <input type="hidden" name="csrf" value="<?= $jeton ?>">
            <?= $champs($nouvelle, $erreursNouvelle, 'ng-') ?>
            <div class="etb-actions">
                <button class="bouton" type="submit">+ <?= e(t('grilles.ajouter')) ?></button>
                <span class="etb-modifie" hidden><?= e(t('etab.non_enregistre')) ?></span>
            </div>
        </form>
    </article>
</div>

<script src="/assets/js/gestion-etablissements.js" defer></script>
