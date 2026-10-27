// Captures rapides de chaque écran : node apercu.js [largeur] [theme]
const { chromium } = require('playwright');
const fs = require('fs');
const { execSync } = require('child_process');
const un = q => execSync(`mysql -N -B rbp_integ -e ${JSON.stringify(q)}`, { encoding: 'utf8' }).trim();
const B = 'http://integ.localhost:8250';
const ADMIN = ['admin@demo.test', fs.readFileSync(__dirname + '/../../instance.txt', 'utf8').match(/Mot de passe\s*:\s*(\S+)/)[1]];
const largeur = +(process.argv[2] || 1366), theme = process.argv[3] || 'light';
(async () => {
  const nav = await chromium.launch();
  const ctx = await nav.newContext({ viewport: { width: largeur, height: largeur < 600 ? 844 : 860 }, colorScheme: theme });
  const page = await ctx.newPage();
  const erreurs = [];
  page.on('pageerror', e => erreurs.push(String(e)));
  await page.goto(B + '/gestion/connexion?lang=fr');
  await page.fill('#email', ADMIN[0]); await page.fill('#mot_de_passe', ADMIN[1]);
  await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
  const dette = (process.argv[4] || '');
  const pages = { clients: '/gestion/clients', client: '/gestion/clients/' + un("select id from clients where nom like 'Moïse%'"), dettes: '/gestion/dettes', dette: '/gestion/dettes/' + un("select id from dettes where statut='transferee' limit 1"), dette2: '/gestion/dettes/' + un("select id from dettes where statut='ouverte' and garant_statut='accepte' limit 1"), remb: '/gestion/remboursements?periode=tout', rembfiche: '/gestion/remboursements/' + un("select max(id) from remboursements"), rembnouveau: '/gestion/remboursements/nouveau?client=' + un("select client_id from dettes where statut='ouverte' group by client_id order by count(*) desc limit 1"), choisir: '/gestion/remboursements/nouveau?q=ka', garanties: '/gestion/garanties', messagerie: '/gestion/messagerie', journal: '/gestion/messagerie/journal?periode=tout', creances: '/gestion/rapports/creances', clientform: '/gestion/clients/' + un("select id from clients where nom like 'Kivu%'") + '/modifier', vente: '/gestion/ventes/' + un("select vente_id from dettes where vente_id is not null and statut='ouverte' limit 1"), etab: '/gestion/etablissements/1', accueil: '/gestion' };
  for (const [nom, url] of Object.entries(pages)) {
    await page.goto(B + url);
    const deb = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    if (deb > 0) console.log('DÉBORDEMENT', nom, deb);
    await page.screenshot({ path: `${__dirname}/apercu-${nom}-${largeur}-${theme}.png`, fullPage: true });
  }
  console.log('erreurs JS', erreurs);
  await nav.close();
})();
