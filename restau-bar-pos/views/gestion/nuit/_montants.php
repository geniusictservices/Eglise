<?php
/**
 * Montants dans chaque devise acceptée (« montants[<devise_id>] ») : la devise locale d'abord, le dollar ensuite.
 * Une devise laissée vide prend l'équivalent au taux du jour (ou n'a pas de montant).
 * @var array<int, array> $acceptees
 * @var array<int, float> $valeurs
 * @var string $id préfixe des identifiants
 * @var bool $desactive
 */
$formateur = new App\Repositories\DeviseRepository();
?>
<?php foreach ($acceptees as $deviseId => $d): ?>
    <label class="champ sal-champ--court"><span><?= e($d['code']) ?></span>
        <input class="saisie" name="montants[<?= (int) $deviseId ?>]" inputmode="decimal" autocomplete="off" id="<?= e($id) ?>-<?= (int) $deviseId ?>"
               value="<?= isset($valeurs[$deviseId]) ? e(rtrim(rtrim(number_format((float) $valeurs[$deviseId], (int) $d['decimales'], '.', ''), '0'), '.')) : '' ?>"<?= !empty($desactive) ? ' disabled' : '' ?>></label>
<?php endforeach; ?>
