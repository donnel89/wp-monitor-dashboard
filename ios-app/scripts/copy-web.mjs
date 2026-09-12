/* מעתיק את אפליקציית הווב מ-baby-sleep אל www, כדי שיישאר מקור אמת אחד */
import { cp, rm, mkdir } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const root = dirname(dirname(fileURLToPath(import.meta.url)));
const src = join(root, '..', 'baby-sleep');
const dst = join(root, 'www');

if (!existsSync(src)) {
  console.error('לא נמצאה תיקיית baby-sleep ליד ios-app');
  process.exit(1);
}

await rm(dst, { recursive: true, force: true });
await mkdir(dst, { recursive: true });
for (const name of ['index.html', 'manifest.webmanifest', 'icons']) {
  await cp(join(src, name), join(dst, name), { recursive: true });
}
console.log('הועתק אל www: index.html, manifest.webmanifest, icons');
console.log('אין Service Worker בגרסת iOS - הקבצים ממילא נמצאים בתוך האפליקציה');
