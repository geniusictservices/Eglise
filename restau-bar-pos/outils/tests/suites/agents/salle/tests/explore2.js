const { chromium } = require('playwright');
const B = 'http://integ.localhost:8250'; const MDP = 'Essai-Integ-2026';
const S = '/home/user/Eglise/restau-bar-pos/outils/tests/suites/agents/salle/';
(async () => {
  const b = await chromium.launch(); const err = [];
  const ctx = await b.newContext({ viewport: { width: 1366, height: 860 } });
  const p = await ctx.newPage(); p.on('pageerror', x => err.push(x.message)); p.on('console', m => m.type() === 'error' && err.push(m.text()));
  await p.goto(B + '/gestion/connexion'); await p.fill('#email', 'admin@demo.test'); await p.fill('#mot_de_passe', MDP); await Promise.all([p.waitForNavigation(), p.click('button[type=submit]')]);
  for (const [u, n] of [['/caisse/cuisine?etab=comptoir-du-lac', 'x-cuisine'], ['/gestion/salle', 'x-plan'], ['/gestion/salle/services?etablissement=2', 'x-services'], ['/gestion/salle/reservations', 'x-resa'], ['/gestion/cuisine', 'x-gcuisine'], ['/gestion/salle/notes', 'x-notes']]) {
    await p.goto(B + u); await p.waitForTimeout(900); await p.screenshot({ path: S + n + '.png', fullPage: n !== 'x-cuisine' });
  }
  console.log('erreurs', err);
  await b.close();
})();
