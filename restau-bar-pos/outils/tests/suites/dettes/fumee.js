const { chromium } = require('playwright'); const fs = require('fs');
const B = 'http://integ.localhost:8250'; const MDP = fs.readFileSync(__dirname + '/../instance.txt', 'utf8').match(/Mot de passe\s*:\s*(\S+)/)[1];
(async () => {
  const b = await chromium.launch(); const p = await (await b.newContext({ viewport: { width: 1366, height: 860 } })).newPage(); const err = [];
  p.on('pageerror', x => err.push(x.message)); p.on('console', m => m.type() === 'error' && err.push(m.text()));
  await p.goto(B + '/gestion/connexion'); await p.fill('#email', 'admin@demo.test'); await p.fill('#mot_de_passe', MDP); await Promise.all([p.waitForNavigation(), p.click('button[type=submit]')]);
  await p.goto(B + '/caisse?e=comptoir-du-lac');
  const D = await p.evaluate(() => JSON.parse(document.getElementById('donnees-caisse').textContent));
  console.log('credit', D.credit, 'rembourser', D.rembourser, 'forcer', D.forcer, 'personnel', D.personnel.map(x => x.nom + (x.vend ? '' : '(-)')).join(', '), 'garanties', D.garanties.length, 'delai', D.delai);
  await p.locator('#grille .tuile').first().click(); await p.waitForTimeout(200);
  if (await p.locator('#formats[open]').count()) { await p.locator('#formats-liste .format').first().click(); }
  await p.click('.paiements [data-ardoise]'); await p.waitForTimeout(300);
  console.log('bloc ardoise', await p.locator('#ardoise').count(), 'bilan', await p.textContent('#paiement-bilan'));
  await p.fill('#ardoise-recherche', 'Fura'); await p.waitForTimeout(600);
  console.log('résultats', await p.locator('.ardoise-client').count(), await p.textContent('.ardoise-choix__liste'));
  await p.screenshot({ path: __dirname + '/fumee.png' });
  console.log('erreurs', err);
  await b.close();
})();
