// Captures des écrans de la trésorerie : ordinateur et téléphone, jour et nuit.
const { chromium } = require('playwright');
const B = 'http://127.0.0.1:8130';
const MDP = require('fs').readFileSync('/home/user/Eglise/restau-bar-pos/outils/tests/instance.txt', 'utf8').match(/Mot de passe\s*:\s*(\S+)/)[1];
const PAGES = (process.argv[2] || 'tresorerie,compte,transferts,depenses,depense,nouvelle,categories,compte-form').split(',');
const URL = {
  tresorerie: '/gestion/tresorerie', compte: '/gestion/tresorerie/comptes/2', transferts: '/gestion/tresorerie/transferts',
  depenses: '/gestion/depenses', depense: '/gestion/depenses/2', nouvelle: '/gestion/depenses/nouvelle',
  categories: '/gestion/depenses/categories', 'compte-form': '/gestion/tresorerie/comptes/nouveau',
};
(async () => {
  const b = await chromium.launch();
  for (const [nom, vp] of [['bureau', { width: 1366, height: 860 }], ['tel', { width: 390, height: 844 }]]) {
    for (const theme of (process.argv[3] || 'jour').split(',')) {
      const c = await b.newContext({ viewport: vp });
      await c.addInitScript(t => { try { localStorage.setItem('gestion.theme', t); } catch (e) {} }, theme);
      const p = await c.newPage();
      p.on('console', m => { if (m.type() === 'error') console.log('console', m.text()); });
      p.on('pageerror', e => console.log('pageerror', e.message));
      await p.goto(B + '/gestion/connexion');
      await p.fill('input[name=email]', 'admin@demo.test');
      await p.fill('input[name=mot_de_passe]', MDP);
      await Promise.all([p.waitForNavigation(), p.click('.connexion button[type=submit]')]);
      for (const n of PAGES) {
        await p.goto(B + URL[n]);
        await p.screenshot({ path: `apercu-${n}-${nom}-${theme}.png`, fullPage: true });
        const sw = await p.evaluate(() => document.documentElement.scrollWidth - innerWidth);
        if (sw > 0) console.log('défilement horizontal', n, nom, theme, sw);
      }
      await c.close();
    }
  }
  await b.close();
})();
