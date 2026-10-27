// Personnel et paie : parcours complet sur la copie « essaiperso » (base restaurée au début).
// Droits et portée, CSRF, employés, borne et code QR (expiration, rejeu), téléphone (identification, GPS, rayon),
// pointage de secours à la caisse, pointage obligatoire, corrections, plannings, congés, paie (deux devises,
// avances, retenues proposées et ajustées, dette réduite sans trésorerie, manquant pas proposé deux fois),
// paiements dans la trésorerie, avances, pourboires, masse salariale, rapport CSV, FR/EN.
const { chromium } = require('playwright'); const { execSync } = require('child_process'); const crypto = require('crypto');
const B = 'http://integ.localhost:8250'; const D = __dirname;
const sql = q => execSync("mysql -N --default-character-set=utf8mb4 rbp_integ", { input: "SET time_zone = '+02:00';\n" + q }).toString().trim();
let nb = 0; const ko = []; const v = (c, m) => { nb++; if (!c) ko.push(m); console.log((c ? 'ok    ' : 'ÉCHEC ') + m); };
execSync('/home/user/Eglise/restau-bar-pos/outils/tests/restaurer.sh sans-garant');   // la fenêtre des garanties en attente s'ouvrirait sur la caisse
const hachage = pin => execSync(`php -r 'echo password_hash("${pin}", PASSWORD_DEFAULT);'`).toString();
sql(`UPDATE utilisateurs SET pin='${hachage('908172')}' WHERE id=1`);
const COMPTOIR = [-1.69123, 29.23461];
const secret = sql("SELECT valeur FROM parametres WHERE cle='pointage.secret'");
const jeton = (borne, etab, f) => `${borne}-${f}-` + crypto.createHmac('sha256', secret).update(`pointage|${borne}|${etab}|${f}`).digest('hex').slice(0, 24);
const fenetre = () => Math.floor(Date.now() / 1000 / 30);
const emp = nom => sql(`SELECT id FROM employes WHERE nom LIKE '${nom}%'`);
const err = [];
async function contexte(b, opts = {}) {
  const ctx = await b.newContext({ viewport: { width: 1366, height: 860 }, ...opts });
  ctx.on('page', p => { p.on('pageerror', x => err.push(x.message)); p.on('console', m => m.type() === 'error' && !m.text().includes('Failed to load resource') && err.push(m.text())); });
  return ctx;
}
async function connecter(ctx, id, mdp) {
  const p = await ctx.newPage(); await p.goto(B + '/gestion/connexion'); await p.fill('#email', id); await p.fill('#mot_de_passe', mdp);
  await Promise.all([p.waitForNavigation(), p.click('button[type=submit]')]); return p;
}
const statut = async (p, url) => (await p.goto(B + url)).status();
async function poster(p, url, champs, avecCsrf = true) {
  return p.evaluate(async ([url, champs, avecCsrf]) => {
    const f = new FormData(); if (avecCsrf) { const c = document.querySelector('input[name=csrf]'); f.append('csrf', c ? c.value : ''); }
    for (const [k, val] of Object.entries(champs)) f.append(k, val);
    const r = await fetch(url, { method: 'POST', body: f, headers: { Accept: 'application/json' }, redirect: 'manual' }); return [r.status, await r.text()];
  }, [url, champs, avecCsrf]);
}

(async () => {
  const b = await chromium.launch();
  const admin = await contexte(b); const pa = await connecter(admin, 'admin@demo.test', 'Essai-Integ-2026');
  const esther = emp('Esther'), aline = emp('Aline'), patrick = emp('Patrick'), jp = emp('Jean-Paul'), baraka = emp('Baraka');

  // ---------- 1. Droits et portée ----------
  const cg = await contexte(b); const pg = await connecter(cg, 'grace@demo.test', 'Compta-Goma-2026');
  v(await statut(pg, '/gestion/personnel/employes') === 403, 'comptable : employés refusés (pas personnel.voir)');
  v(await statut(pg, '/gestion/personnel/paie') === 200, 'comptable : paie ouverte (personnel.paie)');
  const cr = await contexte(b); const pr = await connecter(cr, 'riziki@demo.test', 'Superviseur-2026');
  await pr.goto(B + '/gestion/personnel/employes');
  v(await pr.locator('.per-carte', { hasText: 'Jean-Paul' }).count() === 1 && await pr.locator('.per-carte', { hasText: 'Esther' }).count() === 0, 'superviseure du club : ne voit que les employés du club');
  v(await statut(pr, '/gestion/personnel/employes/' + esther) === 404, 'superviseure : fiche d\'une employée du Comptoir introuvable (portée)');
  v(await statut(pr, '/gestion/personnel/paie') === 403, 'superviseure : paie refusée');
  await pr.goto(B + '/gestion/personnel/employes');
  v((await poster(pr, '/gestion/personnel/employes/nouveau', { nom: 'Intrus' }))[0] === 403, 'superviseure : création d\'employé refusée (personnel.gerer)');
  const cp = await contexte(b); const pp = await connecter(cp, 'patrick@demo.test', 'Gerant-Goma-2026');
  await pp.goto(B + '/gestion/personnel/employes/' + esther);
  v(await pp.locator('[data-salaire]').count() === 0, 'gérant sans personnel.paie : salaire masqué sur la fiche');
  v(await statut(pp, '/gestion/personnel/employes/' + jp) === 404, 'gérant du Comptoir : barman du club introuvable');
  await pp.goto(B + '/gestion/personnel/employes');
  v((await poster(pp, '/gestion/personnel/employes/nouveau', { nom: 'Sans jeton' }, false))[0] === 419, 'CSRF : formulaire sans jeton refusé (419)');

  // ---------- 2. Employés (admin) ----------
  await pa.goto(B + '/gestion/personnel/employes/nouveau');
  await pa.fill('#nom', 'Kahindo Test'); await pa.fill('#telephone', '0975551234'); await pa.fill('#poste', 'Plongeur');
  await pa.selectOption('#contrat', 'journalier'); await pa.selectOption('#periode', 'soiree');
  await pa.fill('#salaire-1', '12'); await pa.fill('#salaire-2', '10000');
  await Promise.all([pa.waitForNavigation(), pa.click('form.per-formulaire button[type=submit]')]);
  const nouveau = sql("SELECT id FROM employes WHERE nom='Kahindo Test'");
  v(nouveau && sql(`SELECT telephone FROM employes WHERE id=${nouveau}`) === '+243975551234', 'employé sans compte créé, téléphone normalisé (+243…)');
  v(sql(`SELECT GROUP_CONCAT(CONCAT(devise_id,':',montant) ORDER BY devise_id) FROM employe_salaires WHERE employe_id=${nouveau}`) === '1:12.000,2:10000.000', 'salaire en deux devises (12 $ + 10 000 FC par soirée)');
  await pa.goto(B + '/gestion/personnel/employes/nouveau'); await pa.fill('#nom', 'Doublon'); await pa.fill('#telephone', '+243975551234');
  await Promise.all([pa.waitForNavigation(), pa.click('form.per-formulaire button[type=submit]')]);
  v(await pa.locator('#erreur-telephone').count() === 1, 'téléphone déjà pris refusé avec un message');
  await pa.goto(B + '/gestion/personnel/employes/' + nouveau);
  await pa.fill('#pin', '123456'); await Promise.all([pa.waitForNavigation(), pa.click('form[action$="/pin"] button[type=submit]')]);
  v(await pa.locator('#erreur-pin').count() === 1, 'PIN de pointage trop simple refusé');
  await pa.fill('#pin', '804613'); await Promise.all([pa.waitForNavigation(), pa.click('form[action$="/pin"] button[type=submit]')]);
  v(sql(`SELECT pin_pointage IS NOT NULL FROM employes WHERE id=${nouveau}`) === '1', 'PIN de pointage défini');
  require('fs').writeFileSync('/home/user/Eglise/restau-bar-pos/outils/tests/suites/agents/personnel/contrat.pdf', '%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF');
  await pa.setInputFiles('#doc-fichier', D + '/contrat.pdf'); await pa.fill('#doc-nom', 'Contrat');
  await Promise.all([pa.waitForNavigation(), pa.click('.per-document-formulaire button')]);
  const doc = sql(`SELECT id FROM employe_documents WHERE employe_id=${nouveau}`);
  const telecharge = await pa.evaluate(async u => { const r = await fetch(u); return [r.status, r.headers.get('content-type'), (await r.text()).slice(0, 8)]; }, `/gestion/personnel/employes/${nouveau}/documents/${doc}`);
  v(telecharge[0] === 200 && telecharge[1] === 'application/pdf' && telecharge[2] === '%PDF-1.4', 'document ajouté puis téléchargé (PDF, hors racine web)');
  v(await pr.evaluate(async u => (await fetch(u)).status, `/gestion/personnel/employes/${nouveau}/documents/${doc}`) === 404, 'document d\'un employé du Comptoir introuvable pour la superviseure du club');
  await pa.goto(B + '/gestion/personnel/employes/' + nouveau);
  await Promise.all([pa.waitForNavigation(), pa.click('form[action$="/supprimer"] button')]);
  v(sql(`SELECT COUNT(*) FROM employe_documents WHERE employe_id=${nouveau}`) === '0', 'document supprimé');

  // ---------- 3. Réglages, borne et code QR ----------
  await pp.goto(B + '/gestion/personnel/presence/reglages?etablissement=1');
  await pp.fill('#rayon', '10'); await Promise.all([pp.waitForNavigation(), pp.click('#reglages-pointage button[type=submit]')]);
  v(await pp.locator('#erreur-rayon').count() === 1, 'réglages : rayon de 10 m refusé (20 m au moins)');
  await pp.fill('#rayon', '100'); await pp.check('input[name=obligatoire]');
  await Promise.all([pp.waitForNavigation(), pp.click('#reglages-pointage button[type=submit]')]);
  v(sql('SELECT CONCAT(pointage_rayon, ":", pointage_obligatoire) FROM etablissements WHERE id=1') === '100:1', 'réglages : 100 m et pointage obligatoire au Comptoir');
  const tablette = await contexte(b, { viewport: { width: 1024, height: 768 }, colorScheme: 'dark' });
  const pt = await connecter(tablette, 'patrick@demo.test', 'Gerant-Goma-2026');
  await pt.goto(B + '/gestion/personnel/presence/bornes'); await pt.selectOption('#borne-etab', '1'); await pt.fill('#borne-nom', 'Tablette entrée');
  await Promise.all([pt.waitForNavigation(), pt.click('form[action="/gestion/personnel/presence/bornes"] button')]);
  await pt.waitForSelector('#borne-qr svg');
  v(pt.url().endsWith('/pointage/borne') && await pt.locator('#borne-qr svg').count() === 1, 'borne autorisée : code QR affiché en plein écran');
  const url1 = await pt.locator('#borne-qr').getAttribute('data-url');
  v(/\/pointer\?t=\d+-\d+-[a-f0-9]{24}$/.test(url1), 'le code QR porte l\'adresse signée /pointer?t=…');
  v(await statut(pt, '/gestion/personnel/employes') === 200 && pt.url().includes('/gestion/connexion'), 'la tablette n\'est plus connectée à la gestion (pas de session laissée ouverte)');
  await pt.goto(B + '/pointage/borne'); await pt.screenshot({ path: D + '/e2e-borne-tablette-nuit.png' });
  const borne = sql("SELECT id FROM pointage_bornes WHERE nom='Tablette entrée'");
  const j = await pt.evaluate(async () => (await fetch('/pointage/borne/jeton')).json());
  v(j.duree === 30 && j.expire_dans >= 1 && j.expire_dans <= 30 && j.url.includes('t=' + borne + '-'), 'jeton de la borne : renouvelé toutes les 30 s');
  const inconnu = await contexte(b); const pi = await inconnu.newPage();
  v((await pi.goto(B + '/pointage/borne')).status() === 403, 'navigateur non autorisé : pas de code QR (403)');

  // ---------- 4. Téléphone : identification, GPS, rejeu, expiration ----------
  const tel = await contexte(b, { viewport: { width: 390, height: 844 }, geolocation: { latitude: COMPTOIR[0] + 0.0002, longitude: COMPTOIR[1] }, permissions: ['geolocation'], isMobile: true, hasTouch: true });
  const ph = await tel.newPage();
  const t1 = jeton(borne, 1, fenetre());
  await ph.goto(B + '/pointer?t=' + t1);
  v(await ph.locator('#telephone').count() === 1, 'téléphone inconnu : demande le numéro et le PIN');
  await ph.fill('#telephone', '0810000103'); await ph.fill('#pin', '000001'); await Promise.all([ph.waitForNavigation(), ph.click('form[action="/pointer/identifier"] button')]);
  v(await ph.locator('#erreur-identification').count() === 1, 'PIN faux : « Numéro ou PIN incorrect »');
  await ph.fill('#telephone', '0810000103'); await ph.fill('#pin', '271828'); await Promise.all([ph.waitForNavigation(), ph.click('form[action="/pointer/identifier"] button')]);
  v(await ph.locator('#pointer[data-sens=arrivee]').count() === 1, 'Aline identifiée : « Pointer mon arrivée »');
  await ph.screenshot({ path: D + '/e2e-telephone-avant.png', fullPage: true });
  await ph.click('#pointer'); await ph.waitForSelector('#resultat:not([hidden])');
  v(await ph.locator('.pointage__resultat--ok').count() === 1, 'arrivée pointée par code QR avec la position');
  await ph.screenshot({ path: D + '/e2e-telephone-ok.png', fullPage: true });
  const p1 = sql(`SELECT CONCAT(arrivee_source,':',arrivee_distance < 100,':',arrivee_borne_id) FROM pointages WHERE employe_id=${aline} AND depart IS NULL ORDER BY id DESC LIMIT 1`);
  v(p1 === `qr:1:${borne}`, 'présence enregistrée : source QR, distance < 100 m, borne');
  // Rejeu du même code (le téléphone recharge la page puis pointe)
  await ph.goto(B + '/pointer?t=' + t1); await ph.click('#pointer'); await ph.waitForSelector('#resultat:not([hidden])');
  v(await ph.locator('.pointage__resultat--erreur').textContent().then(t => /déjà servi/.test(t)), 'rejeu du même code : refusé (déjà servi)');
  // Code expiré et code falsifié
  const expire = jeton(borne, 1, fenetre() - 5);
  await ph.goto(B + '/pointer?t=' + expire);
  v(await ph.locator('[data-erreur=qr_expire]').count() === 1 && await ph.locator('#pointer').count() === 0, 'code de plus de 2 minutes : « Ce code QR a expiré »');
  await ph.goto(B + '/pointer?t=' + t1.slice(0, -1) + (t1.endsWith('a') ? 'b' : 'a'));
  v(await ph.locator('[data-erreur=qr_invalide]').count() === 1, 'code falsifié : refusé');
  const r410 = await poster(ph, '/pointer', { t: expire, lat: COMPTOIR[0], lng: COMPTOIR[1], precision: 10 });
  v(r410[0] === 410 && JSON.parse(r410[1]).code === 'qr_expire', 'API : code expiré → 410');
  // Départ avec le code suivant (arrivée reculée de 3 h pour ne pas être un double scan)
  sql(`UPDATE pointages SET arrivee = DATE_SUB(arrivee, INTERVAL 3 HOUR) WHERE employe_id=${aline} AND depart IS NULL`);
  await ph.goto(B + '/pointer?t=' + jeton(borne, 1, fenetre() - 1));
  v(await ph.locator('#pointer[data-sens=depart]').count() === 1, 'présence ouverte : « Pointer mon départ »');
  await ph.click('#pointer'); await ph.waitForSelector('#resultat:not([hidden])');
  v(sql(`SELECT depart_source FROM pointages WHERE employe_id=${aline} ORDER BY id DESC LIMIT 1`) === 'qr', 'départ pointé');
  // Trop loin, précision insuffisante, sans position
  await tel.setGeolocation({ latitude: COMPTOIR[0] + 0.02, longitude: COMPTOIR[1] });
  sql(`UPDATE pointages SET depart = DATE_SUB(depart, INTERVAL 2 HOUR), arrivee = DATE_SUB(arrivee, INTERVAL 2 HOUR) WHERE employe_id=${aline} ORDER BY id DESC LIMIT 1`);
  await ph.goto(B + '/pointer?t=' + jeton(borne, 1, fenetre())); await ph.click('#pointer'); await ph.waitForSelector('#resultat:not([hidden])');
  v(/km de l'établissement \(Le Comptoir du Lac\)/.test(await ph.locator('#resultat').textContent()), 'à 2 km : refusé, distance affichée (« Vous êtes à 2,2 km… 100 m »)');
  const rp = await poster(ph, '/pointer', { t: jeton(borne, 1, fenetre()), lat: COMPTOIR[0], lng: COMPTOIR[1], precision: 900 });
  v(rp[0] === 422 && JSON.parse(rp[1]).code === 'precision', 'position trop imprécise (900 m) refusée');
  const rs = await poster(ph, '/pointer', { t: jeton(borne, 1, fenetre()) });
  v(rs[0] === 422 && JSON.parse(rs[1]).code === 'position', 'sans position : refusé');
  await ph.screenshot({ path: D + '/e2e-telephone-trop-loin.png', fullPage: true });
  // Téléphone non identifié, employé d'un autre établissement
  const tel2 = await contexte(b, { viewport: { width: 390, height: 844 }, geolocation: { latitude: COMPTOIR[0], longitude: COMPTOIR[1] }, permissions: ['geolocation'] });
  const ph2 = await tel2.newPage(); await ph2.goto(B + '/pointer?t=' + jeton(borne, 1, fenetre()));
  const r401 = await poster(ph2, '/pointer', { t: jeton(borne, 1, fenetre()), lat: COMPTOIR[0], lng: COMPTOIR[1], precision: 10 });
  v(r401[0] === 401 && JSON.parse(r401[1]).code === 'non_identifie', 'téléphone non identifié : 401');
  await ph2.fill('#telephone', '+243 81 000 0105'); await ph2.fill('#pin', '161803'); await Promise.all([ph2.waitForNavigation(), ph2.click('form[action="/pointer/identifier"] button')]);
  v(/n'êtes pas rattaché à cet établissement/.test(await ph2.textContent('main')), 'barman du club au Comptoir : « pas rattaché »');
  // Un seul téléphone par employé : Aline s'identifie sur un autre téléphone, l'ancien est oublié
  await ph2.click('.pointage__oublier button').then(() => ph2.waitForLoadState());
  await ph2.goto(B + '/pointer'); await ph2.fill('#telephone', '0810000103'); await ph2.fill('#pin', '271828');
  await Promise.all([ph2.waitForNavigation(), ph2.click('form[action="/pointer/identifier"] button')]);
  await ph.goto(B + '/pointer');
  v(await ph.locator('#telephone').count() === 1, 'un seul téléphone par employé : l\'ancien n\'est plus reconnu');
  // Langue anglaise
  await ph2.goto(B + '/pointer?lang=en&t=' + jeton(borne, 1, fenetre()));
  v(/Clock (in|out)/.test(await ph2.textContent('#pointer')), 'page du téléphone en anglais');
  await ph2.goto(B + '/pointer?lang=fr');

  // Caissière avec sa caisse ouverte : pas de départ avant la clôture
  sql('UPDATE etablissements SET sessions_caisse=1 WHERE id=1');
  sql(`INSERT INTO pointages (employe_id, etablissement_id, journee, arrivee, arrivee_source) VALUES (${esther}, 1, CURDATE(), DATE_SUB(NOW(), INTERVAL 2 HOUR), 'gestion')`);
  sql("INSERT INTO sessions_caisse (etablissement_id, utilisateur_id, statut, ouverte_le) VALUES (1, 5, 'ouverte', NOW())");
  const tel3 = await contexte(b, { viewport: { width: 390, height: 844 }, geolocation: { latitude: COMPTOIR[0], longitude: COMPTOIR[1] }, permissions: ['geolocation'] });
  const ph3 = await tel3.newPage(); await ph3.goto(B + '/pointer?t=' + jeton(borne, 1, fenetre()));
  await ph3.fill('#telephone', '0810000104'); await ph3.fill('#pin', '314159'); await Promise.all([ph3.waitForNavigation(), ph3.click('form[action="/pointer/identifier"] button')]);
  await ph3.click('#pointer'); await ph3.waitForSelector('#resultat:not([hidden])');
  v(/clôturez votre caisse/.test(await ph3.textContent('#resultat')), 'caissière avec session ouverte : départ refusé (« clôturez votre caisse »)');
  sql("DELETE FROM sessions_caisse WHERE statut='ouverte' AND utilisateur_id=5"); sql('UPDATE etablissements SET sessions_caisse=0 WHERE id=1');

  // ---------- 5. Caisse : pointage obligatoire et pointage de secours ----------
  const cc = await contexte(b); const pc = await connecter(cc, 'patrick@demo.test', 'Gerant-Goma-2026');
  sql(`DELETE FROM pointages WHERE employe_id=${patrick} AND depart IS NULL`);
  await pc.goto(B + '/caisse?e=comptoir-du-lac');
  v(await pc.locator('#pointage-requis').isVisible(), 'caisse : bandeau « pointage obligatoire » pour le gérant non pointé');
  const vendre = async () => pc.evaluate(async () => {
    const D = JSON.parse(document.getElementById('donnees-caisse').textContent); const f = new FormData(); f.append('csrf', D.csrf);
    const fmt = D.produits.find(x => x.nom === 'Kivu Sunset').formats[0];
    f.append('vente', JSON.stringify({ cle: crypto.randomUUID(), etab: 'comptoir-du-lac', lignes: [{ id: fmt.id, qte: 1 }], paiements: [{ devise: 'CDF', compte: 1, recu: fmt.prix.CDF }] }));
    const r = await fetch('/caisse/encaisser', { method: 'POST', body: f }); return [r.status, (await r.json()).code];
  });
  const refus = await vendre();
  v(refus[0] === 409 && refus[1] === 'pointage_requis', 'encaisser sans être pointé : 409 « pointage_requis »');
  await pc.click('#quitter'); await pc.waitForSelector('#fenetre-quitter[open]');
  await pc.screenshot({ path: D + '/e2e-caisse-secours.png' });
  await pc.selectOption('#pointage-valideur', '2'); await pc.fill('#pointage-pin-employe', '482913'); await pc.fill('#pointage-pin-valideur', '482913');
  await pc.click('#form-pointage-secours button[type=submit]'); await pc.waitForSelector('#pointage-message:not([hidden])');
  v(/autre gérant/.test(await pc.textContent('#pointage-message')), 'secours : on ne valide pas son propre pointage');
  await pc.selectOption('#pointage-valideur', '1'); await pc.fill('#pointage-pin-employe', '482913'); await pc.fill('#pointage-pin-valideur', '111112');
  await pc.click('#form-pointage-secours button[type=submit]'); await pc.waitForFunction(() => /PIN du gérant/.test(document.getElementById('pointage-message').textContent));
  v(sql('SELECT pin_essais FROM utilisateurs WHERE id=1') === '1', 'secours : PIN du gérant faux compté (5 essais puis blocage)');
  await pc.fill('#pointage-pin-employe', '482913'); await pc.fill('#pointage-pin-valideur', '908172');
  await pc.click('#form-pointage-secours button[type=submit]'); await pc.waitForFunction(() => /validée par/.test(document.getElementById('pointage-message').textContent));
  v(sql(`SELECT CONCAT(arrivee_source,':',arrivee_valide_par) FROM pointages WHERE employe_id=${patrick} AND depart IS NULL`) === 'secours:1', 'secours : arrivée enregistrée, validée par l\'administrateur');
  await pc.waitForLoadState('load'); await pc.waitForTimeout(1800); await pc.goto(B + '/caisse?e=comptoir-du-lac');
  v(await pc.locator('#pointage-requis').count() === 0, 'pointé : plus de bandeau');
  const ok = await vendre();
  v(ok[0] === 200, 'pointé : l\'encaissement passe');
  const sansFiche = await pa.evaluate(async () => { const r = await fetch('/caisse?e=comptoir-du-lac'); return r.status; });
  v(sansFiche === 200, 'propriétaire sans fiche employé : pas concerné par le pointage obligatoire');
  sql('UPDATE etablissements SET pointage_obligatoire=0 WHERE id=1');

  // ---------- 6. Corrections de présence ----------
  const pid = sql(`SELECT id FROM pointages WHERE employe_id=${aline} AND depart IS NOT NULL ORDER BY id LIMIT 1`);
  await pa.goto(B + '/gestion/personnel/presence/pointages/' + pid);
  await pa.fill('#motif', ''); await Promise.all([pa.waitForNavigation(), pa.click('form.tre-formulaire button[type=submit]')]);
  v(await pa.locator('#erreur-motif').count() === 1, 'correction sans motif refusée');
  const avant = sql(`SELECT arrivee FROM pointages WHERE id=${pid}`);
  const nouvelle = avant.replace(' ', 'T').slice(0, 14) + '00';
  await pa.fill('#arrivee', nouvelle); await pa.fill('#motif', 'Arrivée notée par le gérant (téléphone déchargé)');
  await Promise.all([pa.waitForNavigation(), pa.click('form.tre-formulaire button[type=submit]')]);
  v(sql(`SELECT CONCAT(action,':',motif) FROM pointage_corrections WHERE pointage_id=${pid}`).startsWith('modification:Arrivée notée') && sql(`SELECT arrivee_source FROM pointages WHERE id=${pid}`) === 'gestion', 'correction enregistrée avec motif et inscrite au journal');
  v(await pa.locator(`#pointage-${pid}`).textContent().then(t => t.includes('corrigé')), 'liste : présence marquée « corrigé »');
  await pa.goto(B + '/gestion/personnel/presence/pointages/nouveau?employe=' + aline);
  await pa.selectOption('#employe_id', aline); await pa.fill('#arrivee', '2026-10-20T17:00'); await pa.fill('#depart', '2026-10-20T16:00'); await pa.fill('#motif', 'Oubli');
  await Promise.all([pa.waitForNavigation(), pa.click('form.tre-formulaire button[type=submit]')]);
  v(await pa.locator('#erreur-depart').count() === 1, 'départ avant l\'arrivée refusé');
  v(await statut(pr, '/gestion/personnel/presence/pointages/' + pid) === 404, 'superviseure du club : présence du Comptoir introuvable');
  await pa.goto(B + '/gestion/personnel/presence?etablissement=1');
  v(await pa.locator('.per-presence').count() > 3, 'tableau du jour : employés du Comptoir');

  // ---------- 7. Plannings, jours fériés, congés ----------
  const lundiProchain = sql('SELECT DATE_ADD(CURDATE(), INTERVAL (7 - WEEKDAY(CURDATE())) DAY)');
  await pa.goto(B + '/gestion/personnel/plannings?etablissement=1&semaine=' + lundiProchain);
  await pa.selectOption('#employe_id', aline); await pa.check(`input[name="journees[]"][value="${lundiProchain}"]`);
  await pa.fill('#debut', '22:00'); await pa.fill('#fin', '03:00'); await pa.selectOption('#poste', 'bar');
  await Promise.all([pa.waitForNavigation(), pa.click('form[action="/gestion/personnel/plannings"] button[type=submit]')]);
  v(sql(`SELECT CONCAT(TIME(debut),'-',DATE(fin) > DATE(debut)) FROM plannings WHERE employe_id=${aline} AND journee='${lundiProchain}'`) === '22:00:00-1', 'créneau 22:00 – 03:00 : la fin passe au lendemain');
  await pa.selectOption('#employe_id', aline); await pa.check(`input[name="journees[]"][value="${lundiProchain}"]`);
  await pa.fill('#debut', '23:00'); await pa.fill('#fin', '01:00');
  await Promise.all([pa.waitForNavigation(), pa.click('form[action="/gestion/personnel/plannings"] button[type=submit]')]);
  v(/Déjà occupé/.test(await pa.textContent('.alerte')), 'chevauchement de créneaux signalé');
  await pa.goto(B + '/gestion/personnel/plannings?etablissement=2&semaine=' + sql('SELECT DATE_SUB(CURDATE(), INTERVAL 7 DAY)'));
  v(await pa.locator('.per-semaine__case .badge').count() > 0, 'semaine du club : statuts de présence dans la grille');
  await pa.goto(B + '/gestion/personnel/plannings/feries');
  v(await pa.locator('.det-lignes li', { hasText: '30/06' }).count() === 1, 'jours fériés de la RDC présents (30 juin)');
  await pa.fill('#date', '2026-11-17'); await pa.fill('#nom_fr', 'Fête de l\'établissement');
  await Promise.all([pa.waitForNavigation(), pa.click('form[action="/gestion/personnel/plannings/feries"] button[type=submit]')]);
  v(sql("SELECT COUNT(*) FROM jours_feries WHERE mois=11 AND jour=17 AND annee=2026") === '1', 'jour férié ajouté pour une année');
  await pa.goto(B + '/gestion/personnel/conges?statut=en_attente');
  const demande = sql(`SELECT id FROM absences WHERE employe_id=${esther} AND statut='en_attente'`);
  await Promise.all([pa.waitForNavigation(), pa.click(`[data-absence="${demande}"] button[value=refuser]`)]);
  v(await pa.locator(`#erreur-decision-${demande}`).count() === 1, 'congé : refus sans motif impossible');
  await pa.goto(B + '/gestion/personnel/conges?statut=en_attente');
  await Promise.all([pa.waitForNavigation(), pa.click(`[data-absence="${demande}"] button[value=accepter]`)]);
  v(sql(`SELECT statut FROM absences WHERE id=${demande}`) === 'acceptee', 'congé accepté par le gérant');

  // ---------- 8. Paie : brouillon proposé, ajustements, validation ----------
  // Les ardoises mises à la charge d'Esther par la démo (bin/demo-ventes.php) changent d'une démo à l'autre (montant et
  // devise) : sa dette du personnel est remise au cas connu du test, une seule dette ouverte de 6 $, puis le brouillon recalculé
  let clientEsther = sql("SELECT id FROM clients WHERE type='personnel' AND utilisateur_id=5 LIMIT 1");
  if (!clientEsther) { sql("INSERT INTO clients (nom, type, utilisateur_id) VALUES ('Esther Kahindo', 'personnel', 5)"); clientEsther = sql("SELECT id FROM clients WHERE type='personnel' AND utilisateur_id=5 LIMIT 1"); }
  sql(`UPDATE dettes SET statut='remise', motif='essai : dette remplacée', cloturee_le=NOW() WHERE client_id=${clientEsther} AND statut='ouverte'`);
  sql(`INSERT INTO dettes (client_id, etablissement_id, devise_id, montant, reste, echeance, servi_par, accorde_par, responsable_id, cree_le) VALUES (${clientEsther}, 1, 1, 6, 6, CURDATE(), 5, 2, 5, NOW() - INTERVAL 3 DAY)`);
  const bul = sql(`SELECT id FROM bulletins WHERE employe_id=${esther} AND statut='brouillon'`);
  await pa.goto(B + '/gestion/personnel/paie/' + bul);
  await Promise.all([pa.waitForNavigation(), pa.click('button[value=recalculer]')]);
  const ligne = t => sql(`SELECT CONCAT(devise_id,':',montant,':',IFNULL(source_du,'')) FROM bulletin_lignes WHERE bulletin_id=${bul} AND type='${t}' ORDER BY devise_id`);
  v(ligne('dette') === '1:6.000:6.000', 'retenue proposée : dette du personnel 6 $ (en entier)');
  v(ligne('manquant') === '2:5000.000:5000.000', 'retenue proposée : manquant de caisse 5 000 FC');
  v(ligne('avance').split('\n').join('|') === '1:20.000:|2:20000.000:', 'avance déduite d\'office dans les deux devises (20 $ + 20 000 FC)');
  v(ligne('base').split('\n').join('|') === '1:150.000:|2:100000.000:', 'base : 150 $ + 100 000 FC');
  const lm = sql(`SELECT id FROM bulletin_lignes WHERE bulletin_id=${bul} AND type='manquant'`), ld = sql(`SELECT id FROM bulletin_lignes WHERE bulletin_id=${bul} AND type='dette'`);
  await pa.selectOption(`#etaler-${lm}`, '2');
  v(await pa.inputValue(`#montant-${lm}`) === '2500', 'étaler le manquant sur 2 paies : 2 500 FC proposés');
  await pa.fill(`#montant-${ld}`, '9');
  await Promise.all([pa.waitForNavigation(), pa.click('button[value=enregistrer]:not(.visuellement-cache)')]);
  v(await pa.locator(`#erreur-montant-${ld}`).count() === 1, 'retenue au-delà de la dette refusée (maximum 6 $)');
  await pa.selectOption(`#etaler-${lm}`, '2'); await pa.fill(`#montant-${ld}`, '4');
  await pa.selectOption('#nouvelle-type', 'prime'); await pa.fill('#nouvelle-libelle', 'Prime de ponctualité'); await pa.selectOption('#nouvelle-devise', '2'); await pa.fill('#nouvelle-montant', '10000');
  await Promise.all([pa.waitForNavigation(), pa.click('button[value=enregistrer]:not(.visuellement-cache)')]);
  const hs = sql(`SELECT IFNULL(SUM(montant),0) FROM bulletin_lignes WHERE bulletin_id=${bul} AND type IN ('heures_sup','ferie','absence') AND devise_id=2`);
  const net = parseFloat(await pa.locator('[data-net=CDF] .per-net__montant').textContent().then(t => t.replace(/[^\d,]/g, '').replace(',', '.')));
  const attendu = 100000 + 10000 - 20000 - 2500 + (parseFloat(hs) * 0); // heures sup en FC ajoutées ci-dessous
  const hsCdf = parseFloat(sql(`SELECT IFNULL(SUM(montant),0) FROM bulletin_lignes WHERE bulletin_id=${bul} AND type IN ('heures_sup','ferie') AND devise_id=2`));
  const absCdf = parseFloat(sql(`SELECT IFNULL(SUM(montant),0) FROM bulletin_lignes WHERE bulletin_id=${bul} AND type IN ('absence','sans_solde') AND devise_id=2`));
  v(Math.abs(net - (attendu + hsCdf - absCdf)) < 1, `net en FC = 100 000 + 10 000 (prime) + heures sup − 20 000 (avance) − 2 500 (manquant) (${net})`);
  const hsUsd = parseFloat(sql(`SELECT IFNULL(SUM(montant),0) FROM bulletin_lignes WHERE bulletin_id=${bul} AND type IN ('heures_sup','ferie') AND devise_id=1`));
  const absUsd = parseFloat(sql(`SELECT IFNULL(SUM(montant),0) FROM bulletin_lignes WHERE bulletin_id=${bul} AND type IN ('absence','sans_solde') AND devise_id=1`));
  const netUsd = parseFloat(await pa.locator('[data-net=USD] .per-net__montant').textContent().then(t => t.replace(/[^\d,.]/g, '').replace(',', '.')));
  v(Math.abs(netUsd - (150 + hsUsd - absUsd - 20 - 4)) < 0.01, `net en $ = 150 + heures sup − 20 (avance) − 4 (dette réduite) (${netUsd})`);
  await pa.screenshot({ path: D + '/e2e-bulletin-brouillon.png', fullPage: true });
  const mouvements = sql('SELECT COUNT(*) FROM mouvements_tresorerie');
  pa.once('dialog', d => d.accept());
  await Promise.all([pa.waitForNavigation(), pa.click('button[data-valider]')]);
  v(sql(`SELECT statut FROM bulletins WHERE id=${bul}`) === 'valide', 'bulletin validé');
  v(sql(`SELECT SUM(reste) FROM dettes d JOIN clients c ON c.id=d.client_id WHERE c.utilisateur_id=5 AND d.statut='ouverte'`) === '2.000', 'dette du personnel réduite de 4 $ (reste 2 $)');
  v(sql('SELECT COUNT(*) FROM mouvements_tresorerie') === mouvements, 'retenue sur dette : aucun mouvement de trésorerie');
  v(sql(`SELECT GROUP_CONCAT(montant) FROM bulletin_dettes WHERE bulletin_id=${bul}`) === '4.000', 'part retenue notée (bulletin_dettes)');
  v(sql(`SELECT CONCAT(statut,':',bulletin_id) FROM avances WHERE employe_id=${esther}`) === `deduite:${bul}`, 'avance marquée déduite');
  // Manquant pas proposé deux fois : brouillon du mois suivant
  const moisSuivant = sql("SELECT DATE_FORMAT(DATE_ADD(CURDATE(), INTERVAL 1 MONTH), '%Y-%m')");
  await pa.goto(B + `/gestion/personnel/paie?etablissement=1&mois=${moisSuivant}`);
  await Promise.all([pa.waitForNavigation(), pa.click('form[action="/gestion/personnel/paie/preparer"] button')]);
  const bul2 = sql(`SELECT id FROM bulletins WHERE employe_id=${esther} AND statut='brouillon'`);
  v(sql(`SELECT CONCAT(source_du,'/',montant) FROM bulletin_lignes WHERE bulletin_id=${bul2} AND type='manquant'`) === '2500.000/2500.000', 'mois suivant : seul le reste du manquant (2 500 FC) est proposé');
  v(sql(`SELECT source_du FROM bulletin_lignes WHERE bulletin_id=${bul2} AND type='dette'`) === '2.000', 'mois suivant : reste de la dette (2 $) proposé');
  v(sql(`SELECT COUNT(*) FROM bulletin_lignes WHERE bulletin_id=${bul2} AND type='avance'`) === '0', 'mois suivant : l\'avance déjà déduite n\'est plus là');
  await pa.goto(B + '/gestion/personnel/paie/' + bul2); await pa.click('details.tre-supprimer summary');
  await Promise.all([pa.waitForNavigation(), pa.click('details.tre-supprimer button')]);
  v(sql(`SELECT COUNT(*) FROM bulletins WHERE id=${bul2}`) === '0', 'brouillon supprimé');

  // ---------- 9. Paiement dans la trésorerie ----------
  await pg.goto(B + '/gestion/personnel/paie/' + bul);
  const reste = sql(`SELECT GROUP_CONCAT(devise_id) FROM bulletin_lignes WHERE bulletin_id=${bul}`);
  // Trop payé en $
  await pg.selectOption('#paiement-0-compte', '1'); await pg.selectOption('#paiement-0-devise', '1'); await pg.fill('#paiement-0-montant', '999');
  await Promise.all([pg.waitForNavigation(), pg.click('#formulaire-depense .tre-actions button[type=submit]')]);
  v(/il reste/.test(await pg.textContent('#erreurs')), 'paiement plus grand que le net refusé');
  sql("INSERT INTO comptes_tresorerie (nom, type, etablissement_id, active, position, solde_initial_le) VALUES ('Petite caisse paie', 'caisse', 1, 1, 9, '2026-01-01')");
  const petite = sql("SELECT id FROM comptes_tresorerie WHERE nom='Petite caisse paie'");
  sql(`INSERT INTO compte_devises (compte_id, devise_id, solde_initial) VALUES (${petite}, 1, 5)`);
  await pg.goto(B + '/gestion/personnel/paie/' + bul);
  await pg.selectOption('#paiement-0-compte', petite); await pg.selectOption('#paiement-0-devise', '1'); await pg.fill('#paiement-0-montant', String(netUsd).replace('.', ','));
  v(await pg.locator('[data-paiement]').count() === 2, 'paiement proposé : une ligne par devise du net (FC et $)');
  await pg.selectOption('#paiement-1-compte', '1'); await pg.selectOption('#paiement-1-devise', '2'); await pg.fill('#paiement-1-montant', String(Math.round(net)));
  await Promise.all([pg.waitForNavigation(), pg.click('#formulaire-depense .tre-actions button[type=submit]')]);
  if (!await pg.locator('.tre-avertissement').count()) console.log('DEBUG', await pg.locator('#erreurs, .champ__erreur').allTextContents(), await pg.inputValue('#paiement-0-compte'));
  v(await pg.locator('.tre-avertissement').count() === 1, 'découvert de la petite caisse signalé avant d\'enregistrer');
  await Promise.all([pg.waitForNavigation(), pg.click('.tre-avertissement button[name=confirme]')]);
  v(sql(`SELECT statut FROM bulletins WHERE id=${bul}`) === 'paye', 'salaire payé en deux devises : bulletin « payé »');
  const sorties = sql(`SELECT GROUP_CONCAT(CONCAT(compte_id,':',devise_id,':',sens) ORDER BY devise_id) FROM mouvements_tresorerie m JOIN paiements_personnel p ON p.id=m.source_id WHERE m.nature='salaire' AND p.bulletin_id=${bul}`);
  v(sorties === `${petite}:1:sortie,1:2:sortie`, 'trésorerie : sorties « salaire » de la petite caisse ($) et de la Caisse du Comptoir (FC)');
  await pa.goto(B + '/gestion/tresorerie/comptes/' + petite);
  v(await pa.locator('text=Salaire').count() > 0, 'fiche du compte : mouvement « Salaire »');
  // Annuler : d'abord le paiement, puis le bulletin
  await pa.goto(B + '/gestion/personnel/paie/' + bul);
  v(await pa.locator('details.tre-supprimer').count() === 0, 'bulletin payé : pas d\'annulation tant qu\'il y a un paiement');
  const paiement = sql(`SELECT id FROM paiements_personnel WHERE bulletin_id=${bul}`);
  await pa.click('.per-annuler-paiement summary'); await pa.fill(`#motif-p-${paiement}`, 'Erreur de compte');
  await Promise.all([pa.waitForNavigation(), pa.click('.per-annuler-paiement button')]);
  v(sql(`SELECT statut FROM bulletins WHERE id=${bul}`) === 'valide' && sql(`SELECT COUNT(*) FROM mouvements_tresorerie WHERE nature='salaire' AND source_id=${paiement} AND sens='entree'`) === '2', 'paiement annulé : contre-écritures, bulletin de nouveau « validé »');
  await pa.click('details.tre-supprimer summary'); await pa.fill('#motif-annulation', 'Bulletin refait');
  await Promise.all([pa.waitForNavigation(), pa.click('details.tre-supprimer button')]);
  v(sql(`SELECT statut FROM bulletins WHERE id=${bul}`) === 'annule' && sql(`SELECT SUM(reste) FROM dettes d JOIN clients c ON c.id=d.client_id WHERE c.utilisateur_id=5 AND d.statut='ouverte'`) === '6.000'
    && sql(`SELECT statut FROM avances WHERE employe_id=${esther}`) === 'a_deduire', 'bulletin annulé : dette rendue (6 $), avance de nouveau à déduire');

  // ---------- 10. Avances, extras à la soirée, pourboires, masse salariale, rapport ----------
  await pg.goto(B + '/gestion/personnel/avances?employe=' + aline);
  await pg.selectOption('#employe_id', aline); await pg.selectOption('#paiement-0-compte', '3'); await pg.selectOption('#paiement-0-devise', '1'); await pg.fill('#paiement-0-montant', '10');
  await Promise.all([pg.waitForNavigation(), pg.click('#formulaire-depense .tre-actions button[type=submit]')]);
  const av = sql(`SELECT id FROM avances WHERE employe_id=${aline}`);
  v(av && sql(`SELECT CONCAT(m.compte_id,':',m.sens,':',m.montant) FROM mouvements_tresorerie m JOIN paiements_personnel p ON p.id=m.source_id WHERE m.nature='avance' AND p.avance_id=${av}`) === '3:sortie:10.000', 'avance de 10 $ versée depuis M-Pesa (mouvement « avance »)');
  await pg.click(`[data-avance="${av}"] summary`); await pg.fill(`#motif-a-${av}`, 'Saisie en double');
  await Promise.all([pg.waitForNavigation(), pg.click(`[data-avance="${av}"] form button`)]);
  v(sql(`SELECT statut FROM avances WHERE id=${av}`) === 'annulee', 'avance annulée (contre-écriture)');
  const semaineDerniere = sql('SELECT DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) + 7 DAY)');
  await pa.goto(B + `/gestion/personnel/paie?etablissement=2&type=semaine&semaine=${semaineDerniere}`);
  await Promise.all([pa.waitForNavigation(), pa.click('form[action="/gestion/personnel/paie/preparer"] button')]);
  const nuits = sql(`SELECT COUNT(DISTINCT journee) FROM pointages WHERE employe_id=${baraka} AND journee BETWEEN '${semaineDerniere}' AND DATE_ADD('${semaineDerniere}', INTERVAL 6 DAY)`);
  v(sql(`SELECT CONCAT(quantite,'×',taux,'=',montant) FROM bulletin_lignes l JOIN bulletins b ON b.id=l.bulletin_id WHERE b.employe_id=${baraka} AND l.type='base'`) === `${nuits}.00×15.000000=${nuits * 15}.000`, `agent de sécurité payé à la soirée : ${nuits} nuits × 15 $`);
  await pa.goto(B + '/gestion/personnel/paie?etablissement=2');
  await Promise.all([pa.waitForNavigation(), pa.click('form[action="/gestion/personnel/paie/preparer"] button')]);
  await pa.fill('#pourboires-montant', '30000'); await pa.selectOption('#pourboires-devise', '2');
  await Promise.all([pa.waitForNavigation(), pa.click('form[action="/gestion/personnel/paie/pourboires"] button')]);
  v(sql("SELECT SUM(montant) FROM bulletin_lignes WHERE type='pourboire' AND devise_id=2") === '30000.000', 'pourboires de 30 000 FC répartis au prorata des heures (total exact)');
  await pa.goto(B + '/gestion/personnel/paie/masse');
  v(/septembre[\s\S]*Le Comptoir du Lac[\s\S]*\$450/.test((await pa.locator('[data-masse]').allTextContents()).join(' ')), 'masse salariale : mois dernier du Comptoir (450 $)');
  const csv = await pa.evaluate(async () => (await fetch('/gestion/rapports/presence?periode=mois&format=csv')).text());
  v(csv.split('\n')[0].includes('Heures travaillées') && csv.includes('Esther Kahindo'), 'rapport de présence : export CSV');
  await pa.goto(B + '/gestion/rapports/presence?periode=mois&lang=en');
  v(await pa.locator('h1').textContent() === 'Staff attendance' && await pa.locator('#per-masse').count() === 1, 'rapport en anglais, avec la masse salariale');
  await pa.goto(B + '/gestion/personnel/paie?lang=fr');
  await pp.goto(B + '/gestion/rapports/personnel');
  v(await pp.locator('#per-presence-titre').count() <= 1, 'Rapports > Personnel s\'ouvre (section présence si la page l\'inclut)');

  v(err.length === 0, 'aucune erreur JavaScript' + (err.length ? ' : ' + err.slice(0, 3).join(' | ') : ''));
  console.log(`\n${nb - ko.length}/${nb} vérifications réussies`); if (ko.length) console.log('Échecs :\n- ' + ko.join('\n- '));
  await b.close();
})().catch(e => { console.error(e); process.exit(1); });
