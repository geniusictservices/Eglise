<?php
/**
 * Fiche d'un établissement.
 * @var ?string $flash
 * @var array $etab
 * @var array $infos
 * @var array $apparence couleur, couleur_perso, couleur_saisie, couverture_credit
 * @var array $prix
 * @var array $horaires lignes de l'éditeur
 * @var array $etatOuverture
 * @var array $zones avec nb_qr
 * @var array $zonesSaisies
 * @var array $nouvelleZone
 * @var array $grilles avec leur utilisation
 * @var array $devises
 * @var int $nbQr
 * @var array $erreurs par section
 * @var int $octetsMax
 */
use App\Core\Images;

$id = (int) $etab['id'];
$jeton = e(App\Core\Session::jetonCsrf());
$base = '/gestion/etablissements/' . $id;
$actif = (bool) $etab['actif'];
$grilleEtab = null;
foreach ($grilles as $grille) {
    if ((int) $grille['id'] === (int) $etab['grille_prix_id']) {
        $grilleEtab = $grille;
    }
}
$couleurDesign = '#FF5A1F';
$logo = urlImageSure($etab['logo']);
$couverture = urlImageSure($etab['couverture']);
$tailleMax = Images::tailleLisible($octetsMax);
?>
<link rel="stylesheet" href="/assets/css/gestion-etablissements.css">

<p class="etb-retour"><a class="lien" href="/gestion/etablissements">← <?= e(t('etab.titre')) ?></a></p>

<div class="etb-entete">
    <h1>
        <?= e($etab['nom']) ?>
        <?php if (!$actif): ?><span class="pastille pastille--alerte"><?= e(t('etab.desactive')) ?></span><?php endif; ?>
    </h1>
    <?php if ($actif): ?>
        <div class="etb-entete__actions">
            <a class="bouton bouton--secondaire" href="/e/<?= e($etab['slug']) ?>" target="_blank" rel="noopener"><?= e(t('etab.voir_vitrine')) ?> ↗</a>
        </div>
    <?php endif; ?>
</div>
<p class="aide"><?= e(t('type.' . $etab['type'])) ?><?= $etab['ville'] ? ' · ' . e($etab['ville']) : '' ?></p>

<?php if ($flash): ?>
    <p class="alerte etb-annonce" role="status"><?= e($flash) ?></p>
<?php endif; ?>

<?php if (!$actif): ?>
    <section class="alerte alerte--erreur etb-reactiver">
        <p><?= e(t('etab.desactive_texte')) ?></p>
        <form method="post" action="<?= $base ?>/reactiver">
            <input type="hidden" name="csrf" value="<?= $jeton ?>">
            <button class="bouton" type="submit"><?= e(t('etab.reactiver')) ?></button>
        </form>
    </section>
<?php endif; ?>

<nav class="etb-sommaire" aria-label="<?= e(t('etab.sommaire')) ?>">
    <a href="#infos">1. <?= e(t('etab.section_infos')) ?></a>
    <a href="#apparence">2. <?= e(t('etab.section_apparence')) ?></a>
    <a href="#prix">3. <?= e(t('etab.section_prix')) ?></a>
    <a href="#horaires">4. <?= e(t('etab.section_horaires')) ?></a>
    <a href="#zones">5. <?= e(t('etab.section_zones')) ?></a>
    <a href="#activation">6. <?= e(t('etab.section_activation')) ?></a>
</nav>

<!-- 1. Informations -->
<section class="bloc etb-section" id="infos">
    <h2>1. <?= e(t('etab.section_infos')) ?></h2>
    <?= App\Core\View::rendre('gestion/etablissements/_erreurs', ['erreurs' => array_values($erreurs['infos'] ?? [])]) ?>
    <form class="formulaire etb-formulaire" method="post" action="<?= $base ?>#infos" data-suivi>
        <input type="hidden" name="csrf" value="<?= $jeton ?>">
        <?= App\Core\View::rendre('gestion/etablissements/_infos', ['infos' => $infos, 'erreurs' => $erreurs['infos'] ?? [], 'creation' => false]) ?>
        <div class="etb-actions">
            <button class="bouton" type="submit"><?= e(t('etab.enregistrer')) ?></button>
            <span class="etb-modifie" hidden><?= e(t('etab.non_enregistre')) ?></span>
        </div>
    </form>
</section>

<!-- 2. Apparence -->
<section class="bloc etb-section" id="apparence">
    <h2>2. <?= e(t('etab.section_apparence')) ?></h2>
    <?php $erreursApparence = $erreurs['apparence'] ?? []; ?>
    <?= App\Core\View::rendre('gestion/etablissements/_erreurs', ['erreurs' => array_values($erreursApparence)]) ?>
    <form class="formulaire etb-formulaire" method="post" action="<?= $base ?>/apparence#apparence" enctype="multipart/form-data" data-suivi>
        <input type="hidden" name="csrf" value="<?= $jeton ?>">

        <fieldset class="etb-groupe">
            <legend><?= e(t('etab.couleur')) ?></legend>
            <div class="etb-couleur">
                <label class="etb-case">
                    <input type="checkbox" name="couleur_perso" value="1" data-couleur-perso <?= $apparence['couleur_perso'] ? 'checked' : '' ?>>
                    <span><?= e(t('etab.couleur_perso')) ?></span>
                </label>
                <label class="etb-couleur__choix">
                    <span class="etb-cache"><?= e(t('etab.couleur')) ?></span>
                    <input type="color" name="couleur" data-couleur value="<?= e(couleurValide($apparence['couleur_saisie']) ?? $apparence['couleur'] ?? $couleurDesign) ?>"
                        <?= isset($erreursApparence['couleur']) ? 'aria-invalid="true"' : '' ?>>
                </label>
                <span class="etb-couleur__apercu" data-couleur-apercu data-defaut="<?= e($couleurDesign) ?>"
                      style="--etb-apercu: <?= e($apparence['couleur_perso'] ? (couleurValide($apparence['couleur_saisie']) ?? $couleurDesign) : $couleurDesign) ?>"><?= e(t('etab.couleur_apercu')) ?></span>
            </div>
            <p class="aide"><?= e(t('etab.couleur_aide')) ?></p>
        </fieldset>

        <div class="etb-images">
            <fieldset class="etb-groupe etb-image">
                <legend><?= e(t('etab.logo')) ?></legend>
                <div class="etb-image__apercu etb-image__apercu--logo">
                    <?php if ($logo): ?>
                        <img src="<?= e($logo) ?>" alt="<?= e(t('etab.logo_de', ['nom' => $etab['nom']])) ?>" data-apercu-image>
                    <?php else: ?>
                        <span class="etb-image__vide" data-apercu-vide><?= e(t('etab.pas_de_logo')) ?></span>
                    <?php endif; ?>
                </div>
                <div class="champ">
                    <label for="etb-logo"><?= e($logo ? t('etab.image_remplacer') : t('etab.image_choisir')) ?></label>
                    <input id="etb-logo" name="logo" type="file" accept="image/jpeg,image/png,image/webp" data-image data-octets-max="<?= $octetsMax ?>"
                           data-trop-lourde="<?= e(t('image.trop_lourde', ['max' => $tailleMax])) ?>"<?= isset($erreursApparence['logo']) ? ' aria-invalid="true"' : '' ?>>
                    <p class="aide"><?= e(t('etab.logo_aide', ['max' => $tailleMax])) ?></p>
                </div>
                <?php if ($logo): ?>
                    <button class="bouton bouton--secondaire" type="submit" form="etb-retirer-logo"><?= e(t('etab.logo_retirer')) ?></button>
                <?php endif; ?>
            </fieldset>

            <fieldset class="etb-groupe etb-image">
                <legend><?= e(t('etab.couverture')) ?></legend>
                <div class="etb-image__apercu etb-image__apercu--couverture">
                    <?php if ($couverture): ?>
                        <img src="<?= e($couverture) ?>" alt="<?= e(t('etab.couverture_de', ['nom' => $etab['nom']])) ?>" data-apercu-image>
                    <?php else: ?>
                        <span class="etb-image__vide" data-apercu-vide><?= e(t('etab.pas_de_couverture')) ?></span>
                    <?php endif; ?>
                </div>
                <div class="champ">
                    <label for="etb-couverture"><?= e($couverture ? t('etab.image_remplacer') : t('etab.image_choisir')) ?></label>
                    <input id="etb-couverture" name="couverture" type="file" accept="image/jpeg,image/png,image/webp" data-image data-octets-max="<?= $octetsMax ?>"
                           data-trop-lourde="<?= e(t('image.trop_lourde', ['max' => $tailleMax])) ?>"<?= isset($erreursApparence['couverture']) ? ' aria-invalid="true"' : '' ?>>
                    <p class="aide"><?= e(t('etab.couverture_aide', ['max' => $tailleMax])) ?></p>
                </div>
                <?= App\Core\View::rendre('gestion/etablissements/_champ', [
                    'nom' => 'couverture_credit', 'libelle' => t('etab.couverture_credit'), 'valeurs' => $apparence,
                    'erreurs' => $erreursApparence, 'max' => 255, 'facultatif' => true,
                    'exemple' => t('etab.couverture_credit_exemple'), 'aide' => t('etab.couverture_credit_aide'),
                ]) ?>
                <?php if ($couverture): ?>
                    <button class="bouton bouton--secondaire" type="submit" form="etb-retirer-couverture"><?= e(t('etab.couverture_retirer')) ?></button>
                <?php endif; ?>
            </fieldset>
        </div>

        <div class="etb-actions">
            <button class="bouton" type="submit"><?= e(t('etab.apparence_enregistrer')) ?></button>
            <span class="etb-modifie" hidden><?= e(t('etab.non_enregistre')) ?></span>
        </div>
    </form>
    <?php foreach (['logo' => $logo, 'couverture' => $couverture] as $colonne => $image): if ($image): ?>
        <form id="etb-retirer-<?= $colonne ?>" method="post" action="<?= $base ?>/<?= $colonne ?>/supprimer#apparence" hidden>
            <input type="hidden" name="csrf" value="<?= $jeton ?>">
        </form>
    <?php endif; endforeach; ?>
</section>

<!-- 3. Prix et devises -->
<section class="bloc etb-section" id="prix">
    <h2>3. <?= e(t('etab.section_prix')) ?></h2>
    <?= App\Core\View::rendre('gestion/etablissements/_erreurs', ['erreurs' => array_values($erreurs['prix'] ?? [])]) ?>
    <form class="formulaire etb-formulaire" method="post" action="<?= $base ?>/prix#prix" data-suivi>
        <input type="hidden" name="csrf" value="<?= $jeton ?>">
        <?= App\Core\View::rendre('gestion/etablissements/_prix', ['prix' => $prix, 'grilles' => $grilles, 'devises' => $devises, 'erreurs' => $erreurs['prix'] ?? []]) ?>
        <div class="etb-actions">
            <button class="bouton" type="submit"><?= e(t('etab.enregistrer')) ?></button>
            <span class="etb-modifie" hidden><?= e(t('etab.non_enregistre')) ?></span>
        </div>
    </form>
</section>

<!-- 4. Horaires -->
<section class="bloc etb-section" id="horaires">
    <h2>4. <?= e(t('etab.section_horaires')) ?></h2>
    <p class="aide"><?= e(t('etab.horaires_aide')) ?></p>
    <?php if ($horaires !== [] && empty($erreurs['horaires'])): ?>
        <p class="etb-maintenant">
            <span class="etb-etat <?= $etatOuverture['ouvert'] ? 'etb-etat--ouvert' : 'etb-etat--ferme' ?>">
                <?= e($etatOuverture['ouvert']
                    ? t('etat.ouvert', ['service' => champ($etatOuverture['service'], 'service'), 'heure' => $etatOuverture['jusqua']])
                    : t('etat.ferme')) ?>
            </span>
            <span class="etb-facultatif"><?= e(t('etab.horaires_maintenant', ['heure' => date('H:i'), 'jour' => t('jour_long.' . date('N'))])) ?></span>
        </p>
    <?php endif; ?>
    <?= App\Core\View::rendre('gestion/etablissements/_erreurs', ['erreurs' => $erreurs['horaires'] ?? []]) ?>
    <form class="etb-horaires" method="post" action="<?= $base ?>/horaires#horaires" data-suivi data-editeur-horaires>
        <input type="hidden" name="csrf" value="<?= $jeton ?>">
        <datalist id="etb-services-fr">
            <?php foreach (['Restaurant', 'Bar', 'Bar & terrasse', 'Lounge', 'Club', 'Brunch', 'Happy hour', 'Cuisine'] as $suggestion): ?>
                <option value="<?= e($suggestion) ?>">
            <?php endforeach; ?>
        </datalist>
        <div class="etb-horaires__liste" data-lignes>
            <?php foreach ($horaires as $i => $ligne): ?>
                <?= App\Core\View::rendre('gestion/etablissements/_horaire', ['index' => $i, 'ligne' => $ligne]) ?>
            <?php endforeach; ?>
            <?php // Ligne vide : permet d'ajouter un service même sans JavaScript ?>
            <?= App\Core\View::rendre('gestion/etablissements/_horaire', ['index' => count($horaires), 'ligne' => []]) ?>
        </div>
        <template data-modele-horaire>
            <?= App\Core\View::rendre('gestion/etablissements/_horaire', ['index' => '__N__', 'ligne' => []]) ?>
        </template>
        <p class="aide etb-sans-js"><?= e(t('etab.horaires_sans_js')) ?></p>
        <div class="etb-actions">
            <button class="bouton bouton--secondaire" type="button" data-ajouter-horaire hidden>+ <?= e(t('etab.horaires_ajouter')) ?></button>
            <button class="bouton" type="submit"><?= e(t('etab.horaires_enregistrer')) ?></button>
            <span class="etb-modifie" hidden><?= e(t('etab.non_enregistre')) ?></span>
        </div>
    </form>
</section>

<!-- 5. Zones -->
<section class="bloc etb-section" id="zones">
    <h2>5. <?= e(t('etab.section_zones')) ?></h2>
    <p class="aide"><?= e(t('etab.zones_aide')) ?></p>

    <?php
    $optionsGrille = static function (?int $choisie) use ($grilles, $grilleEtab): string {
        $html = '<option value="">' . e(t('etab.zone_grille_etab', ['grille' => $grilleEtab ? champ($grilleEtab, 'nom') : '—'])) . '</option>';
        foreach ($grilles as $grille) {
            $html .= '<option value="' . (int) $grille['id'] . '"' . ($choisie === (int) $grille['id'] ? ' selected' : '') . '>' . e(champ($grille, 'nom')) . '</option>';
        }
        return $html;
    };
    $champsZone = static function (array $valeurs, array $erreursZone, string $prefixe) use ($optionsGrille): string {
        $commun = ['valeurs' => $valeurs, 'erreurs' => $erreursZone, 'prefixeId' => $prefixe];
        $html = '<div class="etb-paire">'
            . App\Core\View::rendre('gestion/etablissements/_champ', $commun + ['nom' => 'nom_fr', 'libelle' => t('etab.zone_nom_fr'), 'max' => 64, 'requis' => true, 'langue' => 'fr', 'exemple' => t('etab.zone_nom_exemple_fr'), 'attributs' => 'data-code-source'])
            . App\Core\View::rendre('gestion/etablissements/_champ', $commun + ['nom' => 'nom_en', 'libelle' => t('etab.zone_nom_en'), 'max' => 64, 'facultatif' => true, 'langue' => 'en', 'exemple' => t('etab.zone_nom_exemple_en')])
            . '</div><div class="etb-paire">'
            . App\Core\View::rendre('gestion/etablissements/_champ', $commun + ['nom' => 'code', 'libelle' => t('etab.zone_code'), 'max' => 32, 'exemple' => 'vip', 'aide' => t('etab.zone_code_aide'), 'attributs' => 'pattern="[a-z0-9]+(-[a-z0-9]+)*" autocapitalize="off" spellcheck="false" data-code' . (($valeurs['code'] ?? '') === '' ? ' data-code-auto' : '')])
            . '<div class="champ"><label for="etb-' . e($prefixe) . 'grille">' . e(t('etab.zone_grille')) . '</label>'
            . '<select id="etb-' . e($prefixe) . 'grille" name="grille_prix_id">' . $optionsGrille($valeurs['grille_prix_id'] === null ? null : (int) $valeurs['grille_prix_id']) . '</select>'
            . (isset($erreursZone['grille_prix_id']) ? '<p class="etb-erreur-champ">' . e($erreursZone['grille_prix_id']) . '</p>' : '')
            . '</div></div>';
        return $html;
    };
    ?>

    <?php if ($zones === []): ?>
        <p class="etb-vide"><?= e(t('etab.zones_vide')) ?></p>
    <?php endif; ?>

    <div class="etb-zones">
        <?php foreach ($zones as $zone):
            $zid = (int) $zone['id'];
            $valeurs = $zonesSaisies[$zid] ?? $zone;
            $erreursZone = $erreurs['zone-' . $zid] ?? [];
            $nbQrZone = (int) $zone['nb_qr'];
            ?>
            <article class="etb-zone" id="zone-<?= $zid ?>">
                <div class="etb-zone__titre">
                    <h3><?= e(champ($zone, 'nom')) ?></h3>
                    <span class="etb-facultatif">
                        <?= e($zone['grille_prix_id'] === null ? t('etab.zone_prix_etab') : champ(current(array_filter($grilles, static fn ($g) => (int) $g['id'] === (int) $zone['grille_prix_id'])) ?: [], 'nom')) ?>
                        · <?= e($nbQrZone === 0 ? t('etab.zone_sans_qr') : t($nbQrZone > 1 ? 'etab.zone_qr_plusieurs' : 'etab.zone_qr_un', ['n' => $nbQrZone])) ?>
                    </span>
                    <?php if ($actif): ?>
                        <a class="lien etb-lien" href="/e/<?= e($etab['slug']) ?>?zone=<?= e(rawurlencode($zone['code'])) ?>" target="_blank" rel="noopener"><?= e(t('etab.zone_voir')) ?> ↗</a>
                    <?php endif; ?>
                </div>
                <?= App\Core\View::rendre('gestion/etablissements/_erreurs', ['erreurs' => array_values($erreursZone)]) ?>
                <details class="etb-depliant"<?= $erreursZone !== [] ? ' open' : '' ?>>
                    <summary class="bouton bouton--secondaire"><?= e(t('etab.zone_modifier')) ?></summary>
                    <form class="formulaire etb-formulaire" method="post" action="<?= $base ?>/zones/<?= $zid ?>#zone-<?= $zid ?>" data-suivi>
                        <input type="hidden" name="csrf" value="<?= $jeton ?>">
                        <?= $champsZone($valeurs, $erreursZone, 'z' . $zid . '-') ?>
                        <div class="etb-actions">
                            <button class="bouton" type="submit"><?= e(t('etab.zone_enregistrer')) ?></button>
                            <span class="etb-modifie" hidden><?= e(t('etab.non_enregistre')) ?></span>
                        </div>
                    </form>
                </details>
                <details class="etb-confirmer">
                    <summary class="bouton bouton--secondaire"><?= e(t('etab.zone_supprimer')) ?></summary>
                    <div class="etb-confirmer__corps">
                        <p><?= e(t('etab.zone_supprimer_texte', ['nom' => champ($zone, 'nom')])) ?></p>
                        <?php if ($nbQrZone > 0): ?>
                            <p><strong><?= e(t($nbQrZone > 1 ? 'etab.zone_supprimer_qr_plusieurs' : 'etab.zone_supprimer_qr_un', ['n' => $nbQrZone])) ?></strong></p>
                        <?php endif; ?>
                        <form method="post" action="<?= $base ?>/zones/<?= $zid ?>/supprimer#zones">
                            <input type="hidden" name="csrf" value="<?= $jeton ?>">
                            <button class="bouton etb-bouton-danger" type="submit"><?= e(t('etab.zone_supprimer_oui')) ?></button>
                        </form>
                    </div>
                </details>
            </article>
        <?php endforeach; ?>

        <article class="etb-zone etb-zone--nouvelle" id="nouvelle-zone">
            <h3><?= e(t('etab.zone_nouvelle')) ?></h3>
            <?php $erreursNouvelle = $erreurs['nouvelle-zone'] ?? []; ?>
            <?= App\Core\View::rendre('gestion/etablissements/_erreurs', ['erreurs' => array_values($erreursNouvelle)]) ?>
            <form class="formulaire etb-formulaire" method="post" action="<?= $base ?>/zones#nouvelle-zone" data-suivi>
                <input type="hidden" name="csrf" value="<?= $jeton ?>">
                <?= $champsZone($nouvelleZone, $erreursNouvelle, 'nz-') ?>
                <div class="etb-actions">
                    <button class="bouton" type="submit">+ <?= e(t('etab.zone_ajouter')) ?></button>
                    <span class="etb-modifie" hidden><?= e(t('etab.non_enregistre')) ?></span>
                </div>
            </form>
        </article>
    </div>
</section>

<!-- 6. Activation -->
<section class="bloc etb-section" id="activation">
    <h2>6. <?= e(t('etab.section_activation')) ?></h2>
    <?php if ($actif): ?>
        <p class="aide"><?= e(t('etab.desactiver_aide')) ?></p>
        <details class="etb-confirmer">
            <summary class="bouton bouton--secondaire"><?= e(t('etab.desactiver')) ?></summary>
            <div class="etb-confirmer__corps">
                <p><?= e(t('etab.desactiver_confirmer', ['nom' => $etab['nom']])) ?></p>
                <?php if ($nbQr > 0): ?>
                    <p><?= e(t($nbQr > 1 ? 'etab.desactiver_qr_plusieurs' : 'etab.desactiver_qr_un', ['n' => $nbQr])) ?></p>
                <?php endif; ?>
                <form method="post" action="<?= $base ?>/desactiver#activation">
                    <input type="hidden" name="csrf" value="<?= $jeton ?>">
                    <button class="bouton etb-bouton-danger" type="submit"><?= e(t('etab.desactiver_oui')) ?></button>
                </form>
            </div>
        </details>
    <?php else: ?>
        <p class="aide"><?= e(t('etab.reactiver_aide')) ?></p>
        <form method="post" action="<?= $base ?>/reactiver#activation">
            <input type="hidden" name="csrf" value="<?= $jeton ?>">
            <button class="bouton" type="submit"><?= e(t('etab.reactiver')) ?></button>
        </form>
    <?php endif; ?>
</section>

<script src="/assets/js/gestion-etablissements.js" defer></script>
