const { chromium } = require('playwright'); const fs = require('fs'); const { execSync } = require('child_process');
const B = 'http://integ.localhost:8250'; const dir = __dirname + '/';
const MDP = fs.readFileSync(dir + '../instance.txt', 'utf8').match(/Mot de passe\s*:\s*(\S+)/)[1];
const sql = q => execSync(`mysql -u root -N --default-character-set=utf8mb4 rbp_integ -e ${JSON.stringify("SET time_zone = '+02:00'; " + q)}`).toString().trim();
let nb = 0; const ko = []; const v = (c, m) => { nb++; if (!c) ko.push(m); console.log((c ? 'ok    ' : 'ÉCHEC ') + m); };
async function connecter(ctx, id, mdp) { const p = await ctx.newPage(); await p.goto(B + '/gestion/connexion'); await p.fill('#email', id); await p.fill('#mot_de_passe', mdp); await Promise.all([p.waitForNavigation(), p.click('button[type=submit]')]); return p; }
const corps = async p => (await p.textContent('main')).replace(/\s+/g, ' ');
(async () => {
  const b = await chromium.launch(); const err = [];
  const ctx = await b.newContext({ viewport: { width: 1366, height: 860 } });
  const p = await connecter(ctx, 'admin@demo.test', MDP); p.on('pageerror', x => err.push(x.message));
  // 1. Création d'un serveur avec PIN
  await p.goto(B + '/gestion/utilisateurs/nouveau');
  await p.fill('#nom', 'Benjamin Lusenge'); await p.fill('#poste', 'Serveur'); await p.fill('#telephone', '+243 99 123 4567');
  await p.selectOption('#role-0', { label: 'Serveur' }); await p.selectOption('#portee-0', { label: 'Volcano Club' });
  await p.fill('#pin', '509283');   // 908172 est maintenant le PIN de démonstration du propriétaire (README)
  await Promise.all([p.waitForNavigation(), p.click('#formulaire-utilisateur button[type=submit]')]);
  v((await corps(p)).includes('Benjamin Lusenge est créé'), 'création de Benjamin (serveur au Club, PIN)');
  const idBen = new URL(p.url()).pathname.split('/').pop();
  v(sql(`SELECT telephone FROM utilisateurs WHERE id=${idBen}`) === '+243991234567', 'téléphone enregistré sans espaces');
  v(sql(`SELECT COUNT(*) FROM utilisateur_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.utilisateur_id=${idBen} AND r.code='serveur' AND ur.etablissement_id=2`) === '1', 'rôle Serveur au Volcano Club');
  // 2-3. Erreurs : téléphone pris, PIN simple, PIN déjà pris
  async function essai(champs, attendu, msg) {
    await p.goto(B + '/gestion/utilisateurs/nouveau'); await p.fill('#nom', 'Essai');
    for (const [s, val] of Object.entries(champs)) await p.fill(s, val);
    const [r] = await Promise.all([p.waitForNavigation(), p.click('#formulaire-utilisateur button[type=submit]')]);
    v(r.status() === 422 && (await corps(p)).includes(attendu), msg + ' (' + r.status() + ')');
  }
  await essai({ '#telephone': '0810000103'.replace(/^0/, '+243') }, 'déjà celui', 'téléphone déjà pris refusé');
  await essai({ '#pin': '123456' }, 'trop facile', 'PIN trop simple refusé');
  await essai({ '#pin': '271828' }, 'ne peut pas être utilisé', 'PIN déjà pris (Aline) refusé sans dire à qui');
  await essai({ '#pin': '12a4' }, 'exactement 6 chiffres', 'PIN mal formé refusé');
  await essai({ '#mot_de_passe': 'court' }, 'entre 10 et 72', 'mot de passe trop court refusé');
  // 4. PIN tiré au hasard, montré une fois
  await p.goto(B + '/gestion/utilisateurs/' + idBen);
  await Promise.all([p.waitForNavigation(), p.click('button[value=generer]')]);
  const montre = (await p.textContent('.uti-pin-montre__valeur')).replace(/\s/g, '');
  v(/^\d{6}$/.test(montre), 'PIN tiré au hasard affiché : ' + montre);
  await p.reload(); v(await p.locator('.uti-pin-montre__valeur').count() === 0, 'PIN plus affiché après rechargement');
  // 5. Déblocage
  sql("UPDATE utilisateurs SET pin_bloque_le=NOW() WHERE nom='Esther Kahindo'");
  const idEsther = sql("SELECT id FROM utilisateurs WHERE nom='Esther Kahindo'");
  await p.goto(B + '/gestion/utilisateurs/' + idEsther);
  await Promise.all([p.waitForNavigation(), p.click('.uti-bloque button')]);
  v(sql(`SELECT pin_bloque_le IS NULL FROM utilisateurs WHERE id=${idEsther}`) === '1', 'Esther débloquée');
  // 6. Propriétaire : ne peut pas se retirer son rôle
  await p.goto(B + '/gestion/utilisateurs/1'); await p.selectOption('#role-0', '');
  let [r] = await Promise.all([p.waitForNavigation(), p.click('#formulaire-utilisateur button[type=submit]')]);
  v(r.status() === 422 && (await corps(p)).includes('au moins un propriétaire'), 'dernier propriétaire protégé');
  // 7. Rôle « Responsable de salle »
  await p.goto(B + '/gestion/utilisateurs/roles/nouveau');
  await p.fill('#nom_fr', 'Responsable de salle'); await p.fill('#nom_en', 'Floor manager');
  for (const c of ['caisse.vendre', 'caisse.remise', 'parametres.utilisateurs', 'ventes.voir']) await p.check(`input[value="${c}"]`);
  await Promise.all([p.waitForNavigation(), p.click('form.uti-formulaire button[type=submit]')]);
  v((await corps(p)).includes('Responsable de salle » créé'), 'rôle créé');
  const idRole = new URL(p.url()).pathname.split('/').pop();
  v(sql(`SELECT GROUP_CONCAT(permission ORDER BY permission) FROM role_permissions WHERE role_id=${idRole}`) === 'caisse.remise,caisse.vendre,parametres.utilisateurs,ventes.voir', 'permissions du rôle enregistrées');
  r = await p.goto(B + '/gestion/utilisateurs/roles/1');
  v(await p.locator('fieldset.uti-fige[disabled]').count() === 1, 'rôle Propriétaire non modifiable');
  // Patrick reçoit ce rôle au Comptoir
  await p.goto(B + '/gestion/utilisateurs/2');
  await p.selectOption('#role-1', idRole); await p.selectOption('#portee-1', '1');
  await Promise.all([p.waitForNavigation(), p.click('#formulaire-utilisateur button[type=submit]')]);
  v(sql(`SELECT COUNT(*) FROM utilisateur_roles WHERE utilisateur_id=2`) === '2', 'Patrick : gérant + responsable de salle au Comptoir');
  // 8. Patrick : élévation de droits refusée
  const c2 = await b.newContext({ viewport: { width: 1366, height: 860 } }); const pk = await connecter(c2, 'patrick@demo.test', 'Gerant-Goma-2026');
  r = await pk.goto(B + '/gestion/utilisateurs'); v(r.status() === 200, 'Patrick : accès aux utilisateurs');
  r = await pk.goto(B + '/gestion/utilisateurs/1'); v(r.status() === 200, 'Patrick : voit la fiche du propriétaire');
  const jeton = await pk.locator('input[name=csrf]').first().inputValue();
  r = await pk.request.post(B + '/gestion/utilisateurs/1', { form: { csrf: jeton, nom: 'Pirate', 'role[]': '1', 'portee[]': '0' } });
  v(r.status() === 403, 'Patrick : modifier le propriétaire refusé (403)');
  await pk.goto(B + '/gestion/utilisateurs/2');
  v(await pk.locator('#role-0 option[value="1"][disabled]').count() === 1, 'Patrick : rôle Propriétaire grisé');
  r = await pk.request.post(B + '/gestion/utilisateurs/2', { form: { csrf: jeton, nom: 'Patrick Kambale', telephone: '+243810000101', email: 'patrick@demo.test', langue: 'fr', 'role[0]': '1', 'portee[0]': '0', 'role[1]': idRole, 'portee[1]': '1' } });
  v(r.status() === 422 && (await r.text()).includes('Seul un propriétaire'), 'Patrick : se donner Propriétaire refusé');
  const idAline = sql("SELECT id FROM utilisateurs WHERE nom='Aline Mukeba'"); const idGerant = sql("SELECT id FROM roles WHERE code='gerant'");
  r = await pk.request.post(B + '/gestion/utilisateurs/' + idAline, { form: { csrf: jeton, nom: 'Aline Mukeba', telephone: '+243810000103', langue: 'fr', 'role[0]': idGerant, 'portee[0]': '0' } });
  v(r.status() === 422 && (await r.text()).includes('droits que vous n'), 'Patrick : donner Gérant partout refusé');
  r = await pk.request.post(B + '/gestion/utilisateurs/' + idAline, { form: { csrf: jeton, nom: 'Aline Mukeba', telephone: '+243810000103', langue: 'fr', 'role[0]': idGerant, 'portee[0]': '1' } });
  v(r.status() === 200 && sql(`SELECT COUNT(*) FROM utilisateur_roles WHERE utilisateur_id=${idAline} AND role_id=${idGerant} AND etablissement_id=1`) === '1', 'Patrick : Gérant au Comptoir pour Aline permis');
  r = await pk.request.post(B + '/gestion/utilisateurs/roles/nouveau', { form: { csrf: jeton, nom_fr: 'Trésorier', 'permissions[]': 'finance.comptes' } });
  v(r.status() === 422, 'Patrick : rôle avec un droit qu\'il n\'a pas refusé');
  r = await pk.request.post(B + '/gestion/utilisateurs/2', { form: { csrf: jeton, nom: 'Patrick Kambale', telephone: '+243810000101', langue: 'fr', 'role[0]': idGerant, 'portee[0]': '1' } });
  v(r.status() === 422 && (await r.text()).includes('vous retirer à vous-même'), 'Patrick : ne peut pas se retirer la gestion des utilisateurs');
  // Patrick ne touche pas aux personnes qui ont plus de droits que lui
  const idGrace = sql("SELECT id FROM utilisateurs WHERE nom='Grace Mwamini'"); const idJP = sql("SELECT id FROM utilisateurs WHERE nom='Jean-Paul Bahati'");
  r = await pk.request.post(B + '/gestion/utilisateurs/' + idGrace, { form: { csrf: jeton, nom: 'Grace Mwamini', email: 'grace@demo.test', langue: 'fr', mot_de_passe: 'Pirate-123456', 'role[0]': sql("SELECT id FROM roles WHERE code='comptable'"), 'portee[0]': '0' } });
  v(r.status() === 403, 'Patrick : changer le mot de passe de la comptable refusé (403)');
  r = await pk.request.post(B + '/gestion/utilisateurs/' + idGrace + '/pin', { form: { csrf: jeton, action: 'generer' } });
  v(r.status() === 403, 'Patrick : PIN de la comptable refusé (403)');
  r = await pk.request.post(B + '/gestion/utilisateurs/' + idJP + '/pin', { form: { csrf: jeton, action: 'generer' } });
  v(r.status() === 403, 'Patrick : PIN du barman du Club refusé (403)');
  await pk.goto(B + '/gestion/utilisateurs/' + idGrace);
  v(await pk.locator('#formulaire-utilisateur fieldset[disabled]').count() === 1 && await pk.locator('#section-pin').count() === 0, 'Patrick : fiche de la comptable en lecture seule');
  r = await pk.request.post(B + '/gestion/utilisateurs/' + sql("SELECT id FROM utilisateurs WHERE nom='Esther Kahindo'") + '/pin', { form: { csrf: jeton, pin: '840271' } });
  v(r.status() === 200 && !(await r.text()).includes('403'), 'Patrick : PIN de la caissière du Comptoir permis');
  // 9. Désactivation : Christian
  const idChristian = sql("SELECT id FROM utilisateurs WHERE nom='Christian Mugisho'");
  await p.goto(B + '/gestion/utilisateurs/' + idChristian);
  await Promise.all([p.waitForNavigation(), p.click('form[action$="/desactiver"] button')]);
  v(sql(`SELECT actif FROM utilisateurs WHERE id=${idChristian}`) === '0', 'Christian désactivé');
  r = await p.request.post(B + '/gestion/utilisateurs/1/desactiver', { form: { csrf: await p.locator('input[name=csrf]').first().inputValue() } });
  v(r.status() === 422, 'on ne se désactive pas soi-même');
  // Une session ouverte d'une personne désactivée s'arrête
  sql("UPDATE utilisateurs SET actif=0 WHERE id=2");
  await pk.goto(B + '/gestion/tresorerie'); v(new URL(pk.url()).pathname === '/gestion/connexion', 'session de Patrick arrêtée dès sa désactivation');
  sql("UPDATE utilisateurs SET actif=1 WHERE id=2");
  // 10. Appareil autorisé puis retiré
  const tab = await b.newContext({ viewport: { width: 1180, height: 820 } }); const t1 = await connecter(tab, 'admin@demo.test', MDP);
  await t1.goto(B + '/caisse?e=volcano-club'); await t1.click('#quitter'); await t1.click('.partager > summary'); await t1.fill('#nom-appareil', 'Tablette VIP');
  await Promise.all([t1.waitForNavigation(), t1.click('.partager__form button[type=submit]')]);
  const nomsClub = await t1.locator('.verrou__nom').allTextContents();
  v(nomsClub.includes('Benjamin Lusenge') && !nomsClub.includes('Christian Mugisho'), 'tablette du Club : Benjamin oui, Christian (désactivé) non : ' + nomsClub.join(', '));
  await p.goto(B + '/gestion/utilisateurs/appareils');
  v((await corps(p)).includes('Tablette VIP'), 'appareil listé');
  await Promise.all([p.waitForNavigation(), p.click('form[action$="/retirer"] button')]);
  await t1.goto(B + '/caisse'); v(new URL(t1.url()).pathname === '/gestion/connexion', 'appareil retiré : la tablette n\'accepte plus les PIN');
  // 11. Journal
  await p.goto(B + '/gestion/utilisateurs/journal'); const j = await corps(p);
  v(j.includes('Appareil autorisé') && j.includes('Appareil retiré') && j.includes('Personne créée') && j.includes('Rôle créé'), 'journal : actions enregistrées');
  // 12. Anglais
  await ctx.addCookies([{ name: 'lang', value: 'en', url: B }]);
  for (const u of ['/gestion/utilisateurs', '/gestion/utilisateurs/' + idBen, '/gestion/utilisateurs/roles/' + idRole, '/gestion/utilisateurs/appareils', '/gestion/utilisateurs/journal']) {
    await p.goto(B + u); const t = await corps(p);
    v(!/\b(utilisateurs|roles|appareils|journal|perm|acces)\.[a-z_.]+/.test(t), 'anglais sans clé brute : ' + u);
  }
  v(err.length === 0, 'aucune erreur JS ' + err.join(' | '));
  console.log(`\n${nb - ko.length}/${nb} vérifications réussies`); await b.close();
})();
