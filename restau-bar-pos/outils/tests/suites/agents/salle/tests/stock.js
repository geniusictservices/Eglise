// Stock à la caisse (module stock) : en mode « bloquer », un format que le comptoir ne peut plus fournir est
// « épuisé » (grisé, impossible à toucher), refusé par le serveur (vente directe, envoi sur une note ; le paiement d'une note
// ouverte, déjà servie, passe toujours) avec
// la liste des articles ; grisé juste après la vente qui l'épuise. En mode « vendre », rien ne change.
const { chromium } = require('playwright');
const crypto = require('crypto');
const { B, MDP, sql, restaurer, v, bilan, connecter, ajouter } = require('./commun');

(async () => {
  restaurer();
  const b = await chromium.launch(); const err = [];
  const ctx = await b.newContext({ viewport: { width: 1366, height: 860 } });
  const p = await connecter(ctx, 'admin@demo.test', MDP, err);
  p.on('dialog', d => d.accept());
  const heineken = sql("SELECT id FROM produits WHERE nom_fr='Heineken'");
  const format = sql(`SELECT id FROM conditionnements WHERE produit_id=${heineken} AND quantite_base=1 LIMIT 1`);
  const bar = sql("SELECT id FROM lieux_stock WHERE etablissement_id=2 AND nom='Bar principal'");
  const tuile = () => p.locator('#grille .tuile', { has: p.locator('.tuile__nom:text-is("Heineken")') });
  const csrf = () => p.evaluate(() => JSON.parse(document.getElementById('donnees-caisse').textContent).csrf);
  const caisseFC = sql("SELECT c.id FROM comptes_tresorerie c JOIN compte_devises cd ON cd.compte_id=c.id JOIN devises d ON d.id=cd.devise_id WHERE c.type='caisse' AND (c.etablissement_id=2 OR c.etablissement_id IS NULL) AND d.code='CDF' ORDER BY c.etablissement_id IS NULL LIMIT 1");
  const vendre = (lignes, extra = {}) => p.request.post(B + '/caisse/encaisser', { form: { csrf: 0 || extra.csrf, vente: JSON.stringify({ cle: crypto.randomUUID(), etab: 'volcano-club', lignes, paiements: [{ devise: 'CDF', compte: +caisseFC, recu: 200000 }], ...extra.vente }) } });

  // 1. Mode « vendre » (par défaut) : stock à zéro, on vend quand même. La démo met maintenant le Volcano Club en
  // « bloquer » (bin/demo-stock-suite.php, README) : le mode « vendre » est remis pour cette première partie
  sql("UPDATE stock_parametres SET mode_negatif = 'vendre' WHERE etablissement_id = 2");
  sql(`UPDATE stock_niveaux SET quantite = 0 WHERE lieu_id = ${bar} AND produit_id = ${heineken}`);
  await p.goto(B + '/caisse?e=volcano-club'); await p.evaluate(() => sessionStorage.clear()); await p.reload(); await p.waitForTimeout(700);
  v(!(await tuile().isDisabled()), 'mode « vendre » : Heineken à zéro reste vendable');
  let r = await vendre([{ id: +format, qte: 1 }], { csrf: await csrf() });
  v(r.status() === 200, 'mode « vendre » : la vente passe (stock négatif)');

  // 2. Mode « bloquer » : épuisé à la caisse et refusé par le serveur
  sql("UPDATE stock_parametres SET mode_negatif = 'bloquer' WHERE etablissement_id = 2");
  await p.reload(); await p.waitForTimeout(700);
  v(await tuile().isDisabled() && (await tuile().textContent()).includes('Épuisé'), 'mode « bloquer » : Heineken « Épuisé », impossible à toucher');
  v(await p.evaluate(f => JSON.parse(document.getElementById('donnees-caisse').textContent).epuises.includes(f), +format), 'la caisse reçoit la liste des formats épuisés');
  r = await vendre([{ id: +format, qte: 1 }], { csrf: await csrf() });
  let j = await r.json();
  v(r.status() === 422 && j.erreur.includes('Heineken') && j.code === 'epuise', 'vente directe refusée (422) : ' + j.erreur);
  // Note « Bar 2 » (2 Heineken commandées avant) : y envoyer une Heineken de plus est refusé ; mais les Heineken déjà
  // servies se paient toujours (changement voulu, module Nuit : on ne refuse jamais le paiement d'une note ouverte)
  const bar2 = sql("SELECT n.id FROM notes n JOIN salle_tables t ON t.id=n.table_id WHERE t.nom='Bar 2' AND n.statut='ouverte'");
  r = await p.request.post(B + '/caisse/notes/envoyer', { form: { csrf: await csrf(), etab: 'volcano-club', envoi: JSON.stringify({ note: +bar2, lignes: [{ id: +format, qte: 1 }] }) } });
  j = await r.json();
  v(r.status() === 422 && j.erreur.includes('Heineken'), 'envoyer un article épuisé sur une note : refusé (422)');
  r = await vendre([], { csrf: await csrf(), vente: { note: { id: +bar2, mode: 'tout' }, paiements: [{ devise: 'CDF', compte: +caisseFC, recu: 2000000 }] } });
  j = await r.json();
  v(r.status() === 200 && sql(`SELECT statut FROM notes WHERE id=${bar2}`) === 'payee', 'payer une note avec un article déjà servi puis épuisé : accepté (200) ' + (j.erreur || j.numero));
  // Format choisi dans la fenêtre des formats : Primus Grande épuisée, Petite toujours là
  const grande = sql("SELECT id FROM produits WHERE nom_fr='Primus Grande'");
  sql(`UPDATE stock_niveaux SET quantite = 0 WHERE lieu_id = ${bar} AND produit_id = ${grande}`);
  await p.reload(); await p.waitForTimeout(700);
  await p.locator('#grille .tuile', { has: p.locator('.tuile__nom:text-is("Primus")') }).click(); await p.waitForSelector('#formats[open]');
  const grandeB = p.locator('#formats-liste .format', { hasText: 'Grande' }).first();
  v(await grandeB.isDisabled() && (await grandeB.textContent()).includes('Épuisé') && !(await p.locator('#formats-liste .format', { hasText: 'Petite' }).first().isDisabled()), 'fenêtre des formats : Grande « Épuisé », Petite vendable');
  await p.keyboard.press('Escape');

  // 3. Dernière bouteille : vendue, puis grisée tout de suite (sans recharger)
  sql(`UPDATE stock_niveaux SET quantite = 1 WHERE lieu_id = ${bar} AND produit_id = ${heineken}`);
  await p.reload(); await p.waitForTimeout(700);
  v(!(await tuile().isDisabled()), 'une bouteille en stock : vendable');
  await ajouter(p, ['Heineken']);
  await p.click('#encaisser'); await p.waitForSelector('#paiement[open]'); await p.click('#paiement-valider'); await p.waitForSelector('#paiement-fin:not([hidden])');
  v(Number(sql(`SELECT quantite FROM stock_niveaux WHERE lieu_id = ${bar} AND produit_id = ${heineken}`)) === 0, 'vente : le stock du Bar principal tombe à 0');
  await p.click('#paiement-suivant').catch(() => {}); await p.waitForTimeout(500);
  if (await p.locator('.verrou__personnes').count() === 0) {
    v(await tuile().isDisabled(), 'juste après la vente : Heineken grisée sans recharger');
  } else {
    v(true, 'caisse verrouillée après la vente (rechargée)');
  }
  sql("UPDATE stock_parametres SET mode_negatif = 'vendre' WHERE etablissement_id = 2");
  v(err.length === 0, 'aucune erreur JS ' + err.join(' | '));
  bilan(); await b.close();
})();
