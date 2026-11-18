// List Chinese t('…') keys in src/ that have no English translation in src/i18n/en.json.
// Usage: node scripts/i18n-missing.mjs   (exit code 1 when something is missing)
import { readFileSync, readdirSync, statSync } from 'node:fs'
import { join } from 'node:path'

const root = new URL('../src/', import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, '$1')
const en = JSON.parse(readFileSync(join(root, 'i18n', 'en.json'), 'utf8'))

function* files(dir) {
  for (const name of readdirSync(dir)) {
    const p = join(dir, name)
    if (statSync(p).isDirectory()) { if (name !== 'i18n') yield* files(p) }
    else if (/\.(vue|ts)$/.test(name) && !name.endsWith('.d.ts')) yield p
  }
}

const missing = new Map()
const re = /\bt\(\s*'((?:[^'\\]|\\.)*)'/g
for (const file of files(root)) {
  const src = readFileSync(file, 'utf8')
  for (const m of src.matchAll(re)) {
    const key = m[1].replace(/\\'/g, "'").replace(/\\n/g, '\n').replace(/\\\\/g, '\\')
    if (/[一-鿿]/.test(key) && !(key in en) && !missing.has(key)) missing.set(key, file.slice(root.length))
  }
}

if (missing.size) {
  console.log(JSON.stringify(Object.fromEntries(missing), null, 2))
  process.exit(1)
}
console.log('All Chinese UI text has an English translation.')
