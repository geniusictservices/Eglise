// Mode hors ligne, caisse partagée : PIN vérifié sur l'appareil, verrouillage après encaissement, changement de
// personne, limite d'essais, retour du réseau (le serveur est verrouillé avant de montrer une caisse), session
// expirée, stock bloqué, session de caisse, pointage, téléphone
const { chromium } = require('playwright');
const c = require('./commun');
const { v, sql } = c;
const CAP = c.S;

async function attendreFileVide(p, ms = 40000) {
  const fin = Date.now() + ms;
  while (Date.now() < fin) {
    const f = await c.fileHl(p).catch(() => null);
    if (f && f.length === 0) { return true; }
    await p.waitForTimeout(500);
  }
  return false;
}
async function taperPin(p, nom, pin) {
  await p.click(`.verrou__personne[data-nom="${nom}"]`);
  for (const ch of pin) { await p.click(`#pave-touches [data-chiffre="${ch}"]`); }
  await p.waitForLoadState('load'); await p.waitForTimeout(700);
}
async function verrouiller(p) {
  await p.click('#quitter'); await Promise.all([p.waitForNavigation(), p.click('#verrouiller')]);
  await p.waitForSelector('#qui'); await p.waitForTimeout(400);
}
const depuisCache = p => p.evaluate(() => !!document.querySelector('meta[name="rbp-hors-ligne"]'));

(async () => {
  c.restaurer();
  // Esther : pointage obligatoire au Comptoir du Lac, sans pointage ; sessions de caisse demandées
  sql("UPDATE etablissements SET pointage_obligatoire = 1, sessions_caisse = 1 WHERE id = 1");
  sql("DELETE FROM pointages WHERE employe_id IN (1, 4) AND depart IS NULL");
  // Patrick (employé 1) est pointé ; Esther (employée 4) ne l'est pas
  sql("INSERT INTO pointages (employe_id, etablissement_id, journee, arrivee, arrivee_source) VALUES (1, 1, CURDATE(), NOW() - INTERVAL 1 HOUR, 'gestion')");
  const b = await chromium.launch();
  const err = [];
  const ctx = await b.newContext({ viewport: { width: 1280, height: 800 } });
  const p = await c.partager(ctx, 'comptoir-du-lac', 'Patrick Kambale', '482913', err);
  v(await c.swPret(p), 'caisse partagée : service worker actif');
  await p.waitForTimeout(2500);
  // Patrick ouvre sa session de caisse (fond 0)
  const D = await c.donnees(p);
  const o = await p.request.post(c.B + '/caisse/session/ouvrir', { form: { csrf: D.csrf, etab: D.etab, fonds: JSON.stringify({}) } });
  v(o.status() === 200, 'Patrick ouvre sa session de caisse');
  const sessionPatrick = sql("SELECT id FROM sessions_caisse WHERE utilisateur_id = 2 AND statut = 'ouverte'");
  await p.reload(); await p.waitForSelector('#caisse'); await p.waitForTimeout(1500);
  let verifs = await p.evaluate(() => window.RbpHorsLigne.tous('verificateurs').then(l => l.map(x => x.uid)));
  v(verifs.length === 1 && verifs[0] === 2, 'PIN juste en ligne : vérificateur hors ligne de Patrick sur l\'appareil (jamais le PIN)');
  const verif = await p.evaluate(() => window.RbpHorsLigne.lire('verificateurs', 2).then(x => ({ cles: Object.keys(x).sort().join(','), sel: x.sel.length, iv: x.iv.length })));
  v(verif.cles === 'chiffre,cleId,confirme,iv,sel,uid,version' && verif.sel === 16 && verif.iv === 12, 'vérificateur : sel, iv, clé hors ligne chiffrée (AES-GCM via PBKDF2), version du PIN');
  await verrouiller(p);
  await c.pinCaisse(p, 'Esther Kahindo', '314159');
  await p.waitForTimeout(2000);
  verifs = await p.evaluate(() => window.RbpHorsLigne.tous('verificateurs').then(l => l.map(x => x.uid).sort()));
  v(verifs.join(',') === '2,5', 'Esther entrée en ligne : son vérificateur aussi');
  v(sql('SELECT COUNT(*) FROM caisse_cles_hors_ligne WHERE appareil_id IS NOT NULL AND revoquee_le IS NULL') === '2', 'serveur : une clé hors ligne par personne sur cet appareil');
  await verrouiller(p);

  // ---------- Coupure : écran de verrouillage depuis l'appareil ----------
  await ctx.setOffline(true);
  await p.reload(); await p.waitForSelector('#qui'); await p.waitForTimeout(900);
  v(await depuisCache(p), 'sans réseau : l\'écran de verrouillage vient de l\'appareil');
  v((await p.textContent('#hl-etat-texte')).trim() === 'Hors ligne', 'écran de verrouillage : indicateur « Hors ligne »');
  const indispo = await p.evaluate(() => [...document.querySelectorAll('.verrou__personne')].filter(a => a.dataset.hlIndisponible).map(a => a.dataset.nom));
  v(indispo.includes('Aline Mukeba') && !indispo.includes('Esther Kahindo') && !indispo.includes('Patrick Kambale'), 'sans réseau : seules les personnes déjà entrées ici peuvent ouvrir (' + indispo.join(', ') + ' : réseau nécessaire)');
  await p.click('.verrou__personne[data-nom="Aline Mukeba"]');
  v(await p.isHidden('#pave') && (await p.textContent('#hl-pin-message')).includes('seules les personnes'), 'Aline (jamais entrée ici) : explication, pas de pavé');
  await p.screenshot({ path: CAP + 'ordi-verrou-hors-ligne.png' });
  // Esther entre sans réseau
  await taperPin(p, 'Esther Kahindo', '314159');
  await p.waitForSelector('#caisse');
  v((await c.donnees(p)).moi === 5 && await depuisCache(p), 'PIN d\'Esther vérifié sur l\'appareil : sa caisse (gardée)');
  v(await p.isVisible('#encaisser') && (await p.textContent('#hl-note')).includes('session'), 'sans session ouverte, la vente reste possible hors ligne (expliqué)');
  v(await p.locator('#ouvrir-session').isDisabled(), '« Ouvrir ma caisse » grisé sans réseau');
  await c.ajouter(p, ['Primus|Grande', 'Kivu Sunset']);
  await c.payer(p);
  v((await p.textContent('#paiement-suivant')).trim() === 'Terminer' && (await p.textContent('#paiement-recu')).includes('HL-'), 'reçu provisoire, puis « Terminer » (verrouillage après encaissement)');
  await Promise.all([p.waitForNavigation(), p.click('#paiement-suivant')]);
  await p.waitForSelector('#qui');
  v(await depuisCache(p), 'verrouillée sans réseau : écran de verrouillage');
  // Patrick : un PIN faux, puis le bon
  await taperPin(p, 'Patrick Kambale', '111111');
  v((await p.textContent('#pave-aide')).includes('Encore 4'), 'PIN faux sans réseau : « encore 4 essais »');
  await taperPin(p, 'Patrick Kambale', '482913');
  await p.waitForSelector('#caisse');
  v((await c.donnees(p)).moi === 2, 'Patrick entre avec le bon PIN');
  await c.ajouter(p, ['Primus|Grande', 'Primus|Grande']);
  await c.payer(p, { devise: 'USD' });
  await Promise.all([p.waitForNavigation(), p.click('#paiement-suivant')]);
  await p.waitForSelector('#qui');
  v((await p.textContent('#hl-etat-nombre')).trim() === '2', 'écran de verrouillage : 2 ventes en attente');
  // Esther : 5 PIN faux → plus de PIN sans réseau pour elle
  for (let i = 0; i < 5; i++) { await taperPin(p, 'Esther Kahindo', '000111'); }
  const msg = await p.textContent('#hl-pin-message');
  v(msg.includes('Trop d\'essais') && msg.includes('Esther'), '5 PIN faux : « trop d\'essais », il faudra le réseau');
  v(!(await p.evaluate(() => window.RbpHorsLigne.lire('verificateurs', 5))), 'son vérificateur est effacé de l\'appareil');
  await p.click('.verrou__personne[data-nom="Esther Kahindo"]');
  v(await p.isHidden('#pave') && (await p.textContent('#hl-pin-message')).includes('seules les personnes'), 'ensuite, même avec le bon PIN, Esther ne peut plus entrer sans réseau');
  await p.screenshot({ path: CAP + 'ordi-verrou-trop-essais.png' });
  // Stock à zéro pendant la coupure (Primus Grande au Comptoir, mode « bloquer »)
  sql("INSERT INTO stock_parametres (etablissement_id, mode_negatif) VALUES (1, 'bloquer') ON DUPLICATE KEY UPDATE mode_negatif = 'bloquer'");

  // ---------- Retour du réseau ----------
  await ctx.setOffline(false);
  v(await attendreFileVide(p), 'retour du réseau : les 2 ventes partent depuis l\'écran de verrouillage');
  await p.waitForTimeout(3000);
  await p.waitForSelector('#qui');
  v(!(await depuisCache(p)), 'l\'écran de verrouillage se recharge depuis le serveur');
  const ventes = sql("SELECT utilisateur_id, appareil_id IS NOT NULL, COALESCE(session_id, 0), COALESCE(notes_hors_ligne, '') FROM ventes WHERE hors_ligne = 1 ORDER BY id").split('\n').map(l => l.split('\t'));
  v(ventes.length === 2 && ventes[0][0] === '5' && ventes[1][0] === '2' && ventes.every(x => x[1] === '1'), 'une vente par ticket, au nom d\'Esther puis de Patrick (signatures vérifiées), sur cet appareil');
  v(ventes[0][2] === '0' && ventes[0][3].split(',').includes('session') && ventes[0][3].split(',').includes('pointage'), 'Esther : sans session ni pointage, la vente est gardée et notée (' + ventes[0][3] + ')');
  v(ventes[1][2] === sessionPatrick, 'Patrick : vente rattachée à sa session ouverte à cette heure-là');
  v(sql("SELECT pin_bloque_le IS NOT NULL FROM utilisateurs WHERE id = 5") === '1', 'serveur : les 5 PIN faux sans réseau bloquent le PIN d\'Esther (comme en ligne)');
  v(+sql("SELECT COUNT(*) FROM journal WHERE action = 'pin_echec_hors_ligne'") >= 6 && sql("SELECT COUNT(*) FROM journal WHERE action = 'pin_connexion_hors_ligne'") === '2', 'journal : PIN faux et connexions sans réseau');
  sql('UPDATE utilisateurs SET pin_bloque_le = NULL, pin_essais = 0 WHERE id = 5');

  // ---------- Le serveur est verrouillé avant de montrer une caisse ----------
  await c.pinCaisse(p, 'Patrick Kambale', '482913');
  await p.waitForTimeout(1200);
  await ctx.setOffline(true);
  await c.ajouter(p, ['Kivu Sunset']);
  await c.payer(p);
  await Promise.all([p.waitForNavigation(), p.click('#paiement-suivant')]);
  await p.waitForSelector('#qui');
  // Le serveur garde encore Patrick ; le réseau revient et quelqu'un recharge tout de suite
  await ctx.setOffline(false);
  await p.reload(); await p.waitForLoadState('load'); await p.waitForTimeout(800);
  v(await p.locator('#qui').count() === 1 && await p.locator('#caisse').count() === 0, 'réseau revenu : jamais la caisse de Patrick sans son PIN (le serveur est verrouillé d\'abord)');
  v(await attendreFileVide(p), 'la vente de Patrick part');
  const r = await p.request.get(c.B + '/caisse');
  v((r.headers()['x-caisse-page'] || '') === 'verrou', 'le serveur est bien verrouillé');

  // ---------- Stock bloqué : appliqué en ligne, jamais à une vente déjà faite ----------
  await c.pinCaisse(p, 'Patrick Kambale', '482913');
  await p.waitForTimeout(1500);
  await ctx.setOffline(true);
  await c.ajouter(p, ['Primus|Grande']);
  await c.payer(p);
  await Promise.all([p.waitForNavigation(), p.click('#paiement-suivant')]);
  // Le comptoir n'a plus de Primus Grande
  sql("UPDATE stock_niveaux SET quantite = 0 WHERE produit_id = 20");
  await ctx.setOffline(false);
  v(await attendreFileVide(p), 'vente faite avant la rupture : envoyée');
  const derniere = sql("SELECT id, COALESCE(notes_hors_ligne, '') FROM ventes WHERE hors_ligne = 1 ORDER BY id DESC LIMIT 1").split('\t');
  v(derniere[1].split(',').includes('stock'), 'stock à zéro en mode « bloquer » : la vente est gardée, notée « stock »');
  v(sql(`SELECT details FROM journal WHERE action = 'vente_hors_ligne' AND details LIKE '%"vente":${derniere[0]},%'`).includes('stock'), 'journal : ce qui aurait été refusé en ligne (stock)');
  v(+sql(`SELECT COALESCE(SUM(quantite), 0) FROM stock_mouvements WHERE source_id = ${derniere[0]} AND type = 'vente'`) < 0, 'le stock descend quand même (sortie de la vente)');
  // En ligne, le même article est refusé (mode « bloquer »)
  await c.pinCaisse(p, 'Patrick Kambale', '482913');
  const Dp = await c.donnees(p);
  const prim = Dp.produits.find(x => x.nom === 'Primus').formats.find(f => f.taille.indexOf('Grande') === 0);
  v(prim.epuise === true, 'en ligne : Primus Grande épuisée à la caisse');

  // ---------- Session expirée (419) sur la caisse partagée ----------
  await ctx.setOffline(true);
  await c.ajouter(p, ['Kivu Sunset']);
  await c.payer(p);
  await Promise.all([p.waitForNavigation(), p.click('#paiement-suivant')]);
  const garde = (await ctx.cookies()).filter(k => k.name !== 'rbp_session');
  await ctx.clearCookies(); await ctx.addCookies(garde);
  await ctx.setOffline(false);
  await p.waitForTimeout(9000);
  await p.waitForSelector('#qui');
  v(await p.isVisible('#hl-bandeau') && (await p.textContent('#hl-bandeau')).includes('entrez votre PIN'), 'session expirée (419) : la file reste, la caisse demande le PIN');
  v((await c.fileHl(p)).length === 1, 'la vente attend toujours sur l\'appareil');
  await p.screenshot({ path: CAP + 'ordi-verrou-session-expiree.png' });
  await Promise.all([p.waitForNavigation(), p.click('#hl-bandeau-action')]);
  await p.waitForSelector('#qui');
  // Écran de verrouillage du serveur (session neuve) : il envoie la vente signée, sans attendre le PIN
  v(await attendreFileVide(p, 20000), 'puis elle part depuis l\'écran de verrouillage (appareil autorisé + signature)');
  v(sql('SELECT COUNT(*) FROM ventes WHERE hors_ligne = 1') === '5', '5 ventes hors ligne, chacune une fois');

  // ---------- Téléphone ----------
  const ctxT = await b.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });
  await ctxT.addCookies((await ctx.cookies()).filter(k => k.name === 'rbp_caisse'));
  const t = await ctxT.newPage(); c.suivre(t, err);
  await t.goto(c.B + '/caisse'); await c.swPret(t);
  await c.pinCaisse(t, 'Patrick Kambale', '482913');
  await t.waitForTimeout(1500);
  await verrouiller(t);
  await ctxT.setOffline(true);
  await t.reload(); await t.waitForSelector('#qui'); await t.waitForTimeout(800);
  v(await t.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), 'téléphone : écran de verrouillage sans réseau, sans défilement horizontal');
  await t.screenshot({ path: CAP + 'tel-verrou-hors-ligne.png' });
  await taperPin(t, 'Patrick Kambale', '482913');
  await t.waitForSelector('#caisse');
  v((await c.donnees(t)).moi === 2, 'téléphone : PIN vérifié sur l\'appareil');
  await t.screenshot({ path: CAP + 'tel-caisse-pin-hors-ligne.png' });
  await ctxT.setOffline(false);
  await t.waitForTimeout(6000);
  await t.waitForLoadState('load');
  v(await t.locator('#qui').count() === 1, 'téléphone : réseau revenu, la caisse ouverte sans réseau se verrouille (PIN à refaire en ligne)');

  v(err.length === 0, 'aucune erreur JavaScript' + (err.length ? ' : ' + err.join(' | ') : ''));
  c.bilan();
  await b.close();
})().catch(e => { console.error(e); c.bilan(); process.exit(1); });
