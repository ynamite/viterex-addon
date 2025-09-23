;(function ($) {
  'use strict'
  const DEBOUNCE_DELAY = 50
  const debouncedScrollToSlice = debounce(() => scrollToSlice(), DEBOUNCE_DELAY)

  let debounceTimer = null

  function debounce(func, delay) {
    return function (...args) {
      const context = this
      clearTimeout(debounceTimer)
      debounceTimer = setTimeout(() => func.apply(context, args), delay)
    }
  }

  function iframePreviews() {
    const $iframes = $('iframe[data-iframe-preview]')
    const resizeIframe = (event) => {
      // Only accept messages from your iframe’s origin
      if (event.data?.type === 'resize' && event.data?.id) {
        const iframe = $iframes.filter(`[data-slice-id="${event.data.id}"]`)[0]
        if (!iframe) {
          return
        }
        debouncedScrollToSlice()
        iframe.style.height = event.data.height + 'px'
      }
    }

    window.removeEventListener('message', resizeIframe)
    if (!$iframes.length) {
      return
    }
    window.addEventListener('message', resizeIframe)
  }

  function scrollToSlice() {
    const pageHash = window.location.hash.substring(1)
    if (pageHash) {
      // Scroll to the hash after a short delay to ensure the iframe has resized
      const targetElement = document.getElementById(pageHash)
      if (targetElement) {
        clearTimeout(debounceTimer)
        targetElement.scrollIntoView({ behavior: 'auto' })
      }
    }
  }

  $(document).on('rex:ready rex:selectMedia rex:YForm_selectData', function () {
    iframePreviews()
  })
})(jQuery)
