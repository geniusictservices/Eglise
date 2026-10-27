// Tous les écrans : français et anglais, jour et nuit, 1366×860 et 390×844 ; pas de défilement horizontal,
// pas de clé non traduite, pas d'erreur JavaScript ; captures pour la documentation
const { chromium } = require('playwright');
const fs = require('fs');
const { v, q, A, D, connecterConsole, connecterInstance, bilan, surveiller } = require('./outils');
const C = 'rbp_console_essai';
(async () => {
  const b = await chromium.launch();
  const erreurs = [];
  const { neuf, bar, formule } = JSON.parse(fs.readFileSync(D + '/etat.json'));
  const pages = ['/', '/clients', '/clients/nouveau', `/clients/${neuf}`, `/clients/${bar}`, '/formules', `/formules/${formule}`, '/formules/nouvelle', '/messagerie', '/reglages', '/journal', '/journal?vue=messages', '/compte'];
  fs.mkdirSync(D + '/cap6', { recursive: true });
  for (const langue of ['fr', 'en']) for (const theme of ['light', 'dark']) for (const [nom, vp] of [['ordi', { width: 1366, height: 860 }], ['tel', { width: 390, height: 844 }]]) {
    const c = await b.newContext({ viewport: vp, colorScheme: theme, locale: langue === 'fr' ? 'fr-FR' : 'en-US' });
    await c.addCookies([{ name: 'lang', value: langue, url: A }]);
    const p = await c.newPage(); surveiller(p, erreurs);
    await p.goto(A + '/connexion');
    let ok = !(await p.evaluate(() => document.documentElement.scrollWidth > window.innerWidth));
    await connecterConsole(p);
    const problemes = [];
    for (const u of pages) {
      const r = await p.goto(A + u);
      if (r.status() !== 200) problemes.push(u + ' HTTP ' + r.status());
      if (await p.evaluate(() => document.documentElement.scrollWidth > window.innerWidth)) problemes.push(u + ' déborde');
      const texte = await p.evaluate(() => document.body.innerText);
      const cle = texte.replace(/\S+@\S+/g, '').match(/\b(console|plateforme|assistance|module|ecran)\.[a-z_]+(\.[a-z_]+)?\b/);
      if (cle && !/docs\/plateformes/.test(cle[0])) problemes.push(u + ' clé ' + cle[0]);
      if (langue === 'en' && /Enregistrer|Échéance|Clients par statut/.test(texte)) problemes.push(u + ' texte français en anglais');
      const theme_ = await p.evaluate(() => getComputedStyle(document.body).backgroundColor);
      if ((theme === 'dark') !== (theme_ === 'rgb(15, 17, 19)')) problemes.push(u + ' thème ' + theme_);
      if (nom === 'tel' && theme === 'light' && langue === 'fr' || nom === 'ordi' && langue === 'fr') {
        await p.screenshot({ path: `${D}/cap6/${(u.replace(/[^a-z0-9]+/gi, '-').replace(/^-|-$/g, '') || 'accueil')}-${nom}-${theme === 'dark' ? 'nuit' : 'jour'}.png`, fullPage: true });
      }
    }
    v(ok && problemes.length === 0 ? 'ok' : problemes.join(' ; '), 'ok', `console ${langue} ${theme} ${nom} : ${pages.length} écrans sans débordement, traduits, au bon thème`);
    await c.close();
  }
  // Instance : écran Assistance, bandeau et page de refus, en anglais et sur téléphone
  q(C, `UPDATE clients SET echeance=DATE_SUB(CURDATE(), INTERVAL 10 DAY) WHERE id=${bar}`);
  for (const langue of ['fr', 'en']) for (const [nom, vp] of [['ordi', { width: 1366, height: 860 }], ['tel', { width: 390, height: 844 }]]) {
    const c = await b.newContext({ viewport: vp, colorScheme: 'dark' });
    await c.addCookies([{ name: 'lang', value: langue, url: 'http://cnslbar.localhost:8180' }]);
    const p = await c.newPage(); surveiller(p, erreurs);
    await connecterInstance(p, 'cnslbar', 'patron@cnslbar.test', 'Patron-Bar-2026');
    await p.goto('http://cnslbar.localhost:8180/gestion?lang=' + langue);   // la connexion applique la langue du compte
    const problemes = [];
    for (const u of ['/gestion', '/gestion/assistance', '/caisse']) {
      await p.goto('http://cnslbar.localhost:8180' + u);
      if (await p.evaluate(() => document.documentElement.scrollWidth > window.innerWidth)) problemes.push(u + ' déborde');
      const texte = await p.evaluate(() => document.body.innerText);
      const cle = texte.match(/\b(plateforme|assistance|module)\.[a-z_]+/);
      if (cle) problemes.push(u + ' clé ' + cle[0]);
      if (langue === 'en' && !/Read-only|Support access|till cannot open/.test(texte)) problemes.push(u + ' pas en anglais');
      if (nom === 'tel') await p.screenshot({ path: `${D}/cap6/instance${u.replace(/\//g, '-')}-${langue}-tel-nuit.png`, fullPage: true });
    }
    v(problemes.join(' ; ') || 'ok', 'ok', `instance ${langue} nuit ${nom} : bandeau, assistance et caisse fermée sans débordement, traduits`);
    await c.close();
  }
  q(C, `UPDATE clients SET echeance=DATE_ADD(CURDATE(), INTERVAL 20 DAY) WHERE id=${bar}`);
  v(erreurs.length, 0, 'aucune erreur JavaScript' + (erreurs.length ? ' : ' + erreurs.slice(0, 3).join(' | ') : ''));
  await b.close();
  process.exit(bilan() ? 1 : 0);
})();
