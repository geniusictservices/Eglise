// Exploration : caisse partagée, PIN sans réseau, verrouillage après encaissement, changement de personne
const { chromium } = require('playwright');
const c = require('./commun');
(async () => {
  c.restaurer();
  const b = await chromium.launch();
  const ctx = await b.newContext({ viewport: { width: 1366, height: 860 } });
  const err = [];
  const p = await c.partager(ctx, 'comptoir-du-lac', 'Patrick Kambale', '482913', err);
  console.log('sw', await c.swPret(p));
  await p.waitForTimeout(2500);
  console.log('verifs', await p.evaluate(() => window.RbpHorsLigne.tous('verificateurs').then(l => l.map(x => x.uid + ':' + x.cleId))));
  console.log('attente', await p.evaluate(() => window.RbpHorsLigne.tous('attente').then(l => l.map(x => x.uid))));
  // Verrouiller, Esther entre (en ligne)
  await p.click('#quitter'); await Promise.all([p.waitForNavigation(), p.click('#verrouiller')]);
  await c.pinCaisse(p, 'Esther Kahindo', '314159');
  await p.waitForTimeout(2500);
  console.log('verifs', await p.evaluate(() => window.RbpHorsLigne.tous('verificateurs').then(l => l.map(x => x.uid + ':' + x.cleId))));
  await p.click('#quitter'); await Promise.all([p.waitForNavigation(), p.click('#verrouiller')]);
  await p.waitForTimeout(500);
  // Hors ligne : Aline entre avec son PIN
  await ctx.setOffline(true);
  await p.reload(); await p.waitForSelector('#qui');
  console.log('verrou depuis cache', await p.evaluate(() => !!document.querySelector('meta[name="rbp-hors-ligne"]')));
  await p.waitForTimeout(800);
  console.log('indispo', await p.evaluate(() => [...document.querySelectorAll('.verrou__personne')].filter(a => a.dataset.hlIndisponible).map(a => a.dataset.nom)));
  const t0 = Date.now();
  await c.pinCaisse(p, 'Patrick Kambale', '482913');
  console.log('pin hors ligne ms', Date.now() - t0, 'url', p.url());
  console.log('personne', (await c.donnees(p)).moi, await p.textContent('#hl-etat'));
  await c.ajouter(p, ['Primus|Grande']);
  await c.payer(p);
  console.log('suivant', await p.textContent('#paiement-suivant'));
  await Promise.all([p.waitForNavigation(), p.click('#paiement-suivant')]);
  await p.waitForSelector('#qui');
  console.log('verrouillé après vente', p.url());
  // Esther : 2 PIN faux puis le bon
  await p.click('.verrou__personne[data-nom="Esther Kahindo"]');
  for (const ch of '111111') { await p.click(`#pave-touches [data-chiffre="${ch}"]`); }
  await p.waitForLoadState('load'); await p.waitForTimeout(800);
  console.log('après faux', p.url(), await p.textContent('#pave-aide'));
  await c.pinCaisse(p, 'Esther Kahindo', '314159');
  console.log('Esther', (await c.donnees(p)).moi);
  await c.ajouter(p, ['Kivu Sunset']);
  await c.payer(p, { devise: 'USD' });
  await p.screenshot({ path: c.S + 'x-explore2.png' });
  await Promise.all([p.waitForNavigation(), p.click('#paiement-suivant')]);
  console.log('file', (await c.fileHl(p)).map(i => i.numero + ' ' + i.vendeur + ' ' + i.statut + ' sig=' + !!i.signature));
  console.log('evenements', await p.evaluate(() => window.RbpHorsLigne.etat('evenements')));
  await ctx.setOffline(false);
  await p.waitForTimeout(9000);
  console.log('url', p.url(), 'meta', await p.evaluate(() => !!document.querySelector('meta[name="rbp-hors-ligne"]')));
  console.log('file après', (await c.fileHl(p)).map(i => i.numero + ' ' + i.statut + ' ' + (i.message || '')));
  console.log(c.sql("SELECT id, numero, utilisateur_id, appareil_id, hors_ligne, heure_appareil, numero_provisoire, notes_hors_ligne, ecart_hors_ligne FROM ventes ORDER BY id DESC LIMIT 3"));
  console.log(c.sql("SELECT action, cible, utilisateur_id, details FROM journal ORDER BY id DESC LIMIT 8"));
  console.log(c.sql("SELECT id, pin_essais FROM utilisateurs WHERE id IN (4,5)"));
  console.log('erreurs', err);
  await b.close();
})();
