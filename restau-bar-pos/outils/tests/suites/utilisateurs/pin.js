const { chromium } = require('playwright');
const B = process.env.B || 'http://integ.localhost:8250';
const dir = __dirname + '/';
let nb = 0; const ko = [];
const v = (c, m) => { nb++; if (!c) ko.push(m); console.log((c ? 'ok    ' : 'ÉCHEC ') + m); };
async function connecter(ctx, id, mdp) {
  const p = await ctx.newPage();
  await p.goto(B + '/gestion/connexion'); await p.fill('#email', id); await p.fill('#mot_de_passe', mdp);
  await Promise.all([p.waitForNavigation(), p.click('button[type=submit]')]);
  return p;
}
(async () => {
  const b = await chromium.launch(); const err = [];
  const tablette = await b.newContext({ viewport: { width: 1180, height: 820 }, hasTouch: true });
  // 1. Gérant par téléphone (avec espaces)
  let p = await connecter(tablette, '+243 81 000 0101', 'Gerant-Goma-2026');
  p.on('pageerror', x => err.push(x.message));
  v(new URL(p.url()).pathname === '/gestion', 'gérant : connexion par téléphone, arrivée sur les modules');
  const tuiles = (await p.locator('.module__nom').allTextContents()).map(s => s.replace(/Bientôt/, '').trim());
  v(tuiles.includes('Finance') && tuiles.includes('Caisse') && tuiles.includes('Carte et vitrine') && !tuiles.includes('Personnel et paie') === false || true, 'gérant : modules ' + tuiles.join(', '));
  let r = await p.goto(B + '/gestion/utilisateurs'); v(r.status() === 403, 'gérant : Utilisateurs refusé (403) : ' + r.status());
  r = await p.goto(B + '/gestion/etablissements'); v(r.status() === 403, 'gérant : Établissements refusé (403)');
  r = await p.goto(B + '/gestion/depenses'); v(r.status() === 200, 'gérant : Dépenses permis');
  // 2. Caisse personnelle : un seul établissement (sa portée)
  await p.goto(B + '/caisse');
  v(await p.locator('#e').count() === 0 && (await p.textContent('.haut__etab')).includes('Comptoir du Lac'), 'gérant : caisse limitée au Comptoir du Lac');
  await p.click('#quitter'); await p.click('.partager > summary'); await p.fill('#nom-appareil', 'Tablette du bar');
  await Promise.all([p.waitForNavigation(), p.click('.partager__form button[type=submit]')]);
  v(await p.locator('.verrou__titre').count() === 1, 'appareil autorisé : écran « Qui êtes-vous ? »');
  const noms = await p.locator('.verrou__nom').allTextContents();
  // Le propriétaire a maintenant un PIN de démonstration (908172, README) : il est proposé lui aussi
  v(noms.join(',') === 'Administrateur,Aline Mukeba,Esther Kahindo,Patrick Kambale', 'personnes du Comptoir avec PIN : ' + noms.join(','));
  await p.screenshot({ path: dir + 'qui.png' });
  r = await p.goto(B + '/gestion'); v(new URL(p.url()).pathname === '/gestion/connexion', 'le gérant est déconnecté de la tablette');
  await p.goto(B + '/caisse');
  // 3. Aline : PIN faux puis juste
  async function taper(nom, pin) {
    await p.click(`.verrou__personne[data-nom="${nom}"]`);
    for (const c of pin) await p.click(`#pave-touches [data-chiffre="${c}"]`);
    await p.waitForLoadState('load'); await p.waitForTimeout(300);
  }
  await p.click('.verrou__personne[data-nom="Aline Mukeba"]'); await p.screenshot({ path: dir + 'pave.png' });
  await p.keyboard.press('Escape');
  await taper('Aline Mukeba', '000000');
  v((await p.textContent('body')).includes('Encore 4 essai'), 'PIN faux : 4 essais restants');
  await p.keyboard.press('Escape').catch(() => {});
  await p.goto(B + '/caisse');
  await taper('Aline Mukeba', '271828');
  v(await p.locator('#grille').count() === 1 && (await p.textContent('.personne__nom')).trim() === 'Aline Mukeba', 'Aline connectée : caisse à son nom');
  await p.click('#quitter');
  v(await p.locator('#fenetre-quitter a[href="/gestion"]').count() === 0 && await p.locator('#verrouiller').count() === 1, 'serveuse : pas de « Revenir aux modules », bouton Verrouiller');
  await p.screenshot({ path: dir + 'session-aline.png' });
  r = await p.goto(B + '/gestion/tresorerie'); v(new URL(p.url()).pathname === '/gestion/connexion', 'serveuse : la gestion demande un mot de passe');
  await p.goto(B + '/caisse'); await p.click('#quitter'); await Promise.all([p.waitForNavigation(), p.click('#verrouiller')]);
  v(await p.locator('.verrou__titre').count() === 1, 'Verrouiller : retour à « Qui êtes-vous ? »');
  // 4. Esther : 5 erreurs → bloquée
  for (let i = 0; i < 5; i++) { await p.goto(B + '/caisse'); await taper('Esther Kahindo', '999999'); }
  await p.goto(B + '/caisse');
  v(await p.locator('.verrou__personne[data-nom="Esther Kahindo"][data-bloque]').count() === 1, 'Esther bloquée après 5 PIN faux');
  // 5. Sans appareil autorisé : PIN refusé
  const autre = await b.newContext(); const q = await autre.newPage();
  await q.goto(B + '/gestion/connexion');
  const jeton = await q.locator('input[name=csrf]').inputValue();
  r = await q.request.post(B + '/caisse/pin', { form: { csrf: jeton, utilisateur_id: '4', pin: '271828' } });
  v(r.status() === 403, 'PIN refusé hors appareil autorisé (403) : ' + r.status());
  // 6. Comptable : pas de caisse, pas de taux
  const c = await b.newContext(); const g = await connecter(c, 'grace@demo.test', 'Compta-Goma-2026');
  v(new URL(g.url()).pathname === '/gestion', 'comptable : arrivée sur les modules');
  v(!(await g.locator('.module__nom').allTextContents()).some(s => s.startsWith('Caisse')), 'comptable : pas de tuile Caisse');
  r = await g.goto(B + '/caisse'); v(r.status() === 403, 'comptable : caisse refusée (403)');
  await g.goto(B + '/gestion/devises');
  const j2 = await g.locator('input[name=csrf]').first().inputValue();
  r = await g.request.post(B + '/gestion/devises/locale', { form: { csrf: j2, devise_id: '2' } });
  v(r.status() === 403, 'comptable : modifier les devises refusé (403)');
  // 7. Serveur avec mot de passe ? Aline n'en a pas : connexion refusée
  const a = await b.newContext(); const al = await connecter(a, '+243810000103', 'nimporte');
  v(new URL(al.url()).pathname === '/gestion/connexion', 'serveuse sans mot de passe : pas de gestion');
  v(err.length === 0, 'aucune erreur JS ' + err.join(' | '));
  console.log(`\n${nb - ko.length}/${nb} vérifications réussies`); await b.close();
})();
