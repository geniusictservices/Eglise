// Mode hors ligne au Volcano Club (Riziki, superviseure, remise limitée à 10 %) : formule avec ses softs, remise dans
// la limite, remise au-delà refusée sans réseau (le PIN du gérant demande le réseau), cashless et fidélité grisés
const { chromium } = require('playwright');
const c = require('./commun');
const { v, sql } = c;

async function attendreFileVide(p, ms = 40000) {
  const fin = Date.now() + ms;
  while (Date.now() < fin) {
    const f = await c.fileHl(p).catch(() => null);
    if (f && f.length === 0) { return true; }
    await p.waitForTimeout(500);
  }
  return false;
}

(async () => {
  c.restaurer();
  // Le club de la démo est en mode « bloquer » avec la bouteille épuisée : ici, « vendre quand même »
  sql("UPDATE stock_parametres SET mode_negatif = 'vendre' WHERE etablissement_id = 2");
  const b = await chromium.launch();
  const err = [];
  const ctx = await b.newContext({ viewport: { width: 1366, height: 860 } });
  const p = await c.connecter(ctx, 'riziki@demo.test', 'Superviseur-2026', err);
  await p.goto(c.B + '/caisse?e=volcano-club');
  await p.evaluate(() => sessionStorage.clear());
  await c.swPret(p); await p.reload(); await p.waitForSelector('#caisse'); await p.waitForTimeout(1500);
  const D = await c.donnees(p);
  const jw = D.produits.find(x => x.nom.startsWith('Johnnie Walker Black 70 cl + 4'));
  const opt = nom => jw.formule[1].options.find(o => o.nom.startsWith(nom));
  await ctx.setOffline(true);
  await p.reload(); await p.waitForSelector('#caisse'); await p.waitForTimeout(800);
  v(await p.evaluate(() => !!document.querySelector('meta[name="rbp-hors-ligne"]')), 'Volcano Club : caisse rechargée sans réseau');
  if (await p.locator('#nuit-cashless').count()) { v(await p.locator('#nuit-cashless').isDisabled(), 'bouton « Carte » (cashless) grisé'); }
  // 1. Formule : la bouteille et 4 softs
  await p.locator('#grille .tuile', { has: p.locator('.tuile__nom', { hasText: 'Johnnie Walker Black 70 cl + 4' }) }).click();
  await p.waitForSelector('#nuit-fenetre[open]');
  for (const o of [opt('Fanta'), opt('Fanta'), opt('Sprite'), opt('Sprite')]) { await p.click(`[data-option="${o.id}"]`); }
  await p.click('#formule-valider'); await p.waitForTimeout(300);
  await p.click('#encaisser'); await p.waitForSelector('#paiement[open]');
  if (await p.locator('#paiement-carte').count()) { v(await p.locator('#paiement-carte').isDisabled(), 'paiement par carte cashless grisé'); }
  await p.click('#paiement-valider'); await p.waitForSelector('#paiement-fin:not([hidden])');
  v((await p.textContent('#paiement-recu')).includes('En attente'), 'formule vendue sans réseau : reçu provisoire');
  await p.click('#paiement-suivant');
  // 2. Remise de 10 % (dans la limite de Riziki)
  await c.ajouter(p, ['Heineken', 'Heineken']);
  await p.click('#encaisser'); await p.waitForSelector('#paiement[open]');
  await p.click('#paiement-remise'); await p.waitForSelector('#nuit-fenetre[open]');
  await p.fill('#remise-valeur', '10'); await p.click('#remise-appliquer'); await p.waitForTimeout(250);
  await p.click('#paiement-valider'); await p.waitForSelector('#paiement-fin:not([hidden])');
  v((await p.textContent('#paiement-recu')).includes('Remises'), 'remise de 10 % sans réseau : acceptée, sur le reçu provisoire');
  await p.click('#paiement-suivant');
  // 3. Remise de 30 % : au-delà de la limite, le PIN du gérant demande le réseau
  await c.ajouter(p, ['Heineken']);
  await p.click('#encaisser'); await p.waitForSelector('#paiement[open]');
  await p.click('#paiement-remise'); await p.waitForSelector('#nuit-fenetre[open]');
  await p.fill('#remise-valeur', '30'); await p.click('#remise-appliquer'); await p.waitForTimeout(250);
  await p.click('#paiement-valider'); await p.waitForTimeout(500);
  v(await p.isVisible('#paiement-erreur') && (await p.textContent('#paiement-erreur')).includes('PIN du gérant demande le réseau'), 'remise de 30 % sans réseau : refusée, la raison est dite');
  v((await c.fileHl(p)).length === 2, 'rien n\'est mis en attente pour cette vente');
  await p.screenshot({ path: c.S + 'ordi-remise-hors-ligne.png' });
  await p.click('[data-fermer-paiement]');
  await p.click('#vider');
  // Retour du réseau
  await ctx.setOffline(false);
  v(await attendreFileVide(p), 'retour du réseau : formule et remise envoyées');
  const ventes = sql("SELECT id FROM ventes WHERE hors_ligne = 1 ORDER BY id").split('\n');
  v(ventes.length === 2, 'deux ventes hors ligne');
  const lignes = sql(`SELECT GROUP_CONCAT(nature ORDER BY id) FROM vente_lignes WHERE vente_id = ${ventes[0]}`);
  v(lignes.startsWith('formule') && (lignes.match(/composant/g) || []).length >= 3, 'formule : la formule et ses composants (' + lignes + ')');
  v(+sql(`SELECT COUNT(*) FROM stock_mouvements WHERE source_id = ${ventes[0]} AND type = 'vente'`) >= 3, 'formule : les composants sortent du stock');
  v(sql(`SELECT CONCAT(type, ':', CAST(valeur AS UNSIGNED), ':', accorde_par) FROM vente_remises WHERE vente_id = ${ventes[1]}`) === 'pourcent:10:8', 'remise : 10 % accordée par Riziki, enregistrée');
  v(sql(`SELECT COALESCE(notes_hors_ligne, '') FROM ventes WHERE id = ${ventes[1]}`) === '', 'remise dans la limite : rien à signaler');
  v(err.length === 0, 'aucune erreur JavaScript' + (err.length ? ' : ' + err.join(' | ') : ''));
  c.bilan();
  await b.close();
})().catch(e => { console.error(e); c.bilan(); process.exit(1); });
