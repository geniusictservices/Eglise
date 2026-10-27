const { chromium } = require('playwright'); const fs = require('fs'); const { execSync } = require('child_process'); const crypto = require('crypto');
const B = 'http://integ.localhost:8250'; const MDP = fs.readFileSync('/home/user/Eglise/restau-bar-pos/outils/tests/instance.txt', 'utf8').match(/Mot de passe\s*:\s*(\S+)/)[1];
const sql = q => execSync(`mysql -u root -N --default-character-set=utf8mb4 rbp_integ -e ${JSON.stringify("SET time_zone = '+02:00'; " + q)}`).toString().trim();
let nb = 0; const ko = []; const v = (c, m) => { nb++; if (!c) ko.push(m); console.log((c ? 'ok    ' : 'ÉCHEC ') + m); };
(async () => {
  const b = await chromium.launch(); const ctx = await b.newContext(); const p = await ctx.newPage();
  await p.goto(B + '/gestion/connexion'); await p.fill('#email', 'admin@demo.test'); await p.fill('#mot_de_passe', MDP); await Promise.all([p.waitForNavigation(), p.click('button[type=submit]')]);
  await p.goto(B + '/caisse?e=comptoir-du-lac');
  const D = await p.evaluate(() => JSON.parse(document.getElementById('donnees-caisse').textContent));
  const csrf = await p.locator('input[name=csrf]').first().inputValue();
  const fmt = n => { for (const pr of D.produits) for (const f of pr.formats) if ((pr.nom + ' ' + f.taille).trim().startsWith(n)) return f; };
  const grande = fmt('Primus Grande'), kivu = fmt('Kivu Sunset');
  const compte = (type, d) => D.comptes.find(c => c.type === type && c.devises.includes(d));
  console.log('comptes', D.comptes.map(c => c.nom + ':' + c.type + ':' + c.devises.join('+')).join(', '), '| encaisser', D.encaisser);
  const envoyer = (vente, jeton = csrf) => p.request.post(B + '/caisse/encaisser', { form: { csrf: jeton, vente: JSON.stringify(vente) } });
  // 2 Primus Grande (5 500) + Kivu (22 800) = 33 800 FC ; $ : 2×2 + 8 = 12 $
  const lignes = [{ id: grande.id, qte: 2 }, { id: kivu.id, qte: 1 }];
  const soldeAvant = Number(sql(`SELECT COALESCE(SUM(IF(sens='entree',montant,-montant)),0) FROM mouvements_tresorerie WHERE compte_id=${compte('caisse', 'CDF').id} AND devise_id=(SELECT id FROM devises WHERE code='CDF')`));
  const cle = crypto.randomUUID();
  let r = await envoyer({ cle, etab: 'comptoir-du-lac', lignes, paiements: [{ devise: 'USD', compte: compte('caisse', 'USD').id, recu: 5 }, { devise: 'CDF', compte: compte('mobile_money', 'CDF').id, recu: 20000 }] });
  let j = await r.json();
  v(r.status() === 200 && /^CDL-\d{6}$/.test(j.numero), 'vente 5 $ + 20 000 FC (mobile money) enregistrée : ' + j.numero + ' ' + (j.erreur || ''));
  const id = sql(`SELECT id FROM ventes WHERE numero='${j.numero}'`);
  v(sql(`SELECT CONCAT(total,'|',total_reference) FROM ventes WHERE id=${id}`) === '33800.000|12.000', 'totaux : 33 800 FC et 12 $');
  v(sql(`SELECT COUNT(*) FROM vente_lignes WHERE vente_id=${id}`) === '2' && sql(`SELECT libelle FROM vente_lignes WHERE vente_id=${id} ORDER BY id LIMIT 1`) === 'Primus Grande 72 cl', 'lignes avec leur nom complet');
  v(sql(`SELECT COUNT(*) FROM mouvements_tresorerie WHERE nature='vente' AND sens='entree' AND source_id=${id}`) === '2', 'deux entrées en trésorerie (Caisse USD, M-Pesa CDF)');
  v(sql(`SELECT CONCAT(rendu,'|',rendu_devise_id) FROM ventes WHERE id=${id}`) === '200.000|2', '5 $ + 20 000 FC dépassent un peu (41,7 % + 59,2 %) : 200 FC rendus');
  v(j.recu.includes('33 800 FC') && j.recu.includes('12 $') || j.recu.includes('$12'), 'reçu : total en FC puis en $');
  r = await envoyer({ cle, etab: 'comptoir-du-lac', lignes, paiements: [{ devise: 'CDF', compte: compte('caisse', 'CDF').id, recu: 33800 }] });
  v((await r.json()).numero === j.numero && sql(`SELECT COUNT(*) FROM ventes WHERE cle='${cle}'`) === '1', 'double envoi : la même vente, pas deux');
  r = await envoyer({ cle: crypto.randomUUID(), etab: 'comptoir-du-lac', lignes, paiements: [{ devise: 'CDF', compte: compte('caisse', 'CDF').id, recu: 30000 }] });
  j = await r.json(); v(r.status() === 422 && /3\s800\sFC/.test(j.erreur), 'paiement insuffisant refusé : ' + j.erreur);
  // Compte qui ne tient pas le FC (s'il y en a un), puis compte d'un autre établissement
  const sansFC = Number(sql(`SELECT c.id FROM comptes_tresorerie c WHERE c.active=1 AND (c.etablissement_id IS NULL OR c.etablissement_id=(SELECT id FROM etablissements WHERE slug='comptoir-du-lac')) AND NOT EXISTS (SELECT 1 FROM compte_devises cd JOIN devises d ON d.id=cd.devise_id WHERE cd.compte_id=c.id AND d.code='CDF') LIMIT 1`) || 0);
  if (sansFC) {
    r = await envoyer({ cle: crypto.randomUUID(), etab: 'comptoir-du-lac', lignes, paiements: [{ devise: 'CDF', compte: sansFC, recu: 40000 }] });
    v(r.status() === 422, 'compte qui ne tient pas le FC refusé');
  }
  const ailleurs = Number(sql(`SELECT id FROM comptes_tresorerie WHERE etablissement_id IS NOT NULL AND etablissement_id<>(SELECT id FROM etablissements WHERE slug='comptoir-du-lac') LIMIT 1`) || 0);
  v(ailleurs > 0 && !D.comptes.some(c => c.id === ailleurs), 'les comptes d\'un autre établissement ne sont pas proposés');
  r = await envoyer({ cle: crypto.randomUUID(), etab: 'comptoir-du-lac', lignes, paiements: [{ devise: 'CDF', compte: ailleurs, recu: 40000 }] });
  v(r.status() === 422, 'compte d\'un autre établissement refusé');
  v(sql(`SELECT GROUP_CONCAT(DISTINCT moyen) FROM vente_paiements WHERE vente_id=${id}`).split(',').sort().join(',') === 'caisse,mobile_money', 'moyen enregistré = type du compte (caisse, mobile_money)');
  r = await envoyer({ cle: crypto.randomUUID(), etab: 'comptoir-du-lac', lignes: [{ id: grande.id, qte: 2, prix: 1 }], paiements: [{ devise: 'CDF', compte: compte('caisse', 'CDF').id, recu: 50000 }] });
  j = await r.json();
  const id2 = sql(`SELECT id FROM ventes WHERE numero='${j.numero}'`);
  v(r.status() === 200 && sql(`SELECT CONCAT(total,'|',rendu,'|',rendu_devise_id) FROM ventes WHERE id=${id2}`) === '11000.000|39000.000|2', 'prix envoyé par le navigateur ignoré ; monnaie rendue 39 000 FC');
  const soldeApres = Number(sql(`SELECT COALESCE(SUM(IF(sens='entree',montant,-montant)),0) FROM mouvements_tresorerie WHERE compte_id=${compte('caisse', 'CDF').id} AND devise_id=(SELECT id FROM devises WHERE code='CDF')`));
  v(soldeApres - soldeAvant === 10800, 'caisse CDF : −200 (vente 1) +50 000 −39 000 = +10 800 FC');
  r = await envoyer({ cle: crypto.randomUUID(), etab: 'comptoir-du-lac', lignes, paiements: [{ devise: 'USD', compte: compte('caisse', 'USD').id, recu: 20 }], rendu: 'USD' });
  j = await r.json(); const id3 = sql(`SELECT id FROM ventes WHERE numero='${j.numero}'`);
  v(sql(`SELECT CONCAT(rendu,'|',rendu_devise_id) FROM ventes WHERE id=${id3}`) === '8.000|1', 'payé 20 $, monnaie en $ : 8 $');
  r = await envoyer({ cle: crypto.randomUUID(), etab: 'comptoir-du-lac', lignes, paiements: [{ devise: 'CDF', compte: compte('caisse', 'CDF').id, recu: 33800 }] }, 'mauvais');
  v(r.status() === 419, 'sans jeton : 419');
  r = await envoyer({ cle: crypto.randomUUID(), etab: 'comptoir-du-lac', lignes: [], paiements: [] });
  v(r.status() === 422, 'ticket vide refusé');
  // Serveuse (sans « encaisser ») sur une caisse partagée
  const t = await b.newContext(); const tp = await t.newPage();
  await tp.goto(B + '/gestion/connexion'); await tp.fill('#email', 'admin@demo.test'); await tp.fill('#mot_de_passe', MDP); await Promise.all([tp.waitForNavigation(), tp.click('button[type=submit]')]);
  await tp.goto(B + '/caisse?e=comptoir-du-lac'); await tp.click('#quitter'); await tp.click('.partager > summary:has-text("caisse partagée")');
  await Promise.all([tp.waitForNavigation(), tp.click('.partager__form button[type=submit]')]);
  await tp.click('.verrou__personne[data-nom="Aline Mukeba"]'); for (const c of '271828') await tp.click(`#pave-touches [data-chiffre="${c}"]`); await tp.waitForLoadState('load'); await tp.waitForTimeout(300);
  const Da = await tp.evaluate(() => JSON.parse(document.getElementById('donnees-caisse').textContent));
  v(Da.encaisser === false, 'serveuse : la caisse sait qu\'elle ne peut pas encaisser');
  r = await tp.request.post(B + '/caisse/encaisser', { form: { csrf: await tp.locator('input[name=csrf]').first().inputValue(), vente: JSON.stringify({ cle: crypto.randomUUID(), lignes, paiements: [{ devise: 'CDF', compte: compte('caisse', 'CDF').id, recu: 33800 }] }) } });
  v(r.status() === 403, 'serveuse : encaissement refusé (403)');
  console.log(`\n${nb - ko.length}/${nb}`); await b.close();
})();
