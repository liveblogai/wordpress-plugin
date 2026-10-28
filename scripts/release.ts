import { createHash } from 'node:crypto'
import { readFile, writeFile } from 'node:fs/promises'
import { dirname, resolve } from 'node:path'
import { artifact } from './build.ts'

// 构建成功后生成固定版本的分发包与校验值，不上传、创建 Git tag 或修改远程发布状态。
const filename = `liveblog-${artifact.version}.zip`
const path = resolve(dirname(artifact.path), filename)
const archive = await readFile(artifact.path)
const digest = createHash('sha256').update(archive).digest('hex')

await writeFile(path, archive)
await writeFile(`${path}.sha256`, `${digest}  ${filename}\n`)
console.log(`Release archive: ${path}`)
console.log(`SHA-256 checksum: ${path}.sha256`)
