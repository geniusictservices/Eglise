const { chromium } = require('playwright'); const fs = require('fs');
const B = 'http://integ2.localhost:8250';
const MDP = fs.readFileSync('/home/user/Eglise/restau-bar-pos/outils/tests/instance.txt', 'utf8').match(/Mot de passe\s*:\s*(\S+)/)[1];
(async () => {
  const b = await chromium.launch(); const p = await (await b.newContext({ viewport: { width: 1180, height: 820 } })).newPage();
  await p.goto(B + '/gestion/connexion'); await p.fill('#email', 'admin@demo.test'); await p.fill('#mot_de_passe', MDP);
  await Promise.all([p.waitForNavigation(), p.click('button[type=submit]')]);
  await p.goto(B + '/caisse?e=comptoir-du-lac'); await p.click('#quitter'); await p.click('.partager > summary');
  await Promise.all([p.waitForNavigation(), p.click('.partager__form button[type=submit]')]);
  await p.click('.verrou__personne[data-nom="Aline Mukeba"]'); for (const c of '271828') { await p.click(`#pave-touches [data-chiffre="${c}"]`); }
  await p.waitForLoadState('load'); await p.waitForTimeout(400);
  for (const u of ['/caisse/salle?e=comptoir-du-lac', '/caisse/entree', '/caisse/salle?e=comptoir-du-lac', '/caisse?e=comptoir-du-lac']) {
    const r = await p.goto(B + u); console.log(u, r.status(), (await p.textContent('body')).replace(/\s+/g, ' ').slice(0, 200));
  }
  await b.close();
})();
