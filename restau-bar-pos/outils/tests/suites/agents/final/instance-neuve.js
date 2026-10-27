const { chromium } = require('playwright');
const B = 'http://integtest.localhost:8106';
(async () => {
  const nav = await chromium.launch();
  const p = await (await nav.newContext()).newPage();
  const err = []; p.on('pageerror', e => err.push(e.message)); p.on('console', m => m.type() === 'error' && err.push(m.text()));
  await p.goto(B + '/gestion/connexion');
  await p.fill('#email', 'admin@integ.test'); await p.fill('#mot_de_passe', 'Integ-test-2026');
  await Promise.all([p.waitForNavigation(), p.click('button[type=submit]')]);
  for (const u of ['/', '/e/comptoir-du-lac', '/e/volcano-club?zone=vip&devise=CDF', '/gestion', '/gestion/catalogue', '/gestion/catalogue/produits/1', '/gestion/catalogue/categories', '/gestion/etablissements/1', '/gestion/grilles', '/gestion/devises', '/gestion/compte', '/gestion/qr', '/gestion/design', '/caisse']) {
    const r = await p.goto(B + u);
    const h = u === '/gestion/devises' ? (await p.textContent('#historique')).replace(/\s+/g, ' ').slice(0, 300) : '';
    console.log(r.status(), u, (await p.title()), h);
  }
  console.log('erreurs JS', err);
  await nav.close();
})();
