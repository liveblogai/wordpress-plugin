// Retry by reopening the page. The embed host manages data, subscriptions and component lifecycles.
document.addEventListener('click', (event) => {
  if (!(event.target instanceof Element)) return
  const retry = event.target.closest('button[data-liveblog-retry]')
  if (!retry?.closest('.liveblog-wordpress')) return
  window.location.reload()
})

// Show an inline notice if the bootstrap script fails to download. The script handles errors after loading.
document.addEventListener(
  'error',
  (event) => {
    if (
      !(event.target instanceof HTMLScriptElement) ||
      event.target.id !== 'liveblog-embed-js'
    )
      return
    for (const container of document.querySelectorAll<HTMLElement>(
      '[data-liveblog-js]'
    )) {
      const template = container.querySelector<HTMLTemplateElement>(
        'template[data-liveblog-script-error]'
      )
      const target = container.querySelector<HTMLElement>('[data-liveblog-id]')
      if (template && target)
        target.replaceChildren(template.content.cloneNode(true))
    }
  },
  true
)
