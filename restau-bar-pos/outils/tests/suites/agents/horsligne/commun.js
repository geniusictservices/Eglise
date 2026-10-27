// Outils communs des essais du mode hors ligne (instance « essaihl », port 8240, base rbp_integ)
const { execSync } = require('child_process');
const B = 'http://integ.localhost:8250';
const MDP = 'Essai-Integ-2026';
const S = '/home/user/Eglise/restau-bar-pos/outils/tests/suites/agents/horsligne/';
const sql = q => {
  // Même fuseau que l'instance (Africa/Lubumbashi) pour NOW()
  try { return execSync(`mysql -N --default-character-set=utf8mb4 rbp_integ -e ${JSON.stringify("SET time_zone = '+02:00'; " + q)}`, { stdio: ['ignore', 'pipe', 'pipe'] }).toString().trim(); }
  catch (e) { throw new Error('SQL : ' + String(e.stderr).split('\n').filter(Boolean).pop() + '\n' + q); }
};
const restaurer = () => execSync(S + 'restaurer.sh');
let nb = 0; const ko = [];
const v = (c, m) => { nb++; if (!c) ko.push(m); console.log((c ? 'ok    ' : 'ÉCHEC ') + m); };
const bilan = () => { console.log(`\n${nb - ko.length}/${nb}`); if (ko.length) { console.log('Échecs :\n- ' + ko.join('\n- ')); } };
function suivre(p, erreurs) {
  p.on('pageerror', x => erreurs.push(x.message));
  p.on('console', m => m.type() === 'error' && !/Failed to load resource|status of 4\d\d|net::ERR_INTERNET_DISCONNECTED|ERR_FAILED/.test(m.text()) && erreurs.push(m.text()));
}
async function connecter(ctx, id, mdp, erreurs) {
  const p = await ctx.newPage();
  if (erreurs) { suivre(p, erreurs); }
  await p.goto(B + '/gestion/connexion'); await p.fill('#email', id); await p.fill('#mot_de_passe', mdp);
  await Promise.all([p.waitForNavigation(), p.click('button[type=submit]')]);
  return p;
}
async function ajouter(p, noms) {
  for (const n of noms) {
    const [nom, taille] = n.split('|');
    await p.locator('#grille .tuile', { has: p.locator(`.tuile__nom:text-is("${nom}")`) }).click(); await p.waitForTimeout(120);
    if (await p.locator('#formats[open]').count()) { await p.locator('#formats-liste .format', { hasText: taille }).click(); await p.waitForTimeout(120); }
  }
}
async function partager(ctx, slug, nom, pin, erreurs) {
  const p = await connecter(ctx, 'admin@demo.test', MDP, erreurs);
  await p.goto(B + '/caisse?e=' + slug); await p.click('#quitter'); await p.click('.partager > summary:has-text("caisse partagée")');
  await Promise.all([p.waitForNavigation(), p.click('.partager__form button[type=submit]')]);
  if (nom) { await pinCaisse(p, nom, pin); }
  return p;
}
async function pinCaisse(p, nom, pin) {
  await p.click(`.verrou__personne[data-nom="${nom}"]`);
  for (const c of pin) { await p.click(`#pave-touches [data-chiffre="${c}"]`); }
  await p.waitForLoadState('load'); await p.waitForSelector('#caisse'); await p.waitForTimeout(400);
}
const donnees = p => p.evaluate(() => JSON.parse(document.getElementById('donnees-caisse').textContent));
const attendre = ms => new Promise(r => setTimeout(r, ms));
// Service worker actif et qui contrôle la page
async function swPret(p) {
  await p.evaluate(() => navigator.serviceWorker.ready);
  for (let i = 0; i < 40 && !(await p.evaluate(() => !!navigator.serviceWorker.controller)); i++) { await p.waitForTimeout(150); }
  return p.evaluate(() => !!navigator.serviceWorker.controller);
}
// Ventes en attente dans IndexedDB
const fileHl = p => p.evaluate(() => window.RbpHorsLigne.tous('file'));
// Payer le ticket : montant exact dans la devise choisie (ou « recu » donné), compte d'un type
async function payer(p, { devise, recu, compte } = {}) {
  await p.click('#encaisser'); await p.waitForSelector('#paiement[open]');
  if (devise) { await p.click(`#paiement-devises button[data-devises="${devise}"]`); }
  if (compte) { await p.click(`.paiement-ligne .puce:text-is("${compte}")`); }
  if (recu !== undefined) { await p.fill(`#recu-${devise || 'CDF'}`, String(recu)); await p.dispatchEvent(`#recu-${devise || 'CDF'}`, 'input'); }
  await p.click('#paiement-valider');
  await p.waitForSelector('#paiement-fin:not([hidden])', { timeout: 20000 });
}
module.exports = { B, MDP, S, sql, restaurer, v, bilan, suivre, connecter, ajouter, partager, pinCaisse, donnees, attendre, swPret, fileHl, payer };
