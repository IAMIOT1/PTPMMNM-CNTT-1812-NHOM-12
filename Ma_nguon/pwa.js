(() => {
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
})()
