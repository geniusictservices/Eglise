// Thème jour / nuit du système Volcan.
// Chargé dans <head> sans « defer » pour appliquer le thème mémorisé avant l'affichage.
// <html data-theme="jour|nuit|auto" data-theme-cle="clé localStorage"> ; boutons : [data-bascule-theme].
(function () {
  'use strict';

  var html = document.documentElement;
  var cle = html.getAttribute('data-theme-cle') || 'theme';
  var sombreSysteme = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;

  function lire() {
    try { return localStorage.getItem(cle); } catch (e) { return null; }
  }
  function ecrire(valeur) {
    try { localStorage.setItem(cle, valeur); } catch (e) { /* stockage indisponible */ }
  }

  var memorise = lire();
  if (memorise === 'jour' || memorise === 'nuit') {
    html.setAttribute('data-theme', memorise);
  }

  /** Thème réellement affiché (« auto » suit le système). */
  function effectif() {
    var theme = html.getAttribute('data-theme');
    if (theme === 'auto') {
      return sombreSysteme && sombreSysteme.matches ? 'nuit' : 'jour';
    }
    return theme === 'nuit' ? 'nuit' : 'jour';
  }

  function majAffichage() {
    var nuit = effectif() === 'nuit';
    document.querySelectorAll('[data-bascule-theme]').forEach(function (bouton) {
      bouton.setAttribute('aria-pressed', String(nuit));
    });
    // Couleur de la barre du navigateur sur téléphone
    var meta = document.querySelector('meta[name="theme-color"]');
    if (meta && document.body) {
      var fond = getComputedStyle(document.body).backgroundColor;
      if (fond) { meta.setAttribute('content', fond); }
    }
  }

  function basculer() {
    var nouveau = effectif() === 'nuit' ? 'jour' : 'nuit';
    html.setAttribute('data-theme', nouveau);
    ecrire(nouveau);
    majAffichage();
    document.dispatchEvent(new CustomEvent('theme:change', { detail: { theme: nouveau } }));
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-bascule-theme]').forEach(function (bouton) {
      bouton.addEventListener('click', basculer);
    });
    majAffichage();
  });
  if (sombreSysteme && sombreSysteme.addEventListener) {
    sombreSysteme.addEventListener('change', majAffichage);
  }

  window.themeVolcan = { effectif: effectif, basculer: basculer };
})();
