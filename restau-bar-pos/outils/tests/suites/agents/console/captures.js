// Captures d'écran de la console : node captures.js <dossier> <chemin>... (ordi 1366×860 et téléphone 390×844, jour/nuit)
const { chromium } = require('playwright');
const [dossier, ...chemins] = process.argv.slice(2);
(async () => {
  const b = await chromium.launch();
  for (const [nom, vp] of [['ordi', { width: 1366, height: 860 }], ['tel', { width: 390, height: 844 }]]) {
    for (const theme of (process.env.THEMES || 'jour').split(',')) {
      const c = await b.newContext({ viewport: vp, colorScheme: theme === 'nuit' ? 'dark' : 'light' });
      const p = await c.newPage();
      await p.goto('http://admin.localhost:8180/connexion');
      await p.fill('#email', 'super@plateforme.test');
      await p.fill('#mot_de_passe', process.env.MDP || 'Console-Goma-2026!');
      await p.click('button[type=submit]');
      for (const u of chemins) {
        await p.goto('http://admin.localhost:8180' + u);
        const f = `${dossier}/${u.replace(/[^a-z0-9]+/gi, '_').replace(/^_|_$/g, '') || 'accueil'}-${nom}-${theme}.png`;
        await p.screenshot({ path: f, fullPage: process.env.PLEIN === '1' });
        const large = await p.evaluate(() => document.documentElement.scrollWidth > window.innerWidth);
        if (large) console.log('DÉBORDE', u, nom);
      }
      await c.close();
    }
  }
  await b.close();
})();
