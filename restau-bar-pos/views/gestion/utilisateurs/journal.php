<?php
/**
 * Paramètres > Utilisateurs > Journal : qui a fait quoi, quand, sur quel appareil.
 * @var list<array> $entrees
 * @var list<array> $personnes
 * @var ?int $personne
 */
use App\Core\View;
?>
<div class="uti-tete">
    <div>
        <h1><?= e(t('journal.titre')) ?></h1>
        <p class="aide"><?= e(t('journal.aide')) ?></p>
    </div>
</div>
<?= View::rendre('gestion/utilisateurs/_onglets', ['actif' => 'journal']) ?>

<form method="get" action="/gestion/utilisateurs/journal" class="uti-filtre">
    <div class="champ">
        <label for="personne"><?= e(t('journal.personne')) ?></label>
        <select id="personne" name="personne">
            <option value=""><?= e(t('journal.tout_le_monde')) ?></option>
            <?php foreach ($personnes as $p): ?>
                <option value="<?= (int) $p['id'] ?>" <?= (int) $p['id'] === $personne ? 'selected' : '' ?>><?= e($p['nom']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <button class="bouton bouton--secondaire" type="submit"><?= e(t('journal.afficher')) ?></button>
</form>

<?php if ($entrees === []): ?>
    <p class="bloc aide"><?= e(t('journal.vide')) ?></p>
<?php else: ?>
    <div class="bloc">
        <table class="tableau uti-journal">
            <thead><tr><th><?= e(t('journal.date')) ?></th><th><?= e(t('journal.personne')) ?></th><th><?= e(t('journal.action')) ?></th><th><?= e(t('journal.detail')) ?></th></tr></thead>
            <tbody>
                <?php foreach ($entrees as $j): ?>
                    <tr>
                        <td class="nombre"><?= e(date('d/m/Y H:i', strtotime($j['cree_le']))) ?></td>
                        <td><?= e($j['utilisateur'] ?? '—') ?></td>
                        <td><?= e(t('journal.action_' . $j['action']) !== 'journal.action_' . $j['action'] ? t('journal.action_' . $j['action']) : $j['action']) ?></td>
                        <td><?= e(implode(' · ', array_filter([$j['cible'], $j['appareil'] ? t('journal.sur', ['appareil' => $j['appareil']]) : null, $j['ip']]))) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
