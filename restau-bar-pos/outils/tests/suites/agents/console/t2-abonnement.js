// Rattacher une instance, formules, paiements → échéance, statuts et leurs effets dans l'instance (jamais en plein service),
// modules masqués, limites, cache de la licence, console injoignable
const { chromium } = require('playwright');
const crypto = require('crypto');
const { execSync } = require('child_process');
const { v, q, php, A, D, connecterConsole, connecterInstance, bilan, surveiller } = require('./outils');
const I = 'http://cnslbar.localhost:8180';
const C = 'rbp_console_essai', B = 'rbp_cnslbar';
const jour = n => execSync(`TZ=Africa/Lubumbashi date -d '${n} days' +%F`).toString().trim();   // fuseau de Goma
const oublierCache = () => q(B, "DELETE FROM parametres WHERE cle='licence.verifiee_le'");
(async () => {
  const b = await chromium.launch();
  const erreurs = [];
  const hash = execSync(`php -r 'echo password_hash("Patron-Bar-2026", PASSWORD_DEFAULT);'`).toString();
  q(B, `UPDATE utilisateurs SET mot_de_passe='${hash}' WHERE id=1`);
  q(B, "DELETE FROM parametres WHERE cle LIKE 'licence.%'");
  const c = await b.newContext(); const p = await c.newPage(); surveiller(p, erreurs);
  await connecterConsole(p);

  // --- Formule créée dans la console ---
  await p.goto(A + '/formules/nouvelle');
  await p.fill('#nom', 'Essai limites');
  for (const m of await p.locator('input[name="modules[]"]').all()) await m.setChecked(['caisse', 'ventes', 'carte', 'rapports', 'finance'].includes(await m.getAttribute('value')));
  await p.fill('#max_etablissements', '5'); await p.fill('#max_utilisateurs', '50');
  await p.fill('#prix_mois', 'abc');
  await p.click('main button[type=submit]');
  v(await p.locator('#err-prix_mois').count(), 1, 'formule : prix invalide refusé');
  await p.fill('#prix_mois', '30'); await p.fill('#prix_trimestre', '80'); await p.fill('#prix_annee', '');
  await Promise.all([p.waitForNavigation(), p.click('main button[type=submit]')]);
  v(await p.locator('[data-formule="Essai limites"]').count(), 1, 'formule créée et listée');
  const fid = q(C, "SELECT id FROM formules WHERE nom='Essai limites'");
  v(q(C, `SELECT modules FROM formules WHERE id=${fid}`), '["caisse","ventes","finance","rapports","carte"]', 'modules enregistrés dans l\'ordre de Modules::offre()');
  await p.screenshot({ path: D + '/cap/formules-ordi.png', fullPage: true });

  // --- Rattacher l'instance cnslbar (créée en ligne de commande) ---
  await p.goto(A + '/clients');
  v(await p.locator('a[href="/clients/nouveau?instance=cnslbar"]').count(), 1, 'instance non rattachée proposée');
  await p.click('a[href="/clients/nouveau?instance=cnslbar"]');
  v(await p.locator('#sous_domaine').inputValue(), 'cnslbar', 'sous-domaine prérempli');
  await p.fill('#nom', 'Bar Essai Console'); await p.fill('#contact_nom', 'Paul Patron'); await p.fill('#contact_email', 'patron@cnslbar.test');
  await p.fill('#contact_telephone', '+243 81 222 3333');
  await p.selectOption('#formule_id', fid); await p.fill('#essai_jours', '0');
  await Promise.all([p.waitForNavigation(), p.click('main button[type=submit]')]);
  const id = p.url().match(/clients\/(\d+)/)[1];
  v(await p.locator('[data-flash]').textContent(), /rattachée/, 'instance rattachée');
  v(await p.locator('[data-statut]').first().getAttribute('data-statut'), 'a_relancer', 'sans essai : échéance aujourd\'hui → « À relancer »');
  const actifs = Number(q(B, "SELECT COUNT(*) FROM utilisateurs WHERE actif = 1 AND id <> COALESCE((SELECT valeur FROM parametres WHERE cle = 'assistance.utilisateur_id'), 0)"));   // personnes actives de la démo (11 aujourd'hui)
  v(await p.locator('[data-usage="utilisateurs"]').textContent(), new RegExp('^' + actifs + ' '), 'utilisation : ' + actifs + ' personnes actives (nombre seulement)');
  v(await p.locator('[data-usage="etablissements"]').textContent(), /^2/, 'utilisation : 2 établissements actifs');
  const page = await p.content();
  v(/Primus|Kivu|Furaha|ardoise de/.test(page), false, 'confidentialité : aucun produit, client ni dette du bar dans la fiche');

  // --- Paiement → échéance prolongée ---
  await p.fill('#pai-montant', '30'); await p.selectOption('#pai-moyen', 'mobile_money'); await p.fill('#pai-reference', '');
  await p.click('[data-form-paiement] button[type=submit]');
  v(await p.locator('#err-paiement-reference').count(), 1, 'mobile money sans référence refusé');
  await p.fill('#pai-reference', 'MP261026.1200.X1');
  await Promise.all([p.waitForNavigation(), p.click('[data-form-paiement] button[type=submit]')]);
  const auj = q(C, 'SELECT CURDATE()');
  const plus1 = q(C, `SELECT DATE_ADD('${auj}', INTERVAL 1 MONTH)`);
  v(q(C, `SELECT echeance FROM clients WHERE id=${id}`), plus1, 'paiement d\'un mois : échéance + 1 mois');
  v(await p.locator('[data-statut]').first().getAttribute('data-statut'), 'actif', 'statut « Actif »');
  await p.fill('#pai-montant', '220000'); await p.selectOption('#pai-devise', 'CDF'); await p.selectOption('#pai-moyen', 'especes');
  await p.selectOption('#pai-periode', 'trimestre'); await p.fill('#pai-periodes', '2');
  await Promise.all([p.waitForNavigation(), p.click('[data-form-paiement] button[type=submit]')]);
  v(q(C, `SELECT echeance FROM clients WHERE id=${id}`), q(C, `SELECT DATE_ADD('${plus1}', INTERVAL 6 MONTH)`), 'espèces en FC, 2 trimestres : échéance + 6 mois');
  await p.locator('details.con-details summary').first().click();
  await p.fill('[id^=motif-]', 'Saisi deux fois');
  await Promise.all([p.waitForNavigation(), p.click('details.con-details button[type=submit]')]);
  v(q(C, `SELECT echeance FROM clients WHERE id=${id}`), plus1, 'annulation du dernier paiement : échéance d\'avant');
  v(q(C, `SELECT COUNT(*) FROM paiements WHERE client_id=${id} AND annule_le IS NOT NULL`), 1, 'paiement gardé, marqué annulé');
  await p.goto(A + '/');
  v(await p.locator('[data-chiffre="encaisse"]').textContent(), /30/, 'tableau de bord : encaissé ce mois-ci (30 $)');
  v(await p.locator('[data-chiffre="mrr"]').textContent(), /30/, 'tableau de bord : revenu mensuel récurrent');

  // --- Instance : tout permis quand l'abonnement est actif ---
  const ci = await b.newContext(); const pi = await ci.newPage(); surveiller(pi, erreurs);
  await connecterInstance(pi, 'cnslbar', 'patron@cnslbar.test', 'Patron-Bar-2026');
  v(await pi.locator('.plf-bandeau').count(), 0, 'actif : aucun bandeau');
  const noms = await pi.locator('.module__nom').allTextContents();
  v(noms.join('|').includes('Finance') && !noms.join('|').includes('Personnel'), true, 'modules de la formule montrés (Finance), les autres masqués (Personnel)');
  let r = await pi.goto(I + '/gestion/tresorerie');
  v(r.status(), 200, 'écran d\'un module inclus : ouvert');

  // --- Échéance proche : « à relancer », bandeau au propriétaire ---
  q(C, `UPDATE clients SET echeance='${jour(3)}' WHERE id=${id}`); oublierCache();
  await pi.goto(I + '/gestion');
  v(await pi.locator('.plf-bandeau').textContent(), /arrive à échéance/, 'J-3 : bandeau « à échéance » au propriétaire');
  const cg = await b.newContext(); const pg = await cg.newPage();
  await connecterInstance(pg, 'cnslbar', 'patrick@demo.test', 'Gerant-Goma-2026');
  v(await pg.locator('.plf-bandeau').count(), 0, 'J-3 : rien pour le gérant (pas un propriétaire)');

  // --- Grâce ---
  q(C, `UPDATE clients SET echeance='${jour(-3)}' WHERE id=${id}`); oublierCache();
  await pi.goto(I + '/gestion');
  v(await pi.locator('.plf-bandeau').textContent(), /dépassée.*lecture seule/, 'J+3 : bandeau « délai de grâce »');
  await pi.screenshot({ path: D + '/cap/instance-bandeau-grace.png' });
  r = await pi.goto(I + '/caisse?e=comptoir-du-lac');
  v(r.status(), 200, 'grâce : la caisse s\'ouvre encore');

  // --- Caisse ouverte avant la lecture seule (jamais en plein service) ---
  const donnees = await pi.evaluate(() => JSON.parse(document.getElementById('donnees-caisse').textContent));
  const csrf = await pi.locator('input[name=csrf]').first().inputValue();
  const format = donnees.produits[0].formats[0];
  const compte = donnees.comptes.find(k => k.devises.includes('CDF'));
  const vendre = () => pi.evaluate(async ([csrf, vente]) => {
    const r = await fetch('/caisse/encaisser', { method: 'POST', body: new URLSearchParams({ csrf, vente: JSON.stringify(vente) }) });
    return { code: r.status, corps: await r.text() };
  }, [csrf, { cle: crypto.randomUUID(), etab: 'comptoir-du-lac', lignes: [{ id: format.id, qte: 1 }], paiements: [{ devise: 'CDF', compte: compte.id, recu: 1000000 }] }]);
  let vente = await vendre();
  v(vente.code, 200, 'grâce : vente encaissée');
  // Caisse partagée autorisée avant la lecture seule, avec la serveuse connectée par PIN
  const ct = await b.newContext(); const pt = await ct.newPage(); surveiller(pt, erreurs);
  await connecterInstance(pt, 'cnslbar', 'patrick@demo.test', 'Gerant-Goma-2026');
  await pt.goto(I + '/caisse?e=comptoir-du-lac');
  if (await pt.locator('dialog[open]').count()) { await pt.keyboard.press('Escape'); await pt.waitForTimeout(200); }
  await pt.click('#quitter'); await pt.click('.partager > summary');
  await Promise.all([pt.waitForNavigation(), pt.click('.partager__form button[type=submit]')]);
  await pt.click('.verrou__personne[data-nom="Esther Kahindo"]');
  for (const ch of '314159') await pt.click(`#pave-touches [data-chiffre="${ch}"]`);
  await pt.waitForLoadState('load'); await pt.waitForTimeout(400);
  v(await pt.locator('#donnees-caisse').count(), 1, 'grâce : la caissière ouvre la caisse partagée avec son PIN');

  // --- Lecture seule ---
  q(C, `UPDATE clients SET echeance='${jour(-10)}' WHERE id=${id}`);
  await p.goto(A + `/clients/${id}`);
  v(await p.locator('[data-statut]').first().getAttribute('data-statut'), 'lecture_seule', 'console : J+10 → « Lecture seule »');
  await p.screenshot({ path: D + '/cap/fiche-lecture-seule-ordi.png', fullPage: true });
  vente = await vendre();
  v(vente.code, 200, 'lecture seule : la caisse déjà ouverte encaisse encore (jamais en plein service)');
  r = await pi.goto(I + '/caisse?e=comptoir-du-lac');
  v(r.status() + ' ' + await pi.locator('h1').textContent(), '403 La caisse ne peut pas s\'ouvrir', 'lecture seule : recharger /caisse → la caisse ne s\'ouvre plus (licence relue à l\'ouverture)');
  await pi.screenshot({ path: D + '/cap/instance-caisse-fermee.png' });
  // Caisse partagée : la vente en cours passe, puis le verrouillage ; le PIN suivant est refusé
  const dt = await pt.evaluate(() => JSON.parse(document.getElementById('donnees-caisse').textContent));
  const csrfT = await pt.locator('input[name=csrf]').first().inputValue();
  const venteT = await pt.evaluate(async ([csrf, vente]) => {
    const r = await fetch('/caisse/encaisser', { method: 'POST', body: new URLSearchParams({ csrf, vente: JSON.stringify(vente) }) });
    return r.status;
  }, [csrfT, { cle: crypto.randomUUID(), etab: 'comptoir-du-lac', lignes: [{ id: dt.produits[0].formats[0].id, qte: 1 }], paiements: [{ devise: 'CDF', compte: dt.comptes.find(k => k.devises.includes('CDF')).id, recu: 1000000 }] }]);
  v(venteT, 200, 'caisse partagée ouverte : la caissière encaisse encore');
  r = await pt.request.post(I + '/caisse/verrouiller', { form: { csrf: csrfT }, maxRedirects: 0 });
  v(r.status(), 302, 'verrouiller reste possible');
  r = await pt.request.post(I + '/caisse/pin', { form: { csrf: csrfT, utilisateur_id: q(B, "SELECT id FROM utilisateurs WHERE nom='Esther Kahindo'"), pin: '314159' } });
  v(r.status(), 403, 'lecture seule : connexion suivante par PIN refusée (prochaine ouverture)');
  v(/ne peut pas s(&#039;|')ouvrir/.test(await r.text()), true, 'message clair sur l\'écran de la caisse');
  // Gestion en lecture seule (après le délai du cache)
  oublierCache();
  await pi.goto(I + '/gestion');
  v(await pi.locator('.plf-bandeau').textContent(), /Lecture seule/, 'lecture seule : bandeau');
  await pi.screenshot({ path: D + '/cap/instance-lecture-seule.png' });
  v(await pg.goto(I + '/gestion').then(() => pg.locator('.plf-bandeau').textContent()), /Lecture seule/, 'lecture seule : bandeau aussi pour le gérant');
  r = await pi.goto(I + '/gestion/catalogue');
  v(r.status(), 200, 'lecture seule : les données restent visibles');
  const csrfI = await pi.locator('input[name=csrf]').first().inputValue();
  r = await pi.request.post(I + '/gestion/catalogue/categories', { form: { csrf: csrfI, nom_fr: 'Interdite' } });
  v(r.status() + ' ' + /lecture seule/.test(await r.text()), '403 true', 'lecture seule : formulaire de la gestion refusé, avec explication');
  r = await pi.request.post(I + '/gestion/compte', { form: { csrf: csrfI, nom: 'Paul Patron', langue: 'fr' }, maxRedirects: 0 });
  v(r.status(), 302, 'lecture seule : Mon compte reste modifiable');

  // --- Suspension ---
  q(C, `UPDATE clients SET echeance='${jour(-30)}' WHERE id=${id}`); oublierCache();
  r = await pi.goto(I + '/gestion');
  v(r.status() + ' ' + await pi.locator('h1').textContent(), '403 Abonnement suspendu', 'J+30 : suspendu, page d\'explication');
  v(await pi.locator('.plf-contact').count(), 1, 'page de suspension : contact de la plateforme');
  await pi.screenshot({ path: D + '/cap/instance-suspendu.png' });
  r = await pi.goto(I + '/');
  v(r.status() + ' ' + await pi.locator('h1').textContent(), '403 Menu momentanément indisponible', 'vitrine : message neutre (rien sur l\'abonnement)');
  vente = await vendre();
  v(vente.code, 200, 'suspendu : la page de caisse ouverte avant finit son service');
  r = await pi.goto(I + '/caisse?e=comptoir-du-lac');
  v(r.status(), 403, 'suspendu : la caisse ne se rouvre pas');
  v(q(B, 'SELECT COUNT(*) FROM ventes') > 0, true, 'aucune donnée supprimée');

  // --- Réactivation par un paiement ---
  await p.goto(A + `/clients/${id}`);
  await p.fill('#pai-montant', '30'); await p.selectOption('#pai-devise', 'USD'); await p.selectOption('#pai-moyen', 'virement'); await p.fill('#pai-reference', 'RAW-778899');
  await p.selectOption('#pai-periode', 'mois');
  await Promise.all([p.waitForNavigation(), p.click('[data-form-paiement] button[type=submit]')]);
  v(q(C, `SELECT echeance FROM clients WHERE id=${id}`), q(C, `SELECT DATE_ADD(CURDATE(), INTERVAL 1 MONTH)`), 'paiement après suspension : échéance repart d\'aujourd\'hui');
  r = await pi.goto(I + '/caisse?e=comptoir-du-lac');
  v(r.status(), 200, 'réactivé : la caisse s\'ouvre de nouveau');

  // --- Statut à la main ---
  await p.selectOption('#statut-action', 'suspendre');
  await p.click('#statut button[type=submit]');
  v(await p.locator('#err-statut-motif').count(), 1, 'suspendre à la main : motif obligatoire');
  await p.selectOption('#statut-action', 'suspendre'); await p.fill('#statut-motif', 'Chèque sans provision');
  await Promise.all([p.waitForNavigation(), p.click('#statut button[type=submit]')]);
  v(await p.locator('[data-statut]').first().getAttribute('data-statut'), 'suspendu', 'suspendu à la main malgré une échéance future');
  r = await pi.goto(I + '/caisse?e=comptoir-du-lac');
  v(r.status(), 403, 'suspension à la main : appliquée à l\'ouverture suivante de la caisse');
  await p.selectOption('#statut-action', 'auto');
  await Promise.all([p.waitForNavigation(), p.click('#statut button[type=submit]')]);
  v(await p.locator('[data-statut]').first().getAttribute('data-statut'), 'actif', 'réactivé à la main : statut selon les dates (actif)');
  q(C, `UPDATE clients SET echeance='${jour(-40)}' WHERE id=${id}`);
  await p.goto(A + `/clients/${id}`);
  await p.selectOption('#statut-action', 'actif'); await p.fill('#statut-motif', 'Promesse de paiement vendredi');
  await Promise.all([p.waitForNavigation(), p.click('#statut button[type=submit]')]);
  v(await p.locator('[data-statut]').first().getAttribute('data-statut'), 'actif', 'garder actif à la main malgré 40 jours de retard');
  r = await pi.goto(I + '/caisse?e=comptoir-du-lac');
  v(r.status(), 200, 'forcé actif : la caisse s\'ouvre');
  v(q(C, `SELECT COUNT(*) FROM journal WHERE client_id=${id} AND action='statut_force'`), 2, 'décisions à la main inscrites au journal');
  q(C, `UPDATE clients SET statut_force=NULL, echeance='${jour(20)}' WHERE id=${id}`);

  // --- Modules masqués et limites ---
  const nbActifs = Number(q(B, "SELECT COUNT(*) FROM utilisateurs WHERE actif = 1 AND id <> COALESCE((SELECT valeur FROM parametres WHERE cle = 'assistance.utilisateur_id'), 0)"));   // la limite est mise juste au nombre de personnes actives
  q(C, `UPDATE formules SET modules='["caisse","ventes","carte"]', max_etablissements=2, max_utilisateurs=${nbActifs} WHERE id=${fid}`); oublierCache();
  await pi.goto(I + '/gestion');
  const noms2 = (await pi.locator('.module__nom').allTextContents()).join('|');
  v(!noms2.includes('Finance') && !noms2.includes('Rapports') && noms2.includes('Paramètres'), true, 'module retiré de la formule : masqué (Finance, Rapports), Paramètres toujours là');
  r = await pi.goto(I + '/gestion/tresorerie');
  v(r.status() + ' ' + await pi.locator('h1').textContent(), '403 Module non inclus', 'écran d\'un module non inclus : refusé, avec explication');
  await pi.screenshot({ path: D + '/cap/instance-module-non-inclus.png' });
  r = await pi.goto(I + '/gestion/etablissements/nouveau');
  const csrfE = await pi.locator('input[name=csrf]').first().inputValue();
  r = await pi.request.post(I + '/gestion/etablissements/nouveau', { form: { csrf: csrfE, nom: 'Troisième bar', type: 'restau-bar' } });
  const texteE = await r.text();
  v(r.status() + ' ' + /permet 2 établissement/.test(texteE), '403 true', 'limite de 2 établissements atteinte : création refusée avec un message clair');
  r = await pi.request.post(I + '/gestion/utilisateurs/nouveau', { form: { csrf: csrfE, nom: 'Neuvième' } });
  v(r.status() + ' ' + new RegExp('permet ' + nbActifs + ' utilisateur').test(await r.text()), '403 true', 'limite de ' + nbActifs + ' personnes atteinte : création refusée');
  q(C, `UPDATE formules SET max_utilisateurs=${nbActifs + 1} WHERE id=${fid}`); oublierCache();
  r = await pi.request.post(I + '/gestion/utilisateurs/nouveau', { form: { csrf: csrfE, nom: '' } });
  v(r.status() !== 403 || !/Limite de votre formule/.test(await r.text()), true, 'limite relevée à ' + (nbActifs + 1) + ' : la création passe la garde (le formulaire répond lui-même)');
  q(C, `UPDATE formules SET modules='["ventes","carte"]' WHERE id=${fid}`);
  r = await pi.goto(I + '/caisse?e=comptoir-du-lac');
  v(r.status() + ' ' + await pi.locator('h1').textContent(), '403 Caisse non incluse', 'caisse retirée de la formule : refusée à l\'ouverture');
  q(C, `UPDATE formules SET modules='["caisse","ventes","finance","rapports","carte"]', max_etablissements=5, max_utilisateurs=50 WHERE id=${fid}`);

  // --- Console injoignable : la dernière licence connue s'applique ---
  q(C, `UPDATE clients SET echeance='${jour(-10)}' WHERE id=${id}`);
  r = await pi.goto(I + '/caisse?e=comptoir-du-lac');
  v(r.status(), 403, 'lecture seule mémorisée dans l\'instance (licence.*)');
  require('fs').writeFileSync(D + '/console-ko.php', "<?php return ['db' => ['hote' => '127.0.0.1', 'port' => 3306, 'nom' => 'rbp_console_essai', 'utilisateur' => 'rbp_cnsl', 'mot_de_passe' => 'faux'], 'cache_licence_secondes' => 300];\n");
  execSync(`cd /home/user/Eglise/restau-bar-pos && (RBP_CONSOLE_CONFIG=${D}/console-ko.php setsid nohup php -S 127.0.0.1:8181 -t public public/index.php > ${D}/serveur-ko.log 2>&1 < /dev/null & echo $! > ${D}/serveur-ko.pid) && sleep 0.7`);
  const pk = await (await b.newContext()).newPage();
  await connecterInstance(pk, 'cnslbar:8181'.replace(':8181', ''), 'x', 'x').catch(() => {});
  r = await pk.goto('http://cnslbar.localhost:8181/caisse?e=comptoir-du-lac');
  v(r.status() + ' ' + await pk.locator('h1').textContent(), '403 La caisse ne peut pas s\'ouvrir', 'console injoignable : la dernière licence connue s\'applique (toujours en lecture seule)');
  r = await pk.goto('http://cnslbar.localhost:8181/');
  v(r.status(), 200, 'console injoignable : la vitrine fonctionne');
  q(B, "DELETE FROM parametres WHERE cle LIKE 'licence.%'");
  r = await pk.goto('http://cnslbar.localhost:8181/gestion/connexion');
  v(r.status(), 200, 'console injoignable sans licence connue : instance libre');
  execSync(`kill $(cat ${D}/serveur-ko.pid)`);
  q(C, `UPDATE clients SET echeance='${jour(20)}' WHERE id=${id}`); q(B, "DELETE FROM parametres WHERE cle LIKE 'licence.%'");

  require('fs').writeFileSync(D + '/etat.json', JSON.stringify({ ...JSON.parse(require('fs').readFileSync(D + '/etat.json')), bar: id, formule: fid }));
  console.log(erreurs.length ? erreurs.join('\n') : 'aucune erreur JS');
  await b.close();
  process.exit(bilan() ? 1 : 0);
})();
