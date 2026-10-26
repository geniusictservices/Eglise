// Fiche d'une personne : ajouter ou retirer une ligne de rôle (sans JavaScript, une ligne vide est déjà prévue).
(function () {
  'use strict';
  var liste = document.getElementById('liste-roles');
  var modele = document.getElementById('modele-role');
  var ajouter = document.getElementById('ajouter-role');
  if (!liste || !modele || !ajouter) { return; }
  var compteur = 1000;

  function brancher(ligne) {
    var retirer = ligne.querySelector('[data-retirer-role]');
    retirer.hidden = false;
    retirer.addEventListener('click', function () {
      if (liste.querySelectorAll('[data-ligne-role]').length > 1) { ligne.remove(); }
      else { ligne.querySelectorAll('select').forEach(function (s) { s.selectedIndex = 0; }); }
    });
  }
  liste.querySelectorAll('[data-ligne-role]').forEach(brancher);
  ajouter.hidden = false;
  ajouter.addEventListener('click', function () {
    var copie = modele.content.firstElementChild.cloneNode(true);
    compteur++;
    copie.querySelectorAll('[id]').forEach(function (el) { el.id = el.id.replace('999', String(compteur)); });
    copie.querySelectorAll('label[for]').forEach(function (el) { el.htmlFor = el.htmlFor.replace('999', String(compteur)); });
    liste.appendChild(copie);
    brancher(copie);
    copie.querySelector('select').focus();
  });
})();
