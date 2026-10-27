// Captures rapides des écrans (ordinateur et téléphone, jour et nuit)
const { chromium } = require('playwright');
const B = 'http://integ.localhost:8250';
const pages = process.argv.slice(2);
(async () => {
  const nav = await chromium.launch();
  for (const [nom, vp] of [['bureau', { width: 1366, height: 860 }], ['tel', { width: 390, height: 844 }]]) {
    for (const theme of ['jour', 'nuit']) {
      const ctx = await nav.newContext({ viewport: vp, colorScheme: theme === 'nuit' ? 'dark' : 'light' });
      const p = await ctx.newPage();
      const erreurs = [];
      p.on('pageerror', e => erreurs.push(String(e)));
      p.on('console', m => { if (m.type() === 'error') erreurs.push(m.text()); });
      await p.goto(B + '/gestion/connexion?lang=fr');
      await p.fill('#email', 'admin@demo.test');
      await p.fill('#mot_de_passe', 'Essai-Integ-2026');
      await Promise.all([p.waitForNavigation(), p.click('button[type=submit]')]);
      for (const u of pages) {
        await p.goto(B + u);
        const deb = await p.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
        const fichier = 'apercu-' + u.replace(/[^a-z0-9]+/gi, '_') + '-' + nom + '-' + theme + '.png';
        await p.screenshot({ path: fichier, fullPage: true });
        console.log(fichier, 'débordement', deb, erreurs.join(' | '));
      }
      await ctx.close();
    }
  }
  await nav.close();
})();
