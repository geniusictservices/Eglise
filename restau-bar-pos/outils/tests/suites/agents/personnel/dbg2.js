const { chromium } = require('playwright'); const B = 'http://integ.localhost:8250';
(async () => { const b = await chromium.launch(); const p = await (await b.newContext()).newPage();
  await p.goto(B + '/gestion/connexion'); await p.fill('#email', 'patrick@demo.test'); await p.fill('#mot_de_passe', 'Gerant-Goma-2026'); await Promise.all([p.waitForNavigation(), p.click('button[type=submit]')]);
  await p.goto(B + '/gestion/personnel/presence/reglages?etablissement=1'); await p.fill('#rayon', '100'); await p.check('input[name=obligatoire]');
  const [r] = await Promise.all([p.waitForNavigation(), p.click('#reglages-pointage button[type=submit]')]); console.log(r.status(), p.url());
  console.log(await p.locator('.alerte, #erreurs').allTextContents()); await b.close(); })();
