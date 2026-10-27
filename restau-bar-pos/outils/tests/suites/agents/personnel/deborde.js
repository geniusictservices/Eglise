const { chromium } = require('playwright'); const B = 'http://integ.localhost:8250';
(async () => { const b = await chromium.launch(); const p = await (await b.newContext({ viewport: { width: 390, height: 844 } })).newPage();
  await p.goto(B + '/gestion/connexion'); await p.fill('#email', 'admin@demo.test'); await p.fill('#mot_de_passe', 'Essai-Integ-2026'); await Promise.all([p.waitForNavigation(), p.click('button[type=submit]')]);
  for (const u of process.argv.slice(2)) { await p.goto(B + u);
    console.log(u, await p.evaluate(() => document.documentElement.scrollWidth), await p.evaluate(() => [...document.querySelectorAll(".defile")].map(d => d.getBoundingClientRect().right + "/" + getComputedStyle(d).overflowX)), await p.evaluate(() => [...document.querySelectorAll('body *')].filter(e => e.getBoundingClientRect().right > window.innerWidth + 1 && ![...e.parentElement ? [e.parentElement] : []].some(x => x.getBoundingClientRect().right > window.innerWidth + 1)).slice(0, 8).map(e => e.tagName + '.' + e.className + ' ' + Math.round(e.getBoundingClientRect().right))));
  } await b.close(); })();
