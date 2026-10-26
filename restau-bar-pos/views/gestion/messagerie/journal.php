<?php
/**
 * Paramètres > Messagerie > Journal : SMS et messages WhatsApp inscrits (relances, garants, essais), filtres
 * (statut, événement, canal, période). « simule » : inscrit, non envoyé (messagerie non réglée).
 * @var array $periode
 * @var array $filtres
 * @var list<array> $messages
 * @var int $nombre
 * @var array<string, int> $compte par statut
 * @var int $page
 * @var int $pages
 * @var bool $voirDettes
 */

use App\Core\View;
use App\Repositories\GestionDetteRepository;

$filtre = $filtres['statut'] || $filtres['evenement'] || $filtres['canal'] || $periode['cle'] !== 'mois';
?>
<link rel="stylesheet" href="/assets/css/gestion-tresorerie.css">
<link rel="stylesheet" href="/assets/css/gestion-dettes.css">

<div class="tre-entete">
    <div>
        <h1><?= e(t('messagerie.journal_titre')) ?></h1>
        <p class="aide"><?= e(t('messagerie.journal_aide')) ?></p>
    </div>
</div>
<?= View::rendre('gestion/messagerie/_onglets', ['actif' => 'journal']) ?>

<section class="bloc tre-filtres" aria-label="<?= e(t('messagerie.filtres')) ?>">
    <?= View::rendre('gestion/tresorerie/_periode', ['periode' => $periode, 'periodes' => ['jour', '7j', 'mois', 'mois_precedent', 'tout'], 'action' => '/gestion/messagerie/journal', 'avecDates' => false]) ?>
    <nav class="tre-puces" aria-label="<?= e(t('messagerie.filtre_statut')) ?>">
        <a class="puce" href="<?= e(urlAvec(['statut' => null, 'page' => null])) ?>"<?= !$filtres['statut'] ? ' aria-current="true"' : '' ?>><?= e(t('messagerie.tous_statuts')) ?></a>
        <?php foreach (GestionDetteRepository::STATUTS_MESSAGE as $s): ?>
            <a class="puce" href="<?= e(urlAvec(['statut' => $s, 'page' => null])) ?>"<?= $filtres['statut'] === $s ? ' aria-current="true"' : '' ?>><?= e(t('messagerie.statuts_' . $s)) ?> <span class="det-compteur" data-compte="<?= e($s) ?>"><?= (int) $compte[$s] ?></span></a>
        <?php endforeach; ?>
    </nav>
    <form class="tre-filtres__formulaire det-filtres" method="get" action="/gestion/messagerie/journal">
        <?php foreach (['statut' => $filtres['statut'], 'periode' => $periode['cle'] !== 'perso' ? $periode['cle'] : null] as $nom => $valeur): ?>
            <?php if ($valeur): ?><input type="hidden" name="<?= e($nom) ?>" value="<?= e((string) $valeur) ?>"><?php endif; ?>
        <?php endforeach; ?>
        <div class="champ">
            <label for="filtre-du"><?= e(t('tresorerie.du')) ?></label>
            <input type="date" id="filtre-du" name="du" value="<?= e($periode['cle'] === 'perso' ? (string) $periode['du'] : '') ?>">
        </div>
        <div class="champ">
            <label for="filtre-au"><?= e(t('tresorerie.au')) ?></label>
            <input type="date" id="filtre-au" name="au" value="<?= e($periode['cle'] === 'perso' ? (string) $periode['au'] : '') ?>">
        </div>
        <div class="champ">
            <label for="filtre-evenement"><?= e(t('messagerie.evenement')) ?></label>
            <select id="filtre-evenement" name="evenement">
                <option value=""><?= e(t('messagerie.tous_evenements')) ?></option>
                <?php foreach (GestionDetteRepository::EVENEMENTS as $ev): ?>
                    <option value="<?= e($ev) ?>" <?= $filtres['evenement'] === $ev ? 'selected' : '' ?>><?= e(t('messagerie.evenement_' . $ev)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="champ">
            <label for="filtre-canal"><?= e(t('messagerie.canal')) ?></label>
            <select id="filtre-canal" name="canal">
                <option value=""><?= e(t('messagerie.tous_canaux')) ?></option>
                <?php foreach (['sms', 'whatsapp'] as $c): ?>
                    <option value="<?= $c ?>" <?= $filtres['canal'] === $c ? 'selected' : '' ?>><?= e(t('messagerie.canal_' . $c)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="tre-filtres__boutons">
            <button class="bouton bouton--secondaire" type="submit"><?= e(t('tresorerie.afficher')) ?></button>
            <?php if ($filtre): ?>
                <a class="lien" href="/gestion/messagerie/journal"><?= e(t('gventes.effacer_filtres')) ?></a>
            <?php endif; ?>
        </div>
    </form>
</section>

<?php if ($messages === []): ?>
    <p class="bloc aide" data-messages="0"><?= e(t('messagerie.journal_vide')) ?></p>
<?php else: ?>
    <section class="bloc" aria-labelledby="det-journal-titre">
        <h2 id="det-journal-titre"><?= e(t($nombre > 1 ? 'messagerie.n_messages' : 'messagerie.un_message', ['n' => $nombre])) ?></h2>
        <?= View::rendre('gestion/dettes/_messages', ['messages' => $messages, 'avecDette' => $voirDettes]) ?>
        <?= View::rendre('gestion/dettes/_pages', ['page' => $page, 'pages' => $pages]) ?>
    </section>
<?php endif; ?>
