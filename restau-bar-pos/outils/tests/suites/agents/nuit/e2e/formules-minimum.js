// Formules (bouteille + softs au choix) : fenêtre de choix, sorties de stock des composants, prix de la grille (VIP),
// composition refusée, soft épuisé (mode « bloquer ») ; note avec formule. Minimum de consommation du Carré VIP :
// progression sur la note, ligne « minimum non atteint » ajoutée, levée par le PIN d'un gérant (faux PIN refusé), journal.
const { chromium } = require('playwright');
const { B, CAP, sql, restaurer, v, bilan, proche, connecter, ajouter, donnees, encaisser, poster, lire, caisseDe, attendre } = require('./commun');

(async () => {
  restaurer();
  const b = await chromium.launch(); const err = [];
  const ctx = await b.newContext({ viewport: { width: 1366, height: 860 } });
  const p = await connecter(ctx, 'riziki@demo.test', 'Superviseur-2026', err);
  sql("UPDATE stock_parametres SET mode_negatif = 'vendre' WHERE etablissement_id = 2");
  await p.goto(B + '/caisse?e=volcano-club'); await p.evaluate(() => sessionStorage.clear()); await p.reload(); await p.waitForTimeout(700);
  let D = await donnees(p);
  const jw = D.produits.find(x => x.nom.startsWith('Johnnie Walker Black 70 cl + 4'));
  v(jw && jw.formule && jw.formule.length === 2 && jw.formule[1].choix === 4, 'formule JW : deux groupes (bouteille, 4 softs) envoyés à la caisse');
  const fjw = jw.formats[0];
  const opt = nom => jw.formule[1].options.find(o => o.nom.startsWith(nom));
  const fanta = opt('Fanta'), sprite = opt('Sprite');
  const bar = sql("SELECT id FROM lieux_stock WHERE etablissement_id=2 AND nom='Bar principal'");
  const niveau = nom => Number(sql(`SELECT COALESCE(SUM(n.quantite),0) FROM stock_niveaux n JOIN produits p ON p.id=n.produit_id WHERE n.lieu_id=${bar} AND p.nom_fr='${nom}'`));
  const avant = { jw: niveau('Johnnie Walker Black Label'), fanta: niveau('Fanta Orange'), sprite: niveau('Sprite') };

  // 1. Fenêtre de la formule : 2 Fanta + 2 Sprite, « Ajouter » seulement quand les 4 sont choisis
  await p.locator('#grille .tuile', { has: p.locator('.tuile__nom', { hasText: 'Johnnie Walker Black 70 cl + 4' }) }).click();
  await p.waitForSelector('#nuit-fenetre[open]');
  v(await p.locator('#formule-valider').isDisabled(), 'formule : « Ajouter » inactif tant que les softs ne sont pas choisis');
  for (const o of [fanta, fanta, sprite]) { await p.click(`[data-option="${o.id}"]`); }
  v(await p.locator('#formule-valider').isDisabled(), '3 softs sur 4 : toujours inactif');
  await p.click(`[data-option="${sprite.id}"]`);
  v(await p.locator(`[data-option="${fanta.id}"]`).isDisabled(), '4 softs choisis : plus de « + »');
  await p.screenshot({ path: CAP + 'formule-ordi.png' });
  await p.click('#formule-valider'); await p.waitForTimeout(300);
  v((await p.textContent('#lignes')).includes('Fanta'), 'ticket : la formule avec ses softs');
  await p.click('#encaisser'); await p.waitForSelector('#paiement[open]');
  const tot = await p.evaluate(() => window.Caisse.paiement().totaux);
  v(proche(tot.USD, fjw.prix.USD) && proche(tot.CDF, fjw.prix.CDF), `prix de la formule : ${tot.USD} $ / ${tot.CDF} FC`);
  await p.click('#paiement-valider'); await p.waitForTimeout(1500);
  await p.waitForSelector('#paiement-fin:not([hidden])', { timeout: 8000 });
  const venteId = sql('SELECT MAX(id) FROM ventes');
  v(sql(`SELECT GROUP_CONCAT(CONCAT(nature,':',libelle,':',quantite+0) ORDER BY id SEPARATOR ' | ') FROM vente_lignes WHERE vente_id=${venteId}`).split(' | ').filter(x => x.startsWith('composant')).length === 3, 'vente : la formule + 3 composants (bouteille, Fanta ×2, Sprite ×2)');
  await attendre(300);
  const apres = { jw: niveau('Johnnie Walker Black Label'), fanta: niveau('Fanta Orange'), sprite: niveau('Sprite') };
  v(proche(avant.jw - apres.jw, 70) && proche(avant.fanta - apres.fanta, 2) && proche(avant.sprite - apres.sprite, 2), `stock : −70 cl de JW (une bouteille), −2 Fanta, −2 Sprite (${JSON.stringify(avant)} → ${JSON.stringify(apres)})`);
  v(+sql(`SELECT COUNT(*) FROM stock_mouvements m JOIN produits p ON p.id=m.produit_id WHERE p.nom_fr LIKE 'Johnnie Walker Black 70 cl + 4%'`) === 0, 'la formule elle-même ne sort pas du stock');
  v((await p.textContent('#paiement-recu')).includes('Fanta'), 'reçu : les softs de la formule');
  await p.click('#paiement-suivant').catch(() => {});

  // 2. Composition refusée par le serveur
  const fc = caisseDe(2, 'CDF');
  let r = await encaisser(p, { lignes: [{ id: fjw.id, qte: 1, comp: [{ id: fanta.id, qte: 3 }] }], paiements: [{ devise: 'CDF', compte: fc, recu: fjw.prix.CDF }] });
  v(r.statut === 422 && r.j.code === 'composants', '3 softs au lieu de 4 : refusé (422)');
  r = await encaisser(p, { lignes: [{ id: fjw.id, qte: 1, comp: [{ id: fanta.id, qte: 4 }, { id: +sql("SELECT c.id FROM conditionnements c JOIN produits p ON p.id=c.produit_id WHERE p.nom_fr='Heineken' LIMIT 1"), qte: 1 }] }], paiements: [{ devise: 'CDF', compte: fc, recu: fjw.prix.CDF }] });
  v(r.statut === 422, 'article hors formule : refusé (422)');

  // 3. Grille VIP : prix VIP de la formule (100 $)
  r = await encaisser(p, { zone: 'vip', lignes: [{ id: fjw.id, qte: 1, comp: [{ id: fanta.id, qte: 4 }] }], paiements: [{ devise: 'USD', compte: caisseDe(2, 'USD'), recu: 100 }] });
  v(r.statut === 200 && proche(sql(`SELECT t.total FROM vente_totaux t JOIN ventes v ON v.id=t.vente_id WHERE v.numero='${r.j.numero}' AND t.devise_id=1`), 100), 'Carré VIP : formule au prix VIP (100 $)');

  // 4. Mode « bloquer » : Sprite épuisé → option grisée, refus du serveur
  sql("UPDATE stock_parametres SET mode_negatif = 'bloquer' WHERE etablissement_id = 2");
  sql(`UPDATE stock_niveaux n JOIN produits p ON p.id=n.produit_id SET n.quantite = 0 WHERE n.lieu_id=${bar} AND p.nom_fr='Sprite'`);
  sql(`UPDATE stock_niveaux n JOIN produits p ON p.id=n.produit_id SET n.quantite = IF(p.nom_fr LIKE 'Johnnie%', 1400, 20) WHERE n.lieu_id=${bar} AND p.nom_fr IN ('Johnnie Walker Black Label', 'Fanta Orange')`);
  await p.reload(); await p.waitForTimeout(700);
  await p.locator('#grille .tuile', { has: p.locator('.tuile__nom', { hasText: 'Johnnie Walker Black 70 cl + 4' }) }).click();
  await p.waitForSelector('#nuit-fenetre[open]');
  v(await p.locator(`[data-option="${sprite.id}"]`).isDisabled() && !(await p.locator(`[data-option="${fanta.id}"]`).isDisabled()), 'Sprite épuisé : grisé dans la fenêtre, Fanta toujours là');
  await p.keyboard.press('Escape');
  r = await encaisser(p, { lignes: [{ id: fjw.id, qte: 1, comp: [{ id: sprite.id, qte: 4 }] }], paiements: [{ devise: 'CDF', compte: fc, recu: fjw.prix.CDF }] });
  v(r.statut === 422 && r.j.code === 'epuise' && r.j.erreur.includes('Sprite'), 'Sprite épuisé : refusé par le serveur (422 épuisé)');
  sql("UPDATE stock_parametres SET mode_negatif = 'vendre' WHERE etablissement_id = 2");

  // 5. Formule envoyée sur une note (Bar 3), puis payée : composants gardés et sortis du stock
  const bar3 = sql("SELECT id FROM salle_tables WHERE nom='Bar 3' AND etablissement_id=2");
  r = await poster(p, '/caisse/notes/envoyer', { envoi: JSON.stringify({ table: +bar3, lignes: [{ id: fjw.id, qte: 1, comp: [{ id: fanta.id, qte: 4 }] }] }) });
  const noteF = sql(`SELECT id FROM notes WHERE table_id=${bar3} AND statut='ouverte'`);
  v(r.statut === 200 && sql(`SELECT composants FROM note_lignes WHERE note_id=${noteF}`).includes(String(fanta.id)), 'note : la formule garde ses composants ' + r.statut + JSON.stringify(r.j).slice(0, 200) + sql(`SELECT IFNULL(composants,'-') FROM note_lignes WHERE note_id=${noteF}`));
  const fantaAvant = niveau('Fanta Orange');
  r = await encaisser(p, { note: { id: +noteF, mode: 'tout' }, paiements: [{ devise: 'CDF', compte: fc, recu: fjw.prix.CDF }] });
  await attendre(300);
  v(r.statut === 200 && proche(fantaAvant - niveau('Fanta Orange'), 4), 'note payée : 4 Fanta sortent du stock ' + (r.j.erreur || ''));

  // 5 bis. À l'écran : formule envoyée sur la table Bar 4 (caisse-salle.js envoie les composants)
  await p.reload(); await p.waitForTimeout(600);
  await p.click('#salle'); await p.waitForSelector('#salle-fenetre[open]'); await p.waitForTimeout(400);
  await p.click('#salle-zones .puce:has-text("Bar")'); await p.click('.table-plan:has-text("Bar 4")'); await p.waitForTimeout(700);
  await p.locator('#grille .tuile', { has: p.locator('.tuile__nom', { hasText: 'Johnnie Walker Black 70 cl + 4' }) }).click();
  await p.waitForSelector('#nuit-fenetre[open]');
  for (let k = 0; k < 4; k++) { await p.click(`[data-option="${fanta.id}"]`); }
  await p.click('#formule-valider'); await p.waitForTimeout(200);
  await p.click('#envoyer'); await p.waitForTimeout(1000);
  const bar4 = sql("SELECT id FROM salle_tables WHERE nom='Bar 4' AND etablissement_id=2");
  v(sql(`SELECT l.composants FROM note_lignes l JOIN notes n ON n.id=l.note_id WHERE n.table_id=${bar4} AND n.statut='ouverte'`).includes(String(fanta.id)), 'écran : formule envoyée sur la note avec ses softs');
  await p.click('[data-fermer-salle]').catch(() => {});
  await p.reload(); await p.waitForTimeout(500);

  // 6. Minimum du Carré VIP (300 000 FC / 100 $) : progression, ligne ajoutée
  const vip3 = sql("SELECT id FROM salle_tables WHERE nom='VIP 3' AND etablissement_id=2");
  const heineken = D.produits.find(x => x.nom === 'Heineken').formats[0];
  r = await poster(p, '/caisse/notes/envoyer', { envoi: JSON.stringify({ table: +vip3, lignes: [{ id: heineken.id, qte: 2 }] }) });
  const noteV = sql(`SELECT id FROM notes WHERE table_id=${vip3} AND statut='ouverte'`);
  const n = await lire(p, '/caisse/notes/' + noteV);
  const m = n.j.note.minimum;
  v(m && m.minimum.CDF === 300000 && m.minimum.USD === 100 && !m.atteint && m.manque.CDF > 0, `note VIP : minimum 300 000 FC / 100 $, manque ${m && m.manque.CDF} FC`);
  const consommeFC = m.consomme.CDF;
  v(proche(m.manque.CDF, 300000 - consommeFC), 'manque = minimum − consommé');
  // À l'écran : la note montre la progression
  await p.click('#salle'); await p.waitForSelector('#salle-fenetre[open]'); await p.waitForTimeout(400);
  await p.click('#salle-zones .puce:has-text("VIP")'); await p.click('.table-plan:has-text("VIP 3")'); await p.waitForTimeout(700);
  v(await p.locator('.nuit-progres [role=progressbar]').count() === 1, 'écran : barre de progression du minimum sur la note');
  await p.screenshot({ path: CAP + 'minimum-note-ordi.png' });
  await p.click('#encaisser'); await p.waitForSelector('#paiement[open]'); await p.waitForTimeout(300);
  let t = await p.evaluate(() => window.Caisse.paiement().totaux);
  v(proche(t.CDF, 300000) && proche(t.USD, 100), `paiement : total porté au minimum (${t.CDF} FC, ${t.USD} $)`);
  v(await p.locator('#minimum-lever').count() === 1, 'paiement : bouton « Lever le minimum »');
  await p.click('#minimum-lever'); await p.waitForTimeout(250);
  t = await p.evaluate(() => window.Caisse.paiement().totaux);
  v(proche(t.CDF, consommeFC) && await p.locator('#nuit-pin').count() === 1, 'lever : total = consommé, PIN d\'un gérant demandé (superviseure)');
  await p.screenshot({ path: CAP + 'minimum-paiement-ordi.png' });
  await p.keyboard.press('Escape'); await p.waitForTimeout(200);
  // API : sans PIN, faux PIN, bon PIN
  r = await encaisser(p, { note: { id: +noteV, mode: 'tout' }, paiements: [{ devise: 'CDF', compte: fc, recu: consommeFC }], minimum: { lever: true } });
  v(r.statut === 403 && r.j.code === 'minimum_validation', 'lever sans PIN : refusé (403)');
  r = await encaisser(p, { note: { id: +noteV, mode: 'tout' }, paiements: [{ devise: 'CDF', compte: fc, recu: consommeFC }] });
  v(r.statut === 422, 'payer le seul consommé sans lever : refusé (reste à payer)');
  r = await encaisser(p, { note: { id: +noteV, mode: 'tout' }, paiements: [{ devise: 'CDF', compte: fc, recu: consommeFC }], minimum: { lever: true, validateur: 1, pin: '999999' } });
  v(r.statut === 422 && r.j.code === 'minimum_pin', 'faux PIN : refusé (422)');
  r = await encaisser(p, { note: { id: +noteV, mode: 'tout' }, paiements: [{ devise: 'CDF', compte: fc, recu: consommeFC }], minimum: { lever: true, validateur: 1, pin: '908172' } });
  v(r.statut === 200, 'minimum levé par l\'administrateur : encaissé ' + (r.j.erreur || ''));
  v(sql(`SELECT minimum_leve_par FROM notes WHERE id=${noteV}`) === '1' && sql("SELECT details FROM journal WHERE action='minimum_leve' ORDER BY id DESC LIMIT 1").includes('Riziki'), 'note : levé par l\'administrateur ; journal avec la superviseure');
  // Une autre note VIP, payée sans lever : la ligne « minimum non atteint »
  const vip4 = sql("SELECT id FROM salle_tables WHERE nom='VIP 4' AND etablissement_id=2");
  await poster(p, '/caisse/notes/envoyer', { envoi: JSON.stringify({ table: +vip4, lignes: [{ id: heineken.id, qte: 1 }] }) });
  const noteV4 = sql(`SELECT id FROM notes WHERE table_id=${vip4} AND statut='ouverte'`);
  r = await encaisser(p, { note: { id: +noteV4, mode: 'tout' }, paiements: [{ devise: 'USD', compte: caisseDe(2, 'USD'), recu: 100 }] });
  const v4 = sql(`SELECT id FROM ventes WHERE numero='${r.j.numero}'`);
  v(r.statut === 200 && proche(sql(`SELECT total FROM vente_totaux WHERE vente_id=${v4} AND devise_id=1`), 100) && +sql(`SELECT COUNT(*) FROM vente_lignes WHERE vente_id=${v4} AND nature='minimum'`) === 1, 'payé sans lever : ligne « minimum non atteint », total 100 $');
  v(r.j.recu.includes('inimum'), 'reçu : la ligne du minimum');

  // 7. Écrans de gestion : formules et minimums
  const g = await connecter(await b.newContext({ viewport: { width: 1366, height: 860 } }), 'admin@demo.test', require('./commun').MDP, err);
  for (const u of ['/gestion/nuit/formules', '/gestion/nuit/minimums']) {
    const rr = await g.goto(B + u);
    v(rr.status() === 200, u + ' : 200');
    await g.screenshot({ path: CAP + 'gestion' + u.replace(/\//g, '-') + '-ordi.png', fullPage: true });
  }
  v(err.length === 0, 'aucune erreur JavaScript ' + err.join(' | '));
  await b.close(); bilan();
})();
