// Outils communs des captures du manuel (instance « manuel », port 8260, base rbp_manuel)
const { chromium } = require('playwright');
const { execSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const B = 'http://manuel.localhost:8260';
const SCR = __dirname;  // instance.txt : « Mot de passe : … » du propriétaire de la démo (rbp_manuel est une copie de rbp_demo)
const MDP = fs.readFileSync(SCR + '/instance.txt', 'utf8').match(/Mot de passe\s*:\s*(\S+)/)[1];
const OUT = process.env.SORTIE || '/home/user/Eglise/restau-bar-pos/docs/manuel/captures/';
const ORDI = { viewport: { width: 1366, height: 860 }, deviceScaleFactor: 1 };
const TEL = { viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true };
const TABLETTE = { viewport: { width: 1180, height: 820 }, deviceScaleFactor: 1, hasTouch: true };
const sql = q => execSync(`mysql -N --default-character-set=utf8mb4 rbp_manuel -e ${JSON.stringify("SET time_zone = '+02:00'; " + q)}`).toString().trim();
const erreurs = [], faites = [], manquees = [];
let nav;
async function lancer() { nav = await chromium.launch({ args: ['--lang=fr-FR'], env: { ...process.env, LANG: 'fr_FR.UTF-8', LANGUAGE: 'fr', LC_ALL: 'fr_FR.UTF-8' } }); return nav; }
async function fermer() { await nav.close(); }
async function contexte(opt, nuit) {
  const ctx = await nav.newContext({ ...opt, locale: 'fr-FR', colorScheme: nuit ? 'dark' : 'light', timezoneId: 'Africa/Lubumbashi',
    permissions: ['geolocation'], geolocation: { latitude: -1.6792, longitude: 29.2228 } });
  await ctx.addCookies([{ name: 'lang', value: 'fr', url: B }]);
  return ctx;
}
function suivre(p) {
  p.on('pageerror', x => erreurs.push(p.url() + ' : ' + x.message));
  p.on('console', m => m.type() === 'error' && !/Failed to load resource|status of 4\d\d/.test(m.text()) && erreurs.push(p.url() + ' : ' + m.text()));
}
async function page(ctx) { const p = await ctx.newPage(); suivre(p); return p; }
async function connecter(ctx, id = 'admin@demo.test', mdp = MDP) {
  const p = await page(ctx);
  await p.goto(B + '/gestion/connexion'); await p.fill('#email', id); await p.fill('#mot_de_passe', mdp);
  await Promise.all([p.waitForNavigation(), p.click('button[type=submit]')]);
  return p;
}
async function aller(p, url) { await p.goto(B + url, { waitUntil: 'networkidle' }); await pret(p); }
async function pret(p) { await p.evaluate(() => document.fonts.ready); await p.waitForTimeout(300); }
// Capture : page visible, page entière (pleine), un élément (sel), ou une zone (clip)
async function shot(p, nom, o = {}) {
  await pret(p).catch(() => {});
  const fichier = path.join(OUT, nom + '.jpg');
  const opt = { path: fichier, type: 'jpeg', quality: o.q || 72, animations: 'disabled', caret: 'hide' };
  try {
    if (o.sel) {
      const l = p.locator(o.sel).first();
      if (!(await l.count())) { manquees.push(nom + ' : ' + o.sel); return; }
      // Un bandeau collant (sommaire d'une fiche) ne doit pas recouvrir l'élément capturé
      if (p.url().includes('/gestion')) await p.evaluate(() => document.querySelectorAll('body *').forEach(e => { if (getComputedStyle(e).position === 'sticky' && !e.closest('dialog')) e.style.position = 'static'; }));
      await l.scrollIntoViewIfNeeded().catch(() => {});
      await l.screenshot(opt);
    } else {
      if (o.clip) opt.clip = o.clip;
      if (o.pleine) opt.fullPage = true;
      await p.screenshot(opt);
    }
    faites.push(nom);
  } catch (e) { manquees.push(nom + ' : ' + e.message.split('\n')[0]); }
}
async function ajouter(p, noms) {
  for (const n of noms) {
    const [nom, taille] = n.split('|');
    await p.locator('#grille .tuile', { has: p.locator(`.tuile__nom:text-is("${nom}")`) }).first().click(); await p.waitForTimeout(150);
    if (await p.locator('#formats[open]').count()) { await p.locator('#formats-liste .format', { hasText: taille || '' }).first().click(); await p.waitForTimeout(150); }
  }
}
async function pinCaisse(p, nom, pin) {
  await p.click(`.verrou__personne[data-nom="${nom}"]`);
  await p.waitForTimeout(200);
  for (const c of pin) { await p.click(`#pave-touches [data-chiffre="${c}"]`); }
  await p.waitForLoadState('load'); await p.waitForSelector('#caisse'); await p.waitForLoadState('networkidle').catch(() => {}); await p.waitForTimeout(2500);
}
function bilan() {
  console.log('captures : ' + faites.length);
  if (manquees.length) console.log('MANQUÉES :\n- ' + manquees.join('\n- '));
  if (erreurs.length) console.log('ERREURS JS :\n- ' + [...new Set(erreurs)].join('\n- '));
}
module.exports = { B, MDP, OUT, ORDI, TEL, TABLETTE, sql, lancer, fermer, contexte, page, connecter, aller, pret, shot, ajouter, pinCaisse, bilan, erreurs, faites, manquees };
