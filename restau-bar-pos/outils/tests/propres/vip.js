const { chromium } = require('playwright'); const fs = require('fs');
const B = process.env.B || 'http://integ.localhost:8250';
const MDP = fs.readFileSync('/home/user/Eglise/restau-bar-pos/outils/tests/instance.txt', 'utf8').match(/Mot de passe\s*:\s*(\S+)/)[1];
(async () => {
  const b = await chromium.launch(); const p = await (await b.newContext({ viewport: { width: 1366, height: 860 } })).newPage();
  await p.goto(B + '/gestion/connexion'); await p.fill('#email', 'admin@demo.test'); await p.fill('#mot_de_passe', MDP);
  await Promise.all([p.waitForNavigation(), p.click('button[type=submit]')]);
  for (const u of ['/caisse?e=volcano-club&zone=vip', '/caisse?e=volcano-club', '/caisse?e=comptoir-du-lac']) {
    await p.goto(B + u); await p.waitForSelector('.tuile');
    const ep = await p.$$eval('.tuile.tuile--epuise .tuile__nom', l => l.map(x => x.textContent.trim()));
    console.log(u, 'tuiles', await p.locator('.tuile').count(), 'épuisées :', ep.join(', '));
  }
  await b.close();
})();
