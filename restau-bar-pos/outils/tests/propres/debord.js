// Éléments qui dépassent la largeur de l'écran : node debord.js <email> <mdp> <url> [largeur]
const { chromium } = require('playwright'); const fs = require('fs');
const B = process.env.B || 'http://integ2.localhost:8250';
const MDP = fs.readFileSync('/home/user/Eglise/restau-bar-pos/outils/tests/instance.txt', 'utf8').match(/Mot de passe\s*:\s*(\S+)/)[1];
const [email, mdp, url, l = '390'] = process.argv.slice(2);
(async () => {
  const b = await chromium.launch(); const ctx = await b.newContext({ viewport: { width: +l, height: 844 }, isMobile: true, hasTouch: true, deviceScaleFactor: 2 }); const p = await ctx.newPage();
  await p.goto(B + '/gestion/connexion'); await p.fill('#email', email); await p.fill('#mot_de_passe', mdp === 'ADMIN' ? MDP : mdp);
  await Promise.all([p.waitForNavigation(), p.click('button[type=submit]')]);
  for (const u of (process.env.AVANT || '').split(',').filter(Boolean)) { await p.goto(B + u); await p.waitForTimeout(300); }
  await p.goto(B + url); await p.waitForTimeout(+(process.env.ATTENTE || 600));
  const r = await p.evaluate(() => {
    const W = document.documentElement.clientWidth;
    const out = [];
    document.querySelectorAll('body *').forEach(el => { const r = el.getBoundingClientRect(); if (r.width && r.right > W + 1 && getComputedStyle(el).position !== 'fixed') { out.push(`${el.tagName.toLowerCase()}#${el.id}.${(el.className && typeof el.className === 'string' ? el.className : '').trim().replace(/\s+/g, '.')} right=${Math.round(r.right)} w=${Math.round(r.width)} « ${(el.textContent || '').trim().replace(/\s+/g, ' ').slice(0, 40)} »`); } });
    return { scroll: document.documentElement.scrollWidth - W, out: out.slice(0, 25), dialogs: [...document.querySelectorAll('dialog[open]')].map(d => d.id) };
  });
  console.log(JSON.stringify(r, null, 1));
  await p.screenshot({ path: __dirname + '/debord.png' });
  await b.close();
})();
