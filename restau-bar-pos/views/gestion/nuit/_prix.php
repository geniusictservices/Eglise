<?php
/**
 * Montants par devise, lisibles (« 10 000 FC · 4 $ »).
 * @var array<int, float> $montants devise_id → montant
 * @var array<int, array> $devises
 */
$formateur = new App\Repositories\DeviseRepository();
$textes = [];
foreach ($devises as $id => $d) {
    if (isset($montants[$id])) {
        $textes[] = $formateur->formater((float) $montants[$id], $d);
    }
}
echo e($textes === [] ? '—' : implode(' · ', $textes));
