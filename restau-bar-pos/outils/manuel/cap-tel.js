// Captures de la caisse sur téléphone (Le Comptoir du Lac) et de l'écran de cuisine
const o = require('./outils');
const { shot, aller, pret } = o;
const { partager, autoriser, entrer, remonter } = require('./caisse-outils');
(async () => {
  await o.lancer();
  // Téléphone d'un serveur, autorisé comme caisse partagée
  const ctx = await o.contexte(o.TEL);
  const p = await partager(ctx, 'comptoir-du-lac', 'Téléphone de salle');
  await autoriser(p);
  await shot(p, 'caisse-verrou-tel');
  await p.click('.verrou__personne[data-nom="Aline Mukeba"]'); await p.waitForTimeout(300);
  await shot(p, 'caisse-pin-tel');
  await p.reload(); await p.waitForSelector('#qui'); await pret(p);
  await entrer(p, 'Aline Mukeba', '271828');
  await o.ajouter(p, ['Mützig|Grande', 'Brochettes de chèvre', 'Frites']);
  await remonter(p);
  await shot(p, 'caisse-grille-tel');
  await p.click('#resume'); await p.waitForTimeout(400);
  await shot(p, 'caisse-ticket-tel');
  await ctx.close();
  // Écran de cuisine (gestion connectée, tous les postes)
  const c2 = await o.contexte(o.ORDI);
  const k = await o.connecter(c2);
  await aller(k, '/caisse/cuisine?e=comptoir-du-lac'); await k.waitForTimeout(1500);
  await shot(k, 'cuisine-ecran-ordi');
  await k.click('button:has-text("Ruptures")'); await k.waitForTimeout(600);
  await shot(k, 'cuisine-ruptures', { sel: 'dialog[open]' });
  await c2.close();
  o.bilan(); await o.fermer();
})();
