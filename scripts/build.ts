import { mkdir, readdir, readFile, rm, writeFile } from 'node:fs/promises'
import { resolve } from 'node:path'
import { build, transform, type Plugin } from 'esbuild'
import { zipSync, type Zippable } from 'fflate'
import PhpParser from 'php-parser'

const root = resolve(import.meta.dirname, '..')
const source = resolve(root, 'src')
const dist = resolve(root, 'dist')
const output = resolve(dist, 'liveblog')
const dependencies = [
  'wp-blocks',
  'wp-block-editor',
  'wp-components',
  'wp-element',
  'wp-i18n',
]

// Map imported WordPress modules to site dependencies so the bundle excludes React and the editor.
const wordpressExternals: Plugin = {
  name: 'wordpress-externals',
  /** Expose site modules as globals. @param builder - The active esbuild context. */
  setup(builder) {
    builder.onResolve({ filter: /^react$/ }, ({ path }) => ({
      path,
      namespace: 'wordpress',
    }))
    builder.onLoad({ filter: /.*/, namespace: 'wordpress' }, () => ({
      contents: 'module.exports = window.wp.element',
      loader: 'js',
    }))
  },
}

const parser = new PhpParser.Engine({
  parser: { version: '8.1', suppressErrors: false },
})
const phpCommentTokens = new Set(['T_COMMENT', 'T_DOC_COMMENT'])
const legalComment =
  /@license\b|@copyright\b|SPDX-License-Identifier:|\bcopyright\b|^\/\*![^*]/i

/**
 * Strip internal PHP comments by token, retaining the plugin header and collecting license notices.
 * @param content - PHP source to package.
 * @param name - Output filename used to identify the header and report syntax errors.
 * @returns Executable code and license notices for distribution.
 */
function preparePhp(content: string, name: string) {
  parser.parseCode(content, name)
  const tokens = parser.tokenGetAll(content)
  const original = tokens
    .map((token) => (typeof token === 'string' ? token : token[1]))
    .join('')
  if (original !== content)
    throw new Error(`PHP tokenization changed the source text: ${name}`)

  const notices: string[] = []
  let headers = 0
  const code = tokens
    .map((token) => {
      if (typeof token === 'string') return token
      const [kind, text] = token
      if (!phpCommentTokens.has(kind)) return text
      if (name === 'liveblog.php' && /^\s*\*?\s*Plugin Name:/m.test(text)) {
        headers += 1
        return text
      }
      if (legalComment.test(text)) notices.push(text)
      // Replace comments with whitespace to preserve line numbers, token boundaries and string literals.
      return text.replace(/[^\r\n]/g, ' ')
    })
    .join('')
  if (name === 'liveblog.php' && headers !== 1)
    throw new Error('Exactly one WordPress plugin header must be preserved.')
  parser.parseCode(code, name)
  return { code, notices }
}

// Package only the listed runtime files to keep development files out of the ZIP.
const files = [
  'liveblog.php',
  'render.php',
  'sanitize.php',
  'cache.php',
  'settings.php',
  'block.json',
  'readme.txt',
  'editor.css',
  'frontend.css',
  ...(await readdir(source)).filter((name) =>
    /^(?:LICEN[CS]E|NOTICE)(?:\.(?:txt|md))?$/i.test(name)
  ),
].sort()
const contents = await Promise.all(
  files.map(async (name) => {
    const content = await readFile(resolve(source, name), 'utf8')
    if (name.endsWith('.php')) parser.parseCode(content, name)
    return [name, content] as const
  })
)
const manifest = JSON.parse(
  await readFile(resolve(root, 'package.json'), 'utf8')
) as { version: string }
const metadata = JSON.parse(
  await readFile(resolve(source, 'block.json'), 'utf8')
) as { version: string }
const bootstrap = contents.find(([name]) => name === 'liveblog.php')?.[1]
const readme = contents.find(([name]) => name === 'readme.txt')?.[1]
if (
  !/^\d+\.\d+\.\d+$/.test(manifest.version) ||
  metadata.version !== manifest.version ||
  !bootstrap?.includes(`Version: ${manifest.version}\n`) ||
  !bootstrap.includes(`const VERSION = '${manifest.version}';`) ||
  !readme?.includes(`Stable tag: ${manifest.version}\n`)
) {
  throw new Error(
    'Plugin, block, readme and package versions must match x.y.z.'
  )
}

await rm(dist, { recursive: true, force: true })
await mkdir(output, { recursive: true })
for (const [name, content] of contents) {
  let code = content
  let notices = ''
  if (name.endsWith('.php')) {
    const prepared = preparePhp(content, name)
    code = prepared.code
    notices = prepared.notices.join('\n\n')
  } else if (name.endsWith('.css')) {
    const prepared = await transform(content, {
      loader: 'css',
      minifyWhitespace: true,
      legalComments: 'external',
      sourcemap: false,
    })
    code = prepared.code
    notices = prepared.legalComments
  }
  await writeFile(resolve(output, name), code)
  if (notices) await writeFile(resolve(output, `${name}.LEGAL.txt`), notices)
}
await build({
  entryPoints: [resolve(source, 'editor.tsx'), resolve(source, 'frontend.ts')],
  outdir: output,
  bundle: true,
  platform: 'browser',
  format: 'iife',
  target: 'es2022',
  jsxFactory: 'React.createElement',
  jsxFragment: 'React.Fragment',
  plugins: [wordpressExternals],
  sourcemap: false,
  minifyWhitespace: true,
  legalComments: 'external',
  logLevel: 'info',
})
const handles = [...dependencies]
  .sort()
  .map((handle) => `'${handle}'`)
  .join(', ')
const asset = `<?php\n/** WordPress loads these editor dependencies and the plugin version after installation. */\nreturn array('dependencies' => array(${handles}), 'version' => '${manifest.version}');\n`
await writeFile(
  resolve(output, 'editor.asset.php'),
  preparePhp(asset, 'editor.asset.php').code
)

const archive: Zippable = {}
for (const name of (await readdir(output)).sort()) {
  archive[`liveblogai/${name}`] = [
    new Uint8Array(await readFile(resolve(output, name))),
    { mtime: new Date('2020-01-01T00:00:00Z') },
  ]
}
export const artifact = {
  version: manifest.version,
  path: resolve(dist, 'liveblog.zip'),
}

await writeFile(artifact.path, zipSync(archive, { level: 9 }))
console.log(`Built LiveblogAI ${artifact.version}: ${artifact.path}`)
