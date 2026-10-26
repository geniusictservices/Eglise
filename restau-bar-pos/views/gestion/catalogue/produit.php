<?php
/**
 * Fiche produit.
 * @var ?string $flash
 * @var array $produit
 * @var ?array $categorie
 * @var ?array $categorieParente catégorie principale quand le produit est rangé dans une sous-catégorie
 * @var ?array $parent produit principal quand cette fiche est celle d'une taille
 * @var array $tailles tailles du produit principal (avec leurs formats vendus et le prix de référence)
 * @var array $nouvelleTaille saisie du formulaire « Ajouter une taille »
 * @var array $infos
 * @var string $credit
 * @var array $categories
 * @var array $formats
 * @var array $formatsSaisis
 * @var array $nouveauFormat
 * @var array $grilles
 * @var array $devises
 * @var ?array $reference
 * @var array $prix
 * @var ?array $prixSaisis
 * @var array $automatiques
 * @var array $calcul
 * @var array $etablissements
 * @var array $vente
 * @var array $erreurs
 * @var int $octetsMax
 */
use App\Controllers\Gestion\CatalogueSaisie;
use App\Core\Images;
use App\Repositories\GestionCatalogueRepository;

$id = (int) $produit['id'];
$jeton = e(App\Core\Session::jetonCsrf());
$base = '/gestion/catalogue/produits/' . $id;
$libelleTaille = $parent !== null ? GestionCatalogueRepository::libelleTaille($produit) : '';
// Une taille s'appelle « Primus Petite 33 cl » : nom du produit principal, puis la taille
$nom = $parent !== null ? champ($parent, 'nom') . ' ' . $libelleTaille : champ($produit, 'nom');
$unite = t('unite.' . $produit['unite_base']);
$vendables = array_values(array_filter($formats, static fn (array $f) => (bool) $f['vendable']));
$lieuVitrine = null;
foreach ($etablissements as $etab) {
    if (in_array((int) $etab['id'], $vente['etablissements'], true)) {
        $lieuVitrine = $etab;
        break;
    }
}
// Le produit apparaît-il quelque part ? (format vendable avec un prix, ou une taille avec un prix ; établissement ; actif)
$avecPrix = false;
foreach ($vendables as $format) {
    if (!empty($prix[$format['id']])) {
        $avecPrix = true;
    }
}
foreach ($tailles as $taille) {
    foreach ($taille['formats'] as $format) {
        if ($format['montant'] !== null) {
            $avecPrix = true;
        }
    }
}
// Sections numérotées : la section « Tailles » n'existe que sur un produit principal
$sections = ['infos' => t('catalogue.section_infos'), 'photo' => t('catalogue.section_photo')];
if ($parent === null) {
    $sections['tailles'] = t('tailles.section');
}
$sections['formats'] = t('catalogue.section_formats');
$sections['vente'] = t('catalogue.section_vente');
if ($produit['actif']) {
    $sections['desactiver'] = $parent !== null ? t('tailles.section_desactiver') : t('catalogue.section_desactiver');
}
$numero = array_flip(array_keys($sections));
$n = static fn (string $section): string => ($numero[$section] + 1) . '. ';
?>
<link rel="stylesheet" href="/assets/css/gestion-catalogue.css">
<link rel="stylesheet" href="/assets/css/champ-image.css">

<p class="cat-retour"><a class="lien" href="/gestion/catalogue#produit-<?= $id ?>">← <?= e(t('catalogue.titre')) ?></a></p>

<div class="cat-entete">
    <h1>
        <?= e($nom) ?>
        <?php if (!$produit['actif']): ?><span class="pastille pastille--alerte"><?= e(t('catalogue.desactive')) ?></span>
        <?php elseif (!$produit['disponible']): ?><span class="pastille pastille--alerte"><?= e(t('badge.epuise')) ?></span><?php endif; ?>
    </h1>
    <?php if ($lieuVitrine && $produit['actif']): ?>
        <div class="cat-entete__actions">
            <a class="bouton bouton--secondaire" href="/e/<?= e($lieuVitrine['slug']) ?>#cat-<?= (int) $produit['categorie_id'] ?>" target="_blank" rel="noopener"><?= e(t('catalogue.voir_sur_vitrine')) ?></a>
        </div>
    <?php endif; ?>
</div>
<p class="aide"><?= e($categorie ? ($categorieParente ? champ($categorieParente, 'nom') . ' › ' : '') . champ($categorie, 'nom') : '') ?></p>

<?php if ($parent !== null): ?>
    <p class="alerte cat-bandeau-taille">
        <span><?= e(t('tailles.bandeau_avant', ['taille' => champ($produit, 'taille')])) ?></span>
        <a class="lien" href="/gestion/catalogue/produits/<?= (int) $parent['id'] ?>#tailles"><?= e(champ($parent, 'nom')) ?></a>
        <span class="cat-bandeau-taille__aide"><?= e(t('tailles.bandeau_aide')) ?></span>
    </p>
<?php endif; ?>

<?php if ($flash): ?>
    <p class="alerte cat-annonce" role="status"><?= e($flash) ?></p>
<?php endif; ?>

<?php if (!$produit['actif']): ?>
    <section class="alerte alerte--erreur cat-reactiver" id="reactiver">
        <p><?= e(t('catalogue.desactive_texte')) ?></p>
        <form method="post" action="<?= $base ?>/reactiver">
            <input type="hidden" name="csrf" value="<?= $jeton ?>">
            <button class="bouton" type="submit"><?= e(t('catalogue.reactiver')) ?></button>
        </form>
    </section>
<?php elseif (!$avecPrix || $vente['etablissements'] === []): ?>
    <p class="alerte cat-annonce"><?= e(!$avecPrix ? t('catalogue.alerte_sans_prix') : t('catalogue.alerte_sans_lieu')) ?></p>
<?php endif; ?>

<nav class="cat-sommaire" aria-label="<?= e(t('catalogue.sommaire')) ?>">
    <?php foreach ($sections as $ancre => $titreSection): ?>
        <a href="#<?= $ancre ?>"><?= $n($ancre) ?><?= e($titreSection) ?></a>
    <?php endforeach; ?>
</nav>

<!-- 1. Informations -->
<section class="bloc cat-section" id="infos">
    <h2><?= $n('infos') ?><?= e(t('catalogue.section_infos')) ?></h2>
    <?= App\Core\View::rendre('gestion/catalogue/_erreurs', ['erreurs' => $erreurs['infos'] ?? []]) ?>
    <form class="formulaire cat-formulaire" method="post" action="<?= $base ?>#infos" data-suivi>
        <input type="hidden" name="csrf" value="<?= $jeton ?>">
        <?= App\Core\View::rendre('gestion/catalogue/_infos', ['infos' => $infos, 'categories' => $categories, 'parent' => $parent]) ?>
        <div class="cat-actions">
            <button class="bouton" type="submit"><?= e(t('catalogue.enregistrer')) ?></button>
            <span class="cat-modifie" hidden><?= e(t('catalogue.non_enregistre')) ?></span>
        </div>
    </form>
</section>

<!-- 2. Photo -->
<section class="bloc cat-section" id="photo">
    <h2><?= $n('photo') ?><?= e(t('catalogue.section_photo')) ?></h2>
    <?= App\Core\View::rendre('gestion/catalogue/_erreurs', ['erreurs' => $erreurs['photo'] ?? []]) ?>
    <form class="formulaire cat-photo" method="post" action="<?= $base ?>/photo#photo" enctype="multipart/form-data" data-suivi>
        <input type="hidden" name="csrf" value="<?= $jeton ?>">
        <?= App\Core\View::rendre('gestion/_champ-image', [
            'id' => 'photo', 'fichier' => 'photo', 'nom' => 'photo_media', 'chemin' => $produit['photo'],
            'alt' => t('catalogue.photo_de', ['nom' => $nom]), 'vide' => t('catalogue.pas_de_photo'),
            'libelleFichier' => $produit['photo'] ? t('catalogue.photo_remplacer') : t('catalogue.photo_choisir'),
            'aide' => t('catalogue.photo_aide', ['max' => Images::tailleLisible($octetsMax)]),
            'credit' => 'photo_credit', 'retirerForm' => 'cat-retirer-photo', 'retirerTexte' => t('catalogue.photo_retirer'),
            'invalide' => !empty($erreurs['photo']), 'octetsMax' => $octetsMax,
        ]) ?>
        <div class="cat-photo__formulaires">
            <div class="champ">
                <label for="photo_credit"><?= e(t('catalogue.photo_credit')) ?> <span class="cat-facultatif"><?= e(t('catalogue.facultatif')) ?></span></label>
                <input id="photo_credit" name="photo_credit" maxlength="255" value="<?= e($credit) ?>" placeholder="<?= e(t('catalogue.photo_credit_exemple')) ?>">
                <p class="aide"><?= e(t('catalogue.photo_credit_aide')) ?></p>
            </div>
            <div class="cat-actions">
                <button class="bouton" type="submit"><?= e(t('catalogue.photo_enregistrer')) ?></button>
                <span class="cat-modifie" hidden><?= e(t('catalogue.non_enregistre')) ?></span>
            </div>
        </div>
    </form>
    <?php if ($produit['photo']): ?>
        <form id="cat-retirer-photo" method="post" action="<?= $base ?>/photo/supprimer#photo" hidden>
            <input type="hidden" name="csrf" value="<?= $jeton ?>">
        </form>
    <?php endif; ?>
</section>

<?php if ($parent === null): ?>
<!-- Tailles : bouteilles différentes (Petite 33 cl, Grande 72 cl), chacune avec ses formats et prix -->
<section class="bloc cat-section" id="tailles">
    <h2><?= $n('tailles') ?><?= e(t('tailles.section')) ?></h2>
    <p class="aide"><?= e(t('tailles.aide')) ?></p>
    <?= App\Core\View::rendre('gestion/catalogue/_erreurs', ['erreurs' => $erreurs['tailles'] ?? []]) ?>
    <?php if ($tailles !== []): ?>
        <ul class="cat-tailles">
            <?php foreach ($tailles as $t => $taille):
                $tid = (int) $taille['id'];
                $libelle = GestionCatalogueRepository::libelleTaille($taille);
                $principal = $taille['principal'];
                ?>
                <li class="cat-taille<?= $taille['disponible'] ? '' : ' cat-taille--epuisee' ?>" id="taille-<?= $tid ?>">
                    <div class="cat-taille__texte">
                        <p class="cat-taille__nom">
                            <a href="/gestion/catalogue/produits/<?= $tid ?>"><?= e($libelle) ?></a>
                            <?php if (!$taille['disponible']): ?><span class="pastille pastille--alerte"><?= e(t('badge.epuise')) ?></span><?php endif; ?>
                            <?php if (!$taille['visible_public']): ?><span class="pastille pastille--alerte"><?= e(t('catalogue.cache_public')) ?></span><?php endif; ?>
                        </p>
                        <p class="cat-taille__prix">
                            <?php if ($principal !== null): ?>
                                <span class="nombre"><?= e($principal['nom']) ?> · <?= e($principal['montant']) ?></span>
                                <?php if (count($taille['formats']) > 1): ?>
                                    <span class="aide"><?= e(count($taille['formats']) === 2 ? t('catalogue.plus_un_format') : t('catalogue.plus_n_formats', ['n' => count($taille['formats']) - 1])) ?></span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="pastille pastille--alerte"><?= e($taille['formats'] === [] ? t('catalogue.sans_format') : t('catalogue.sans_prix')) ?></span>
                            <?php endif; ?>
                        </p>
                    </div>
                    <a class="bouton bouton--secondaire cat-taille__ouvrir" href="/gestion/catalogue/produits/<?= $tid ?>"><?= e(t('tailles.ouvrir')) ?><span class="cat-cache"> · <?= e($libelle) ?></span></a>
                    <div class="cat-ordre">
                        <form method="post" action="<?= $base ?>/tailles/<?= $tid ?>/monter">
                            <input type="hidden" name="csrf" value="<?= $jeton ?>">
                            <button class="cat-ordre__bouton" type="submit" <?= $t === 0 ? 'disabled' : '' ?> aria-label="<?= e(t('catalogue.monter', ['nom' => $libelle])) ?>" title="<?= e(t('catalogue.monter', ['nom' => $libelle])) ?>">↑</button>
                        </form>
                        <form method="post" action="<?= $base ?>/tailles/<?= $tid ?>/descendre">
                            <input type="hidden" name="csrf" value="<?= $jeton ?>">
                            <button class="cat-ordre__bouton" type="submit" <?= $t === count($tailles) - 1 ? 'disabled' : '' ?> aria-label="<?= e(t('catalogue.descendre', ['nom' => $libelle])) ?>" title="<?= e(t('catalogue.descendre', ['nom' => $libelle])) ?>">↓</button>
                        </form>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php if ($produit['actif']): ?>
        <div class="cat-format cat-format--nouveau" id="nouvelle-taille">
            <h3><?= e(t('tailles.ajouter')) ?></h3>
            <form class="formulaire cat-format__champs" method="post" action="<?= $base ?>/tailles#tailles" data-suivi>
                <input type="hidden" name="csrf" value="<?= $jeton ?>">
                <?= App\Core\View::rendre('gestion/catalogue/_taille', ['prefixe' => 'nt', 'valeurs' => $nouvelleTaille]) ?>
                <p class="aide"><?= e(t('tailles.ajouter_aide')) ?></p>
                <div class="cat-actions">
                    <button class="bouton" type="submit">+ <?= e(t('tailles.ajouter')) ?></button>
                    <span class="cat-modifie" hidden><?= e(t('catalogue.non_enregistre')) ?></span>
                </div>
            </form>
        </div>
    <?php endif; ?>
</section>
<?php endif; ?>

<!-- Formats et prix -->
<section class="bloc cat-section" id="formats">
    <h2><?= $n('formats') ?><?= e(t('catalogue.section_formats')) ?></h2>
    <p class="aide"><?= e(t('catalogue.formats_aide', ['unite' => $unite])) ?></p>
    <?php if ($tailles !== []): ?>
        <p class="alerte cat-annonce"><?= e(t('tailles.formats_du_principal')) ?></p>
    <?php endif; ?>

    <div class="cat-formats">
        <?php foreach ($formats as $format):
            $fid = (int) $format['id'];
            $valeurs = $formatsSaisis[$fid] ?? $format + ['quantite_saisie' => CatalogueSaisie::quantite($format['quantite_base'])];
            ?>
            <div class="cat-format" id="format-<?= $fid ?>">
                <?= App\Core\View::rendre('gestion/catalogue/_erreurs', ['erreurs' => $erreurs['format-' . $fid] ?? []]) ?>
                <form class="formulaire cat-format__champs" method="post" action="<?= $base ?>/formats/<?= $fid ?>#format-<?= $fid ?>" data-suivi>
                    <input type="hidden" name="csrf" value="<?= $jeton ?>">
                    <?= App\Core\View::rendre('gestion/catalogue/_format', ['prefixe' => 'f' . $fid, 'valeurs' => $valeurs, 'unite' => $unite]) ?>
                    <div class="cat-actions">
                        <button class="bouton bouton--secondaire" type="submit"><?= e(t('catalogue.format_enregistrer')) ?></button>
                        <span class="cat-modifie" hidden><?= e(t('catalogue.non_enregistre')) ?></span>
                    </div>
                </form>
                <details class="cat-confirmer">
                    <summary class="bouton bouton--secondaire"><?= e(t('catalogue.format_supprimer')) ?></summary>
                    <div class="cat-confirmer__corps">
                        <p><?= e(t('catalogue.format_supprimer_texte', ['format' => champ($format, 'nom')])) ?></p>
                        <form method="post" action="<?= $base ?>/formats/<?= $fid ?>/supprimer#formats">
                            <input type="hidden" name="csrf" value="<?= $jeton ?>">
                            <button class="bouton cat-bouton-danger" type="submit"><?= e(t('catalogue.format_supprimer_oui')) ?></button>
                        </form>
                    </div>
                </details>
            </div>
        <?php endforeach; ?>

        <div class="cat-format cat-format--nouveau" id="nouveau-format">
            <h3><?= e(t('catalogue.format_nouveau')) ?></h3>
            <?= App\Core\View::rendre('gestion/catalogue/_erreurs', ['erreurs' => $erreurs['nouveau-format'] ?? []]) ?>
            <form class="formulaire cat-format__champs" method="post" action="<?= $base ?>/formats#nouveau-format" data-suivi>
                <input type="hidden" name="csrf" value="<?= $jeton ?>">
                <?= App\Core\View::rendre('gestion/catalogue/_format', ['prefixe' => 'nf', 'valeurs' => $nouveauFormat, 'unite' => $unite]) ?>
                <div class="cat-actions">
                    <button class="bouton" type="submit">+ <?= e(t('catalogue.format_ajouter')) ?></button>
                    <span class="cat-modifie" hidden><?= e(t('catalogue.non_enregistre')) ?></span>
                </div>
            </form>
        </div>
    </div>

    <h3 class="cat-sous-titre" id="prix"><?= e(t('catalogue.prix_titre')) ?></h3>
    <?= App\Core\View::rendre('gestion/catalogue/_erreurs', ['erreurs' => $erreurs['prix'] ?? []]) ?>
    <?php if ($vendables === []): ?>
        <p class="aide"><?= e(t('catalogue.prix_sans_format')) ?></p>
    <?php elseif ($devises === []): ?>
        <p class="alerte alerte--erreur"><?= e(t('catalogue.prix_sans_devise')) ?></p>
    <?php else: ?>
        <p class="aide"><?= e(t('catalogue.prix_aide', ['reference' => $reference['code'] ?? ''])) ?></p>
        <?php if ($reference): ?>
            <ul class="cat-taux">
                <?php foreach ($devises as $devise): if ($devise['est_reference']) { continue; } ?>
                    <li class="nombre">
                        <?php if ($devise['taux'] === null): ?>
                            <span class="pastille pastille--alerte"><?= e(t('gestion.taux_manquant')) ?> · <?= e($devise['code']) ?></span>
                        <?php else: ?>
                            1 <?= e($reference['code']) ?> = <?= e(CatalogueSaisie::quantite((float) $devise['taux'])) ?> <?= e($devise['code']) ?>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <form class="cat-prix" method="post" action="<?= $base ?>/prix#prix" data-suivi data-calcul="<?= e(json_encode($calcul, JSON_UNESCAPED_UNICODE)) ?>">
            <input type="hidden" name="csrf" value="<?= $jeton ?>">
            <div class="cat-defile">
                <table class="tableau cat-prix__tableau">
                    <thead>
                    <tr>
                        <th scope="col"><?= e(t('catalogue.format')) ?></th>
                        <th scope="col"><?= e(t('catalogue.grille')) ?></th>
                        <?php foreach ($devises as $devise): ?>
                            <th scope="col" class="nombre"><?= e($devise['code']) ?><?php if ($devise['est_reference']): ?> <span class="pastille"><?= e(t('gestion.reference')) ?></span><?php endif; ?></th>
                        <?php endforeach; ?>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($vendables as $format): $fid = (int) $format['id']; ?>
                        <?php foreach ($grilles as $g => $grille): $gid = (int) $grille['id']; ?>
                            <tr class="<?= $g === 0 ? 'cat-prix__premier' : '' ?>">
                                <?php if ($g === 0): ?>
                                    <th scope="rowgroup" rowspan="<?= count($grilles) ?>">
                                        <?= e(champ($format, 'nom')) ?>
                                        <span class="cat-facultatif"><?= e(CatalogueSaisie::quantite($format['quantite_base'])) ?> <?= e($unite) ?></span>
                                    </th>
                                <?php endif; ?>
                                <th scope="row" class="cat-prix__grille"><?= e(champ($grille, 'nom')) ?></th>
                                <?php foreach ($devises as $did => $devise):
                                    if ($prixSaisis !== null) {
                                        $valeur = $prixSaisis[$fid][$gid][$did] ?? '';
                                        $valeur = is_string($valeur) ? $valeur : '';
                                    } else {
                                        $valeur = isset($prix[$fid][$gid][$did]) ? CatalogueSaisie::pourSaisie($prix[$fid][$gid][$did], (int) $devise['decimales']) : '';
                                    }
                                    $auto = $automatiques[$fid][$gid][$did] ?? null;
                                    ?>
                                    <td>
                                        <input class="cat-prix__case" type="text" inputmode="decimal" autocomplete="off"
                                               name="prix[<?= $fid ?>][<?= $gid ?>][<?= (int) $did ?>]" value="<?= e($valeur) ?>"
                                               placeholder="<?= e($auto !== null ? t('catalogue.prix_auto', ['montant' => $auto]) : '') ?>"
                                               aria-label="<?= e(champ($format, 'nom') . ', ' . champ($grille, 'nom') . ', ' . $devise['code']) ?>"
                                               data-format="<?= $fid ?>" data-grille="<?= $gid ?>" data-devise="<?= (int) $did ?>">
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="cat-actions">
                <button class="bouton" type="submit"><?= e(t('catalogue.prix_enregistrer')) ?></button>
                <span class="cat-modifie" hidden><?= e(t('catalogue.non_enregistre')) ?></span>
            </div>
        </form>
    <?php endif; ?>
</section>

<!-- Où il est vendu -->
<section class="bloc cat-section" id="vente">
    <h2><?= $n('vente') ?><?= e(t('catalogue.section_vente')) ?></h2>
    <?php if ($tailles !== []): ?><p class="aide"><?= e(t('tailles.vente_aide')) ?></p><?php endif; ?>
    <form class="formulaire cat-formulaire" method="post" action="<?= $base ?>/vente#vente" data-suivi>
        <input type="hidden" name="csrf" value="<?= $jeton ?>">
        <?= App\Core\View::rendre('gestion/catalogue/_vente', ['vente' => $vente, 'etablissements' => $etablissements]) ?>
        <div class="cat-actions">
            <button class="bouton" type="submit"><?= e(t('catalogue.enregistrer')) ?></button>
            <span class="cat-modifie" hidden><?= e(t('catalogue.non_enregistre')) ?></span>
        </div>
    </form>
</section>

<!-- Désactiver -->
<?php if ($produit['actif']): ?>
    <section class="bloc cat-section" id="desactiver">
        <h2><?= $n('desactiver') ?><?= e($sections['desactiver']) ?></h2>
        <p class="aide"><?= e($parent !== null ? t('tailles.desactiver_aide') : t('catalogue.desactiver_aide')) ?></p>
        <?php if ($tailles !== []): ?>
            <p class="aide"><strong><?= e(count($tailles) === 1 ? t('tailles.desactiver_avec_une') : t('tailles.desactiver_avec_n', ['n' => count($tailles)])) ?></strong></p>
        <?php endif; ?>
        <details class="cat-confirmer">
            <summary class="bouton bouton--secondaire"><?= e($parent !== null ? t('tailles.desactiver') : t('catalogue.desactiver')) ?></summary>
            <div class="cat-confirmer__corps">
                <p><?= e($tailles !== []
                    ? t('tailles.desactiver_confirmer', ['nom' => $nom, 'liste' => implode(', ', array_map(static fn (array $tl) => GestionCatalogueRepository::libelleTaille($tl), $tailles))])
                    : t('catalogue.desactiver_confirmer', ['nom' => $nom])) ?></p>
                <form method="post" action="<?= $base ?>/desactiver">
                    <input type="hidden" name="csrf" value="<?= $jeton ?>">
                    <button class="bouton cat-bouton-danger" type="submit"><?= e(t('catalogue.desactiver_oui')) ?></button>
                </form>
            </div>
        </details>
    </section>
<?php endif; ?>

<script src="/assets/js/champ-image.js" defer></script>
<script src="/assets/js/gestion-catalogue.js" defer></script>
