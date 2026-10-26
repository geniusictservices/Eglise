<?php
/**
 * État de paiement d'une réception : badge (payée, partielle, à payer, en retard) et reste dû.
 * @var array $r réception avec etat_paiement, reste, echeance, devise_id
 * @var array<int, array> $devises
 */

use App\Controllers\Gestion\TresorerieAffichage as A;

$retard = $r['etat_paiement'] !== 'payee' && $r['echeance'] !== null && $r['echeance'] < date('Y-m-d');
$classe = match (true) {
    $r['etat_paiement'] === 'payee' => 'badge--succes',
    $retard => 'badge--erreur',
    $r['etat_paiement'] === 'partielle' => 'badge--braise',
    default => 'badge--neutre',
};
?>
<span class="badge <?= $classe ?>" data-paiement="<?= e($r['etat_paiement']) ?>"><?= e(t($retard ? 'achats.en_retard' : 'achats.paiement_' . $r['etat_paiement'])) ?></span>
<?php if ($r['etat_paiement'] !== 'payee' && isset($devises[(int) $r['devise_id']])): ?>
    <span class="tre-sous-texte"><?= e(t('achats.reste', ['montant' => A::montant($r['reste'], $devises[(int) $r['devise_id']])])) ?><?= $r['echeance'] ? ' · ' . e(t('achats.echeance_le', ['date' => A::jour($r['echeance'])])) : '' ?></span>
<?php endif; ?>
