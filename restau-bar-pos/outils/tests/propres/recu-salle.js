// Caisse partagée du Volcano Club (module Salle, verrouillage après chaque encaissement) : le reçu reste affiché tant
// qu'on ne l'a pas fermé, même si l'actualisation de la salle (toutes les 8 s) tombe pendant ce temps ; « Imprimer le reçu »
// marche ; à la fermeture, l'écran de verrouillage revient.
const { chromium } = require('playwright'); const fs = require('fs'); const { execSync } = require('child_process');
const B = process.env.B || 'http://integ.localhost:8250'; const DB = process.env.DB || 'rbp_integ';
const MDP = fs.readFileSync('/home/user/Eglise/restau-bar-pos/outils/tests/instance.txt', 'utf8').match(/Mot de passe\s*:\s*(\S+)/)[1];
const sql = q => execSync(`mysql -N --default-character-set=utf8mb4 ${DB} -e ${JSON.stringify(q)}`).toString().trim();
let nb = 0; const ko = []; const v = (c, m) => { nb++; if (!c) ko.push(m); console.log((c ? 'ok    ' : 'ÉCHEC ') + m); };
(async () => {
  sql("UPDATE dettes SET garant_statut = 'refuse' WHERE garant_statut = 'en_attente'");
  v(sql("SELECT verrou_apres_encaissement FROM etablissements WHERE slug='volcano-club'") === '1', 'Volcano Club : verrouillage après chaque encaissement');
  const b = await chromium.launch(); const err = [];
  for (const [nom, vp] of [['tablette', { width: 1180, height: 820 }], ['téléphone', { width: 390, height: 844, isMobile: true, hasTouch: true }]]) {
    const ctx = await b.newContext({ viewport: { width: vp.width, height: vp.height }, isMobile: !!vp.isMobile, hasTouch: !!vp.hasTouch, serviceWorkers: process.env.ANCIEN ? 'block' : 'allow' });
    // ANCIEN=1 : le fichier d'avant la correction, pour montrer le défaut
    if (process.env.ANCIEN) { await ctx.route('**/assets/js/caisse-salle.js*', r => r.fulfill({ contentType: 'application/javascript', body: fs.readFileSync(__dirname + '/ancien-caisse-salle.js') })); }
    const p = await ctx.newPage(); p.on('pageerror', x => err.push(x.message));
    let navigations = 0; p.on('framenavigated', f => { if (f === p.mainFrame()) { navigations++; } });
    await p.goto(B + '/gestion/connexion'); await p.fill('#email', 'admin@demo.test'); await p.fill('#mot_de_passe', MDP);
    await Promise.all([p.waitForNavigation(), p.click('button[type=submit]')]);
    await p.goto(B + '/caisse?e=volcano-club'); await p.click('#quitter'); await p.click('.partager > summary');
    await Promise.all([p.waitForNavigation(), p.click('.partager__form button[type=submit]')]);
    await p.click('.verrou__personne[data-nom="Jean-Paul Bahati"]'); for (const c of '161803') { await p.click(`#pave-touches [data-chiffre="${c}"]`); }
    await p.waitForLoadState('load'); await p.waitForSelector('#caisse'); await p.waitForTimeout(500);
    v(await p.evaluate(() => !!document.getElementById('salle')), `${nom} : caisse du club avec le module Salle`);
    await p.locator('#grille .tuile:not([disabled])').first().click(); await p.waitForTimeout(200);
    if (await p.locator('#formats[open]').count()) { await p.locator('#formats-liste .format:not([disabled])').first().click(); }
    if (vp.isMobile) { await p.click('#resume'); await p.waitForTimeout(300); }
    await p.click('#encaisser'); await p.waitForSelector('#paiement[open]');
    await p.click('#paiement-valider'); await p.waitForSelector('#paiement-fin:not([hidden])', { timeout: 15000 });
    const avant = navigations;
    // Deux actualisations de la salle au moins (8 s chacune) pendant que le reçu est affiché
    const reponses = []; p.on('response', r => { if (r.url().includes('/caisse/salle')) { reponses.push(r.status()); } });
    await p.waitForTimeout(18000);
    v(navigations === avant && await p.locator('#paiement-fin').isVisible() && (await p.textContent('#paiement-recu')).trim().length > 20, `${nom} : 18 s plus tard, le reçu est toujours affiché (aucun rechargement ; réponses de /caisse/salle pendant ce temps : ${reponses.join(',') || 'aucune'})`);
    await p.evaluate(() => { window.__imprime = 0; window.print = () => { window.__imprime++; }; });
    await p.click('#paiement-imprimer');
    v(await p.evaluate(() => window.__imprime) === 1 && (await p.locator('#zone-impression .recu').count()) === 1, `${nom} : « Imprimer le reçu » imprime le reçu`);
    await Promise.all([p.waitForNavigation({ timeout: 15000 }).catch(() => null), p.click('#paiement-suivant')]);
    await p.waitForTimeout(1000);
    v(await p.locator('.verrou__personnes, .verrou__personne').count() > 0, `${nom} : reçu fermé, la caisse se verrouille (écran « Qui êtes-vous ? »)`);
    await ctx.close();
  }
  v(err.length === 0, 'aucune erreur JS ' + err.join(' | '));
  console.log(`\n${nb - ko.length}/${nb}`); await b.close();
})();
