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
  let deferredInstallPrompt = null
  const supportsWebInstall = typeof Navigator.prototype.install === 'function'
  let isInstalled = window.matchMedia('(display-mode: standalone)').matches
    || navigator.standalone === true

  installButton.id = 'pwa-install-button'
  installButton.type = 'button'
  installButton.className = 'pwa-install-button'
  installButton.hidden = true
  const updateInstallButton = () => {
    installButton.hidden = isInstalled || (!supportsWebInstall && deferredInstallPrompt === null)
  }
  const markInstalled = () => {
    isInstalled = true
    deferredInstallPrompt = null
    updateInstallButton()
  }

  installButton.addEventListener('click', async () => {
    if (installButton.getAttribute('aria-disabled') === 'true') return
    installButton.setAttribute('aria-disabled', 'true')

    try {
      if (supportsWebInstall) {
        await navigator.install()
        markInstalled()
        setStatus(labels().installed)
      } else if (deferredInstallPrompt) {
        const promptEvent = deferredInstallPrompt
        deferredInstallPrompt = null
        updateInstallButton()
        await promptEvent.prompt()
        const { outcome } = await promptEvent.userChoice
        if (outcome === 'accepted') {
          markInstalled()
          setStatus(labels().installed)
        } else {
          setStatus(labels().cancelled)
          updateInstallButton()
        }
      }
    } catch (error) {
      const isAbort = error instanceof DOMException && error.name === 'AbortError'
      setStatus(isAbort ? labels().cancelled : labels().failed)
      if (!isAbort) console.error('Unable to install the IAMIOT web app.', error)
      if (!supportsWebInstall) updateInstallButton()
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
  }
  updateInstallButton()

  window.addEventListener('beforeinstallprompt', (event) => {
    event.preventDefault()
    deferredInstallPrompt = event
    if (!isInstalled) loadInstallStyles()
    updateInstallButton()
  })

  window.addEventListener('appinstalled', () => {
    markInstalled()
    loadInstallStyles()
    setStatus(labels().installed)
  })

  installButton.textContent = labels().install
  new MutationObserver(() => {
    installButton.textContent = labels().install
  }).observe(document.documentElement, { attributes: true, attributeFilter: ['lang'] })
})()
