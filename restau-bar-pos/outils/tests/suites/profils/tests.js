const { chromium } = require('playwright'); const fs = require('fs'); const { execSync } = require('child_process');
const B = 'http://integ.localhost:8250'; const dir = __dirname + '/';
const MDP = fs.readFileSync(dir + '../instance.txt', 'utf8').match(/Mot de passe\s*:\s*(\S+)/)[1];
const sql = q => execSync(`mysql -u root -N --default-character-set=utf8mb4 rbp_integ -e ${JSON.stringify("SET time_zone = '+02:00'; " + q)}`).toString().trim();
let nb = 0; const ko = []; const v = (c, m) => { nb++; if (!c) ko.push(m); console.log((c ? 'ok    ' : 'ÉCHEC ') + m); };
async function connecter(ctx, id, mdp) { const p = await ctx.newPage(); await p.goto(B + '/gestion/connexion'); await p.fill('#email', id); await p.fill('#mot_de_passe', mdp); await Promise.all([p.waitForNavigation(), p.click('button[type=submit]')]); return p; }
async function autoriser(ctx, slug, nom) {
  const p = await connecter(ctx, 'admin@demo.test', MDP);
  await p.goto(B + '/caisse?e=' + slug); await p.click('#quitter'); await p.click('.partager > summary:has-text("caisse partagée")'); await p.fill('#nom-appareil', nom);
  await Promise.all([p.waitForNavigation(), p.click('.partager__form button[type=submit]')]); return p;
}
async function pin(p, nom, code) { await p.click(`.verrou__personne[data-nom="${nom}"]`); for (const c of code) await p.click(`#pave-touches [data-chiffre="${c}"]`); await p.waitForLoadState('load'); await p.waitForTimeout(250); }
const fichier = sql("SELECT avatar FROM utilisateurs WHERE nom='Aline Mukeba'");
(async () => {
  const b = await chromium.launch(); const err = [];
  const ctx = await b.newContext({ viewport: { width: 1366, height: 860 } });
  const a = await connecter(ctx, 'admin@demo.test', MDP); a.on('pageerror', x => err.push(x.message));
  // 1. Photo par le gérant
  await a.goto(B + '/gestion/utilisateurs/4');
  v(await a.locator('#photo [data-envoyer]').isDisabled(), 'bouton « Enregistrer la photo » inactif avant le choix');
  await a.setInputFiles('#photo input[type=file]', dir + 'visage.jpg');
  v(!(await a.locator('#photo [data-envoyer]').isDisabled()) && await a.locator('#photo [data-apercu] img').count() === 1, 'aperçu de la photo choisie');
  await Promise.all([a.waitForNavigation(), a.click('#photo [data-envoyer]')]);
  const chemin = sql("SELECT avatar FROM utilisateurs WHERE nom='Aline Mukeba'");
  v(/^\/uploads\/integ\/avatars\/4-[a-f0-9]{16}\.jpg$/.test(chemin) && fs.existsSync('/home/user/Eglise/restau-bar-pos/public' + chemin), 'photo d\'Aline ré-encodée et enregistrée : ' + chemin);
  await a.goto(B + '/gestion/utilisateurs');
  v(await a.locator(`.uti-carte img[src="${chemin}"]`).count() === 1, 'photo dans la liste des personnes');
  // Remplacement : l'ancien fichier est effacé
  await a.goto(B + '/gestion/utilisateurs/4'); await a.setInputFiles('#photo input[type=file]', dir + 'visage.jpg');
  await Promise.all([a.waitForNavigation(), a.click('#photo [data-envoyer]')]);
  const chemin2 = sql("SELECT avatar FROM utilisateurs WHERE nom='Aline Mukeba'");
  v(chemin2 !== chemin && !fs.existsSync('/home/user/Eglise/restau-bar-pos/public' + chemin), 'nouvelle photo : l\'ancienne est effacée');
  // Fichier qui n'est pas une image
  await a.setInputFiles('#photo input[type=file]', dir + 'faux.jpg');
  const [r1] = await Promise.all([a.waitForNavigation(), a.click('#photo [data-envoyer]')]);
  v(r1.status() === 422 && await a.locator('#photo .champ__erreur').count() === 1, 'fichier qui n\'est pas une image refusé (422)');
  // 2. Ma photo (Mon compte) + avatar dans l'en-tête
  await a.goto(B + '/gestion/compte'); await a.setInputFiles('#photo input[type=file]', dir + 'visage.jpg');
  await Promise.all([a.waitForNavigation(), a.click('#photo [data-envoyer]')]);
  v(await a.locator('.haut__profil img').count() === 1, 'ma photo dans l\'en-tête de la gestion');
  await Promise.all([a.waitForNavigation(), a.click('#photo button[value=retirer]')]);
  v(await a.locator('.haut__profil img').count() === 0 && sql('SELECT avatar IS NULL FROM utilisateurs WHERE id=1') === '1', 'photo retirée : initiales à la place');
  // 3. Réglages du Comptoir : 1 min, message, pas de photo à la caisse
  await a.goto(B + '/gestion/utilisateurs/appareils');
  v(await a.locator('.uti-reglages').count() === 2, 'propriétaire : réglages des deux établissements');
  const f1 = a.locator('#reglages-1 form');
  await f1.locator('select[name=verrou_minutes]').selectOption('1');
  await f1.locator('textarea[name=message_verrou_fr]').fill('Bonjour l\'équipe ! Pensez au PIN.');
  await f1.locator('input[name=photo_a_la_caisse]').uncheck();
  await Promise.all([a.waitForNavigation(), f1.locator('button[type=submit]').click()]);
  v(sql("SELECT CONCAT(verrou_apres_encaissement,'|',verrou_minutes,'|',photo_a_la_caisse,'|',message_verrou_fr) FROM etablissements WHERE id=1") === "1|1|0|Bonjour l'équipe ! Pensez au PIN.", 'réglages du Comptoir enregistrés');
  const jeton = await a.locator('input[name=csrf]').first().inputValue();
  let r = await a.request.post(B + '/gestion/utilisateurs/appareils/reglages/1', { form: { csrf: jeton, verrou_minutes: '7' } });
  v(sql('SELECT verrou_minutes FROM etablissements WHERE id=1') === '0', 'délai hors liste ramené à « jamais »');
  await a.request.post(B + '/gestion/utilisateurs/appareils/reglages/1', { form: { csrf: jeton, verrou_apres_encaissement: '1', verrou_minutes: '1', message_verrou_fr: "Bonjour l'équipe ! Pensez au PIN." } });
  // 4. Écran de verrouillage du Comptoir
  const tab = await b.newContext({ viewport: { width: 1280, height: 800 }, hasTouch: true });
  const t = await autoriser(tab, 'comptoir-du-lac', 'Tablette Comptoir'); t.on('pageerror', x => err.push(x.message));
  v((await t.textContent('.verrou__message')).includes('Pensez au PIN') && (await t.textContent('.verrou__regles')).includes('1 min'), 'écran : message de l\'admin et règle de verrouillage');
  v(/^\d{2}:\d{2}$/.test((await t.textContent('[data-heure]')).trim()), 'écran : heure affichée');
  v(await t.locator(`.verrou__personne[data-nom="Aline Mukeba"] img`).count() === 1, 'écran : photo d\'Aline');
  await pin(t, 'Aline Mukeba', '271828');
  v(await t.locator('#grille').count() === 1, 'Aline connectée');
  await t.click('#quitter');
  v(await t.locator('summary:has-text("Ma photo")').count() === 0, 'photo à la caisse désactivée : pas de « Ma photo »');
  v((await t.textContent('#fenetre-quitter')).includes('après 1 min'), 'fenêtre : règle de verrouillage rappelée');
  r = await t.request.post(B + '/caisse/photo', { form: { csrf: await t.locator('input[name=csrf]').first().inputValue() } });
  v(r.status() === 403, 'envoi de photo à la caisse refusé quand l\'admin l\'interdit (403)');
  await t.keyboard.press('Escape');
  // 5. Verrouillage après 1 min sans toucher (horloge simulée)
  await t.clock.install(); await t.reload(); await t.waitForSelector('#grille');
  await t.clock.runFor(51000);
  v(await t.locator('.verrou-bientot').count() === 1, 'avertissement 10 s avant le verrouillage');
  await t.mouse.click(600, 400); await t.clock.runFor(2000);
  v(await t.locator('.verrou-bientot').count() === 0, 'toucher l\'écran annule le verrouillage');
  await Promise.all([t.waitForNavigation(), t.clock.runFor(61000)]);
  v(await t.locator('.verrou__personnes').count() === 1, 'après 1 min sans toucher : retour à « Qui êtes-vous ? »');
  // 6. Photo prise à la caisse du Club (permis)
  const tab2 = await b.newContext({ viewport: { width: 1280, height: 800 }, hasTouch: true });
  const c = await autoriser(tab2, 'volcano-club', 'Tablette Club');
  await pin(c, 'Jean-Paul Bahati', '161803');
  await c.click('#quitter'); await c.click('summary:has-text("Ma photo")');
  v(await c.locator('#fenetre-quitter input[type=file][capture=user]').count() === 1, 'caisse : appareil photo de face proposé');
  await c.setInputFiles('#fenetre-quitter input[type=file]', dir + 'visage.jpg');
  await Promise.all([c.waitForNavigation(), c.click('#fenetre-quitter [data-envoyer]')]);
  v(await c.locator('.personne img').count() === 1 && sql("SELECT avatar IS NOT NULL FROM utilisateurs WHERE nom='Jean-Paul Bahati'") === '1', 'Jean-Paul : selfie enregistré, visible dans la pastille');
  v(await c.locator('.verrou-bientot').count() === 0 && (await c.textContent('#fenetre-quitter')).length > 0, 'Club : pas de verrouillage à la minute (réglage « jamais »)');
  // 7. Patrick : seulement le Comptoir
  const pk = await connecter(await b.newContext(), 'patrick@demo.test', 'Gerant-Goma-2026');
  sql("INSERT INTO utilisateur_roles (utilisateur_id, role_id, etablissement_id) SELECT 2, id, 1 FROM roles WHERE code='proprietaire'");
  sql("DELETE ur FROM utilisateur_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.utilisateur_id=2 AND r.code='proprietaire'");
  const idRole = sql("INSERT INTO roles (code, nom_fr, nom_en) VALUES ('test_admin_salle','Admin salle','Floor admin'); SELECT LAST_INSERT_ID()");
  sql(`INSERT INTO role_permissions (role_id, permission) VALUES (${idRole}, 'parametres.utilisateurs'); INSERT INTO utilisateur_roles (utilisateur_id, role_id, etablissement_id) VALUES (2, ${idRole}, 1)`);
  await pk.goto(B + '/gestion/utilisateurs/appareils');
  v(await pk.locator('.uti-reglages').count() === 1 && await pk.locator('#reglages-1').count() === 1, 'Patrick : réglages du Comptoir seulement');
  r = await pk.request.post(B + '/gestion/utilisateurs/appareils/reglages/2', { form: { csrf: await pk.locator('input[name=csrf]').first().inputValue(), verrou_minutes: '5' } });
  v(r.status() === 403 && sql('SELECT verrou_minutes FROM etablissements WHERE id=2') === '0', 'Patrick : réglages du Club refusés (403)');
  // 8. Téléphone, anglais
  const tel = await b.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, deviceScaleFactor: 2 });
  await tel.addCookies([{ name: 'lang', value: 'en', url: B }]);
  const tp = await autoriser(tel, 'comptoir-du-lac', 'Phone');
  await tel.addCookies([{ name: 'lang', value: 'en', url: B }]); await tp.reload();
  const txt = (await tp.textContent('body')).replace(/\s+/g, ' ');
  v(txt.includes('Who are you?') && txt.includes('The till locks after each payment') && !/\b(verrou|caisse_pin|profil)\.[a-z_]+/.test(txt), 'téléphone en anglais : textes traduits');
  v(await tp.evaluate(() => document.documentElement.scrollWidth - innerWidth) === 0, 'téléphone : pas de défilement horizontal');
  const ga = await connecter(tel, 'admin@demo.test', MDP);
  await tel.addCookies([{ name: 'lang', value: 'en', url: B }]);
  for (const u of ['/gestion/utilisateurs/appareils', '/gestion/compte', '/gestion/utilisateurs/4']) {
    await ga.goto(B + u);
    const tt = (await ga.textContent('main')).replace(/\s+/g, ' ');
    v(!/\b(verrou|profil|appareils|utilisateurs)\.[a-z_]+/.test(tt) && await ga.evaluate(() => document.documentElement.scrollWidth - innerWidth) === 0, 'téléphone EN sans clé ni débordement : ' + u);
  }
  await ga.goto(B + '/gestion/utilisateurs/journal');
  v((await ga.textContent('main')).includes('Profile photo changed') && (await ga.textContent('main')).includes('Till settings changed'), 'journal : photos et réglages');
  v(err.length === 0, 'aucune erreur JS ' + err.join(' | '));
  console.log(`\n${nb - ko.length}/${nb} vérifications réussies`); await b.close();
})();
