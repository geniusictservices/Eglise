const { chromium } = require('playwright');
const fs = require('fs');
const B = 'http://integ.localhost:8250';
const MDP = fs.readFileSync(__dirname + '/../../instance.txt', 'utf8').match(/Mot de passe\s*:\s*(\S+)/)[1];
(async () => {
  const nav = await chromium.launch();
  const p = await (await nav.newContext({ viewport: { width: +process.argv[2] || 1180, height: 820 } })).newPage();
  await p.goto(B + '/gestion/connexion');
  await p.fill('#email', 'admin@demo.test'); await p.fill('#mot_de_passe', MDP);
  await Promise.all([p.waitForNavigation(), p.click('button[type=submit]')]);
  for (const taille of ['petite', 'moyenne', 'grande']) {
    await p.goto(B + '/caisse?e=' + (process.argv[3] || 'volcano-club'));
    await p.click(`[data-taille=${taille}]`); await p.waitForTimeout(600);
    const r = await p.evaluate(() => [...document.querySelectorAll('.tuile')].slice(0, 2).map(t => {
      const i = t.querySelector('img'), n = t.querySelector('.tuile__nom');
      return { tuile: Math.round(t.getBoundingClientRect().width) + 'x' + Math.round(t.getBoundingClientRect().height), img: i ? Math.round(i.getBoundingClientRect().width) + 'x' + Math.round(i.getBoundingClientRect().height) : '-', nom: Math.round(n.getBoundingClientRect().height), scroll: t.scrollHeight, overflow: getComputedStyle(t).overflow, grilleRows: getComputedStyle(t.parentElement).gridAutoRows };
    }));
    console.log(taille, JSON.stringify(r));
    if (process.argv[4]) await p.screenshot({ path: __dirname + `/tuiles-${taille}.png` });
  }
  await nav.close();
})();
