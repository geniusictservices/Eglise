<?php
/**
 * Onglets de Paramètres > Messagerie : réglages, journal des messages.
 * @var string $actif reglages | journal
 */
?>
<nav class="tre-puces det-onglets" aria-label="<?= e(t('messagerie.titre')) ?>">
    <a class="puce" href="/gestion/messagerie"<?= $actif === 'reglages' ? ' aria-current="page"' : '' ?>><?= e(t('messagerie.onglet_reglages')) ?></a>
    <a class="puce" href="/gestion/messagerie/journal"<?= $actif === 'journal' ? ' aria-current="page"' : '' ?>><?= e(t('messagerie.onglet_journal')) ?></a>
</nav>
