<?php
/**
 * Addition (pré-note) d'une note ouverte, à imprimer (80 mm) : ce qui reste à payer, ligne par ligne, avec le total
 * dans la devise du ticket puis dans les autres devises. Ce n'est pas un reçu : le reçu vient à l'encaissement.
 *
 * @var array $note note avec ses lignes (NoteRepository::note)
 * @var array $json la même note pour la caisse (prix actuels, reste par devise)
 * @var array $etablissement
 * @var array $personne
 * @var array<string, array> $devises devises acceptées par code
 * @var App\Repositories\DeviseRepository $formateur
 */
$principale = array_key_first($devises);
$montant = static fn (?float $m, string $code): string => $m === null || !isset($devises[$code]) ? '—' : $formateur->formater($m, $devises[$code]);
$qte = static function (float $q): string {
    if (abs($q - round($q)) < 0.0005) {
        return (string) (int) round($q);
    }
    for ($k = 2; $k <= 20; $k++) {
        if (abs($q * $k - round($q * $k)) < 0.01 * $k) {
            return (int) round($q * $k) . '/' . $k;
        }
    }
    return number_format($q, 2, ',', ' ');
};
?>
<article class="recu recu--addition" aria-label="<?= e(t('note.addition')) ?>">
    <header class="recu__tete">
        <p class="recu__etab"><?= e($etablissement['nom']) ?></p>
        <?php if (!empty($etablissement['adresse'])): ?><p class="recu__info"><?= e($etablissement['adresse']) ?></p><?php endif; ?>
        <p class="recu__titre"><?= e(t('note.addition_titre')) ?></p>
    </header>
    <dl class="recu__meta">
        <div><dt><?= e(t('note.table_libelle')) ?></dt><dd><?= e(App\Repositories\CuisineRepository::titreNote($note)) ?></dd></div>
        <div><dt><?= e(t('recu.date')) ?></dt><dd><?= e(date('d/m/Y H:i')) ?></dd></div>
        <?php if (!empty($note['serveur_nom'])): ?><div><dt><?= e(t('recu.vendeur')) ?></dt><dd><?= e($note['serveur_nom']) ?></dd></div><?php endif; ?>
        <?php if (!empty($note['couverts'])): ?><div><dt><?= e(t('note.couverts')) ?></dt><dd><?= (int) $note['couverts'] ?></dd></div><?php endif; ?>
    </dl>
    <table class="recu__lignes">
        <tbody>
            <?php foreach ($json['lignes'] as $l): if ($l['annulee'] || $l['reste'] <= 0.0005) { continue; } $pu = $l['prix'][$principale] ?? null; ?>
                <tr>
                    <td class="recu__qte"><?= e($qte($l['reste'])) ?> ×</td>
                    <td><?= e($l['libelle']) ?><?php if ($l['format']): ?><br><small><?= e($l['format']) ?></small><?php endif; ?>
                        <?php if ($l['reste'] > 1 && $pu !== null): ?><br><small><?= e($montant($pu, $principale)) ?></small><?php endif; ?></td>
                    <td class="recu__montant"><?= e($montant($pu !== null ? $pu * $l['reste'] : null, $principale)) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <div class="recu__total">
        <span><?= e(t('note.a_payer')) ?></span>
        <strong><?= e($montant($json['totaux'][$principale] ?? null, $principale)) ?></strong>
    </div>
    <?php $autres = array_filter($json['totaux'], static fn ($t, $c) => $c !== $principale && $t !== null, ARRAY_FILTER_USE_BOTH); ?>
    <?php if ($autres !== []): ?>
        <p class="recu__equivalent"><?= e(t('recu.soit')) ?> <?= e(implode(' · ', array_map(static fn ($t, $c) => $montant($t, $c), $autres, array_keys($autres)))) ?></p>
    <?php endif; ?>
    <p class="recu__merci"><?= e(t('note.addition_pied')) ?></p>
</article>
