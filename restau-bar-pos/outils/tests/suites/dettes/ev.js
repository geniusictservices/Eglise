const { chromium } = require('playwright'); const fs = require('fs'); const { execSync } = require('child_process');
const MDP = fs.readFileSync(__dirname + '/../instance.txt', 'utf8').match(/Mot de passe\s*:\s*(\S+)/)[1];
(async () => {
  const b = await chromium.launch(); const p = await (await b.newContext()).newPage();
  await p.goto('http://integ.localhost:8250/gestion/connexion'); await p.fill('#email', 'admin@demo.test'); await p.fill('#mot_de_passe', MDP); await Promise.all([p.waitForNavigation(), p.click('button[type=submit]')]);
  const id = execSync(`mysql -N rbp_integ -e "SELECT e.id FROM evenements e WHERE e.debut < NOW() AND EXISTS (SELECT 1 FROM billets b WHERE b.evenement_id=e.id) ORDER BY e.debut DESC LIMIT 1"`).toString().trim();
  const r = await p.goto('http://integ.localhost:8250/gestion/evenements/' + id);
  const t = await p.textContent('main');
  console.log('event', id, r.status(), 'Entrées:', t.includes('Entrées'), 'Sans catégorie:', t.includes('Sans catégorie'));
  const r2 = await p.goto('http://integ.localhost:8250/gestion/tableau-de-bord'); console.log('tableau', r2.status());
  await b.close();
})();
