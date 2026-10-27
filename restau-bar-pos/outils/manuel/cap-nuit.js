// Captures « À la caisse » de nuit : Volcano Club (nightclub, thème nuit) et fidélité au Comptoir du Lac
const o = require('./outils');
const { shot, aller, pret } = o;
const { partager, autoriser, plusTard, entrer, verrouiller, remonter, grande, fermerDialogues } = require('./caisse-outils');
async function suivant(p) {
  if (suivant.reprendre) suivant.reprendre();
  await p.waitForSelector('#paiement-fin:not([hidden])', { timeout: 8000 }).catch(() => {});
  if (await p.locator('#paiement-suivant:visible').count()) {
    // « Terminer » recharge la page quand la caisse se verrouille : on attend ce rechargement
    await Promise.all([p.waitForNavigation({ timeout: 6000 }).catch(() => {}), p.click('#paiement-suivant', { timeout: 5000 }).catch(() => {})]);
  }
  if (await p.locator('#paiement[open]').count()) { await p.keyboard.press('Escape'); await p.waitForTimeout(500); }
  await p.waitForTimeout(800);
  await p.waitForLoadState('load'); await p.waitForSelector('#qui, #caisse'); await p.waitForTimeout(1200);
}
async function nuit(p) { await p.waitForSelector('#nuit-fenetre[open]'); await p.waitForTimeout(350); }
async function code(p, valeur) {
  const champ = p.locator('#nuit-fenetre input[type=text], #nuit-fenetre input:not([type])').first();
  await champ.fill(valeur); await champ.press('Enter'); await p.waitForTimeout(900);
}
(async () => {
  await o.lancer();
  const ctx = await o.contexte(o.ORDI, true);
  const p = await partager(ctx, 'volcano-club', 'Tablette du bar VIP', 'admin@demo.test', o.MDP);
  await autoriser(p);
  // Après un encaissement qui verrouille la caisse, le rafraîchissement de la salle (toutes les 8 s) reçoit 401 et recharge
  // la page, ce qui ferme le reçu : pendant les reçus, on suspend ce rafraîchissement pour avoir le temps de les capturer
  let sonde = true;
  await ctx.route('**/caisse/salle?*', r => sonde ? r.continue() : r.abort());
  p.on('dialog', d => d.dismiss().catch(() => {}));
  const recu = () => { sonde = false; };
  suivant.reprendre = () => { sonde = true; };
  await shot(p, 'nuit-verrou-ordi');
  // Jean-Paul (barman) : carré VIP, formule, cashless
  await entrer(p, 'Jean-Paul Bahati', '161803');
  await p.selectOption('#zone', 'vip').catch(() => {}); await p.waitForLoadState('load'); await p.waitForSelector('#caisse'); await pret(p); await p.waitForTimeout(800);
  await shot(p, 'nuit-caisse-vip-ordi');
  // Le Volcano Club bloque les produits épuisés : pour la suite, on vend quand même
  o.sql("UPDATE stock_parametres SET mode_negatif = 'vendre' WHERE etablissement_id = 2");
  await p.reload(); await p.waitForSelector('#caisse'); await pret(p); await p.waitForTimeout(600);
  await p.locator('#grille .tuile', { has: p.locator('.tuile__nom:text-is("Johnnie Walker Black 70 cl + 4 softs")') }).first().click(); await p.waitForTimeout(400);
  if (await p.locator('#formats[open]').count()) { await p.locator('#formats-liste .format').first().click(); await p.waitForTimeout(400); }
  await nuit(p);
  for (let i = 0; i < 4; i++) {
    const plus = p.locator('#nuit-fenetre .pas__bouton[data-option]:not([disabled])');
    if (!(await plus.count())) break;
    await plus.nth(i < 2 ? 0 : Math.min(1, (await plus.count()) - 1)).click(); await p.waitForTimeout(150);
  }
  await grande(p, 'nuit-formule', '#nuit-fenetre');
  await p.click('#formule-valider').catch(() => console.log('formule non validée')); await p.waitForTimeout(300);
  await remonter(p);
  await shot(p, 'nuit-ticket-formule', { sel: '#ticket' });
  // Cashless : lire une carte, la recharger
  await p.click('#nuit-cashless'); await nuit(p);
  await shot(p, 'nuit-carte-scanner', { sel: '#nuit-fenetre' });
  await code(p, 'C0001-S2ATCYBRUL');
  await grande(p, 'nuit-carte-solde', '#nuit-fenetre');
  await p.click('#carte-recharger'); await p.waitForTimeout(400);
  const montant = p.locator('#recharge-montant');
  await montant.fill('100000').catch(() => {}); await montant.dispatchEvent('input').catch(() => {});
  await grande(p, 'nuit-carte-recharger', '#nuit-fenetre');
  recu(); await p.click('#recharge-valider'); await p.waitForSelector('#paiement[open]'); await p.waitForTimeout(400);
  await grande(p, 'nuit-carte-recharge-paiement', '#paiement');
  recu(); await p.click('#paiement-valider'); await p.waitForSelector('#paiement-fin:not([hidden])', { timeout: 10000 }).catch(() => console.log('fin recharge absente'));
  await p.waitForTimeout(400);
  await shot(p, 'nuit-carte-recharge-recu', { sel: '#paiement-recu .recu' });
  await suivant(p);
  if (await p.locator('#qui').count()) await entrer(p, 'Jean-Paul Bahati', '161803');
  // Payer la formule avec la carte et le reste en espèces
  if (await p.locator('#lignes li').count()) {
    await p.click('#encaisser'); await p.waitForSelector('#paiement[open]'); await p.waitForTimeout(300);
    await p.click('#paiement-carte'); await nuit(p);
    await code(p, 'C0001-S2ATCYBRUL');
    await grande(p, 'nuit-payer-carte', '#nuit-fenetre');
    await p.click('#nuit-fenetre button[data-devise]').catch(() => console.log('pas de paiement carte'));
    await p.waitForTimeout(500);
    await grande(p, 'nuit-paiement-carte', '#paiement');
    recu(); await p.click('#paiement-valider'); await p.waitForSelector('#paiement-fin:not([hidden])', { timeout: 10000 }).catch(() => console.log('fin carte absente'));
    await shot(p, 'nuit-recu-carte', { sel: '#paiement-recu .recu' });
    await suivant(p);
  }
  // Christian (serveur) envoie une commande à la table VIP 1
  await entrer(p, 'Christian Mugisho', '577215');
  await o.ajouter(p, ['Mützig|Grande', 'Mützig|Grande', 'Mojito']);
  await p.click('.actions-note__envoyer'); await p.waitForSelector('#salle-fenetre[open]'); await p.waitForTimeout(400);
  await p.click('#salle-zones button:has-text("VIP")').catch(() => {}); await p.waitForTimeout(400);
  await shot(p, 'nuit-salle-vip', { sel: '#salle-fenetre' });
  await p.click('#plan .table-plan >> nth=0'); await p.waitForTimeout(2500);
  // Riziki (superviseure) : minimum de consommation, remise au-delà de sa limite
  await entrer(p, 'Riziki Amani', '662607');
  await p.click('#salle'); await p.waitForSelector('#salle-fenetre[open]'); await p.waitForTimeout(400);
  await p.click('#salle-zones button:has-text("VIP")').catch(() => {}); await p.waitForTimeout(400);
  await p.click('#plan .table-plan >> nth=0'); await p.waitForTimeout(1200);
  await remonter(p);
  await shot(p, 'nuit-note-minimum', { sel: '#ticket' });
  await p.click('#encaisser'); await p.waitForSelector('#paiement[open]'); await p.waitForTimeout(300);
  await grande(p, 'nuit-paiement-minimum', '#paiement');
  await p.click('#minimum-lever').catch(() => console.log('pas de lever')); await p.waitForTimeout(300);
  await grande(p, 'nuit-minimum-lever', '#paiement');
  await p.click('#minimum-lever').catch(() => {}); await p.waitForTimeout(200);
  await p.click('#paiement-remise'); await nuit(p);
  await p.fill('#remise-valeur', '20');
  await p.click('#remise-appliquer'); await p.waitForTimeout(400);
  await grande(p, 'nuit-remise-validation', '#paiement');
  await p.click('[data-fermer-paiement]'); await p.waitForTimeout(200);
  await p.click('.destination__quitter').catch(() => {});
  o.sql("UPDATE stock_parametres SET mode_negatif = 'bloquer' WHERE etablissement_id = 2");
  // Sarah (agente d'entrée) : billets, contrôle, invités
  await entrer(p, 'Sarah Kavugho', '735102');
  if (!p.url().includes('/caisse/entree')) { await aller(p, '/caisse/entree'); await p.waitForTimeout(800); }
  console.log('entrée :', p.url());
  await o.ajouter(p, ['Entrée', 'Entrée', 'Entrée VIP']).catch(e => console.log('tuiles entrée', e.message.split('\n')[0]));
  await remonter(p);
  await shot(p, 'nuit-entree-ordi');
  await p.click('#encaisser'); await p.waitForSelector('#paiement[open]'); await p.waitForTimeout(300);
  recu(); await p.click('#paiement-valider'); await p.waitForSelector('#paiement-fin:not([hidden])', { timeout: 10000 }); await p.waitForTimeout(800);
  await grande(p, 'nuit-billets', '#paiement-recu', 2200);
  const texte = await p.textContent('#paiement-recu');
  const codes = [...new Set(texte.match(/[A-Z]\d{4,}-[A-Z0-9]{10}/g) || [])];
  console.log('billets', codes);
  await suivant(p);
  if (await p.locator('#qui').count()) { await entrer(p, 'Sarah Kavugho', '735102'); if (!p.url().includes('/caisse/entree')) await aller(p, '/caisse/entree'); }
  await p.click('#nuit-controle'); await nuit(p);
  if (codes[0]) {
    await code(p, codes[0]); await shot(p, 'nuit-controle-ok', { sel: '#nuit-fenetre' });
    await p.click('#controle-suivant').catch(() => {}); await p.waitForTimeout(300);
    await code(p, codes[0]); await shot(p, 'nuit-controle-deja', { sel: '#nuit-fenetre' });
  }
  await fermerDialogues(p);
  await p.click('#nuit-invites'); await nuit(p); await p.waitForTimeout(800);
  await grande(p, 'nuit-invites', '#nuit-fenetre');
  await p.click('#invites-liste button:has-text("Entrer") >> nth=0').catch(() => console.log('pas d\'invité'));
  await p.waitForTimeout(800);
  await grande(p, 'nuit-invites-entre', '#nuit-fenetre');
  await fermerDialogues(p);
  await shot(p, 'nuit-entree-compteurs', { clip: { x: 0, y: 0, width: 1366, height: 130 } });
  // Didier (vestiaire) : dépôt avec frais, rendre, objets encore déposés
  await entrer(p, 'Didier Kasereka', '846295');
  if (!p.url().includes('/caisse/vestiaire')) { await aller(p, '/caisse/vestiaire'); await p.waitForTimeout(800); }
  console.log('vestiaire :', p.url());
  await shot(p, 'nuit-vestiaire-ordi');
  const tuile = p.locator('#grille .tuile:not([disabled])').first();
  if (await tuile.count()) {
    await tuile.click(); await p.waitForTimeout(300);
    await nuit(p); await p.fill('#depot-description', 'Veste noire et casque');
    await shot(p, 'nuit-vestiaire-depot', { sel: '#nuit-fenetre' });
    await p.press('#depot-description', 'Enter'); await p.waitForTimeout(400);
    await p.click('#encaisser'); await p.waitForSelector('#paiement[open]'); await p.waitForTimeout(300);
    await grande(p, 'nuit-vestiaire-paiement', '#paiement');
    recu(); await p.click('#paiement-valider'); await p.waitForSelector('#paiement-fin:not([hidden])', { timeout: 10000 }).catch(() => console.log('fin vestiaire absente'));
    await p.waitForTimeout(600);
    await grande(p, 'nuit-vestiaire-ticket', '#paiement-recu');
    await suivant(p);
    if (await p.locator('#qui').count()) { await entrer(p, 'Didier Kasereka', '846295'); if (!p.url().includes('/caisse/vestiaire')) await aller(p, '/caisse/vestiaire'); }
  }
  await p.click('#nuit-restants'); await nuit(p); await p.waitForTimeout(600);
  await grande(p, 'nuit-vestiaire-restants', '#nuit-fenetre');
  await fermerDialogues(p);
  await p.click('#nuit-rendre'); await nuit(p); await p.waitForTimeout(300);
  await shot(p, 'nuit-vestiaire-rendre', { sel: '#nuit-fenetre' });
  await fermerDialogues(p);
  await ctx.close();

  // Caisse du club sur le téléphone d'un serveur
  const c3 = await o.contexte(o.TEL, true);
  const t = await partager(c3, 'volcano-club', 'Téléphone de Christian', 'admin@demo.test', o.MDP);
  await autoriser(t);
  await entrer(t, 'Christian Mugisho', '577215');
  await o.ajouter(t, ['Primus|Grande', 'Kivu Sunset']);
  await remonter(t);
  await shot(t, 'nuit-caisse-tel');
  await c3.close();

  // Fidélité au Comptoir du Lac (thème jour)
  const c2 = await o.contexte(o.ORDI);
  const f = await o.connecter(c2);
  await aller(f, '/caisse?e=comptoir-du-lac'); await f.waitForTimeout(1200); await plusTard(f);
  await o.ajouter(f, ['Mojito', 'Mojito', 'Poulet braisé']);
  await f.click('#encaisser'); await f.waitForSelector('#paiement[open]'); await f.waitForTimeout(300);
  await f.click('#paiement-fidelite'); await nuit(f);
  const rech = f.locator('#nuit-fenetre input').first(); await rech.fill('Bisimwa'); await f.waitForTimeout(1200);
  await f.click('#nuit-fenetre .ardoise-client, #nuit-fenetre button:has-text("Bisimwa")').catch(() => console.log('client fidélité introuvable'));
  await f.waitForTimeout(800);
  await grande(f, 'caisse-fidelite', '#nuit-fenetre');
  await c2.close();
  o.bilan(); await o.fermer();
})();
