// node apercu.js <chemin> <nom> [email mdp]  → captures 1366 jour + 390 nuit, erreurs JS, débordement
const { chromium } = require('playwright');
const B = 'http://integ.localhost:8250';
(async () => {
  const [chemin, nom, email = 'admin@demo.test', mdp = 'Essai-Integ-2026'] = process.argv.slice(2);
  const nav = await chromium.launch();
  for (const [l, h, theme] of [[1366, 860, 'jour'], [390, 844, 'nuit']]) {
    const ctx = await nav.newContext({ viewport: { width: l, height: h } });
    const page = await ctx.newPage();
    const erreurs = [];
    page.on('pageerror', e => erreurs.push(String(e)));
    page.on('console', m => { if (m.type() === 'error') erreurs.push(m.text()); });
    await page.goto(B + '/gestion/connexion?lang=fr');
    await page.fill('#email', email); await page.fill('#mot_de_passe', mdp);
    await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
    await page.addInitScript(t => { try { localStorage.setItem('gestion.theme', t); } catch (e) {} }, theme);
    await page.goto(B + chemin);
    const deb = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    await page.screenshot({ path: `${nom}-${l}-${theme}.png`, fullPage: true });
    console.log(l, theme, 'débordement', deb, 'erreurs', erreurs);
    await ctx.close();
  }
  await nav.close();
})();
