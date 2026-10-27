const { chromium } = require('playwright'); const B = 'http://integ.localhost:8250';
(async () => { const b = await chromium.launch(); const p = await (await b.newContext()).newPage();
  await p.goto(B + '/gestion/connexion'); await p.fill('#email', 'admin@demo.test'); await p.fill('#mot_de_passe', 'Essai-Integ-2026'); await Promise.all([p.waitForNavigation(), p.click('button[type=submit]')]);
  const id = process.argv[2]; const r = await p.goto(B + '/gestion/personnel/employes/' + id); console.log(r.status());
  console.log(await p.locator('form[action$="/pin"]').count(), await p.locator('form[action$="/pin"] button').evaluateAll(x => x.map(e => e.outerHTML)));
  await p.fill('#pin', '123456'); const [rep] = await Promise.all([p.waitForResponse(() => true, { timeout: 5000 }).catch(e => e.message), p.click('form[action$="/pin"] button')]); console.log(rep.status ? rep.status() + rep.url() : rep);
  await b.close(); })();
