import { copyFileSync, mkdirSync, rmSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const projectRoot = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const source = resolve(projectRoot, 'public/spa/index.html');
const destination = resolve(projectRoot, 'resources/spa/index.html');

mkdirSync(dirname(destination), { recursive: true });
copyFileSync(source, destination);
// The shell must be served by the controller (with bootstrap), never straight off disk.
rmSync(source);
console.log(`SPA shell placed at ${destination}`);
