<?php
/**
 * Stock > Inventaires : nouvel inventaire (lieu, type, catégorie pour un inventaire partiel) et liste.
 * @var list<array> $inventaires
 * @var ?string $statut
 * @var list<array> $lieuxComptage
 * @var list<array> $categories
 * @var array<string, string> $erreurs
 * @var array $valeurs
 */

use App\Controllers\Gestion\TresorerieAffichage as A;
use App\Core\View;
use App\Repositories\InventaireRepository;

$jeton = e(App\Core\Session::jetonCsrf());
$badges = ['en_cours' => 'badge--braise', 'compte' => 'badge--glace', 'valide' => 'badge--succes', 'annule' => 'badge--neutre'];
$invalide = static fn (string $champ): string => isset($erreurs[$champ]) ? ' aria-invalid="true" aria-describedby="erreur-' . $champ . '"' : '';
$erreurChamp = static fn (string $champ): string => isset($erreurs[$champ]) ? '<p class="champ__erreur" id="erreur-' . $champ . '">' . e($erreurs[$champ]) . '</p>' : '';
?>
<?= View::rendre('gestion/stock/_onglets', ['flash' => $flash ?? null]) ?>

<div class="tre-entete">
    <div>
        <h1><?= e(t('stock.inventaires_titre')) ?></h1>
        <p class="aide"><?= e(t('stock.inventaires_aide')) ?></p>
    </div>
</div>

<?php if ($lieuxComptage !== []): ?>
    <section class="bloc" aria-labelledby="nouvel-inventaire">
        <h2 id="nouvel-inventaire"><?= e(t('stock.nouvel_inventaire')) ?></h2>
        <?= View::rendre('gestion/tresorerie/_erreurs', ['erreurs' => array_values($erreurs)]) ?>
        <form method="post" action="/gestion/stock/inventaires" class="stk-form-grille" novalidate>
            <input type="hidden" name="csrf" value="<?= $jeton ?>">
            <div class="champ">
                <label for="lieu_id"><?= e(t('stock.lieu')) ?></label>
                <select id="lieu_id" name="lieu_id"<?= $invalide('lieu_id') ?>>
                    <option value=""><?= e(t('stock.choisir_lieu')) ?></option>
                    <?php foreach ($lieuxComptage as $l): ?>
                        <option value="<?= (int) $l['id'] ?>" <?= (int) $valeurs['lieu_id'] === (int) $l['id'] ? 'selected' : '' ?>><?= e($l['nom'] . ' · ' . ($l['etablissement_nom'] ?? t('stock.central'))) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= $erreurChamp('lieu_id') ?>
            </div>
            <div class="champ">
                <label for="type"><?= e(t('stock.type_inventaire')) ?></label>
                <select id="type" name="type">
                    <?php foreach (InventaireRepository::TYPES as $type): ?>
                        <option value="<?= $type ?>" <?= $valeurs['type'] === $type ? 'selected' : '' ?>><?= e(t('stock.inventaire_' . $type)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="champ">
                <label for="categorie_id"><?= e(t('stock.categorie_partiel')) ?></label>
                <select id="categorie_id" name="categorie_id"<?= $invalide('categorie_id') ?>>
                    <option value=""><?= e(t('stock.toutes_categories')) ?></option>
                    <?php foreach ($categories as $c): ?>
                        <option value="<?= (int) $c['id'] ?>" <?= (int) $valeurs['categorie_id'] === (int) $c['id'] ? 'selected' : '' ?>><?= e(champ($c, 'nom')) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= $erreurChamp('categorie_id') ?>
            </div>
            <div class="champ">
                <label for="notes"><?= e(t('stock.notes')) ?> <span class="tre-facultatif"><?= e(t('tresorerie.facultatif')) ?></span></label>
                <input id="notes" name="notes" maxlength="255" value="<?= e($valeurs['notes']) ?>" placeholder="<?= e(t('stock.notes_inventaire_exemple')) ?>">
            </div>
            <div class="tre-actions"><button class="bouton" type="submit"><?= e(t('stock.commencer_inventaire')) ?></button></div>
        </form>
        <p class="aide"><?= e(t('stock.inventaire_fige_aide')) ?></p>
    </section>
<?php endif; ?>

<section class="bloc" aria-labelledby="liste-inventaires">
    <h2 id="liste-inventaires"><?= e(t('stock.inventaires_liste')) ?></h2>
    <nav class="tre-puces" aria-label="<?= e(t('stock.statut')) ?>">
        <a class="puce" href="<?= e(urlAvec(['statut' => null])) ?>"<?= $statut === null ? ' aria-current="true"' : '' ?>><?= e(t('stock.tous_statuts')) ?></a>
        <?php foreach (['en_cours', 'compte', 'valide', 'annule'] as $s): ?>
            <a class="puce" href="<?= e(urlAvec(['statut' => $s])) ?>"<?= $statut === $s ? ' aria-current="true"' : '' ?>><?= e(t('stock.statut_inventaire_' . $s)) ?></a>
        <?php endforeach; ?>
    </nav>
    <?php if ($inventaires === []): ?>
        <p class="aide"><?= e(t('stock.aucun_inventaire')) ?></p>
    <?php else: ?>
        <div class="tre-defile">
            <table class="tableau stk-table">
                <thead>
                    <tr>
                        <th scope="col"><?= e(t('stock.col_numero')) ?></th>
                        <th scope="col"><?= e(t('stock.lieu')) ?></th>
                        <th scope="col"><?= e(t('stock.statut')) ?></th>
                        <th scope="col" class="nombre"><?= e(t('stock.col_comptes')) ?></th>
                        <th scope="col"><?= e(t('stock.col_qui')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($inventaires as $i): ?>
                        <tr data-inventaire="<?= (int) $i['id'] ?>">
                            <td><a class="stk-numero" href="/gestion/stock/inventaires/<?= (int) $i['id'] ?>"><?= e($i['numero']) ?></a><span class="tre-sous-texte"><?= e(A::dateHeure($i['cree_le'])) ?> · <?= e(t('stock.inventaire_' . $i['type'])) ?></span></td>
                            <td><?= e($i['lieu_nom']) ?><span class="tre-sous-texte"><?= e($i['etablissement_nom'] ?? t('stock.central')) ?></span></td>
                            <td><span class="badge <?= $badges[$i['statut']] ?>"><?= e(t('stock.statut_inventaire_' . $i['statut'])) ?></span>
                                <?php if ((int) $i['nb_ecarts'] > 0): ?><span class="badge badge--erreur"><?= e(t((int) $i['nb_ecarts'] > 1 ? 'stock.n_ecarts' : 'stock.un_ecart', ['n' => (int) $i['nb_ecarts']])) ?></span><?php endif; ?></td>
                            <td class="nombre"><?= (int) $i['nb_comptees'] ?> / <?= (int) $i['nb_lignes'] ?></td>
                            <td class="tre-sous-texte"><?= e(implode(' · ', array_filter([$i['compteur'] ? t('stock.compte_par', ['nom' => $i['compteur']]) : null, $i['validateur'] ? t('stock.valide_par', ['nom' => $i['validateur']]) : null]))) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
