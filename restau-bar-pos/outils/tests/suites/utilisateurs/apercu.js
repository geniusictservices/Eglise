const { chromium } = require('playwright'); const fs = require('fs');
const dir = __dirname + '/';
const MDP = fs.readFileSync(dir + '../instance.txt', 'utf8').match(/Mot de passe\s*:\s*(\S+)/)[1];
(async () => {
  const b = await chromium.launch();
  for (const [nom, o] of [['ordi', { viewport: { width: 1366, height: 860 } }], ['tel', { viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true }]]) {
    const p = await (await b.newContext(o)).newPage(); const err = []; p.on('pageerror', x => err.push(x.message));
    await p.goto('http://integ.localhost:8250/gestion/connexion'); await p.fill('#email', 'admin@demo.test'); await p.fill('#mot_de_passe', MDP);
    await Promise.all([p.waitForNavigation(), p.click('button[type=submit]')]);
    for (const u of ['/gestion/utilisateurs', '/gestion/utilisateurs/2', '/gestion/utilisateurs/nouveau', '/gestion/utilisateurs/roles', '/gestion/utilisateurs/roles/2', '/gestion/utilisateurs/appareils', '/gestion/utilisateurs/journal']) {
      const r = await p.goto('http://integ.localhost:8250' + u);
      const t = await p.textContent('main');
      console.log(nom, u, r.status(), 'débord', await p.evaluate(() => document.documentElement.scrollWidth - innerWidth), /\b[a-z_]+\.[a-z_]+_[a-z_]+\b/.test(t) ? 'CLÉ?' : '');
      await p.screenshot({ path: dir + 'ecran' + u.replace(/\//g, '-') + '-' + nom + '.png', fullPage: nom === 'ordi' });
    }
    console.log(err);
  }
  await b.close();
})();
