import './ViteRexBadge.module.css'
import classes from './ViteRexBadge.module.css'

const scriptTag = document.getElementById('viterex-badge-script')
if (!scriptTag) {
  console.warn(
    'ViteRexBadge: No script tag with ID "viterex-badge-script" found.'
  )
} else {
  const version = scriptTag.getAttribute('data-version') || 'unknown'
  const isDev = scriptTag.getAttribute('data-is-dev') === 'true'
  console.log(`ViteRexBadge v${version} initialized. Dev mode: ${isDev}`)
  const redaxoVersion = scriptTag.getAttribute('data-rex-version') || 'unknown'

  // Create badge element
  const badge = document.createElement('div')
  badge.id = 'viterex-badge'
  badge.className = isDev ? classes.dev : classes.prod
  badge.className += ' ' + classes.wrapper
  badge.title = `ViteRex - ${isDev ? 'Development Mode' : 'Production Mode'}`
  badge.innerHTML = `<div class="${classes.badge}">
    <div class="${classes.label}"><span><b>Vite</b>Rex</span><span class="${classes.version}">${version}</span></div>
    <div class="${classes.versionWrapper}">
      <span class="${classes.label}">${isDev ? 'Dev' : 'Prod'}</span>
      <span class="${classes.dot}"></span>
    </div>
    <div class="${classes.label}"><span><b>R</b></span><span class="${classes.version}">${redaxoVersion}</span></div>
  </div>`

  // Add badge to the page
  document.body.appendChild(badge)

  // Toggle details on click
  badge.addEventListener('click', () => {
    badge.classList.toggle('expanded')
  })
}
