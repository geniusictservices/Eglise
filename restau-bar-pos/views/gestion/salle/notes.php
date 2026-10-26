<?php
/**
 * Salle > Notes ouvertes : notes en cours et notes d'une journée d'exploitation, temps de préparation par poste.
 * @var list<array> $etablissements
 * @var array $etab
 * @var string $jour journée d'exploitation (Y-m-d)
 * @var array{0: string, 1: string} $bornes
 * @var list<array> $notes
 * @var array $devise devise du ticket
 * @var App\Repositories\DeviseRepository $formateur
 * @var list<array> $temps
 */
use App\Repositories\CuisineRepository;

$statuts = ['ouverte' => 'badge--lave', 'payee' => 'badge--succes', 'fusionnee' => 'badge--neutre', 'annulee' => 'badge--neutre'];
$ouvertes = array_filter($notes, static fn (array $n) => $n['statut'] === 'ouverte');
$autres = array_filter($notes, static fn (array $n) => $n['statut'] !== 'ouverte');
$ligne = static function (array $n) use ($formateur, $devise, $statuts): string {
    $minutes = (int) floor(((($n['fermee_le'] ? strtotime($n['fermee_le']) : time())) - strtotime($n['ouverte_le'])) / 60);
    ob_start(); ?>
    <li>
        <a class="ven-ligne" href="/gestion/salle/notes/<?= (int) $n['id'] ?>">
            <span class="ven-ligne__numero"><?= e(CuisineRepository::titreNote($n)) ?>
                <span class="badge <?= $statuts[$n['statut']] ?>"><?= e(t('gsalle.note_' . $n['statut'])) ?></span>
                <?php if ($n['addition_le'] && $n['statut'] === 'ouverte'): ?><span class="badge badge--braise"><?= e(t('note.addition_demandee')) ?></span><?php endif; ?>
            </span>
            <span class="ven-ligne__info"><?= e(date('d/m H:i', strtotime($n['ouverte_le']))) ?> · <?= e(t('note.minutes', ['n' => $minutes])) ?><?= $n['serveur_nom'] ? ' · ' . e($n['serveur_nom']) : '' ?><?= (int) $n['ventes'] ? ' · ' . e(t('gsalle.n_ventes', ['n' => (int) $n['ventes']])) : '' ?><?= (int) $n['annulees'] ? ' · ' . e(t('gsalle.n_annulees', ['n' => (int) $n['annulees']])) : '' ?></span>
            <span class="ven-ligne__montant"><?= e($formateur->formater((float) ($n['statut'] === 'ouverte' ? $n['reste'] : $n['total']), $devise)) ?></span>
        </a>
    </li>
    <?php return (string) ob_get_clean();
};
?>
<link rel="stylesheet" href="/assets/css/gestion-ventes.css">
<link rel="stylesheet" href="/assets/css/gestion-salle.css">
<div class="sal-entete">
    <div>
        <h1><?= e(t('gsalle.notes_titre')) ?></h1>
        <p class="aide"><?= e(t('gsalle.notes_aide', ['debut' => date('d/m H:i', strtotime($bornes[0])), 'fin' => date('d/m H:i', strtotime($bornes[1]))])) ?></p>
    </div>
</div>
<?= App\Core\View::rendre('gestion/salle/_etablissements', ['etablissements' => $etablissements, 'etab' => $etab, 'garder' => ['jour' => $jour]]) ?>
<form class="sal-jour" method="get" action="/gestion/salle/notes">
    <input type="hidden" name="etablissement" value="<?= (int) $etab['id'] ?>">
    <label class="champ"><span><?= e(t('gsalle.journee')) ?></span><input class="saisie" type="date" name="jour" value="<?= e($jour) ?>"></label>
    <button class="bouton bouton--secondaire" type="submit"><?= e(t('gsalle.afficher')) ?></button>
</form>

<section class="bloc" aria-labelledby="sal-ouvertes">
    <h2 id="sal-ouvertes"><?= e(t('gsalle.en_cours', ['n' => count($ouvertes)])) ?></h2>
    <?php if ($ouvertes === []): ?><p class="aide"><?= e(t('gsalle.aucune_ouverte')) ?></p><?php else: ?>
        <ul class="ven-liste"><?php foreach ($ouvertes as $n) { echo $ligne($n); } ?></ul>
    <?php endif; ?>
</section>
<section class="bloc" aria-labelledby="sal-fermees">
    <h2 id="sal-fermees"><?= e(t('gsalle.de_la_journee', ['n' => count($autres)])) ?></h2>
    <?php if ($autres === []): ?><p class="aide"><?= e(t('gsalle.aucune_journee')) ?></p><?php else: ?>
        <ul class="ven-liste"><?php foreach ($autres as $n) { echo $ligne($n); } ?></ul>
    <?php endif; ?>
</section>
<?php if (array_filter($temps, static fn (array $p) => $p['plats'] > 0) !== []): ?>
    <section class="bloc" aria-labelledby="sal-temps">
        <h2 id="sal-temps"><?= e(t('gsalle.temps_titre')) ?></h2>
        <div class="defile"><table class="tableau">
            <thead><tr><th><?= e(t('gcuisine.poste')) ?></th><th class="nombre"><?= e(t('gsalle.plats')) ?></th><th class="nombre"><?= e(t('gsalle.temps_moyen')) ?></th><th class="nombre"><?= e(t('gsalle.retards')) ?></th></tr></thead>
            <tbody><?php foreach ($temps as $p): ?><tr><td><?= e($p['poste']) ?></td><td class="nombre"><?= $p['plats'] ?></td><td class="nombre"><?= $p['moyenne'] !== null ? e(t('note.minutes', ['n' => str_replace('.', ',', (string) $p['moyenne'])])) : '—' ?></td><td class="nombre"><?= $p['retards'] ?></td></tr><?php endforeach; ?></tbody>
        </table></div>
    </section>
<?php endif; ?>
