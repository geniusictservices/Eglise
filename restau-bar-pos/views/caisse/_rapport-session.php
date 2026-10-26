<?php
/**
 * Rapport de clôture d'une session de caisse (80 mm) : par caisse et devise le fond, les entrées et sorties,
 * l'attendu, le compté et l'écart ; puis les tickets, l'encaissé par compte, les ardoises et les remboursements.
 *
 * @var array $session
 * @var list<array> $lignes
 * @var array $resume
 * @var array<int, array> $devises
 * @var array $etablissement
 */
$formateur = new App\Repositories\DeviseRepository();
$montant = static fn (float|string $m, int $deviseId): string => isset($devises[$deviseId]) ? $formateur->formater((float) $m, $devises[$deviseId]) : (string) $m;
$signe = static fn (float $m, int $deviseId): string => ($m > 0 ? '+' : ($m < 0 ? '−' : '')) . $montant(abs($m), $deviseId);
?>
<article class="recu recu--session" aria-labelledby="rapport-session-<?= (int) $session['id'] ?>">
    <header class="recu__tete">
        <p class="recu__etab"><?= e($etablissement['nom']) ?></p>
    </header>
    <p class="recu__titre" id="rapport-session-<?= (int) $session['id'] ?>"><?= e(t('session.rapport')) ?></p>
    <dl class="recu__meta">
        <div><dt><?= e(t('session.numero')) ?></dt><dd><?= (int) $session['id'] ?></dd></div>
        <div><dt><?= e(t('gsession.personne')) ?></dt><dd><?= e($session['utilisateur_nom']) ?></dd></div>
        <div><dt><?= e(t('session.ouverte_le')) ?></dt><dd><?= e(date('d/m/Y H:i', strtotime($session['ouverte_le']))) ?></dd></div>
        <?php if ($session['fermee_le']): ?>
            <div><dt><?= e(t('session.fermee_le')) ?></dt><dd><?= e(date('d/m/Y H:i', strtotime($session['fermee_le']))) ?></dd></div>
            <?php if ((int) $session['fermee_par'] !== (int) $session['utilisateur_id']): ?><div><dt><?= e(t('session.fermee_par')) ?></dt><dd><?= e((string) $session['fermee_par_nom']) ?></dd></div><?php endif; ?>
        <?php endif; ?>
    </dl>
    <?php foreach ($lignes as $l): ?>
        <table class="recu__lignes recu__session-caisse">
            <caption><?= e($l['nom']) ?> · <?= e($devises[$l['devise_id']]['code'] ?? '') ?></caption>
            <tbody>
                <tr><td><?= e(t('session.fond')) ?></td><td class="recu__montant"><?= e($montant($l['fond'], $l['devise_id'])) ?></td></tr>
                <tr><td><?= e(t('session.mouvements')) ?></td><td class="recu__montant"><?= e($signe($l['mouvements'], $l['devise_id'])) ?></td></tr>
                <tr><td><strong><?= e(t('session.attendu')) ?></strong></td><td class="recu__montant"><strong><?= e($montant($l['attendu'], $l['devise_id'])) ?></strong></td></tr>
                <?php if ($l['compte'] !== null): ?>
                    <tr><td><?= e(t('session.compte')) ?></td><td class="recu__montant"><?= e($montant($l['compte'], $l['devise_id'])) ?></td></tr>
                    <tr class="<?= $l['ecart'] < 0 ? 'recu__ecart recu__ecart--manquant' : 'recu__ecart' ?>"><td><?= e($l['ecart'] < 0 ? t('session.manquant') : ($l['ecart'] > 0 ? t('session.excedent') : t('session.juste'))) ?></td><td class="recu__montant"><?= e($signe((float) $l['ecart'], $l['devise_id'])) ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    <?php endforeach; ?>
    <table class="recu__paiements">
        <tbody>
            <tr><td><?= e(t('session.tickets')) ?></td><td class="recu__montant"><?= (int) $resume['tickets'] ?><?= $resume['annulees'] ? ' (' . e(t('session.annulees', ['n' => (int) $resume['annulees']])) . ')' : '' ?></td></tr>
            <?php foreach ($resume['totaux'] as $t): ?>
                <tr><td><?= e(t('session.vendu')) ?></td><td class="recu__montant"><?= e($montant($t['total'], (int) $t['devise_id'])) ?></td></tr>
            <?php endforeach; ?>
            <?php foreach ($resume['encaisse'] as $p): ?>
                <tr><td><?= e($p['compte']) ?><br><small><?= e(t('tresorerie.type_' . $p['moyen'])) ?></small></td><td class="recu__montant"><?= e($montant($p['montant'], (int) $p['devise_id'])) ?></td></tr>
            <?php endforeach; ?>
            <?php foreach ($resume['ardoises'] as $a): ?>
                <tr><td><?= e(t('session.ardoises', ['n' => (int) $a['nombre']])) ?></td><td class="recu__montant"><?= e($montant($a['montant'], (int) $a['devise_id'])) ?></td></tr>
            <?php endforeach; ?>
            <?php foreach ($resume['remboursements'] as $r): ?>
                <tr><td><?= e(t('session.remboursements', ['n' => (int) $r['nombre']])) ?></td><td class="recu__montant"><?= e($montant($r['montant'], (int) $r['devise_id'])) ?></td></tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php if (!empty($session['note'])): ?><p class="recu__info"><?= e($session['note']) ?></p><?php endif; ?>
</article>
