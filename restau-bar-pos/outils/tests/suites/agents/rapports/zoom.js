// node zoom.js <chemin> <sélecteur> <sortie> [largeur] [theme]
const { chromium } = require('playwright');
const B = 'http://integ.localhost:8250';
(async () => {
  const [chemin, sel, sortie, l = '390', theme = 'nuit', email = 'admin@demo.test', mdp = 'Essai-Integ-2026'] = process.argv.slice(2);
  const nav = await chromium.launch();
  const ctx = await nav.newContext({ viewport: { width: +l, height: 844 } });
  const page = await ctx.newPage();
  await page.goto(B + '/gestion/connexion?lang=fr');
  await page.fill('#email', email); await page.fill('#mot_de_passe', mdp);
  await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
  await page.addInitScript(t => { try { localStorage.setItem('gestion.theme', t); } catch (e) {} }, theme);
  await page.goto(B + chemin);
  await page.locator(sel).first().screenshot({ path: sortie });
  await nav.close();
})();
