<?php
/**
 * Stock > Emballages : articles consignés et leurs vides en stock, soldes par fournisseur et par client,
 * formats qui apportent des emballages, échanges.
 * @var array<int, array> $emballages
 * @var array<int, array> $articles
 * @var array<int, array> $lieux
 * @var array $niveaux
 * @var array $liens conditionnement => [emballage => quantité]
 * @var array $soldesFournisseurs
 * @var array $soldesClients
 * @var list<array> $mouvements
 * @var list<array> $fournisseurs
 * @var list<array> $clients
 * @var array<int, array> $devises
 * @var list<array> $lieuxEchange
 * @var bool $peutParametrer
 * @var array<string, string> $erreurs
 * @var array $valeurs
 */

use App\Controllers\Gestion\StockAffichage as S;
use App\Controllers\Gestion\TresorerieAffichage as A;
use App\Core\View;

$jeton = e(App\Core\Session::jetonCsrf());
$csrf = '<input type="hidden" name="csrf" value="' . $jeton . '">';
$erreurChamp = static fn (string $champ): string => isset($erreurs[$champ]) ? '<p class="champ__erreur" id="erreur-' . $champ . '">' . e($erreurs[$champ]) . '</p>' : '';
$invalide = static fn (string $champ): string => isset($erreurs[$champ]) ? ' aria-invalid="true" aria-describedby="erreur-' . $champ . '"' : '';
$formats = [];
foreach ($articles as $a) {
    foreach ($a['conditionnements'] as $c) {
        $formats[(int) $c['id']] = ['article' => $a, 'format' => $c];
    }
}
$nomEmb = static fn (int $id): string => isset($emballages[$id]) ? champ($emballages[$id], 'nom') : '?';
$soldes = static function (array $s) use ($nomEmb, $devises): string {
    $parties = [];
    foreach ($s['emballages'] as $id => $q) {
        $parties[] = S::nombre($q) . ' × ' . $nomEmb((int) $id);
    }
    $valeur = [];
    foreach ($s['valeur'] as $deviseId => $m) {
        if (isset($devises[$deviseId])) {
            $valeur[] = A::montant($m, $devises[$deviseId]);
        }
    }
    return e(implode(' · ', $parties)) . ($valeur ? ' <span class="tre-sous-texte">' . e(t('emballages.valeur_consigne', ['valeur' => implode(' + ', $valeur)])) . '</span>' : '');
};
$ve = $valeurs['echange'] ?? ['tiers' => 'fournisseur', 'fournisseur_id' => null, 'client_id' => null, 'emballage_id' => null, 'type' => 'rendu', 'quantite' => '', 'lieu_id' => null, 'notes' => ''];
$vn = $valeurs['emballage'] ?? ['nom_fr' => '', 'nom_en' => '', 'valeur' => '', 'devise_id' => null];
?>
<?= View::rendre('gestion/stock/_onglets', ['flash' => $flash ?? null]) ?>

<div class="tre-entete">
    <div>
        <h1><?= e(t('emballages.titre')) ?></h1>
        <p class="aide"><?= e(t('emballages.aide')) ?></p>
    </div>
</div>

<?= View::rendre('gestion/tresorerie/_erreurs', ['erreurs' => array_values($erreurs)]) ?>

<section class="bloc" aria-labelledby="emb-articles">
    <h2 id="emb-articles"><?= e(t('emballages.articles')) ?></h2>
    <?php if ($emballages === []): ?>
        <p class="aide"><?= e(t('emballages.aucun')) ?></p>
    <?php else: ?>
        <div class="tre-defile">
            <table class="tableau stk-table">
                <thead><tr><th scope="col"><?= e(t('emballages.article')) ?></th><th scope="col" class="nombre"><?= e(t('emballages.consigne')) ?></th><th scope="col"><?= e(t('emballages.vides_stock')) ?></th></tr></thead>
                <tbody>
                    <?php foreach ($emballages as $id => $emb): ?>
                        <tr data-emballage="<?= (int) $id ?>">
                            <th scope="row"><?= e(champ($emb, 'nom')) ?></th>
                            <td class="nombre">
                                <?php if ($peutParametrer): ?>
                                    <form method="post" action="/gestion/stock/emballages/<?= (int) $id ?>/consigne" class="stk-form-consigne">
                                        <?= $csrf ?>
                                        <label class="visuellement-cache" for="consigne-<?= (int) $id ?>"><?= e(t('emballages.consigne') . ' · ' . champ($emb, 'nom')) ?></label>
                                        <input class="saisie stk-saisie-courte" id="consigne-<?= (int) $id ?>" name="valeur" inputmode="decimal" value="<?= e(A::saisie($emb['valeur_consigne'])) ?>"<?= $invalide('consigne-' . (int) $id) ?>>
                                        <label class="visuellement-cache" for="consigne-devise-<?= (int) $id ?>"><?= e(t('emballages.devise')) ?></label>
                                        <select class="saisie stk-saisie-devise" id="consigne-devise-<?= (int) $id ?>" name="devise_id">
                                            <?php foreach ($devises as $d): if (!$d['active']) { continue; } ?><option value="<?= (int) $d['id'] ?>" <?= (int) $d['id'] === (int) $emb['devise_id'] ? 'selected' : '' ?>><?= e($d['code']) ?></option><?php endforeach; ?>
                                        </select>
                                        <button class="bouton bouton--secondaire bouton--petit" type="submit"><?= e(t('stock.enregistrer')) ?></button>
                                    </form>
                                <?php else: ?>
                                    <?= isset($devises[(int) $emb['devise_id']]) ? e(A::montant($emb['valeur_consigne'], $devises[(int) $emb['devise_id']])) : '' ?>
                                <?php endif; ?>
                            </td>
                            <td><?php $parties = [];
                                foreach ($lieux as $lid => $l) {
                                    if (isset($niveaux[$lid][$id]) && abs($niveaux[$lid][$id]['quantite']) > 0.0005) {
                                        $parties[] = '<span class="stk-lieu-qte">' . e($l['nom']) . ' <strong>' . e(S::nombre($niveaux[$lid][$id]['quantite'])) . '</strong></span>';
                                    }
                                }
                                echo $parties ? implode(' ', $parties) : '—'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <?php if ($peutParametrer): ?>
        <details class="stk-details stk-ajout"<?= isset($erreurs['emb-nom']) || isset($erreurs['emb-valeur']) || isset($erreurs['emb-devise']) ? ' open' : '' ?>>
            <summary class="bouton bouton--secondaire bouton--petit">+ <?= e(t('emballages.nouveau')) ?></summary>
            <form method="post" action="/gestion/stock/emballages" class="stk-form-grille" novalidate>
                <?= $csrf ?>
                <div class="champ"><label for="emb-nom-fr"><?= e(t('emballages.nom_fr')) ?></label><input id="emb-nom-fr" name="nom_fr" maxlength="128" value="<?= e($vn['nom_fr']) ?>" placeholder="<?= e(t('emballages.nom_exemple')) ?>"<?= $invalide('emb-nom') ?>><?= $erreurChamp('emb-nom') ?></div>
                <div class="champ"><label for="emb-nom-en"><?= e(t('emballages.nom_en')) ?> <span class="tre-facultatif"><?= e(t('tresorerie.facultatif')) ?></span></label><input id="emb-nom-en" name="nom_en" maxlength="128" value="<?= e($vn['nom_en']) ?>"></div>
                <div class="champ"><label for="emb-valeur"><?= e(t('emballages.consigne')) ?></label><input id="emb-valeur" name="valeur" inputmode="decimal" value="<?= e($vn['valeur']) ?>"<?= $invalide('emb-valeur') ?>><?= $erreurChamp('emb-valeur') ?></div>
                <div class="champ"><label for="emb-devise"><?= e(t('emballages.devise')) ?></label>
                    <select id="emb-devise" name="devise_id"<?= $invalide('emb-devise') ?>><?php foreach ($devises as $d): if (!$d['active']) { continue; } ?><option value="<?= (int) $d['id'] ?>" <?= (int) $vn['devise_id'] === (int) $d['id'] ? 'selected' : '' ?>><?= e($d['code']) ?></option><?php endforeach; ?></select><?= $erreurChamp('emb-devise') ?></div>
                <div class="tre-actions"><button class="bouton" type="submit"><?= e(t('emballages.creer')) ?></button></div>
            </form>
        </details>
    <?php endif; ?>
</section>

<section class="bloc" aria-labelledby="emb-soldes">
    <h2 id="emb-soldes"><?= e(t('emballages.soldes_fournisseurs')) ?></h2>
    <p class="aide"><?= e(t('emballages.soldes_fournisseurs_aide')) ?></p>
    <?php if ($soldesFournisseurs === []): ?><p class="aide"><?= e(t('emballages.aucun_solde')) ?></p><?php else: ?>
        <ul class="stk-liste-lignes">
            <?php foreach ($soldesFournisseurs as $fid => $s): ?>
                <li data-solde-fournisseur="<?= (int) $fid ?>"><a href="/gestion/achats/fournisseurs/<?= (int) $fid ?>"><strong><?= e($s['nom']) ?></strong></a><span><?= $soldes($s) ?></span></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <h3><?= e(t('emballages.soldes_clients')) ?></h3>
    <?php if ($soldesClients === []): ?><p class="aide"><?= e(t('emballages.aucun_solde_client')) ?></p><?php else: ?>
        <ul class="stk-liste-lignes">
            <?php foreach ($soldesClients as $cid => $s): ?>
                <li data-solde-client="<?= (int) $cid ?>"><strong><?= e($s['nom']) ?></strong><span><?= $soldes($s) ?></span></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<?php if ($lieuxEchange !== [] && $emballages !== []): ?>
    <section class="bloc" aria-labelledby="emb-echange">
        <h2 id="emb-echange"><?= e(t('emballages.echange_titre')) ?></h2>
        <p class="aide"><?= e(t('emballages.echange_aide')) ?></p>
        <form method="post" action="/gestion/stock/emballages/echange" class="stk-form-grille" novalidate data-echange>
            <?= $csrf ?>
            <fieldset class="tre-groupe-champs stk-champ-large">
                <legend><?= e(t('emballages.tiers')) ?></legend>
                <div class="tre-moyens tre-moyens--compact stk-deux">
                    <label class="tre-moyen"><input type="radio" name="tiers" value="fournisseur" <?= $ve['tiers'] === 'fournisseur' ? 'checked' : '' ?>><span><?= e(t('emballages.tiers_fournisseur')) ?></span></label>
                    <label class="tre-moyen"><input type="radio" name="tiers" value="client" <?= $ve['tiers'] === 'client' ? 'checked' : '' ?>><span><?= e(t('emballages.tiers_client')) ?></span></label>
                </div>
            </fieldset>
            <div class="champ" data-tiers="fournisseur"><label for="ech-fournisseur"><?= e(t('emballages.tiers_fournisseur')) ?></label>
                <select id="ech-fournisseur" name="fournisseur_id"<?= $invalide('ech-tiers') ?>><option value=""><?= e(t('achats.choisir_fournisseur')) ?></option><?php foreach ($fournisseurs as $f): ?><option value="<?= (int) $f['id'] ?>" <?= (int) $ve['fournisseur_id'] === (int) $f['id'] ? 'selected' : '' ?>><?= e($f['nom']) ?></option><?php endforeach; ?></select></div>
            <div class="champ" data-tiers="client"><label for="ech-client"><?= e(t('emballages.tiers_client')) ?></label>
                <select id="ech-client" name="client_id"><option value=""><?= e(t('emballages.choisir_client')) ?></option><?php foreach ($clients as $c): ?><option value="<?= (int) $c['id'] ?>" <?= (int) $ve['client_id'] === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['nom']) ?></option><?php endforeach; ?></select></div>
            <?= $erreurChamp('ech-tiers') ?>
            <div class="champ"><label for="ech-type"><?= e(t('emballages.type')) ?></label>
                <select id="ech-type" name="type"<?= $invalide('ech-type') ?>>
                    <?php foreach (['rendu', 'recu', 'pret', 'retour', 'ajustement'] as $type): ?><option value="<?= $type ?>" <?= $ve['type'] === $type ? 'selected' : '' ?>><?= e(t('emballages.type_' . $type)) ?></option><?php endforeach; ?>
                </select><?= $erreurChamp('ech-type') ?></div>
            <div class="champ"><label for="ech-emballage"><?= e(t('emballages.article')) ?></label>
                <select id="ech-emballage" name="emballage_id"<?= $invalide('ech-emballage') ?>><?php foreach ($emballages as $id => $emb): ?><option value="<?= (int) $id ?>" <?= (int) $ve['emballage_id'] === (int) $id ? 'selected' : '' ?>><?= e(champ($emb, 'nom')) ?></option><?php endforeach; ?></select><?= $erreurChamp('ech-emballage') ?></div>
            <div class="champ"><label for="ech-quantite"><?= e(t('stock.quantite')) ?></label><input id="ech-quantite" name="quantite" inputmode="decimal" value="<?= e($ve['quantite']) ?>"<?= $invalide('ech-quantite') ?>><?= $erreurChamp('ech-quantite') ?></div>
            <div class="champ"><label for="ech-lieu"><?= e(t('emballages.lieu_vides')) ?></label>
                <select id="ech-lieu" name="lieu_id"<?= $invalide('ech-lieu') ?>><option value=""><?= e(t('stock.choisir_lieu')) ?></option><?php foreach ($lieuxEchange as $l): ?><option value="<?= (int) $l['id'] ?>" <?= (int) $ve['lieu_id'] === (int) $l['id'] ? 'selected' : '' ?>><?= e($l['nom'] . ' · ' . ($l['etablissement_nom'] ?? t('stock.central'))) ?></option><?php endforeach; ?></select><?= $erreurChamp('ech-lieu') ?></div>
            <div class="champ stk-champ-large"><label for="ech-notes"><?= e(t('stock.notes')) ?> <span class="tre-facultatif"><?= e(t('tresorerie.facultatif')) ?></span></label><input id="ech-notes" name="notes" maxlength="255" value="<?= e($ve['notes']) ?>"></div>
            <div class="tre-actions"><button class="bouton" type="submit"><?= e(t('emballages.enregistrer_echange')) ?></button></div>
        </form>
    </section>
<?php endif; ?>

<section class="bloc" id="liens" aria-labelledby="emb-liens">
    <h2 id="emb-liens"><?= e(t('emballages.liens_titre')) ?></h2>
    <p class="aide"><?= e(t('emballages.liens_aide')) ?></p>
    <?php if ($liens !== []): ?>
        <ul class="stk-liste-lignes">
            <?php foreach ($liens as $condId => $parEmb): if (!isset($formats[$condId])) { continue; } $f = $formats[$condId]; ?>
                <li data-lien="<?= (int) $condId ?>"><span><strong><?= e(champ($f['article'], 'nom')) ?></strong> · <?= e(champ($f['format'], 'nom')) ?></span>
                    <span><?= e(implode(' + ', array_map(static fn ($id, $q) => S::nombre($q) . ' × ' . $nomEmb((int) $id), array_keys($parEmb), $parEmb))) ?></span></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <?php if ($peutParametrer && $emballages !== []): ?>
        <form method="post" action="/gestion/stock/emballages/liens" class="stk-form-grille" novalidate>
            <?= $csrf ?>
            <div class="champ stk-champ-large"><label for="lien-format"><?= e(t('stock.article_format')) ?></label>
                <select id="lien-format" name="conditionnement_id"<?= $invalide('lien') ?>><option value=""><?= e(t('stock.choisir_article')) ?></option>
                    <?= View::rendre('gestion/stock/_options-formats', ['articles' => array_filter($articles, static fn (array $a): bool => !$a['emballage']), 'choisi' => null, 'achat' => false]) ?></select></div>
            <div class="champ"><label for="lien-emballage"><?= e(t('emballages.article')) ?></label>
                <select id="lien-emballage" name="emballage_id"><?php foreach ($emballages as $id => $emb): ?><option value="<?= (int) $id ?>"><?= e(champ($emb, 'nom')) ?></option><?php endforeach; ?></select></div>
            <div class="champ"><label for="lien-quantite"><?= e(t('emballages.quantite_par_format')) ?></label><input id="lien-quantite" name="quantite" inputmode="decimal" placeholder="12"></div>
            <?= $erreurChamp('lien') ?>
            <div class="tre-actions"><button class="bouton bouton--secondaire" type="submit"><?= e(t('emballages.enregistrer_lien')) ?></button></div>
        </form>
        <p class="aide"><?= e(t('emballages.lien_retirer_aide')) ?></p>
    <?php endif; ?>
</section>

<?php if ($mouvements !== []): ?>
    <section class="bloc" aria-labelledby="emb-mvts">
        <h2 id="emb-mvts"><?= e(t('emballages.derniers_echanges')) ?></h2>
        <div class="tre-defile">
            <table class="tableau stk-table">
                <thead><tr><th scope="col"><?= e(t('stock.col_date')) ?></th><th scope="col"><?= e(t('emballages.tiers')) ?></th><th scope="col"><?= e(t('emballages.type')) ?></th><th scope="col" class="nombre"><?= e(t('stock.col_quantite')) ?></th></tr></thead>
                <tbody>
                    <?php foreach ($mouvements as $m): ?>
                        <tr>
                            <td class="tre-journal__date"><?= e(A::dateHeure($m['date_mouvement'])) ?></td>
                            <td><?= e($m['fournisseur'] ?? $m['client'] ?? '') ?><?php if ($m['reception_numero']): ?><span class="tre-sous-texte"><a href="/gestion/achats/receptions/<?= (int) $m['reception_id'] ?>"><?= e($m['reception_numero']) ?></a></span><?php endif; ?></td>
                            <td><span class="badge badge--neutre"><?= e(t('emballages.type_' . $m['type'])) ?></span> <?= e(champ($m, 'nom')) ?></td>
                            <td class="nombre"><?= (float) $m['quantite'] > 0 ? '+' : '' ?><?= e(S::nombre($m['quantite'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>
<script src="/assets/js/gestion-stock.js" defer></script>
