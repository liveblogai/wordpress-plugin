import { createHash } from 'node:crypto'
import { readFile, writeFile } from 'node:fs/promises'
import { dirname, resolve } from 'node:path'
import { artifact } from './build.ts'

// Create a versioned archive and checksum after building. Uploads, tags and remote releases are separate steps.
const filename = `liveblog-${artifact.version}.zip`
const path = resolve(dirname(artifact.path), filename)
const archive = await readFile(artifact.path)
const digest = createHash('sha256').update(archive).digest('hex')

await writeFile(path, archive)
await writeFile(`${path}.sha256`, `${digest}  ${filename}\n`)
console.log(`Release archive: ${path}`)
console.log(`SHA-256 checksum: ${path}.sha256`)
