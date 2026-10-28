// 重试重新打开当前页面；直播的数据、订阅和组件生命周期仍由正式宿主负责。
document.addEventListener('click', (event) => {
  if (!(event.target instanceof Element)) return
  const retry = event.target.closest('button[data-liveblog-retry]')
  if (!retry?.closest('.liveblog-wordpress')) return
  window.location.reload()
})

// 引导器自身未能下载时提供局部提示；成功加载后的错误继续使用引导器自己的界面。
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
