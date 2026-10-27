const { chromium } = require('playwright');
const fs = require('fs');
const mdp = fs.readFileSync(__dirname + '/../../instance.txt', 'utf8').match(/Mot de passe\s*:\s*(\S+)/)[1];
(async () => {
  const [url, sortie, w, h, theme, y] = process.argv.slice(2);
  const b = await chromium.launch();
  const c = await b.newContext({ viewport: { width: +w, height: +h }, deviceScaleFactor: 1 });
  const p = await c.newPage();
  await p.goto('http://integ.localhost:8250/gestion/connexion?lang=fr');
  await p.fill('#email', 'admin@demo.test'); await p.fill('#mot_de_passe', mdp);
  await Promise.all([p.waitForNavigation(), p.click('button[type=submit]')]);
  await p.evaluate(t => localStorage.setItem('gestion.theme', t), theme || 'jour');
  await p.goto('http://integ.localhost:8250' + url);
  if (y) await p.evaluate(y => window.scrollTo(0, +y), y);
  await p.screenshot({ path: sortie });
  await b.close();
})();
