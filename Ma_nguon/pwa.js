(() => {
  const installButton = document.createElement('button')
  const installStatus = document.createElement('p')
  const language = () => document.documentElement.lang.toLowerCase().startsWith('vi')
  const labels = () => ({
    install: language() ? 'Cài đặt ứng dụng' : 'Install app',
    installed: language() ? 'Ứng dụng đã được cài đặt.' : 'The app was installed.',
    cancelled: language() ? 'Đã hủy cài đặt.' : 'Installation was cancelled.',
    failed: language() ? 'Không thể bắt đầu cài đặt.' : 'Installation could not start.',
  })
  const setStatus = (message) => {
    installStatus.textContent = message
    installStatus.hidden = false
  }
  let deferredInstallPrompt
  const supportsWebInstall = typeof Navigator.prototype.install === 'function'

  installButton.id = 'pwa-install-button'
  installButton.type = 'button'
  installButton.className = 'pwa-install-button'
  installButton.hidden = true
  installButton.addEventListener('click', async () => {
    if (installButton.getAttribute('aria-disabled') === 'true') return
    installButton.setAttribute('aria-disabled', 'true')

    try {
      if (supportsWebInstall) {
        await navigator.install()
        setStatus(labels().installed)
        installButton.hidden = true
      } else if (deferredInstallPrompt) {
        const promptEvent = deferredInstallPrompt
        deferredInstallPrompt = undefined
        await promptEvent.prompt()
        const { outcome } = await promptEvent.userChoice
        setStatus(outcome === 'accepted' ? labels().installed : labels().cancelled)
        if (!deferredInstallPrompt) installButton.hidden = true
      }
    } catch (error) {
      const isAbort = error instanceof DOMException && error.name === 'AbortError'
      setStatus(isAbort ? labels().cancelled : labels().failed)
      if (!isAbort) console.error('Unable to install the IAMIOT web app.', error)
      if (!supportsWebInstall && !deferredInstallPrompt) installButton.hidden = true
    } finally {
      installButton.removeAttribute('aria-disabled')
    }
  })

  installStatus.id = 'pwa-install-status'
  installStatus.className = 'pwa-install-status'
  installStatus.setAttribute('role', 'status')
  installStatus.setAttribute('aria-live', 'polite')
  installStatus.hidden = true
  document.body.append(installButton, installStatus)

  const loadInstallStyles = () => {
    if (document.querySelector('link[data-pwa-install-styles]')) return
    const stylesheet = document.createElement('link')
    stylesheet.rel = 'stylesheet'
    stylesheet.href = '/pwa-install.css'
    stylesheet.dataset.pwaInstallStyles = ''
    document.head.append(stylesheet)
  }

  if ('serviceWorker' in navigator && window.isSecureContext) {
    void (async () => {
      try {
        const registrations = await navigator.serviceWorker.getRegistrations()
        const legacyRegistrations = registrations.filter((registration) =>
          [registration.active, registration.waiting, registration.installing]
            .filter(Boolean)
            .some((worker) => new URL(worker.scriptURL).pathname === '/dashboard-ui/sw.js'),
        )
        await Promise.all(legacyRegistrations.map((registration) => registration.unregister()))
        await navigator.serviceWorker.register('/sw.js', { scope: '/' })
      } catch (error) {
        console.error('Unable to register the IAMIOT site-wide service worker.', error)
      }
    })()
  }

  if (supportsWebInstall) {
    loadInstallStyles()
    installButton.hidden = false
  }

  window.addEventListener('beforeinstallprompt', (event) => {
    event.preventDefault()
    deferredInstallPrompt = event
    loadInstallStyles()
    if (!supportsWebInstall) installButton.hidden = false
  })

  window.addEventListener('appinstalled', () => {
    deferredInstallPrompt = undefined
    installButton.hidden = true
    setStatus(labels().installed)
  })

  installButton.textContent = labels().install
  new MutationObserver(() => {
    installButton.textContent = labels().install
  }).observe(document.documentElement, { attributes: true, attributeFilter: ['lang'] })
})()
