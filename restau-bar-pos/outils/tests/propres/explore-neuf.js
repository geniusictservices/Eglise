const { chromium } = require('playwright'); const fs = require('fs');
const B = 'http://essaineuf.localhost:8250';
const MDP = fs.readFileSync(__dirname + '/../paquet/creer-essaineuf.txt', 'utf8').match(/Mot de passe\s*:\s*(\S+)/)[1];
(async () => {
  const b = await chromium.launch(); const p = await (await b.newContext({ viewport: { width: 1366, height: 860 } })).newPage();
  p.on('pageerror', e => console.log('JS', e.message));
  await p.goto(B + '/gestion/connexion'); await p.fill('#email', 'patron@essaineuf.test'); await p.fill('#mot_de_passe', MDP);
  await Promise.all([p.waitForNavigation(), p.click('button[type=submit]')]);
  console.log('après connexion', p.url());
  console.log((await p.textContent('main')).replace(/\s+/g, ' ').slice(0, 800));
  for (const u of process.argv.slice(2)) {
    const r = await p.goto(B + u); console.log('\n=== ' + u + ' ' + r.status());
    console.log((await p.textContent('main')).replace(/\s+/g, ' ').slice(0, 1200));
    console.log(await p.$$eval('main form input, main form select, main form textarea', l => l.map(e => `${e.tagName.toLowerCase()}#${e.id}[name=${e.name}]${e.type ? ':' + e.type : ''}${e.tagName === 'SELECT' ? '{' + [...e.options].map(o => o.value + '=' + o.textContent.trim()).join('|') + '}' : ''}`).join('\n')));
  }
  await b.close();
})();
