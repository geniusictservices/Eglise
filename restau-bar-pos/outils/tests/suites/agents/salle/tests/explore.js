const { chromium } = require('playwright');
const B = 'http://integ.localhost:8250'; const MDP = 'Essai-Integ-2026';
const S = '/home/user/Eglise/restau-bar-pos/outils/tests/suites/agents/salle/';
(async () => {
  const b = await chromium.launch(); const err = [];
  const ctx = await b.newContext({ viewport: { width: 1366, height: 860 } });
  const p = await ctx.newPage(); p.on('pageerror', x => err.push(x.message)); p.on('console', m => m.type() === 'error' && err.push(m.text()));
  await p.goto(B + '/gestion/connexion'); await p.fill('#email', 'admin@demo.test'); await p.fill('#mot_de_passe', MDP); await Promise.all([p.waitForNavigation(), p.click('button[type=submit]')]);
  await p.goto(B + '/caisse?e=comptoir-du-lac'); await p.evaluate(() => sessionStorage.clear()); await p.reload();
  await p.waitForTimeout(800);
  await p.screenshot({ path: S + 'x-caisse.png' });
  await p.click('#salle'); await p.waitForTimeout(500);
  await p.screenshot({ path: S + 'x-salle.png' });
  await p.click('.table-plan[data-statut="occupee"]'); await p.waitForTimeout(800);
  await p.screenshot({ path: S + 'x-note.png' });
  console.log('erreurs', err);
  await b.close();
})();
