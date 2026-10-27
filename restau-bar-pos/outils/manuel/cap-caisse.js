// Captures « À la caisse » : Le Comptoir du Lac (restaurant-bar, thème jour)
const o = require('./outils');
const { shot, aller, pret } = o;
const etape = process.argv[2] || 'tout';
const faire = n => etape === 'tout' || etape.split(',').includes(n);

const { partager, autoriser, plusTard, entrer, verrouiller, remonter, grande, fermerDialogues } = require('./caisse-outils');

(async () => {
  await o.lancer();
  const ctx = await o.contexte(o.ORDI);
  // 1. Autoriser la tablette du bar comme caisse partagée (gérant)
  const p = await partager(ctx, 'comptoir-du-lac', 'Tablette du bar');
  if (faire('partage')) await shot(p, 'caisse-partager-ordi', { sel: '#fenetre-quitter' });
  await autoriser(p);
  if (faire('verrou')) {
    await shot(p, 'caisse-verrou-ordi');
    await p.click('.verrou__personne[data-nom="Esther Kahindo"]'); await p.waitForTimeout(250);
    for (const c of '314') await p.click(`#pave-touches [data-chiffre="${c}"]`);
    await shot(p, 'caisse-pin-ordi');
    await p.click('#pave-touches [data-effacer], #pave-touches button[aria-label*="ffacer"]').catch(() => {});
    await p.reload(); await p.waitForSelector('#qui'); await pret(p);
  }
  // 2. Esther (caissière) : garanties à confirmer
  await o.pinCaisse(p, 'Esther Kahindo', '314159');
  if (await p.locator('#fenetre-garanties[open]').count()) {
    if (faire('garanties')) await shot(p, 'caisse-garanties-ordi', { sel: '#fenetre-garanties' });
    await p.click('#fenetre-garanties button:has-text("Plus tard")'); await p.waitForTimeout(200);
  }
  // 3. Grille, tailles, formats, ticket
  if (faire('vente')) {
    await shot(p, 'caisse-grille-ordi');
    await p.locator('#grille .tuile', { has: p.locator('.tuile__nom:text-is("Primus")') }).first().click(); await p.waitForTimeout(300);
    await shot(p, 'caisse-tailles-ordi', { sel: '#formats' });
    await p.locator('#formats-liste .format', { hasText: 'Grande' }).first().click(); await p.waitForTimeout(150);
    await o.ajouter(p, ['Primus|Grande', 'Tilapia grillé du lac', 'Frites']);
    await p.locator('#grille .tuile', { has: p.locator('.tuile__nom:text-is("Johnnie Walker Black Label")') }).first().click(); await p.waitForTimeout(300);
    await shot(p, 'caisse-formats-ordi', { sel: '#formats' });
    await p.locator('#formats-liste .format', { hasText: 'Dose' }).first().click(); await p.waitForTimeout(200);
    await remonter(p);
    await shot(p, 'caisse-ticket-ordi');
    await shot(p, 'caisse-ticket-detail', { sel: '#ticket' });
  }
  // 4. Encaisser en dollars et en francs, monnaie, reçu
  if (faire('paiement')) {
    await p.click('#encaisser'); await p.waitForSelector('#paiement[open]'); await p.waitForTimeout(300);
    await shot(p, 'caisse-paiement-fc', { sel: '#paiement' });
    await p.click('#paiement-devises button:has-text("Les deux")'); await p.waitForTimeout(200);
    await p.fill('#recu-USD', '20'); await p.dispatchEvent('#recu-USD', 'input');
    await p.fill('#recu-CDF', '30000'); await p.dispatchEvent('#recu-CDF', 'input'); await p.waitForTimeout(300);
    await grande(p, 'caisse-paiement-deux', '#paiement');
    await p.click('#paiement-valider');
    await p.waitForSelector('#paiement-fin:not([hidden])', { timeout: 8000 }); await p.waitForTimeout(400);
    await shot(p, 'caisse-paiement-fin', { sel: '#paiement' });
    await Promise.all([p.waitForNavigation(), p.click('#paiement-suivant')]); await p.waitForSelector('#qui'); await pret(p);
  }
  // 5. Patrick (gérant) : remise, puis ardoise
  await entrer(p, 'Patrick Kambale', '482913');
  await o.ajouter(p, ['Mützig|Grande', 'Mützig|Grande', 'Poulet braisé', 'Coca-Cola']);
  if (faire('remise')) {
    await p.click('#encaisser'); await p.waitForSelector('#paiement[open]'); await p.waitForTimeout(250);
    await p.click('#paiement-remise'); await p.waitForSelector('#nuit-fenetre[open]'); await p.waitForTimeout(200);
    await p.fill('#remise-valeur', '10');
    await p.click('#remise-motifs .puce:has-text("Client VIP"), #remise-motifs button:has-text("Client VIP")').catch(() => {});
    await shot(p, 'caisse-remise', { sel: '#nuit-fenetre' });
    await p.click('#remise-appliquer'); await p.waitForTimeout(300);
    await grande(p, 'caisse-paiement-remise', '#paiement');
    await p.click('[data-fermer-paiement]'); await p.waitForTimeout(200);
  }
  if (faire('ardoise')) {
    await p.click('.paiements [data-ardoise]'); await p.waitForSelector('#paiement[open]'); await p.waitForTimeout(300);
    await p.fill('#ardoise-recherche', 'Bisimwa'); await p.waitForSelector('.ardoise-client'); await p.waitForTimeout(300);
    await grande(p, 'caisse-ardoise-recherche', '#paiement');
    await p.click('.ardoise-client'); await p.waitForTimeout(300);
    await p.click('#paiement-valider'); await p.waitForSelector('#ardoise-forcer', { timeout: 8000 }).catch(() => console.log('pas de Forcer'));
    await p.waitForTimeout(300);
    await grande(p, 'caisse-ardoise-retard', '#paiement');
    await p.click('.ardoise-carte button:has-text("Changer")'); await p.waitForTimeout(200);
    await p.fill('#ardoise-recherche', 'Kahambu'); await p.waitForSelector('.ardoise-client'); await p.waitForTimeout(300);
    await p.click('.ardoise-client'); await p.waitForTimeout(300);
    await p.click('#ardoise-servi button:has-text("Aline")').catch(() => console.log('pas de Aline'));
    await p.waitForTimeout(200);
    await grande(p, 'caisse-ardoise', '#paiement');
    await p.click('#paiement-valider');
    await p.waitForSelector('#paiement-fin:not([hidden])', { timeout: 10000 }); await p.waitForTimeout(400);
    await shot(p, 'caisse-ardoise-recu', { sel: '#paiement-recu .recu' });
    await Promise.all([p.waitForNavigation(), p.click('#paiement-suivant')]); await p.waitForSelector('#qui'); await pret(p);
  }
  // 6. Esther : remboursement d'une ardoise, pointage de secours
  await entrer(p, 'Esther Kahindo', '314159');
  if (faire('rembourser')) {
    await p.click('#ardoises'); await p.waitForSelector('#fenetre-ardoises[open]'); await p.waitForTimeout(200);
    await p.fill('#ardoises-recherche', 'Ndungo'); await p.waitForSelector('#fenetre-ardoises .ardoise-client'); await p.waitForTimeout(300);
    await p.click('#fenetre-ardoises .ardoise-client'); await p.waitForTimeout(600);
    await p.fill('#ardoises-montant', '20000').catch(() => {}); await p.dispatchEvent('#ardoises-montant', 'input').catch(() => {});
    await p.waitForTimeout(300);
    await grande(p, 'caisse-rembourser', '#fenetre-ardoises');
    await p.click('#fenetre-ardoises button:has-text("Encaisser le remboursement")'); await p.waitForTimeout(1500);
    await grande(p, 'caisse-rembourser-recu', '#fenetre-ardoises');
    await p.click('[data-fermer-ardoises]').catch(() => {}); await p.waitForTimeout(300);
    if (await p.locator('#qui').count() === 0 && await p.locator('#fenetre-ardoises[open]').count()) await p.keyboard.press('Escape');
  }
  if (faire('pointage')) {
    await p.click('#quitter'); await p.waitForTimeout(250);
    await p.click('#pointage-secours'); await p.waitForTimeout(200);
    await p.selectOption('#pointage-employe', { index: 1 }).catch(() => {});
    await grande(p, 'caisse-pointage-secours', '#fenetre-quitter');
    await p.keyboard.press('Escape'); await p.waitForTimeout(200);
  }
  // 7. Sessions de caisse (activées pour l'occasion) : ouverture avec le fond, clôture avec le comptage
  if (faire('session')) {
    o.sql("UPDATE etablissements SET sessions_caisse = 1 WHERE id = 1");
    await p.reload(); await p.waitForSelector('#caisse'); await pret(p); await plusTard(p);
    await shot(p, 'caisse-session-fermee', { sel: '#ticket' });
    await p.click('#ouvrir-session'); await p.waitForSelector('#fenetre-ouverture[open]');
    const champs = p.locator('#fenetre-ouverture input[data-compte]');
    for (let i = 0; i < await champs.count(); i++) await champs.nth(i).fill(['50000', '20'][i] || '0');
    await shot(p, 'caisse-session-ouvrir', { sel: '#fenetre-ouverture' });
    await p.click('#fenetre-ouverture button[type=submit]'); await p.waitForTimeout(1500);
    await p.reload(); await p.waitForSelector('#caisse'); await pret(p); await plusTard(p);
    await o.ajouter(p, ['Primus|Petite', 'Primus|Petite']);
    await p.click('.paiements [data-compte]'); await p.waitForSelector('#paiement[open]'); await p.waitForTimeout(250);
    await p.click('#paiement-valider'); await p.waitForSelector('#paiement-fin:not([hidden])');
    await Promise.all([p.waitForNavigation(), p.click('#paiement-suivant')]); await p.waitForSelector('#qui');
    await entrer(p, 'Esther Kahindo', '314159');
    await p.click('#quitter'); await p.waitForTimeout(250);
    await p.click('#cloturer-session'); await p.waitForSelector('#fenetre-cloture[open]');
    const cl = p.locator('#fenetre-cloture input[data-compte]');
    for (let i = 0; i < await cl.count(); i++) await cl.nth(i).fill(['58000', '20'][i] || '0');
    await p.fill('#cloture-note', 'Fin du service de midi');
    await shot(p, 'caisse-session-cloturer', { sel: '#fenetre-cloture' });
    await p.click('#fenetre-cloture button[type=submit]'); await p.waitForSelector('#fenetre-cloture .session-rapport:not([hidden])', { timeout: 8000 }).catch(() => console.log('pas de rapport'));
    await p.waitForTimeout(400);
    await grande(p, 'caisse-session-rapport', '#fenetre-cloture .session-rapport__recu', 1600);
    o.sql("UPDATE etablissements SET sessions_caisse = 0 WHERE id = 1");
    await p.click('#fenetre-cloture [data-terminer]').catch(() => {}); await p.waitForTimeout(500);
  }
  // 8. Salle : Aline (serveuse) prend la commande de la table T4, précisions, allergie, envoi en deux temps
  if (faire('salle')) {
    await entrer(p, 'Aline Mukeba', '271828');
    await o.ajouter(p, ['Tilapia grillé du lac', 'Brochettes de chèvre', 'Primus|Grande', 'Primus|Grande']);
    await p.click('#lignes .ligne__texte--options:has-text("Tilapia")'); await p.waitForSelector('#ligne-fenetre[open]');
    await p.click('#ligne-remarques .puce:has-text("Bien cuit")').catch(() => {});
    await p.fill('#ligne-allergie', 'arachides');
    await shot(p, 'caisse-precisions', { sel: '#ligne-fenetre' });
    await p.click('#ligne-valider'); await p.waitForTimeout(200);
    await p.click('#lignes .ligne__texte--options:has-text("Brochettes")'); await p.waitForSelector('#ligne-fenetre[open]');
    await p.check('#ligne-temps').catch(() => {}); await p.click('#ligne-valider'); await p.waitForTimeout(200);
    await remonter(p);
    await shot(p, 'caisse-note-nouvelle', { sel: '#ticket' });
    await p.click('.actions-note__envoyer'); await p.waitForSelector('#salle-fenetre[open]'); await p.waitForTimeout(400);
    await shot(p, 'caisse-envoyer-table', { sel: '#salle-fenetre' });
    await p.click('#plan .table-plan:has-text("T4")'); await p.waitForTimeout(2500);
    if (!(await p.locator('#qui').count())) await verrouiller(p);
    // Esther reprend la note de T4
    await entrer(p, 'Esther Kahindo', '314159');
    await p.click('#salle'); await p.waitForSelector('#salle-fenetre[open]'); await p.waitForTimeout(500);
    await shot(p, 'caisse-salle-plan');
    await p.click('#plan .table-plan:has-text("T4")'); await p.waitForTimeout(1200);
    await remonter(p);
    await shot(p, 'caisse-note-ticket', { sel: '#ticket' });
    await p.click('#note-lignes .note-ligne__annuler >> nth=0'); await p.waitForSelector('#annuler-fenetre[open]').catch(() => {});
    await shot(p, 'caisse-annuler-ligne', { sel: '#annuler-fenetre' });
    await p.keyboard.press('Escape'); await p.waitForTimeout(200);
    await p.click('#actions-note button:has-text("Partager")'); await p.waitForSelector('#partage-fenetre[open]').catch(() => {});
    await p.waitForTimeout(300);
    await p.click('#partage-lignes .pas__bouton:has-text("+") >> nth=0'); await p.click('#partage-lignes .pas__bouton:has-text("+") >> nth=2'); await p.waitForTimeout(300);
    await grande(p, 'caisse-partage-articles', '#partage-fenetre');
    await p.click('#partage-modes button >> nth=1'); await p.waitForTimeout(300);
    await p.click('#partage-nombres button:has-text("3")').catch(() => {}); await p.waitForTimeout(300);
    await grande(p, 'caisse-partage-egal', '#partage-fenetre');
    await p.keyboard.press('Escape'); await p.waitForTimeout(200);
    await p.click('.destination__quitter').catch(() => console.log('pas de retour au comptoir')); await p.waitForTimeout(300);
  }
  // 9. Hors ligne : la vente attend sur l'appareil, reçu provisoire
  if (faire('horsligne')) {
    await entrer(p, 'Esther Kahindo', '314159');
    await p.evaluate(() => navigator.serviceWorker.ready);
    for (let i = 0; i < 40 && !(await p.evaluate(() => !!navigator.serviceWorker.controller)); i++) await p.waitForTimeout(150);
    await ctx.setOffline(true); await p.waitForTimeout(1500);
    await o.ajouter(p, ['Mojito', 'Kivu Sunset']);
    await p.click('#encaisser'); await p.waitForSelector('#paiement[open]'); await p.waitForTimeout(250);
    await p.click('#paiement-valider');
    await p.waitForSelector('#paiement-fin:not([hidden])', { timeout: 30000 }); await p.waitForTimeout(600);
    await shot(p, 'caisse-hors-ligne-recu', { sel: '#paiement' });
    await p.click('#paiement-suivant'); await p.waitForTimeout(2500);
    await shot(p, 'caisse-hors-ligne-verrou');
    await ctx.setOffline(false); await p.waitForTimeout(12000);
  }
  o.bilan(); await o.fermer();
})();
