const { chromium } = require('playwright');
const B = 'http://integ.localhost:8250';
const MDP = 'Essai-Integ-2026';
(async () => {
  const b = await chromium.launch(); const err = [];
  const ctx = await b.newContext({ viewport: { width: 1366, height: 860 } });
  const p = await ctx.newPage(); p.on('pageerror', x => err.push(x.message)); p.on('console', m => m.type() === 'error' && err.push(m.text()));
  await p.goto(B + '/gestion/connexion'); await p.fill('#email', 'admin@demo.test'); await p.fill('#mot_de_passe', MDP); await Promise.all([p.waitForNavigation(), p.click('button[type=submit]')]);
  for (const u of ['/caisse?e=comptoir-du-lac', '/gestion/salle', '/gestion/salle/notes', '/gestion/salle/reservations', '/gestion/salle/services', '/gestion/cuisine', '/caisse/cuisine', '/gestion']) {
    const r = await p.goto(B + u); const t = await p.textContent('body');
    console.log(r.status(), u, (t.match(/(Fatal|Warning|Notice|Deprecated)[^\n]{0,200}/) || [''])[0]);
  }
  console.log('erreurs', err);
  await b.close();
})();
