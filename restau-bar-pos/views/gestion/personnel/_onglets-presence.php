<?php /** @var string $actif jour | pointages | bornes | reglages */ ?>
<?= App\Core\View::rendre('gestion/personnel/_onglets', ['actif' => $actif, 'libelle' => t('ecran.presence'), 'onglets' => [
    'jour' => ['/gestion/personnel/presence', 'pers.onglet_jour'],
    'pointages' => ['/gestion/personnel/presence/pointages', 'pers.onglet_pointages'],
    'bornes' => ['/gestion/personnel/presence/bornes', 'pers.onglet_bornes'],
    'reglages' => ['/gestion/personnel/presence/reglages', 'pers.onglet_reglages'],
]]) ?>
