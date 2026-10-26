<?php
/**
 * Fiche produit.
 * @var ?string $flash
 * @var array $produit
 * @var ?array $categorie
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

$id = (int) $produit['id'];
$jeton = e(App\Core\Session::jetonCsrf());
$base = '/gestion/catalogue/produits/' . $id;
$nom = champ($produit, 'nom');
$unite = t('unite.' . $produit['unite_base']);
$vendables = array_values(array_filter($formats, static fn (array $f) => (bool) $f['vendable']));
$lieuVitrine = null;
foreach ($etablissements as $etab) {
    if (in_array((int) $etab['id'], $vente['etablissements'], true)) {
        $lieuVitrine = $etab;
        break;
    }
}
// Le produit apparaît-il quelque part ? (format vendable avec un prix, établissement, actif)
$avecPrix = false;
foreach ($vendables as $format) {
    if (!empty($prix[$format['id']])) {
        $avecPrix = true;
    }
}
?>
<link rel="stylesheet" href="/assets/css/gestion-catalogue.css">

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
<p class="aide"><?= e($categorie ? champ($categorie, 'nom') : '') ?></p>

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
    <a href="#infos">1. <?= e(t('catalogue.section_infos')) ?></a>
    <a href="#photo">2. <?= e(t('catalogue.section_photo')) ?></a>
    <a href="#formats">3. <?= e(t('catalogue.section_formats')) ?></a>
    <a href="#vente">4. <?= e(t('catalogue.section_vente')) ?></a>
    <?php if ($produit['actif']): ?><a href="#desactiver">5. <?= e(t('catalogue.section_desactiver')) ?></a><?php endif; ?>
</nav>

<!-- 1. Informations -->
<section class="bloc cat-section" id="infos">
    <h2>1. <?= e(t('catalogue.section_infos')) ?></h2>
    <?= App\Core\View::rendre('gestion/catalogue/_erreurs', ['erreurs' => $erreurs['infos'] ?? []]) ?>
    <form class="formulaire cat-formulaire" method="post" action="<?= $base ?>#infos" data-suivi>
        <input type="hidden" name="csrf" value="<?= $jeton ?>">
        <?= App\Core\View::rendre('gestion/catalogue/_infos', ['infos' => $infos, 'categories' => $categories]) ?>
        <div class="cat-actions">
            <button class="bouton" type="submit"><?= e(t('catalogue.enregistrer')) ?></button>
            <span class="cat-modifie" hidden><?= e(t('catalogue.non_enregistre')) ?></span>
        </div>
    </form>
</section>

<!-- 2. Photo -->
<section class="bloc cat-section" id="photo">
    <h2>2. <?= e(t('catalogue.section_photo')) ?></h2>
    <?= App\Core\View::rendre('gestion/catalogue/_erreurs', ['erreurs' => $erreurs['photo'] ?? []]) ?>
    <div class="cat-photo">
        <div class="cat-photo__apercu" data-apercu>
            <?php if (urlImageSure($produit['photo'])): ?>
                <img src="<?= e($produit['photo']) ?>" alt="<?= e(t('catalogue.photo_de', ['nom' => $nom])) ?>" width="240" height="240">
            <?php else: ?>
                <span class="cat-photo__vide"><?= e(t('catalogue.pas_de_photo')) ?></span>
            <?php endif; ?>
        </div>
        <div class="cat-photo__formulaires">
            <form class="formulaire" method="post" action="<?= $base ?>/photo#photo" enctype="multipart/form-data" data-suivi>
                <input type="hidden" name="csrf" value="<?= $jeton ?>">
                <div class="champ">
                    <label for="photo-fichier"><?= e($produit['photo'] ? t('catalogue.photo_remplacer') : t('catalogue.photo_choisir')) ?></label>
                    <input id="photo-fichier" name="photo" type="file" accept="image/jpeg,image/png,image/webp" data-photo data-octets-max="<?= $octetsMax ?>"
                           data-trop-lourde="<?= e(t('image.trop_lourde', ['max' => Images::tailleLisible($octetsMax)])) ?>">
                    <p class="aide"><?= e(t('catalogue.photo_aide', ['max' => Images::tailleLisible($octetsMax)])) ?></p>
                </div>
                <div class="champ">
                    <label for="photo_credit"><?= e(t('catalogue.photo_credit')) ?> <span class="cat-facultatif"><?= e(t('catalogue.facultatif')) ?></span></label>
                    <input id="photo_credit" name="photo_credit" maxlength="255" value="<?= e($credit) ?>" placeholder="<?= e(t('catalogue.photo_credit_exemple')) ?>">
                    <p class="aide"><?= e(t('catalogue.photo_credit_aide')) ?></p>
                </div>
                <div class="cat-actions">
                    <button class="bouton" type="submit"><?= e(t('catalogue.photo_enregistrer')) ?></button>
                    <span class="cat-modifie" hidden><?= e(t('catalogue.non_enregistre')) ?></span>
                </div>
            </form>
            <?php if ($produit['photo']): ?>
                <form method="post" action="<?= $base ?>/photo/supprimer#photo">
                    <input type="hidden" name="csrf" value="<?= $jeton ?>">
                    <button class="bouton bouton--secondaire" type="submit"><?= e(t('catalogue.photo_retirer')) ?></button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- 3. Formats et prix -->
<section class="bloc cat-section" id="formats">
    <h2>3. <?= e(t('catalogue.section_formats')) ?></h2>
    <p class="aide"><?= e(t('catalogue.formats_aide', ['unite' => $unite])) ?></p>

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

<!-- 4. Où il est vendu -->
<section class="bloc cat-section" id="vente">
    <h2>4. <?= e(t('catalogue.section_vente')) ?></h2>
    <form class="formulaire cat-formulaire" method="post" action="<?= $base ?>/vente#vente" data-suivi>
        <input type="hidden" name="csrf" value="<?= $jeton ?>">
        <?= App\Core\View::rendre('gestion/catalogue/_vente', ['vente' => $vente, 'etablissements' => $etablissements]) ?>
        <div class="cat-actions">
            <button class="bouton" type="submit"><?= e(t('catalogue.enregistrer')) ?></button>
            <span class="cat-modifie" hidden><?= e(t('catalogue.non_enregistre')) ?></span>
        </div>
    </form>
</section>

<!-- 5. Désactiver -->
<?php if ($produit['actif']): ?>
    <section class="bloc cat-section" id="desactiver">
        <h2>5. <?= e(t('catalogue.section_desactiver')) ?></h2>
        <p class="aide"><?= e(t('catalogue.desactiver_aide')) ?></p>
        <details class="cat-confirmer">
            <summary class="bouton bouton--secondaire"><?= e(t('catalogue.desactiver')) ?></summary>
            <div class="cat-confirmer__corps">
                <p><?= e(t('catalogue.desactiver_confirmer', ['nom' => $nom])) ?></p>
                <form method="post" action="<?= $base ?>/desactiver">
                    <input type="hidden" name="csrf" value="<?= $jeton ?>">
                    <button class="bouton cat-bouton-danger" type="submit"><?= e(t('catalogue.desactiver_oui')) ?></button>
                </form>
            </div>
        </details>
    </section>
<?php endif; ?>

<script src="/assets/js/gestion-catalogue.js" defer></script>
