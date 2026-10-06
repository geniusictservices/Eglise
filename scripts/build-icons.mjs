// Extrait les icônes Lucide utilisées par l'interface dans resources/icons/icons.php.
// Usage : node scripts/build-icons.mjs
import { readFileSync, writeFileSync } from 'node:fs';

const names = [
    'house', 'layout-dashboard', 'users', 'user', 'user-plus', 'user-round', 'shield-check', 'shield-alert',
    'key-round', 'network', 'building-2', 'landmark', 'coins', 'banknote', 'wallet', 'hand-coins',
    'arrow-left-right', 'scroll-text', 'history', 'settings', 'languages', 'log-out', 'menu', 'x',
    'chevron-down', 'chevron-right', 'chevron-left', 'chevrons-up-down', 'plus', 'pencil', 'trash-2',
    'search', 'check', 'circle-check', 'triangle-alert', 'info', 'download', 'smartphone', 'monitor',
    'share', 'phone', 'mail', 'map-pin', 'calendar', 'calendar-days', 'lock', 'eye', 'eye-off',
    'fingerprint', 'ellipsis', 'bell', 'circle-help', 'refresh-cw', 'arrow-right', 'book-open',
    'file-text', 'heart-handshake', 'link', 'circle-dollar-sign', 'badge-check', 'undo-2', 'tag',
    'type', 'globe', 'clock', 'square-plus', 'wifi-off', 'image', 'save', 'log-in',
    'contact-round', 'id-card', 'house-plus', 'users-round', 'baby', 'cake', 'filter', 'sliders-horizontal',
    'upload', 'file-spreadsheet', 'archive', 'archive-restore', 'sparkles', 'droplets', 'briefcase',
    'graduation-cap', 'heart', 'user-check', 'user-x', 'qr-code', 'printer', 'hash', 'camera', 'milestone', 'award', 'message-circle',
];

const out = {};
for (const name of names) {
    const svg = readFileSync(`node_modules/lucide-static/icons/${name}.svg`, 'utf8');
    const inner = svg.slice(svg.indexOf('>', svg.indexOf('<svg')) + 1, svg.lastIndexOf('</svg>'));
    out[name] = inner.replace(/\s*\n\s*/g, '').trim();
}

const php = "<?php\n\n// Icônes Lucide (licence ISC) — généré par scripts/build-icons.mjs\nreturn [\n"
    + Object.entries(out).map(([k, v]) => `    '${k}' => '${v.replace(/'/g, "\\'")}',`).join('\n')
    + '\n];\n';
writeFileSync('resources/icons/icons.php', php);
console.log(`${names.length} icônes écrites.`);
