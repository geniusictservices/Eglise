<?php
/**
 * Achats > Marché : achat de la cuisine au marché, payé par la caisse des achats au marché de l'établissement
 * (photo obligatoire, péremption facultative par ligne), solde des fonds et derniers achats.
 * @var list<array> $lieux lieux (avec leur fonds) où la personne achète au marché
 * @var ?array $lieu lieu choisi
 * @var array<int, array{etablissement: string, compte: ?array, peutRegler: bool}> $fonds par établissement
 * @var list<array> $marches fournisseurs de type marché
 * @var array<int, array> $devises devises tenues par le fonds du lieu choisi
 * @var ?array $devise
 * @var array<int, array> $toutesDevises
 * @var array<int, array> $articles
 * @var list<array> $recents
 * @var bool $voirTresorerie
 * @var bool $peutTransferer
 * @var array $valeurs
 * @var list<array> $lignes
 * @var array<string, string> $erreurs
 * @var string[] $avertissements
 * @var int $max
 * @var int $octetsMax
 */

use App\Controllers\Gestion\TresorerieAffichage as A;
use App\Core\View;

$jeton = e(App\Core\Session::jetonCsrf());
$invalide = static fn (string $champ): string => isset($erreurs[$champ]) ? ' aria-invalid="true" aria-describedby="erreur-' . $champ . '"' : '';
$erreurChamp = static fn (string $champ): string => isset($erreurs[$champ]) ? '<p class="champ__erreur" id="erreur-' . $champ . '">' . e($erreurs[$champ]) . '</p>' : '';
$devisesJs = array_map([A::class, 'deviseJs'], $toutesDevises);
$sansFonds = array_filter($fonds, static fn (array $f): bool => $f['compte'] === null);
?>
<?= View::rendre('gestion/stock/_onglets', ['flash' => $flash ?? null]) ?>

<div class="tre-entete">
    <div>
        <h1><?= e(t('achats.marche_titre')) ?></h1>
        <p class="aide"><?= e(t('achats.marche_aide')) ?></p>
    </div>
</div>

<section class="bloc" aria-labelledby="fonds-titre">
    <h2 id="fonds-titre"><?= e(t('achats.marche_fonds')) ?></h2>
    <ul class="stk-liste-lignes" data-fonds>
        <?php foreach ($fonds as $etab => $f): ?>
            <li data-fonds-etablissement="<?= (int) $etab ?>">
                <span><strong><?= e($f['etablissement']) ?></strong>
                    <span class="tre-sous-texte"><?= $f['compte'] !== null ? e($f['compte']['nom']) : e(t('achats.marche_fonds_absent')) ?>
                        <?php if ($f['compte'] === null && $f['peutRegler']): ?> · <a class="lien" href="/gestion/stock/lieux#reglages-<?= (int) $etab ?>"><?= e(t('achats.marche_choisir_fonds')) ?></a><?php endif; ?></span></span>
                <span class="montant">
                    <?php if ($f['compte'] !== null):
                        $soldes = [];
                        foreach ($f['compte']['devises'] as $id => $d) {
                            if (!empty($d['tenue']) && isset($toutesDevises[$id])) {
                                $soldes[] = '<span class="' . ((float) $d['solde'] < -0.0005 ? 'tre-negatif' : '') . '">' . e(A::montant((float) $d['solde'], $toutesDevises[$id])) . '</span>';
                            }
                        }
                        echo implode(' · ', $soldes);
                    endif; ?>
                </span>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php if ($peutTransferer && $fonds !== [] && count($sansFonds) < count($fonds)): ?>
        <p><a class="lien" href="/gestion/tresorerie/transferts"><?= e(t('achats.marche_remettre')) ?></a></p>
    <?php endif; ?>
</section>

<?php if ($lieux !== [] && count($sansFonds) < count($fonds)): ?>
    <?= View::rendre('gestion/tresorerie/_erreurs', ['erreurs' => array_values($erreurs)]) ?>
    <form method="post" action="/gestion/achats/marche" class="tre-formulaire stk-formulaire-large" enctype="multipart/form-data" novalidate
          data-formulaire-lignes data-max="<?= (int) $max ?>" data-texte-base="<?= e(t('stock.soit_js')) ?>"
          data-devises="<?= e((string) json_encode($devisesJs)) ?>" data-texte-total="<?= e(t('achats.total_js')) ?>" data-marche>
        <input type="hidden" name="csrf" value="<?= $jeton ?>">
        <button type="submit" class="visuellement-cache" tabindex="-1" aria-hidden="true"><?= e(t('achats.marche_enregistrer')) ?></button>
        <?php if ($valeurs['justificatif_garde'] !== ''): ?><input type="hidden" name="justificatif_garde" value="<?= e($valeurs['justificatif_garde']) ?>"><?php endif; ?>
        <?php if ($avertissements !== []): ?>
            <?= View::rendre('gestion/tresorerie/_avertissements', ['avertissements' => $avertissements]) ?>
        <?php endif; ?>

        <section class="bloc">
            <h2><?= e(t('achats.marche_section_achat')) ?></h2>
            <div class="tre-paire">
                <div class="champ">
                    <label for="lieu_id"><?= e(t('achats.marche_lieu')) ?></label>
                    <select id="lieu_id" name="lieu_id"<?= $invalide('lieu_id') ?> data-marche-lieu>
                        <option value=""><?= e(t('stock.choisir_lieu')) ?></option>
                        <?php foreach ($lieux as $l): ?>
                            <option value="<?= (int) $l['id'] ?>" <?= (int) $valeurs['lieu_id'] === (int) $l['id'] ? 'selected' : '' ?><?= $l['fonds'] === null ? ' disabled' : '' ?>><?= e($l['nom'] . ' · ' . $l['etablissement_nom']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?= $erreurChamp('lieu_id') ?>
                    <noscript><p class="champ__aide"><?= e(t('achats.marche_lieu_aide')) ?></p></noscript>
                </div>
                <div class="champ">
                    <label for="devise_id"><?= e(t('achats.marche_devise')) ?></label>
                    <select id="devise_id" name="devise_id" data-devise-facture<?= $invalide('devise_id') ?>>
                        <?php foreach ($devises as $d): ?><option value="<?= (int) $d['id'] ?>" data-symbole="<?= e($d['symbole']) ?>" <?= (int) $valeurs['devise_id'] === (int) $d['id'] ? 'selected' : '' ?>><?= e($d['code']) ?></option><?php endforeach; ?>
                    </select>
                    <?= $erreurChamp('devise_id') ?>
                </div>
            </div>
            <div class="tre-paire">
                <div class="champ">
                    <label for="date"><?= e(t('achats.marche_date')) ?> <span class="tre-facultatif"><?= e(t('tresorerie.facultatif')) ?></span></label>
                    <input type="datetime-local" id="date" name="date" value="<?= e($valeurs['date']) ?>"<?= $invalide('date') ?>>
                    <?= $erreurChamp('date') ?>
                </div>
                <?php if (count($marches) > 1): ?>
                    <div class="champ">
                        <label for="fournisseur_id"><?= e(t('achats.marche_lequel')) ?></label>
                        <select id="fournisseur_id" name="fournisseur_id"<?= $invalide('fournisseur_id') ?>>
                            <?php foreach ($marches as $f): ?><option value="<?= (int) $f['id'] ?>" <?= (int) $valeurs['fournisseur_id'] === (int) $f['id'] ? 'selected' : '' ?>><?= e($f['nom']) ?></option><?php endforeach; ?>
                        </select>
                        <?= $erreurChamp('fournisseur_id') ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="champ">
                <label for="justificatif"><?= e(t('achats.marche_photo')) ?></label>
                <?php if ($valeurs['justificatif_garde'] !== ''): ?>
                    <p class="champ__aide" data-photo-gardee><?= e(t('achats.marche_photo_gardee')) ?></p>
                <?php endif; ?>
                <input type="file" id="justificatif" name="justificatif" accept="image/jpeg,image/png,image/webp" capture="environment"<?= $valeurs['justificatif_garde'] === '' ? ' required' : '' ?><?= $invalide('justificatif') ?>>
                <?= $erreurChamp('justificatif') ?>
                <p class="champ__aide"><?= e(t('achats.justificatif_aide', ['taille' => App\Core\Images::tailleLisible($octetsMax)])) ?></p>
            </div>
        </section>

        <section class="bloc" aria-labelledby="lignes-titre">
            <h2 id="lignes-titre"><?= e(t('achats.marche_lignes')) ?></h2>
            <p class="aide"><?= e(t('achats.marche_lignes_aide')) ?></p>
            <?= $erreurChamp('lignes') ?>
            <div class="stk-lignes" data-lignes>
                <?php foreach (array_values($lignes) as $i => $ligne): ?>
                    <?= View::rendre('gestion/stock/_ligne-article', ['i' => $i, 'ligne' => $ligne, 'articles' => $articles, 'erreurs' => $erreurs, 'avecPrix' => true, 'achat' => true, 'avecPeremption' => true, 'symbole' => $devise['symbole'] ?? '']) ?>
                <?php endforeach; ?>
            </div>
            <template data-modele-ligne>
                <?= View::rendre('gestion/stock/_ligne-article', ['i' => '__i__', 'ligne' => ['conditionnement_id' => null, 'quantite' => '', 'prix' => '', 'peremption' => '', 'lot_numero' => ''], 'articles' => $articles, 'erreurs' => [], 'avecPrix' => true, 'achat' => true, 'avecPeremption' => true, 'symbole' => $devise['symbole'] ?? '']) ?>
            </template>
            <button class="bouton bouton--secondaire stk-ajouter-ligne" type="submit" name="action" value="ajouter_ligne" formnovalidate data-ajouter-ligne>+ <?= e(t('stock.ajouter_ligne')) ?></button>
            <p class="tre-resume" data-total-lignes aria-live="polite" hidden></p>
        </section>

        <section class="bloc">
            <div class="champ">
                <label for="notes"><?= e(t('stock.notes')) ?> <span class="tre-facultatif"><?= e(t('tresorerie.facultatif')) ?></span></label>
                <textarea id="notes" name="notes" rows="2" maxlength="2000"><?= e($valeurs['notes']) ?></textarea>
            </div>
            <p class="aide"><?= e(t('achats.marche_paiement_aide', ['compte' => $lieu['fonds']['nom'] ?? '—'])) ?></p>
            <div class="tre-actions">
                <?php if ($avertissements !== []): ?>
                    <button class="bouton" type="submit" name="confirme" value="1"><?= e(t('achats.marche_confirmer')) ?></button>
                <?php else: ?>
                    <button class="bouton" type="submit"><?= e(t('achats.marche_enregistrer')) ?></button>
                <?php endif; ?>
            </div>
        </section>
    </form>
<?php elseif ($lieux === []): ?>
    <p class="aide"><?= e(t('achats.marche_aucun_lieu')) ?></p>
<?php endif; ?>

<section class="bloc" aria-labelledby="recents-titre">
    <h2 id="recents-titre"><?= e(t('achats.marche_recents')) ?></h2>
    <?php if ($recents === []): ?>
        <p class="aide"><?= e(t('achats.marche_aucun_recent')) ?></p>
    <?php else: ?>
        <ul class="stk-liste-lignes">
            <?php foreach ($recents as $r): $d = $toutesDevises[(int) $r['devise_id']] ?? null; ?>
                <li data-achat-marche="<?= (int) $r['id'] ?>">
                    <span><a class="lien" href="/gestion/achats/receptions/<?= (int) $r['id'] ?>"><?= e($r['numero']) ?></a>
                        <span class="tre-sous-texte"><?= e(A::dateHeure($r['date_reception']) . ' · ' . $r['lieu_nom'] . ' · ' . ($r['etablissement_nom'] ?? '') . ($r['auteur'] ? ' · ' . $r['auteur'] : '')) ?></span></span>
                    <span class="montant"><?= $d ? e(A::montant((float) $r['total'], $d)) : '' ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
<script src="/assets/js/gestion-stock.js" defer></script>
