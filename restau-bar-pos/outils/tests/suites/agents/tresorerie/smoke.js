const { chromium } = require('playwright');
const fs = require('fs');
const B = 'http://integ.localhost:8250';
const MDP = fs.readFileSync(__dirname + '/../../instance.txt', 'utf8').match(/Mot de passe\s*:\s*(\S+)/)[1];
(async () => {
  const b = await chromium.launch();
  const p = await (await b.newContext({ viewport: { width: 1366, height: 860 } })).newPage();
  const err = [];
  p.on('pageerror', e => err.push(e.message));
  p.on('console', m => { if (m.type() === 'error') err.push(m.text()); });
  await p.goto(B + '/gestion/connexion?lang=fr');
  await p.fill('input[name=email]', 'admin@demo.test'); await p.fill('input[name=mot_de_passe]', MDP);
  await Promise.all([p.waitForNavigation(), p.click('.connexion button[type=submit]')]);
  for (const u of process.argv.slice(2)) {
    const r = await p.goto(B + u);
    const html = await p.content();
    const pb = html.match(/(Warning|Fatal error|Notice|Deprecated|Uncaught)[^<]{0,300}/g);
    console.log(r.status(), u, pb ? pb.slice(0, 3) : '', err.splice(0));
    if (process.env.TEXTE) console.log((await p.textContent('main')).replace(/\s+/g, ' ').slice(0, +process.env.TEXTE));
  }
  await b.close();
})();
