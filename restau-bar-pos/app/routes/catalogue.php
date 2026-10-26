<?php

declare(strict_types=1);

/**
 * Gestion du catalogue : produits, formats et prix, catégories.
 * @var App\Core\Router $routeur
 */

use App\Controllers\Gestion\CatalogueController;
use App\Controllers\Gestion\CatalogueProduitController;
use App\Controllers\Gestion\CategorieController;

$routeur->get('/gestion/catalogue', [CatalogueController::class, 'index']);

// Catégories
$routeur->get('/gestion/catalogue/categories', [CategorieController::class, 'index']);
$routeur->post('/gestion/catalogue/categories', [CategorieController::class, 'creer']);
$routeur->post('/gestion/catalogue/categories/{id}', [CategorieController::class, 'modifier']);
$routeur->post('/gestion/catalogue/categories/{id}/monter', [CategorieController::class, 'monter']);
$routeur->post('/gestion/catalogue/categories/{id}/descendre', [CategorieController::class, 'descendre']);
$routeur->post('/gestion/catalogue/categories/{id}/supprimer', [CategorieController::class, 'supprimer']);

// Produits (« nouveau » avant {id}, que le motif accepterait aussi)
$routeur->get('/gestion/catalogue/produits/nouveau', [CatalogueProduitController::class, 'nouveau']);
$routeur->post('/gestion/catalogue/produits/nouveau', [CatalogueProduitController::class, 'creer']);
$routeur->get('/gestion/catalogue/produits/{id}', [CatalogueProduitController::class, 'fiche']);
$routeur->post('/gestion/catalogue/produits/{id}', [CatalogueProduitController::class, 'enregistrerInfos']);
$routeur->post('/gestion/catalogue/produits/{id}/photo', [CatalogueProduitController::class, 'envoyerPhoto']);
$routeur->post('/gestion/catalogue/produits/{id}/photo/supprimer', [CatalogueProduitController::class, 'supprimerPhoto']);
$routeur->post('/gestion/catalogue/produits/{id}/formats', [CatalogueProduitController::class, 'ajouterFormat']);
$routeur->post('/gestion/catalogue/produits/{id}/formats/{format}', [CatalogueProduitController::class, 'modifierFormat']);
$routeur->post('/gestion/catalogue/produits/{id}/formats/{format}/supprimer', [CatalogueProduitController::class, 'supprimerFormat']);
$routeur->post('/gestion/catalogue/produits/{id}/prix', [CatalogueProduitController::class, 'enregistrerPrix']);
$routeur->post('/gestion/catalogue/produits/{id}/vente', [CatalogueProduitController::class, 'enregistrerVente']);
// Tailles d'un produit principal (Petite 33 cl, Grande 72 cl) : chacune est un produit avec ses formats et prix
$routeur->post('/gestion/catalogue/produits/{id}/tailles', [CatalogueProduitController::class, 'ajouterTaille']);
$routeur->post('/gestion/catalogue/produits/{id}/tailles/{taille}/monter', [CatalogueProduitController::class, 'monterTaille']);
$routeur->post('/gestion/catalogue/produits/{id}/tailles/{taille}/descendre', [CatalogueProduitController::class, 'descendreTaille']);
$routeur->post('/gestion/catalogue/produits/{id}/desactiver', [CatalogueProduitController::class, 'desactiver']);
$routeur->post('/gestion/catalogue/produits/{id}/reactiver', [CatalogueProduitController::class, 'reactiver']);

// Depuis la liste : disponibilité en un toucher et ordre dans la catégorie
$routeur->post('/gestion/catalogue/produits/{id}/disponible', [CatalogueController::class, 'basculerDisponible']);
$routeur->post('/gestion/catalogue/produits/{id}/monter', [CatalogueController::class, 'monter']);
$routeur->post('/gestion/catalogue/produits/{id}/descendre', [CatalogueController::class, 'descendre']);
