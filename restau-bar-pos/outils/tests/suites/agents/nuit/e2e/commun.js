// Outils communs des essais de bout en bout du module Nuit (instance « essainuit », port 8230)
const { execSync } = require('child_process');
const crypto = require('crypto');
const B = 'http://integ.localhost:8250';
const MDP = 'Essai-Integ-2026';
const S = '/home/user/Eglise/restau-bar-pos/outils/tests/suites/agents/nuit/';
const CAP = S + 'e2e/captures/';
const sql = q => {
  try { return execSync(`mysql -N --default-character-set=utf8mb4 rbp_integ -e ${JSON.stringify("SET time_zone = '+02:00'; " + q)}`, { stdio: ['ignore', 'pipe', 'pipe'] }).toString().trim(); }
  catch (e) { throw new Error('SQL : ' + String(e.stderr).split('\n').filter(Boolean).pop() + '\n' + q); }
};
const restaurer = () => execSync(S + 'e2e/restaurer.sh');
let nb = 0; const ko = [];
const v = (c, m) => { nb++; if (!c) ko.push(m); console.log((c ? 'ok    ' : 'ÉCHEC ') + m); };
const bilan = () => { console.log(`\n${nb - ko.length}/${nb}`); if (ko.length) { console.log('Échecs :\n- ' + ko.join('\n- ')); } };
const proche = (a, b, eps = 0.0011) => Math.abs(Number(a) - Number(b)) < eps;
const cle = () => crypto.randomUUID();
function suivre(p, erreurs) {
  p.on('pageerror', x => erreurs.push(x.message));
  p.on('console', m => m.type() === 'error' && !/Failed to load resource|status of 4\d\d/.test(m.text()) && erreurs.push(m.text()));
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
// Caisse partagée : un gérant (l'administrateur) autorise l'appareil, puis la personne tape son PIN
async function partager(ctx, slug, nom, pin, erreurs) {
  const p = await connecter(ctx, 'admin@demo.test', MDP, erreurs);
  await p.goto(B + '/caisse?e=' + slug); await p.click('#quitter'); await p.click('.partager > summary');
  await Promise.all([p.waitForNavigation(), p.click('.partager__form button[type=submit]')]);
  await pinCaisse(p, nom, pin);
  return p;
}
async function pinCaisse(p, nom, pin) {
  await p.click(`.verrou__personne[data-nom="${nom}"]`);
  for (const c of pin) { await p.click(`#pave-touches [data-chiffre="${c}"]`); }
  await p.waitForLoadState('load'); await p.waitForSelector('#caisse'); await p.waitForTimeout(400);
}
const donnees = p => p.evaluate(() => JSON.parse(document.getElementById('donnees-caisse').textContent));
const jeton = async p => (await donnees(p)).csrf;
// Encaisser par l'API (comme la caisse) : { statut, j }
async function encaisser(p, vente, csrf) {
  const D = await donnees(p);
  const r = await p.request.post(B + '/caisse/encaisser', { form: { csrf: csrf === undefined ? D.csrf : csrf, vente: JSON.stringify({ cle: cle(), etab: D.etab, ...vente }) } });
  let j = {}; try { j = await r.json(); } catch (e) { j = { brut: (await r.text()).slice(0, 300) }; }
  return { statut: r.status(), j };
}
async function poster(p, url, champs, csrf) {
  const D = await donnees(p);
  const r = await p.request.post(B + url, { form: { csrf: csrf === undefined ? D.csrf : csrf, etab: D.etab, ...champs } });
  let j = {}; try { j = await r.json(); } catch (e) { j = { brut: (await r.text()).slice(0, 300) }; }
  return { statut: r.status(), j };
}
async function lire(p, url) {
  const D = await donnees(p);
  const r = await p.request.get(B + url + (url.includes('?') ? '&' : '?') + 'etab=' + D.etab);
  let j = {}; try { j = await r.json(); } catch (e) { j = {}; }
  return { statut: r.status(), j };
}
// Format et prix (par devise) d'un produit de la caisse
async function format(p, nom, taille) {
  const D = await donnees(p);
  const prod = D.produits.find(x => x.nom === nom);
  if (!prod) { throw new Error('produit absent : ' + nom); }
  const f = taille ? prod.formats.find(x => (x.taille + ' ' + x.nom).includes(taille)) : prod.formats[0];
  return { id: f.id, prix: f.prix, produit: prod };
}
// Compte de caisse qui tient une devise dans l'établissement
const caisseDe = (etab, code) => +sql(`SELECT c.id FROM comptes_tresorerie c JOIN compte_devises cd ON cd.compte_id=c.id JOIN devises d ON d.id=cd.devise_id WHERE c.type='caisse' AND (c.etablissement_id=${etab} OR c.etablissement_id IS NULL) AND d.code='${code}' AND c.active=1 ORDER BY c.etablissement_id IS NULL, c.id LIMIT 1`);
// Plus de défilement horizontal, cibles d'au moins 44 px (zone visible, éléments interactifs)
async function controlerPage(p, nom) {
  return p.evaluate(() => {
    const largeur = document.documentElement.scrollWidth - document.documentElement.clientWidth;
    const petits = [];
    document.querySelectorAll('button, a.bouton, .puce, input:not([type=hidden]):not([type=checkbox]):not([type=radio]), select, summary, [role=button]').forEach(e => {
      const r = e.getBoundingClientRect(); const s = getComputedStyle(e);
      if (r.width === 0 || r.height === 0 || s.visibility === 'hidden' || e.closest('[hidden]') || e.closest('dialog:not([open])')) { return; }
      if (e.matches('.lien, a:not(.bouton)')) { return; }
      if (r.height < 43.5 || r.width < 43.5) { petits.push((e.id || e.className || e.tagName) + ' ' + Math.round(r.width) + '×' + Math.round(r.height)); }
    });
    return { largeur, petits };
  });
}
const attendre = ms => new Promise(r => setTimeout(r, ms));
module.exports = { B, MDP, S, CAP, sql, restaurer, v, bilan, proche, cle, suivre, connecter, ajouter, partager, pinCaisse, donnees, jeton, encaisser, poster, lire, format, caisseDe, controlerPage, attendre };
