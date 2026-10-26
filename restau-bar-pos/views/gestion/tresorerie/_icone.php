<?php
/**
 * Icône d'un moyen de paiement (décorative : le nom du moyen est toujours écrit à côté).
 * @var string $type caisse | mobile_money | banque
 */
$chemins = [
    // Billet
    'caisse' => 'M3 6h18a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1Zm1 2v8h16V8H4Zm8 1.5a2.5 2.5 0 1 1 0 5 2.5 2.5 0 0 1 0-5ZM5.5 9.5h2v1.5h-2V9.5Zm11 3.5h2v1.5h-2V13Z',
    // Téléphone
    'mobile_money' => 'M8 2h8a2 2 0 0 1 2 2v16a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2Zm0 2v14h8V4H8Zm3 15.25h2v1.5h-2v-1.5ZM10.25 7h3.5v1.5h-3.5V7Zm-1 3.5h5.5V12h-5.5v-1.5Z',
    // Banque
    'banque' => 'M12 2.5 21.5 7v2h-19V7L12 2.5ZM12 4.7 6.2 7.5h11.6L12 4.7ZM4.5 10.5h2v7h-2v-7Zm4.33 0h2v7h-2v-7Zm4.34 0h2v7h-2v-7Zm4.33 0h2v7h-2v-7ZM2.5 19h19v2.5h-19V19Z',
];
?>
<svg class="tre-icone" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="currentColor" fill-rule="evenodd" d="<?= $chemins[$type] ?? $chemins['caisse'] ?>"/></svg>
