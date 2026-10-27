// Outils communs des tests de la console
const { execSync } = require('child_process');
const D = __dirname;
const A = 'http://admin.localhost:8180';
const ENV = { ...process.env, RBP_CONSOLE_CONFIG: D + '/console.php' };
let nb = 0, ko = 0;
function v(obtenu, attendu, libelle) {
  nb++;
  const ok = attendu instanceof RegExp ? attendu.test(String(obtenu)) : String(obtenu) === String(attendu);
  if (ok) console.log('ok    ' + libelle); else { ko++; console.log(`ÉCHEC ${libelle} (attendu « ${attendu} », obtenu « ${obtenu} »)`); }
}
// Date du jour à Goma (celle de l'application), pas celle du serveur MySQL
const GOMA = () => execSync('TZ=Africa/Lubumbashi date +%F').toString().trim();
function q(base, sql) {
  sql = sql.replace(/CURDATE\(\)/g, `'${GOMA()}'`);
  return execSync(`mysql -N --default-character-set=utf8mb4 ${base}`, { input: sql }).toString().trim();
}
function php(cmd) {
  try { return execSync(`cd /home/user/Eglise/restau-bar-pos && php ${cmd}`, { env: ENV }).toString(); }
  catch (e) { return 'CODE ' + e.status + ' ' + e.stdout + e.stderr; }
}
async function connecterConsole(p, email = 'super@plateforme.test', mdp = 'Console-Goma-2026!') {
  await p.goto(A + '/connexion');
  await p.fill('#email', email);
  await p.fill('#mot_de_passe', mdp);
  await Promise.all([p.waitForNavigation(), p.click('button[type=submit]')]);
}
async function connecterInstance(p, sd, email, mdp) {
  await p.goto(`http://${sd}.localhost:8180/gestion/connexion`);
  await p.fill('#email', email);
  await p.fill('#mot_de_passe', mdp);
  await Promise.all([p.waitForNavigation(), p.click('button[type=submit]')]);
}
function bilan() { console.log(`\n${nb - ko}/${nb}`); return ko; }
function surveiller(p, erreurs) {
  p.on('pageerror', e => erreurs.push('JS ' + e.message));
  p.on('console', m => { if (m.type() === 'error' && !/Failed to load resource/.test(m.text())) erreurs.push('console ' + m.text()); });
}
module.exports = { v, q, php, A, D, connecterConsole, connecterInstance, bilan, surveiller };
