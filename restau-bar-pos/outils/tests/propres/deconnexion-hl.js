// Déconnexion de la gestion et caisse hors ligne : sur un appareil personnel, la déconnexion efface les pages de la
// caisse gardées et les clés de ce navigateur (le compteur des reçus provisoires reste) ; s'il reste des ventes à
// envoyer, rien n'est effacé et un avertissement le dit ; sur une caisse partagée, rien n'est effacé.
const { chromium } = require('playwright');
const c = require('../suites/agents/horsligne/commun');
const { v, sql } = c;

// État de la caisse hors ligne de ce navigateur, lu directement (pages gardées, magasins IndexedDB)
const etatHl = p => p.evaluate(async () => {
  const pages = [];
  for (const n of await caches.keys()) { if (n.indexOf('rbp-caisse-pages-') === 0) { for (const k of await (await caches.open(n)).keys()) { pages.push(k.url.replace(location.origin, '')); } } }
  const bases = indexedDB.databases ? (await indexedDB.databases()).map(d => d.name) : ['rbp-caisse'];
  const r = { pages, fichiers: (await caches.keys()).filter(n => n.indexOf('rbp-caisse-fichiers-') === 0).length, base: bases.includes('rbp-caisse') };
  if (!r.base) { return r; }
  const db = await new Promise((ok, ko) => { const q = indexedDB.open('rbp-caisse'); q.onsuccess = () => ok(q.result); q.onerror = () => ko(q.error); });
  for (const s of ['file', 'etat', 'verificateurs', 'cles', 'attente']) {
    r[s] = db.objectStoreNames.contains(s) ? await new Promise(ok => { const q = db.transaction(s).objectStore(s).getAll(); q.onsuccess = () => ok(q.result); }) : [];
  }
  db.close();
  r.etat = r.etat.map(e => e.k).sort();
  r.file = r.file.map(i => i.statut);
  r.cles = r.cles.map(x => x.uid); r.verificateurs = r.verificateurs.map(x => x.uid);
  return r;
});
async function seDeconnecter(p) {
  await p.goto(c.B + '/gestion');
  await Promise.all([p.waitForNavigation(), p.click('form[action="/gestion/deconnexion"] button[type=submit]')]);
  await p.waitForTimeout(1500);
}
async function ouvrirCaisse(p) {
  await p.goto(c.B + '/caisse?e=comptoir-du-lac');
  v(await c.swPret(p), 'service worker actif sur la caisse');
  await p.reload(); await p.waitForSelector('#caisse'); await p.waitForTimeout(1500);
}

(async () => {
  c.restaurer();
  const b = await chromium.launch();
  const err = [];

  // ---------- 1. Téléphone personnel de Patrick : file vide → tout est oublié ----------
  const tel = await b.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });
  let p = await c.connecter(tel, 'patrick@demo.test', 'Gerant-Goma-2026', err);
  await ouvrirCaisse(p);
  let e = await etatHl(p);
  v(e.pages.length === 1 && e.cles.length === 1 && e.etat.length > 0, `avant : la caisse de Patrick est gardée sur le téléphone (pages ${e.pages.length}, clés ${e.cles}, états ${e.etat.join(',')})`);
  // Une vente sans réseau puis envoyée : le compteur des reçus provisoires existe
  await tel.setOffline(true); await p.reload(); await p.waitForSelector('#caisse'); await p.waitForTimeout(500);
  await c.ajouter(p, ['Kivu Sunset']); await p.click('#resume'); await p.waitForTimeout(300); await c.payer(p); await p.click('#paiement-suivant');
  const marque = await p.evaluate(() => window.RbpHorsLigne.etat('marque'));
  v((await c.fileHl(p)).length === 1, 'une vente faite sans réseau attend sur le téléphone');
  await tel.setOffline(false);
  await p.goto(c.B + '/caisse?e=comptoir-du-lac'); await p.waitForSelector('#caisse');
  for (let i = 0; i < 60 && (await c.fileHl(p)).length; i++) { await p.waitForTimeout(500); }
  v((await c.fileHl(p)).length === 0, 'au retour du réseau, la vente est partie (file vide)');
  await seDeconnecter(p);
  v(new URL(p.url()).pathname === '/gestion/connexion', 'déconnexion : page de connexion');
  v(await p.locator('#caisse-oubli').count() === 1 && await p.locator('#caisse-oubli').isHidden(), 'file vide : aucun avertissement');
  e = await etatHl(p);
  v(e.pages.length === 0, 'les pages de la caisse gardées sur le téléphone sont effacées (' + e.pages.join(', ') + ')');
  v(e.cles.length === 0 && e.verificateurs.length === 0 && e.attente.length === 0, 'clés de signature et vérificateurs effacés');
  v(e.etat.join(',') === 'marque,sequence', 'petits états effacés, sauf le compteur et la marque des reçus provisoires (' + e.etat.join(',') + ')');
  v(e.fichiers === 1, 'les fichiers de la caisse (CSS, JS) restent en cache : rien de personnel');
  // La caisse revient normalement à la connexion suivante, et le numéro provisoire continue
  p = await c.connecter(tel, 'patrick@demo.test', 'Gerant-Goma-2026', err);
  await ouvrirCaisse(p);
  e = await etatHl(p);
  v(e.pages.length === 1 && e.cles.length === 1, 'reconnexion : la caisse est de nouveau gardée, avec une nouvelle clé');
  await tel.setOffline(true); await p.reload(); await p.waitForSelector('#caisse'); await p.waitForTimeout(500);
  await c.ajouter(p, ['Kivu Sunset']); await p.click('#resume'); await p.waitForTimeout(300); await c.payer(p);
  const recu = await p.textContent('#paiement-recu');
  v(recu.includes('HL-P2-' + marque + '-0002'), 'numéro provisoire suivant (HL-P2-' + marque + '-0002), jamais deux fois le même');
  await p.click('#paiement-suivant');

  // ---------- 2. Vente encore en attente : rien n'est effacé, avertissement ----------
  await p.goto('about:blank');
  await tel.setOffline(false);
  await seDeconnecter(p);
  const avis = p.locator('#caisse-oubli');
  v(await avis.isVisible() && /Une vente faite sans réseau attend encore/.test(await avis.textContent()), 'vente en attente : avertissement « Une vente faite sans réseau attend encore… »');
  e = await etatHl(p);
  v(e.file.length === 1 && e.pages.length === 1 && e.cles.length === 1, 'vente en attente : la file, la page de la caisse et la clé restent (' + e.file + ')');
  await p.screenshot({ path: __dirname + '/deconnexion-attente-tel.png' });
  // Reconnexion, caisse avec le réseau : la vente part ; puis la déconnexion efface
  p = await c.connecter(tel, 'patrick@demo.test', 'Gerant-Goma-2026', err);
  await p.goto(c.B + '/caisse?e=comptoir-du-lac'); await p.waitForSelector('#caisse');
  for (let i = 0; i < 60 && (await c.fileHl(p)).length; i++) { await p.waitForTimeout(500); }
  v((await c.fileHl(p)).length === 0 && sql("SELECT COUNT(*) FROM ventes WHERE numero_provisoire LIKE 'HL-P2-%'") === '2', 'la vente en attente est partie à la reconnexion (2 ventes HL-P2 au serveur)');
  await seDeconnecter(p);
  e = await etatHl(p);
  v(e.pages.length === 0 && e.cles.length === 0 && await p.locator('#caisse-oubli').isHidden(), 'puis la déconnexion efface la caisse de l\'appareil');
  // En anglais
  await tel.addCookies([{ name: 'lang', value: 'en', url: c.B }]);
  await tel.close();

  // ---------- 3. Caisse partagée : la déconnexion de la gestion n'efface rien ----------
  const tab = await b.newContext({ viewport: { width: 1180, height: 820 }, hasTouch: true });
  const t = await c.partager(tab, 'comptoir-du-lac', 'Aline Mukeba', '271828', err);
  v(await c.swPret(t), 'caisse partagée : service worker actif');
  await t.reload(); await t.waitForSelector('#caisse'); await t.waitForTimeout(2500);
  const avant = await etatHl(t);
  v(avant.verificateurs.includes(4), 'caisse partagée : vérificateur du PIN d\'Aline gardé (' + avant.verificateurs + ')');
  const g = await c.connecter(tab, 'admin@demo.test', c.MDP, err);
  await seDeconnecter(g);
  const apres = await etatHl(g);
  v(await g.locator('#caisse-oubli').count() === 0, 'caisse partagée : pas de script d\'effacement après la déconnexion');
  v(JSON.stringify(apres.verificateurs) === JSON.stringify(avant.verificateurs) && apres.pages.length === avant.pages.length, 'caisse partagée : vérificateurs et pages gardés intacts');
  await tab.close();

  v(err.length === 0, 'aucune erreur JS ' + err.join(' | '));
  c.bilan(); await b.close();
})();
