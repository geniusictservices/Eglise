/* Nouveau remboursement : les comptes proposés sont ceux de l'établissement choisi et les comptes communs,
   et la devise ne propose que celles que le compte tient. Sans JavaScript, le serveur vérifie les mêmes règles. */
(function () {
  'use strict';

  var formulaire = document.querySelector('[data-remboursement]');
  if (!formulaire) return;
  var etablissement = formulaire.querySelector('[data-etablissement]');
  var compte = formulaire.querySelector('[data-compte]');
  var devise = formulaire.querySelector('[data-devise]');
  var codes = {};
  try { codes = JSON.parse(formulaire.dataset.codes || '{}'); } catch (e) { codes = {}; }

  function filtrerComptes() {
    var etab = etablissement ? etablissement.value : '';
    Array.prototype.forEach.call(compte.querySelectorAll('option[data-etablissement]'), function (option) {
      var propre = option.dataset.etablissement;
      var visible = propre === '' || etab === '' || propre === etab;
      option.hidden = !visible;
      option.disabled = !visible;
      if (!visible && option.selected) compte.value = '';
    });
    Array.prototype.forEach.call(compte.querySelectorAll('optgroup'), function (groupe) {
      groupe.hidden = !groupe.querySelector('option:not([hidden])');
    });
  }

  function remplirDevises() {
    var option = compte.selectedIndex >= 0 ? compte.options[compte.selectedIndex] : null;
    if (!option || !option.dataset.devises) return;
    var tenues = [];
    try { tenues = JSON.parse(option.dataset.devises); } catch (e) { tenues = []; }
    var choisie = devise.value;
    devise.innerHTML = '';
    tenues.forEach(function (id) {
      var o = document.createElement('option');
      o.value = String(id);
      o.textContent = codes[id] || String(id);
      if (String(id) === choisie) o.selected = true;
      devise.appendChild(o);
    });
  }

  if (etablissement && etablissement.tagName === 'SELECT') {
    etablissement.addEventListener('change', function () { filtrerComptes(); remplirDevises(); });
  }
  compte.addEventListener('change', remplirDevises);
  filtrerComptes();
  remplirDevises();
})();
