function iframePreviews() {
  const $iframes = $('iframe[data-iframe-preview]')
  const resizeIframe = (event) => {
    // Only accept messages from your iframe’s origin
    if (event.data?.type === 'resize' && event.data?.id) {
      const iframe = $iframes.filter(`[data-slice-id="${event.data.id}"]`)[0]
      if (!iframe) {
        return
      }
      iframe.style.height = event.data.height + 'px'
    }
  }

  window.removeEventListener('message', resizeIframe)
  if (!$iframes.length) {
    return
  }
  window.addEventListener('message', resizeIframe)
}

$(document).on('rex:ready rex:selectMedia rex:YForm_selectData', function () {
  iframePreviews()
})
