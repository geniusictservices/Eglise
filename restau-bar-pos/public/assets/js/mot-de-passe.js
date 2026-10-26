// Bouton « œil » sur chaque champ mot de passe : afficher ou masquer ce qui est tapé.
// Les libellés viennent de <html data-mdp-afficher="…" data-mdp-masquer="…">.
(function () {
  'use strict';

  var racine = document.documentElement;
  var afficher = racine.getAttribute('data-mdp-afficher') || 'Afficher le mot de passe';
  var masquer = racine.getAttribute('data-mdp-masquer') || 'Masquer le mot de passe';

  var OEIL = '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
    + '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>';
  var OEIL_BARRE = '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
    + '<path d="M10.6 5.1A10.4 10.4 0 0 1 12 5c6.5 0 10 7 10 7a17.6 17.6 0 0 1-3 3.9M6.6 6.6C3.7 8.4 2 12 2 12s3.5 7 10 7c1.9 0 3.6-.6 5-1.5"/>'
    + '<path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/><path d="M3 3l18 18"/></svg>';

  document.querySelectorAll('input[type="password"]').forEach(function (champ) {
    var enveloppe = document.createElement('span');
    enveloppe.className = 'mdp';
    champ.parentNode.insertBefore(enveloppe, champ);
    enveloppe.appendChild(champ);

    var bouton = document.createElement('button');
    bouton.type = 'button';
    bouton.className = 'mdp__oeil';
    bouton.setAttribute('aria-controls', champ.id || '');
    enveloppe.appendChild(bouton);

    function maj(visible) {
      champ.type = visible ? 'text' : 'password';
      bouton.innerHTML = visible ? OEIL_BARRE : OEIL;
      bouton.setAttribute('aria-label', visible ? masquer : afficher);
      bouton.setAttribute('aria-pressed', String(visible));
      bouton.title = visible ? masquer : afficher;
    }
    maj(false);

    bouton.addEventListener('click', function () {
      var debut = champ.selectionStart, fin = champ.selectionEnd;
      maj(champ.type === 'password');
      champ.focus();
      // Garde la position du curseur après la bascule
      try { champ.setSelectionRange(debut, fin); } catch (e) { /* sélection indisponible */ }
    });

    // Le mot de passe repasse masqué à l'envoi, pour ne pas être enregistré en clair par le navigateur
    if (champ.form) {
      champ.form.addEventListener('submit', function () { maj(false); });
    }
  });
})();
