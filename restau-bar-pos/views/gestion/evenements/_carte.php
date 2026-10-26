<?php
/**
 * Une soirée dans la liste : date, titre, heures, établissement, artistes ; recettes et résultat si elle est passée.
 * @var array $ev
 * @var ?array $locale
 * @var ?array $reference
 * @var float $taux
 */

use App\Controllers\Gestion\EvenementController;
use App\Controllers\Gestion\RapportAffichage as R;
use App\Controllers\Gestion\TresorerieAffichage as A;

$etat = EvenementController::etat($ev);
$t = strtotime($ev['debut']);
$jours = App\Core\I18n::langue() === 'fr' ? ['lun', 'mar', 'mer', 'jeu', 'ven', 'sam', 'dim'] : ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
$mois = App\Core\I18n::langue() === 'fr' ? ['janv', 'févr', 'mars', 'avr', 'mai', 'juin', 'juil', 'août', 'sept', 'oct', 'nov', 'déc'] : ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
$artistes = trim(str_replace("\n", ', ', (string) $ev['artistes']));
$bilan = $ev['bilan'] ?? null;
?>
<li>
    <a class="evt-carte<?= $etat === 'passe' || $etat === 'annule' ? ' evt-carte--passe' : '' ?>" href="/gestion/evenements/<?= (int) $ev['id'] ?>" data-evenement="<?= (int) $ev['id'] ?>">
        <span class="evt-date" aria-hidden="true">
            <span class="evt-date__jour"><?= e($jours[(int) date('N', $t) - 1]) ?></span>
            <span class="evt-date__num"><?= date('j', $t) ?></span>
            <span class="evt-date__mois"><?= e($mois[(int) date('n', $t) - 1]) ?></span>
        </span>
        <span>
            <span class="evt-carte__titre"><?= e($ev['titre']) ?>
                <?php if ($etat === 'annule'): ?><span class="badge badge--erreur"><?= e(t('evenements.statut_annule')) ?></span>
                <?php elseif ($etat === 'en_cours'): ?><span class="badge badge--succes"><?= e(t('evenements.statut_en_cours')) ?></span><?php endif; ?></span>
            <span class="evt-carte__details"><span class="visuellement-cache"><?= e(R::jourLong($ev['debut'])) ?>, </span><?= e(R::heure($ev['debut']) . ' – ' . R::heure($ev['fin'])) ?> · <?= e($ev['etablissement_nom']) ?><?= $ev['theme'] ? ' · ' . e($ev['theme']) : '' ?></span>
            <?php if ($artistes !== ''): ?><span class="evt-carte__details"><?= e($artistes) ?></span><?php endif; ?>
        </span>
        <span class="evt-carte__chiffres">
            <?php if ($bilan !== null): ?>
                <span class="montant" data-cle="ca"><?= e($locale ? A::montant($bilan['totaux']['ca_locale'], $locale) : '') ?></span>
                <span class="rap-ecart <?= $bilan['resultat'] >= 0 ? 'rap-ecart--bon' : 'rap-ecart--mauvais' ?>" data-cle="resultat"><?= e(t('evenements.resultat_court', ['montant' => $reference ? A::montant($bilan['resultat'], $reference) : ''])) ?></span>
            <?php elseif ($ev['budget'] !== null): ?>
                <span class="rap-note"><?= e(t('evenements.budget_court')) ?></span>
                <span class="montant"><?= e(App\Controllers\Gestion\DetteAffichage::montant($ev['budget'], (int) $ev['budget_devise_id'], App\Controllers\Gestion\VenteController::devises())) ?></span>
            <?php endif; ?>
        </span>
    </a>
</li>
