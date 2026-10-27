// Captures d'écran (ordinateur 1366×860, téléphone 390×844, jour et nuit) : vue d'ensemble, fiche d'un compte,
// formulaire d'un compte, transfert (change dans la caisse du Club), dépense payée en deux devises.
const { chromium } = require('playwright');
const fs = require('fs');
const B = 'http://integ.localhost:8250';
const D = __dirname + '/multidevises';
const MDP = fs.readFileSync(__dirname + '/../../instance.txt', 'utf8').match(/Mot de passe\s*:\s*(\S+)/)[1];
(async () => {
  const b = await chromium.launch();
  for (const [vue, taille] of [['bureau', { width: 1366, height: 860 }], ['tel', { width: 390, height: 844 }]]) {
    for (const theme of ['jour', 'nuit']) {
      const ctx = await b.newContext({ viewport: taille });
      await ctx.addInitScript((th) => { try { localStorage.setItem('gestion.theme', th); } catch (e) {} }, theme);
      const p = await ctx.newPage();
      await p.goto(B + '/gestion/connexion?lang=fr');
      await p.fill('input[name=email]', 'admin@demo.test'); await p.fill('input[name=mot_de_passe]', MDP);
      await Promise.all([p.waitForNavigation(), p.click('.connexion button[type=submit]')]);
      for (const [n, u, plein] of [['tresorerie', '/gestion/tresorerie', true], ['compte', '/gestion/tresorerie/comptes/1?periode=7j', false],
        ['compte-form', '/gestion/tresorerie/comptes/1/modifier', true], ['transfert', '/gestion/tresorerie/transferts?source=2', false], ['depense', '/gestion/depenses/2', true]]) {
        await p.goto(B + u);
        if (n === 'transfert') {
          await p.selectOption('#devise_source_id', '1');
          await p.selectOption('#compte_destination_id', '2');
          await p.fill('#montant_source', '100');
          await p.evaluate(() => window.scrollTo(0, 0));
        }
        await p.screenshot({ path: `${D}/${n}-${vue}-${theme}.png`, fullPage: plein });
      }
      await ctx.close();
    }
  }
  await b.close();
})();
