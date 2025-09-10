function iframePreviews() {
  const $iframes = $('iframe[data-iframe-preview]')
  const resizeIframe = (event) => {
    let hasScrolled = false
    // Only accept messages from your iframe’s origin
    if (event.data?.type === 'resize' && event.data?.id) {
      const iframe = $iframes.filter(`[data-slice-id="${event.data.id}"]`)[0]
      if (!iframe) {
        return
      }
      iframe.style.height = event.data.height + 'px'
      if (!hasScrolled) {
        hasScrolled = true
        const pageHash = window.location.hash.substring(1)
        if (pageHash) {
          // Scroll to the hash after a short delay to ensure the iframe has resized
          setTimeout(() => {
            const targetElement = document.getElementById(pageHash)
            if (targetElement) {
              targetElement.scrollIntoView({ behavior: 'smooth' })
            }
          }, 100)
        }
      }
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
