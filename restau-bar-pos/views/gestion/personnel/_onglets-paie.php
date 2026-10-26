<?php /** @var string $actif bulletins | masse */ ?>
<?= App\Core\View::rendre('gestion/personnel/_onglets', ['actif' => $actif, 'libelle' => t('ecran.paie'), 'onglets' => [
    'bulletins' => ['/gestion/personnel/paie', 'pers.onglet_bulletins'],
    'masse' => ['/gestion/personnel/paie/masse', 'pers.onglet_masse'],
]]) ?>
