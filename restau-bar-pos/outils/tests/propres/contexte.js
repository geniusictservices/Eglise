const { chromium } = require('playwright'); const fs = require('fs');
const B = 'http://integ2.localhost:8250';
const MDP = fs.readFileSync('/home/user/Eglise/restau-bar-pos/outils/tests/instance.txt', 'utf8').match(/Mot de passe\s*:\s*(\S+)/)[1];
(async () => {
  const b = await chromium.launch(); const p = await (await b.newContext({ viewport: { width: 1180, height: 900 } })).newPage();
  await p.goto(B + '/gestion/connexion'); await p.fill('#email', 'admin@demo.test'); await p.fill('#mot_de_passe', MDP);
  await Promise.all([p.waitForNavigation(), p.click('button[type=submit]')]);
  await p.goto(B + '/gestion/personnel/employes/4');
  const r = await p.evaluate(() => [...document.querySelectorAll('main span.montant')].filter(e => e.scrollWidth > e.clientWidth + 1).map(e => { const c = e.parentElement; return c.outerHTML.slice(0, 300) + ' | ' + getComputedStyle(e).display + ' ' + e.getBoundingClientRect().width; }));
  console.log(r.join('\n'));
  const el = await p.$('main span.montant >> nth=0'); 
  await b.close();
})();
