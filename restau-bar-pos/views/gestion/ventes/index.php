<?php
/**
 * Ventes > Tickets : liste des ventes encaissées, filtres (période, établissement, vendeur, type de compte, statut),
 * totaux des ventes payées (tickets, total par devise, encaissé par devise et par type de compte, équivalent en
 * devise de référence), pages de 50 tickets.
 * @var ?string $flash
 * @var array $periode
 * @var list<string> $periodes
 * @var array $filtres
 * @var list<array> $ventes
 * @var int $nombre
 * @var int $page
 * @var int $pages
 * @var array $totaux
 * @var array<int, array> $devises
 * @var ?array $reference
 * @var array $etablissements
 * @var array $vendeurs
 */

use App\Controllers\Gestion\TresorerieAffichage as A;
use App\Core\View;
use App\Repositories\GestionTresorerieRepository;

$types = GestionTresorerieRepository::TYPES;
$type = static fn (string $t): string => t('tresorerie.type_' . $t);
$filtre = $filtres['etablissement'] || $filtres['vendeur'] || $filtres['moyen'] || $filtres['statut'];
$montant = static fn ($m, int $deviseId): string => isset($devises[$deviseId]) ? A::montant($m, $devises[$deviseId]) : (string) $m;
// Devises des totaux dans l'ordre d'affichage (locale, dollar, autres)
$ordre = array_flip(array_keys($devises));
$encaisse = $totaux['encaisse'];
uksort($encaisse, static fn ($a, $b) => ($ordre[$a] ?? 999) <=> ($ordre[$b] ?? 999));
$parDevise = $totaux['parDevise'];
uksort($parDevise, static fn ($a, $b) => ($ordre[$a] ?? 999) <=> ($ordre[$b] ?? 999));
?>
<link rel="stylesheet" href="/assets/css/gestion-tresorerie.css">
<link rel="stylesheet" href="/assets/css/gestion-ventes.css">

<div class="tre-entete">
    <div>
        <h1><?= e(t('gventes.titre')) ?></h1>
        <p class="aide"><?= e(t('gventes.aide')) ?></p>
    </div>
    <?= View::rendre('gestion/rapports/_exporter', ['titre' => t('gventes.titre'), 'periode' => $periode['du'] !== null ? App\Controllers\Gestion\RapportAffichage::periode($periode['du'], $periode['au']) : null]) ?>
</div>

<?php if ($flash): ?>
    <p class="alerte" role="status"><?= e($flash) ?></p>
<?php endif; ?>

<section class="bloc tre-filtres ven-filtres" aria-label="<?= e(t('gventes.filtres')) ?>">
    <?= View::rendre('gestion/tresorerie/_periode', ['periode' => $periode, 'periodes' => $periodes, 'action' => '/gestion/ventes', 'avecDates' => false]) ?>
    <nav class="tre-puces" aria-label="<?= e(t('gventes.filtre_moyen')) ?>">
        <a class="puce" href="<?= e(urlAvec(['moyen' => null, 'page' => null])) ?>"<?= !$filtres['moyen'] ? ' aria-current="true"' : '' ?>><?= e(t('gventes.tous_moyens')) ?></a>
        <?php foreach ($types as $t): ?>
            <a class="puce" href="<?= e(urlAvec(['moyen' => $t, 'page' => null])) ?>"<?= $filtres['moyen'] === $t ? ' aria-current="true"' : '' ?>><?= View::rendre('gestion/tresorerie/_icone', ['type' => $t]) ?><?= e($type($t)) ?></a>
        <?php endforeach; ?>
    </nav>
    <form class="tre-filtres__formulaire" method="get" action="/gestion/ventes">
        <?php if ($filtres['moyen']): ?><input type="hidden" name="moyen" value="<?= e($filtres['moyen']) ?>"><?php endif; ?>
        <?php if ($periode['cle'] !== 'perso'): ?><input type="hidden" name="periode" value="<?= e($periode['cle']) ?>"><?php endif; ?>
        <div class="champ">
            <label for="filtre-du"><?= e(t('tresorerie.du')) ?></label>
            <input type="date" id="filtre-du" name="du" value="<?= e($periode['cle'] === 'perso' ? (string) $periode['du'] : '') ?>">
        </div>
        <div class="champ">
            <label for="filtre-au"><?= e(t('tresorerie.au')) ?></label>
            <input type="date" id="filtre-au" name="au" value="<?= e($periode['cle'] === 'perso' ? (string) $periode['au'] : '') ?>">
        </div>
        <?php if (count($etablissements) > 1): ?>
            <div class="champ">
                <label for="filtre-etablissement"><?= e(t('gestion.etablissement')) ?></label>
                <select id="filtre-etablissement" name="etablissement">
                    <option value=""><?= e(t('gventes.tous_etablissements')) ?></option>
                    <?php foreach ($etablissements as $etab): ?>
                        <option value="<?= (int) $etab['id'] ?>" <?= $filtres['etablissement'] === (int) $etab['id'] ? 'selected' : '' ?>><?= e($etab['nom']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>
        <div class="champ">
            <label for="filtre-vendeur"><?= e(t('gventes.vendeur')) ?></label>
            <select id="filtre-vendeur" name="vendeur">
                <option value=""><?= e(t('gventes.tous_vendeurs')) ?></option>
                <?php foreach ($vendeurs as $v): ?>
                    <option value="<?= (int) $v['id'] ?>" <?= $filtres['vendeur'] === (int) $v['id'] ? 'selected' : '' ?>><?= e($v['nom']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="champ">
            <label for="filtre-statut"><?= e(t('gventes.statut')) ?></label>
            <select id="filtre-statut" name="statut">
                <option value=""><?= e(t('gventes.tous_statuts')) ?></option>
                <option value="payee" <?= $filtres['statut'] === 'payee' ? 'selected' : '' ?>><?= e(t('gventes.statut_payee')) ?></option>
                <option value="annulee" <?= $filtres['statut'] === 'annulee' ? 'selected' : '' ?>><?= e(t('gventes.statut_annulee')) ?></option>
            </select>
        </div>
        <div class="tre-filtres__boutons">
            <button class="bouton bouton--secondaire" type="submit"><?= e(t('tresorerie.afficher')) ?></button>
            <?php if ($filtre || $periode['cle'] !== 'jour'): ?>
                <a class="lien" href="/gestion/ventes"><?= e(t('gventes.effacer_filtres')) ?></a>
            <?php endif; ?>
        </div>
    </form>
</section>

<section class="tre-totaux ven-totaux" aria-labelledby="ven-totaux-titre">
    <h2 class="visuellement-cache" id="ven-totaux-titre"><?= e(t('gventes.totaux')) ?></h2>
    <?php if ($totaux['tickets'] > 0): ?>
        <div class="chiffres ven-chiffres">
            <div class="chiffre">
                <span class="chiffre__valeur" data-total="tickets"><?= e((string) $totaux['tickets']) ?></span>
                <span class="chiffre__libelle"><?= e(t($totaux['tickets'] > 1 ? 'gventes.n_tickets_payes' : 'gventes.un_ticket_paye', ['articles' => $totaux['articles']])) ?></span>
            </div>
            <?php foreach ($parDevise as $deviseId => $somme): ?>
                <div class="chiffre">
                    <span class="chiffre__valeur" data-total="devise-<?= (int) $deviseId ?>"><?= e($montant($somme, (int) $deviseId)) ?></span>
                    <span class="chiffre__libelle"><?= e(t('gventes.total_tickets_en', ['code' => $devises[$deviseId]['code'] ?? ''])) ?></span>
                </div>
            <?php endforeach; ?>
            <?php if ($reference): ?>
                <div class="chiffre">
                    <span class="chiffre__valeur" data-total="reference">≈ <?= e(A::montant($totaux['reference'], $reference)) ?></span>
                    <span class="chiffre__libelle"><?= e(t('gventes.equivalent_encaisse', ['code' => $reference['code']])) ?></span>
                </div>
            <?php endif; ?>
        </div>

        <div class="ven-encaisse">
            <h3 class="ven-encaisse__titre"><?= e(t('gventes.encaisse_titre')) ?></h3>
            <p class="aide"><?= e(t('gventes.encaisse_aide')) ?></p>
            <ul class="ven-encaisse__devises">
                <?php foreach ($encaisse as $deviseId => $parType): $somme = array_sum($parType); ?>
                    <li class="ven-encaisse__devise" data-encaisse="<?= e($devises[$deviseId]['code'] ?? (string) $deviseId) ?>">
                        <p class="ven-encaisse__total"><span class="surtitre"><?= e($devises[$deviseId]['code'] ?? '') ?></span><strong class="montant"><?= e($montant($somme, (int) $deviseId)) ?></strong></p>
                        <ul class="ven-encaisse__types">
                            <?php foreach (array_merge(array_intersect($types, array_keys($parType)), array_diff(array_keys($parType), $types)) as $t): ?>
                                <li><span><?= View::rendre('gestion/tresorerie/_icone', ['type' => $t]) ?><?= e($type($t)) ?></span><span class="montant" data-type="<?= e($t) ?>"><?= e($montant($parType[$t], (int) $deviseId)) ?></span></li>
                            <?php endforeach; ?>
                        </ul>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php else: ?>
        <p class="bloc aide"><?= e(t('gventes.aucun_paye')) ?></p>
    <?php endif; ?>
    <?php if ($totaux['annulees'] > 0 && $filtres['statut'] !== 'annulee'): ?>
        <p class="aide ven-annulees-note"><a class="lien" href="<?= e(urlAvec(['statut' => 'annulee', 'page' => null])) ?>"><?= e(t($totaux['annulees'] > 1 ? 'gventes.n_annulees' : 'gventes.une_annulee', ['n' => $totaux['annulees']])) ?></a></p>
    <?php endif; ?>
</section>

<?php if ($ventes !== []): ?>
    <section class="bloc ven-bloc-liste" aria-labelledby="ven-liste-titre">
        <h2 id="ven-liste-titre"><?= e(t($nombre > 1 ? 'gventes.liste_n' : 'gventes.liste_1', ['n' => $nombre])) ?></h2>
        <div class="ven-defile">
            <table class="tableau ven-liste">
                <thead>
                    <tr>
                        <th scope="col"><?= e(t('gventes.col_ticket')) ?></th>
                        <th scope="col"><?= e(t('gventes.col_date')) ?></th>
                        <th scope="col"><?= e(t('gestion.etablissement')) ?></th>
                        <th scope="col"><?= e(t('gventes.vendeur')) ?></th>
                        <th scope="col" class="nombre"><?= e(t('gventes.col_articles')) ?></th>
                        <th scope="col" class="nombre"><?= e(t('gventes.col_total')) ?></th>
                        <th scope="col"><?= e(t('gventes.col_paiement')) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($ventes as $v): $annulee = $v['statut'] === 'annulee'; ?>
                        <tr class="ven-ligne<?= $annulee ? ' ven-ligne--annulee' : '' ?>" data-vente="<?= (int) $v['id'] ?>">
                            <td class="ven-ligne__numero">
                                <a class="ven-numero" href="/gestion/ventes/<?= (int) $v['id'] ?>"><?= e($v['numero']) ?></a>
                                <?php if ($annulee): ?><span class="badge badge--erreur"><?= e(t('gventes.badge_annulee')) ?></span><?php endif; ?>
                            </td>
                            <td class="ven-ligne__date"><?= e(A::dateHeure($v['cree_le'])) ?></td>
                            <td class="ven-ligne__etab"><?= e($v['etablissement_nom']) ?></td>
                            <td class="ven-ligne__vendeur">
                                <span class="ven-vendeur"><?= View::rendre('partials/_avatar', ['nom' => $v['vendeur'] ?? '?', 'photo' => $v['vendeur_avatar'], 'classe' => 'avatar--petit']) ?><span><?= e($v['vendeur'] ?? t('gventes.vendeur_inconnu')) ?></span></span>
                            </td>
                            <td class="nombre ven-ligne__articles" data-libelle="<?= e(t('gventes.col_articles')) ?>"><?= (int) $v['articles'] ?></td>
                            <td class="nombre ven-ligne__total"><strong class="montant"><?= e($montant($v['total'], (int) $v['devise_id'])) ?></strong></td>
                            <td class="ven-ligne__moyens">
                                <span class="ven-moyens">
                                    <?php foreach ($v['moyens'] as $t): ?>
                                        <span class="badge badge--neutre ven-moyen"><?= View::rendre('gestion/tresorerie/_icone', ['type' => $t]) ?><?= e($type($t)) ?></span>
                                    <?php endforeach; ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($pages > 1): ?>
            <nav class="ven-pages" aria-label="<?= e(t('gventes.pages')) ?>">
                <?php if ($page > 1): ?><a class="bouton bouton--secondaire bouton--petit" href="<?= e(urlAvec(['page' => $page - 1 > 1 ? $page - 1 : null])) ?>" rel="prev">← <?= e(t('gventes.page_precedente')) ?></a><?php endif; ?>
                <span class="nombre"><?= e(t('gventes.page_sur', ['page' => $page, 'pages' => $pages])) ?></span>
                <?php if ($page < $pages): ?><a class="bouton bouton--secondaire bouton--petit" href="<?= e(urlAvec(['page' => $page + 1])) ?>" rel="next"><?= e(t('gventes.page_suivante')) ?> →</a><?php endif; ?>
            </nav>
        <?php endif; ?>
    </section>
<?php elseif ($totaux['tickets'] > 0 || $filtre): ?>
    <p class="bloc aide"><?= e(t('gventes.aucune')) ?></p>
<?php endif; ?>
