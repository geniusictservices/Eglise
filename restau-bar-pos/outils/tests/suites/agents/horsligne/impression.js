const { chromium } = require('playwright');
const c = require('./commun');
(async () => {
  c.restaurer();
  const b = await chromium.launch();
  const ctx = await b.newContext({ viewport: { width: 1280, height: 800 } });
  const p = await c.connecter(ctx, 'admin@demo.test', c.MDP, []);
  await p.goto(c.B + '/caisse?e=comptoir-du-lac');
  await c.ajouter(p, ['Primus|Grande']);
  await c.payer(p);
  await p.evaluate(() => { window.print = () => {}; });
  await p.click('#paiement-imprimer');
  await p.emulateMedia({ media: 'print' });
  console.log('visible en impression', await p.locator('#zone-impression .recu').isVisible());
  await p.pdf({ path: c.S + 'x-impression.pdf', width: '80mm' });
  await b.close();
})();
