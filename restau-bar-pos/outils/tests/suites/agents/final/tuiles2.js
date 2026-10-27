const { chromium } = require('playwright');
const fs = require('fs');
const B = 'http://integ.localhost:8250';
const MDP = fs.readFileSync(__dirname + '/../../instance.txt', 'utf8').match(/Mot de passe\s*:\s*(\S+)/)[1];
(async () => {
  const nav = await chromium.launch();
  const p = await (await nav.newContext({ viewport: { width: 1180, height: 820 } })).newPage();
  await p.goto(B + '/gestion/connexion');
  await p.fill('#email', 'admin@demo.test'); await p.fill('#mot_de_passe', MDP);
  await Promise.all([p.waitForNavigation(), p.click('button[type=submit]')]);
  await p.goto(B + '/caisse?e=volcano-club');
  await p.click('[data-taille=grande]'); await p.waitForTimeout(500);
  const mes = () => p.evaluate(() => { const t = document.querySelector('.tuile'); const g = t.parentElement; return [Math.round(t.getBoundingClientRect().height), t.scrollHeight, getComputedStyle(g).display, getComputedStyle(g).height, getComputedStyle(g).gridTemplateRows.slice(0, 60)]; });
  console.log('base', await mes());
  console.log(await p.evaluate(() => { const t = document.querySelector('.tuile'); const s = getComputedStyle(t); return ['height','maxHeight','minHeight','contain','boxSizing','display','alignSelf','gridRowEnd','overflow','containIntrinsicSize','contentVisibility'].map(k => k + '=' + s[k]).join(' ') + ' | style=' + t.getAttribute('style') + ' | children=' + [...t.children].map(c => c.className + ':' + Math.round(c.getBoundingClientRect().height)).join(','); }));
  for (const css of ['.grille{grid-auto-rows:max-content !important}', '.grille{grid-auto-rows:auto !important}', '.tuile{height:auto !important; min-height:0 !important}', '.tuile__photo{aspect-ratio:auto !important; height:82px}', '.grille{grid-auto-rows:minmax(var(--tuile-haut), max-content) !important}']) {
    const h = await p.addStyleTag({ content: css }); await p.waitForTimeout(200);
    console.log(css, await mes());
    await h.evaluate(n => n.remove());
  }
  await nav.close();
})();
