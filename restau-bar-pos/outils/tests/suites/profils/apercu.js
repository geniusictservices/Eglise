const { chromium } = require('playwright'); const fs = require('fs');
const dir = __dirname + '/'; const B = 'http://integ.localhost:8250';
const MDP = fs.readFileSync(dir + '../instance.txt', 'utf8').match(/Mot de passe\s*:\s*(\S+)/)[1];
async function connecter(ctx, id, mdp) { const p = await ctx.newPage(); await p.goto(B + '/gestion/connexion'); await p.fill('#email', id); await p.fill('#mot_de_passe', mdp); await Promise.all([p.waitForNavigation(), p.click('button[type=submit]')]); return p; }
(async () => {
  const b = await chromium.launch(); const err = [];
  const a = await connecter(await b.newContext(), 'admin@demo.test', MDP);
  await a.goto(B + '/gestion/utilisateurs/4');
  await a.setInputFiles('#photo input[type=file]', dir + 'visage.jpg');
  await Promise.all([a.waitForNavigation(), a.click('#photo [data-envoyer]')]);
  console.log('photo Aline :', (await a.textContent('main')).includes('Photo de Aline Mukeba enregistrée'));
  for (const [nom, o, slug] of [['tablette', { viewport: { width: 1280, height: 800 }, hasTouch: true }, 'volcano-club'], ['tel', { viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true }, 'comptoir-du-lac']]) {
    const ctx = await b.newContext(o);
    const p = await connecter(ctx, 'admin@demo.test', MDP); p.on('pageerror', x => err.push(x.message));
    await p.goto(B + '/caisse?e=' + slug); await p.click('#quitter'); await p.click('.partager > summary:has-text("caisse partagée")'); await p.fill('#nom-appareil', nom === 'tel' ? 'Téléphone du bar' : 'Tablette VIP');
    await Promise.all([p.waitForNavigation(), p.click('.partager__form button[type=submit]')]);
    await p.waitForTimeout(300); await p.screenshot({ path: dir + 'verrou-' + nom + '.png' });
    const premier = p.locator('.verrou__personne').first(); await premier.click(); await p.waitForTimeout(200);
    await p.screenshot({ path: dir + 'verrou-pin-' + nom + '.png' });
    console.log(nom, 'débord', await p.evaluate(() => document.documentElement.scrollWidth - innerWidth));
  }
  console.log(err); await b.close();
})();
