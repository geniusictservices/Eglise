// Outils communs des essais Salle et cuisine (instance « essaisalle », port 8210)
const { execSync } = require('child_process');
const B = 'http://integ.localhost:8250';
const MDP = 'Essai-Integ-2026';
const S = '/home/user/Eglise/restau-bar-pos/outils/tests/suites/agents/salle/';
const sql = q => execSync(`mysql -N --default-character-set=utf8mb4 rbp_integ -e ${JSON.stringify("SET time_zone = '+02:00'; " + q)}`).toString().trim();
const restaurer = () => execSync(S + 'restaurer.sh');
let nb = 0; const ko = [];
const v = (c, m) => { nb++; if (!c) ko.push(m); console.log((c ? 'ok    ' : 'ÉCHEC ') + m); };
const bilan = () => { console.log(`\n${nb - ko.length}/${nb}`); if (ko.length) { console.log('Échecs :\n- ' + ko.join('\n- ')); } };
async function connecter(ctx, id, mdp, erreurs) {
  const p = await ctx.newPage();
  if (erreurs) { suivre(p, erreurs); }
  await p.goto(B + '/gestion/connexion'); await p.fill('#email', id); await p.fill('#mot_de_passe', mdp);
  await Promise.all([p.waitForNavigation(), p.click('button[type=submit]')]);
  return p;
}
function suivre(p, erreurs) {
  p.on('pageerror', x => erreurs.push(x.message));
  p.on('console', m => m.type() === 'error' && !/Failed to load resource|status of 4\d\d/.test(m.text()) && erreurs.push(m.text()));
}
async function ajouter(p, noms) {
  for (const n of noms) {
    const [nom, taille] = n.split('|');
    await p.locator('#grille .tuile', { has: p.locator(`.tuile__nom:text-is("${nom}")`) }).click(); await p.waitForTimeout(120);
    if (await p.locator('#formats[open]').count()) { await p.locator('#formats-liste .format', { hasText: taille }).click(); await p.waitForTimeout(120); }
  }
}
// Caisse partagée : un gérant autorise l'appareil, puis la personne tape son PIN
async function partager(ctx, slug, nom, pin, erreurs) {
  const p = await connecter(ctx, 'admin@demo.test', MDP, erreurs);
  await p.goto(B + '/caisse?e=' + slug); await p.click('#quitter'); await p.click('.partager > summary:has-text("caisse partagée")');
  await Promise.all([p.waitForNavigation(), p.click('.partager__form button[type=submit]')]);
  await pinCaisse(p, nom, pin);
  return p;
}
async function pinCaisse(p, nom, pin) {
  await p.click(`.verrou__personne[data-nom="${nom}"]`);
  for (const c of pin) { await p.click(`#pave-touches [data-chiffre="${c}"]`); }
  await p.waitForLoadState('load'); await p.waitForSelector('#caisse'); await p.waitForTimeout(400);
}
const attendre = ms => new Promise(r => setTimeout(r, ms));
module.exports = { B, MDP, S, sql, restaurer, v, bilan, connecter, suivre, ajouter, partager, pinCaisse, attendre };
