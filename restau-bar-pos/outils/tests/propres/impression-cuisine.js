// Impression d'un bon depuis l'écran de cuisine : la zone d'impression est seule visible, avec le bon (média print émulé)
const { chromium } = require('playwright'); const fs = require('fs');
const B = process.env.B || 'http://integ.localhost:8250';
const MDP = fs.readFileSync('/home/user/Eglise/restau-bar-pos/outils/tests/instance.txt', 'utf8').match(/Mot de passe\s*:\s*(\S+)/)[1];
let nb = 0; const ko = []; const v = (c, m) => { nb++; if (!c) ko.push(m); console.log((c ? 'ok    ' : 'ÉCHEC ') + m); };
(async () => {
  const b = await chromium.launch(); const err = [];
  for (const [nom, vp] of [['tablette', { width: 1180, height: 820 }], ['téléphone', { width: 390, height: 844 }]]) {
    const ctx = await b.newContext({ viewport: vp });
    const p = await ctx.newPage(); p.on('pageerror', x => err.push(x.message));
    await p.goto(B + '/gestion/connexion'); await p.fill('#email', 'admin@demo.test'); await p.fill('#mot_de_passe', MDP);
    await Promise.all([p.waitForNavigation(), p.click('button[type=submit]')]);
    await p.goto(B + '/caisse/cuisine?etab=comptoir-du-lac');
    await p.addInitScript(() => {});
    await p.evaluate(() => { window.__impressions = 0; window.print = () => { window.__impressions++; }; });
    await p.waitForSelector('.bon-carte__imprimer', { timeout: 15000 });
    const nbBons = await p.locator('.bon-carte__imprimer').count();
    v(nbBons > 0, `${nom} : ${nbBons} bon(s) à l'écran`);
    await p.locator('.bon-carte__imprimer').first().click();
    await p.waitForFunction(() => window.__impressions > 0, null, { timeout: 10000 });
    v(await p.evaluate(() => document.getElementById('zone-impression').parentNode === document.body), `${nom} : la zone d'impression est un enfant direct de <body>`);
    await p.emulateMedia({ media: 'print' });
    const etat = await p.evaluate(() => {
      const z = document.getElementById('zone-impression'); const bon = z.querySelector('.bon'); const r = bon ? bon.getBoundingClientRect() : null;
      const visibles = [...document.body.children].filter(e => getComputedStyle(e).display !== 'none' && e.tagName !== 'SCRIPT').map(e => e.id || e.className);
      return { bon: !!bon, largeur: r ? r.width : 0, hauteur: r ? r.height : 0, texte: bon ? bon.innerText.slice(0, 60) : '', visibles, zone: getComputedStyle(z).display };
    });
    v(etat.bon && etat.zone === 'block' && etat.largeur > 100 && etat.hauteur > 40, `${nom} : à l'impression, le bon est visible (${Math.round(etat.largeur)}×${Math.round(etat.hauteur)}) « ${etat.texte.replace(/\s+/g, ' ')} »`);
    v(etat.visibles.length === 1 && etat.visibles[0] === 'zone-impression', `${nom} : rien d'autre n'est imprimé (${etat.visibles.join(', ')})`);
    const pdf = await p.pdf({ width: '80mm', printBackground: false });
    v(pdf.length > 1000, `${nom} : PDF de la page (${pdf.length} octets)`);
    fs.writeFileSync(__dirname + `/bon-cuisine-${nom === 'tablette' ? 'tablette' : 'tel'}.pdf`, pdf);
    await p.emulateMedia({ media: 'screen' });
    await ctx.close();
  }
  v(err.length === 0, 'aucune erreur JS ' + err.join(' | '));
  console.log(`\n${nb - ko.length}/${nb}`); await b.close();
})();
