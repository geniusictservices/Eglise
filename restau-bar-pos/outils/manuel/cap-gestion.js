// Captures « Dans la gestion » (thème jour), vitrine, pointage par code QR
const o = require('./outils');
const { shot, aller, pret } = o;
// [nom, adresse, options] — options : vers (titre vers lequel défiler), sel (élément), hauteur (fenêtre plus haute),
// avant (action avant la capture)
const ORDI = [
  ['modules-ordi', '/gestion'],
  ['compte-ordi', '/gestion/compte'],
  ['etablissements-ordi', '/gestion/etablissements'],
  ['etablissement-fiche-ordi', '/gestion/etablissements/1'],
  ['etablissement-prix', '/gestion/etablissements/1', { sel: '#prix' }],
  ['etablissement-horaires', '/gestion/etablissements/2', { sel: '#horaires' }],
  ['etablissement-zones', '/gestion/etablissements/2', { sel: '#zones' }],
  ['appareils-ordi', '/gestion/utilisateurs/appareils', { hauteur: 1100 }],
  ['catalogue-ordi', '/gestion/catalogue'],
  ['produit-fiche-ordi', '/gestion/catalogue/produits/1'],
  ['produit-tailles', '/gestion/catalogue/produits/1', { sel: '#tailles' }],
  ['produit-prix', '/gestion/catalogue/produits/12', { depuis: '#prix', dans: '#formats', h: 760 }],
  ['categories-ordi', '/gestion/catalogue/categories'],
  ['grilles-ordi', '/gestion/grilles'],
  ['mediatheque-ordi', '/gestion/mediatheque'],
  ['qr-ordi', '/gestion/qr'],
  ['qr-chevalet', '/gestion/qr/volc-vip/imprimer', { sel: '.chevalet' }],
  ['devises-ordi', '/gestion/devises', { hauteur: 1000 }],
  ['utilisateurs-ordi', '/gestion/utilisateurs'],
  ['utilisateur-fiche-ordi', '/gestion/utilisateurs/2', { hauteur: 1200 }],
  ['roles-ordi', '/gestion/utilisateurs/roles'],
  ['role-fiche-ordi', '/gestion/utilisateurs/roles/3'],
  ['journal-ordi', '/gestion/utilisateurs/journal'],
  ['ventes-ordi', '/gestion/ventes'],
  ['ventes-hors-ligne-ordi', '/gestion/ventes?hors_ligne=1&periode=tout', { hauteur: 1250 }],
  ['vente-fiche-ordi', '/gestion/ventes/1500', { hauteur: 1200 }],
  ['vente-annuler', '/gestion/ventes/1500', { vers: 'Annuler la vente' }],
  ['sessions-ordi', '/gestion/sessions-caisse'],
  ['session-fiche-ordi', '/gestion/sessions-caisse/1', { hauteur: 1100 }],
  ['remises-ordi', '/gestion/remises'],
  ['remises-limites', '/gestion/remises', { vers: 'Limite de remise' }],
  ['fidelite-ordi', '/gestion/fidelite'],
  ['clients-ordi', '/gestion/clients'],
  ['client-fiche-ordi', '/gestion/clients/1', { hauteur: 1200 }],
  ['client-modifier-ordi', '/gestion/clients/1/modifier', { hauteur: 1200 }],
  ['dettes-ordi', '/gestion/dettes'],
  ['dette-fiche-ordi', '/gestion/dettes/3', { hauteur: 1400 }],
  ['remboursements-ordi', '/gestion/remboursements'],
  ['remboursement-nouveau-ordi', '/gestion/remboursements/nouveau?client=14'],
  ['messagerie-ordi', '/gestion/messagerie', { hauteur: 1100 }],
  ['messagerie-journal-ordi', '/gestion/messagerie/journal'],
  ['creances-ordi', '/gestion/rapports/creances'],
  ['tresorerie-ordi', '/gestion/tresorerie'],
  ['compte-tresorerie-ordi', '/gestion/tresorerie/comptes/1'],
  ['transferts-tresorerie-ordi', '/gestion/tresorerie/transferts'],
  ['depenses-ordi', '/gestion/depenses'],
  ['depense-nouvelle-ordi', '/gestion/depenses/nouvelle'],
  ['stock-ordi', '/gestion/stock'],
  ['stock-mouvements-ordi', '/gestion/stock/mouvements'],
  ['stock-transferts-ordi', '/gestion/stock/transferts'],
  ['stock-transfert-fiche-ordi', '/gestion/stock/transferts/1'],
  ['inventaires-ordi', '/gestion/stock/inventaires'],
  ['inventaire-fiche-ordi', '/gestion/stock/inventaires/1'],
  ['pertes-ordi', '/gestion/stock/pertes'],
  ['recettes-ordi', '/gestion/stock/recettes'],
  ['recette-fiche-ordi', '/gestion/stock/recettes/8'],
  ['productions-ordi', '/gestion/stock/productions'],
  ['peremptions-ordi', '/gestion/stock/peremptions'],
  ['emballages-ordi', '/gestion/stock/emballages'],
  ['lieux-ordi', '/gestion/stock/lieux'],
  ['lieux-reglages', '/gestion/stock/lieux', { vers: 'Réglages du stock' }],
  ['suggestions-ordi', '/gestion/stock/suggestions'],
  ['fournisseurs-ordi', '/gestion/achats/fournisseurs'],
  ['fournisseur-fiche-ordi', '/gestion/achats/fournisseurs/1'],
  ['commandes-ordi', '/gestion/achats/commandes'],
  ['commande-fiche-ordi', '/gestion/achats/commandes/1', { hauteur: 1100 }],
  ['receptions-ordi', '/gestion/achats/receptions'],
  ['reception-nouvelle-ordi', '/gestion/achats/receptions/nouvelle', { hauteur: 1300 }],
  ['dettes-fournisseurs-ordi', '/gestion/achats/dettes'],
  ['prix-achat-ordi', '/gestion/achats/prix'],
  ['marche-ordi', '/gestion/achats/marche', { hauteur: 1100 }],
  ['gros-ordi', '/gestion/gros'],
  ['gros-nouvelle-ordi', '/gestion/gros/nouvelle', { hauteur: 1300 }],
  ['employes-ordi', '/gestion/personnel/employes'],
  ['employe-fiche-ordi', '/gestion/personnel/employes/1', { hauteur: 1200 }],
  ['presence-ordi', '/gestion/personnel/presence'],
  ['pointages-ordi', '/gestion/personnel/presence/pointages'],
  ['presence-reglages-ordi', '/gestion/personnel/presence/reglages'],
  ['plannings-ordi', '/gestion/personnel/plannings'],
  ['conges-ordi', '/gestion/personnel/conges'],
  ['paie-ordi', '/gestion/personnel/paie', { hauteur: 1100 }],
  ['bulletin-ordi', '/gestion/personnel/paie/2', { hauteur: 1300 }],
  ['masse-salariale-ordi', '/gestion/personnel/paie/masse'],
  ['avances-ordi', '/gestion/personnel/avances'],
  ['salle-plan-ordi', '/gestion/salle'],
  ['salle-notes-ordi', '/gestion/salle/notes'],
  ['reservations-ordi', '/gestion/salle/reservations'],
  ['services-ordi', '/gestion/salle/services'],
  ['services-happy', '/gestion/salle/services', { vers: 'Happy hour' }],
  ['cuisine-postes-ordi', '/gestion/cuisine'],
  ['billetterie-ordi', '/gestion/nuit/entrees'],
  ['invites-ordi', '/gestion/nuit/invites'],
  ['promoteurs-ordi', '/gestion/nuit/promoteurs'],
  ['promoteur-fiche-ordi', '/gestion/nuit/promoteurs/1'],
  ['formules-ordi', '/gestion/nuit/formules'],
  ['minimums-ordi', '/gestion/nuit/minimums'],
  ['vestiaire-ordi', '/gestion/nuit/vestiaire'],
  ['cashless-ordi', '/gestion/cashless'],
  ['cashless-carte-ordi', '/gestion/cashless/1'],
  ['cashless-passif-ordi', '/gestion/cashless/passif'],
  ['evenements-ordi', '/gestion/evenements'],
  ['evenement-fiche-ordi', '/gestion/evenements/1', { hauteur: 1300 }],
  ['evenements-rentabilite-ordi', '/gestion/evenements/rentabilite'],
  ['tableau-de-bord-ordi', '/gestion/tableau-de-bord', { hauteur: 1300 }],
  ['rapport-ventes-ordi', '/gestion/rapports/ventes'],
  ['rapport-personnel-ordi', '/gestion/rapports/personnel'],
  ['rapport-presence-ordi', '/gestion/rapports/presence'],
  ['rapport-couts-ordi', '/gestion/rapports/couts'],
  ['export-comptable-ordi', '/gestion/rapports/export-comptable'],
  ['impression-ordi', '/gestion/rapports/ventes?imprimer=1'],
  ['assistance-ordi', '/gestion/assistance'],
];
const TEL = [
  ['modules-tel', '/gestion'],
  ['tresorerie-tel', '/gestion/tresorerie'],
  ['dettes-tel', '/gestion/dettes'],
  ['catalogue-tel', '/gestion/catalogue'],
  ['tableau-de-bord-tel', '/gestion/tableau-de-bord'],
  ['stock-tel', '/gestion/stock'],
];
async function capturer(p, [nom, url, opt = {}]) {
  await aller(p, url);
  if (opt.hauteur) await p.setViewportSize({ width: p.viewportSize().width, height: opt.hauteur });
  if (opt.vers) {
    const h = p.locator(`h2:has-text("${opt.vers}"), h3:has-text("${opt.vers}"), summary:has-text("${opt.vers}")`).first();
    if (await h.count()) await h.evaluate(e => window.scrollTo(0, e.getBoundingClientRect().top + scrollY - 90)); else o.manquees.push(nom + ' : titre ' + opt.vers);
  }
  if (opt.depuis) {
    // Une partie d'une section : du titre « depuis », sur la largeur de la section « dans », sur h pixels
    await p.evaluate(() => document.querySelectorAll('body *').forEach(e => { if (getComputedStyle(e).position === 'sticky') e.style.position = 'static'; }));
    const t = await p.locator(opt.depuis).first().evaluate(e => { const r = e.getBoundingClientRect(); return { y: r.top + scrollY }; });
    const d = await p.locator(opt.dans).first().evaluate(e => { const r = e.getBoundingClientRect(); return { x: r.left + scrollX, w: r.width }; });
    await shot(p, nom, { pleine: true, clip: { x: d.x, y: t.y - 12, width: d.w, height: opt.h } });
  } else await shot(p, nom, opt.sel ? { sel: opt.sel } : {});
  if (opt.hauteur) await p.setViewportSize({ width: p.viewportSize().width, height: 860 });
}
(async () => {
  await o.lancer();
  const seul = process.argv.slice(2);
  const garder = n => !seul.length || seul.some(s => n.includes(s));
  // Connexion (déconnecté)
  let ctx = await o.contexte(o.ORDI);
  let p = await o.page(ctx);
  if (garder('connexion')) { await aller(p, '/gestion/connexion'); await shot(p, 'connexion-ordi'); }
  if (garder('vitrine')) {
    await aller(p, '/'); await shot(p, 'vitrine-accueil-ordi');
    await aller(p, '/e/comptoir-du-lac'); await shot(p, 'vitrine-restaurant-ordi');
  }
  await ctx.close();
  // Administrateur, ordinateur
  ctx = await o.contexte(o.ORDI);
  p = await o.connecter(ctx);
  for (const c of ORDI) if (garder(c[0])) await capturer(p, c);
  // Borne de pointage : autoriser cet appareil, afficher le code QR
  let lien = null;
  if (garder('pointage')) {
    await aller(p, '/gestion/personnel/presence/bornes');
    await p.selectOption('#borne-etab', '1').catch(() => {});
    await Promise.all([p.waitForNavigation(), p.click('form[action="/gestion/personnel/presence/bornes"] button[type=submit]')]);
    await p.waitForTimeout(1200); await shot(p, 'pointage-borne-ordi');
    // L'appareil devenu borne n'est plus connecté à la gestion : on se reconnecte pour voir la liste des bornes
    const g = await o.connecter(ctx); await aller(g, '/gestion/personnel/presence/bornes'); await shot(g, 'bornes-ordi');
    lien = (await (await p.request.get(o.B + '/pointage/borne/jeton')).json()).url;
  }
  await ctx.close();
  // Garanties du gérant, espace du promoteur
  if (garder('garanties')) {
    ctx = await o.contexte(o.ORDI); p = await o.connecter(ctx, 'patrick@demo.test', 'Gerant-Goma-2026');
    await aller(p, '/gestion'); await shot(p, 'modules-garantie-ordi', { clip: { x: 0, y: 0, width: 1366, height: 420 } });
    await aller(p, '/gestion/garanties'); await shot(p, 'garanties-ordi');
    await ctx.close();
  }
  if (garder('promoteur')) {
    ctx = await o.contexte(o.ORDI); p = await o.connecter(ctx, 'kevin@demo.test', 'Promo-Goma-2026');
    await aller(p, '/gestion/nuit/mon-espace'); await shot(p, 'promoteur-espace-ordi');
    await ctx.close();
  }
  // Téléphone
  ctx = await o.contexte(o.TEL);
  p = await o.page(ctx);
  if (garder('connexion')) { await aller(p, '/gestion/connexion'); await shot(p, 'connexion-tel'); }
  if (garder('vitrine')) {
    await aller(p, '/e/comptoir-du-lac'); await shot(p, 'vitrine-restaurant-tel');
    await p.fill('#recherche', 'tilapia'); await p.locator('#recherche').scrollIntoViewIfNeeded(); await p.waitForTimeout(300); await shot(p, 'vitrine-recherche-tel');
  }
  await ctx.close();
  if (garder('vitrine')) {
    ctx = await o.contexte(o.TEL, true); p = await o.page(ctx);
    await aller(p, '/e/volcano-club?zone=vip&devise=CDF'); await shot(p, 'vitrine-club-tel');
    await p.evaluate(() => { const s = document.querySelector('section.categorie'); if (s) window.scrollTo(0, s.getBoundingClientRect().top + scrollY - 120); });
    await shot(p, 'vitrine-club-menu-tel');
    await ctx.close();
  }
  ctx = await o.contexte(o.TEL);
  p = await o.connecter(ctx);
  for (const c of TEL) if (garder(c[0])) await capturer(p, c);
  await ctx.close();
  // Pointage depuis le téléphone de l'employé (au Comptoir du Lac)
  if (lien) {
    ctx = await o.contexte(o.TEL);
    await ctx.setGeolocation({ latitude: -1.69125, longitude: 29.23462 });
    p = await o.page(ctx);
    await p.goto(lien.replace(/^https?:\/\/[^/]+/, o.B)); await pret(p);
    await p.fill('#telephone', '+243810000101').catch(() => {}); await p.fill('#pin', '482913').catch(() => {});
    await shot(p, 'pointer-identification-tel');
    await Promise.all([p.waitForNavigation().catch(() => {}), p.click('button[type=submit]')]); await p.waitForTimeout(1500); await pret(p);
    await shot(p, 'pointer-tel');
    const bouton = p.locator('button:has-text("Pointer")').first();
    if (await bouton.count()) { await Promise.all([p.waitForNavigation().catch(() => {}), bouton.click()]); await p.waitForTimeout(2000); await pret(p); await shot(p, 'pointer-fait-tel'); }
    await ctx.close();
  }
  o.bilan(); await o.fermer();
})();
