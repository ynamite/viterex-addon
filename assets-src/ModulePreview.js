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

  function asyncEdit() {
    if (rex.page !== 'content/edit') {
      return
    }

    const editButtons = $('a.btn-edit[href*="slice_id"]')
    editButtons
      .off('click.asyncEdit')
      .on('click.asyncEdit', async function (e) {
        e.preventDefault()
        const slice = $(this).closest('.rex-slice')
        const sliceId = slice.attr('id')
        if (!sliceId) {
          return
        }
        restore()
        rex_loader.show()
        try {
          const result = await fetch(this.href, {
            method: 'GET',
            headers: {
              'Content-Type': 'text/html',
              'X-Requested-With': 'XMLHttpRequest'
            }
          })
          if (!result.ok) throw new Error('Network response was not ok')
          const html = await result.text()
          const resultSlice = $(html).find(`#${sliceId}`)

          if (resultSlice.length) {
            setSliceEdit(sliceId, slice)
            slice.replaceWith(resultSlice)
            $(document).trigger('rex:ready', [resultSlice])
            resultSlice[0].scrollIntoView({ behavior: 'auto' })
          }
          rex_loader.hide()
        } catch (error) {
          console.error('Error editing slice:', error)
        }
      })
    const setSliceEdit = (id, element) => {
      rex.isSliceEditing = true
      rex.sliceEditCurrent = element.clone(true, true)
      rex.sliceEditCurrentId = id
    }
    const restore = () => {
      if (rex.isSliceEditing && rex.sliceEditCurrent) {
        const currentSlice = $(`#${rex.sliceEditCurrentId}`)
        if (currentSlice.length && currentSlice.hasClass('rex-slice-edit')) {
          currentSlice.replaceWith(rex.sliceEditCurrent)
        }
        rex.isSliceEditing = false
        rex.sliceEditCurrent = null
      }
    }
  }

  $(document).on('rex:ready rex:selectMedia rex:YForm_selectData', function () {
    iframePreviews()
    asyncEdit()
    debouncedScrollToSlice()
  })
})(jQuery)
