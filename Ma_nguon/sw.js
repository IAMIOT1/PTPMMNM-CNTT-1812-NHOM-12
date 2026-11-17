const CACHE_NAME = 'iamiot-static-v1'
const CORE_ASSETS = [
  '/manifest.json',
  '/offline.html',
  '/icons/iamiot-192.png',
  '/icons/iamiot-512.png',
  '/icons/iamiot-maskable-512.png',
  '/icons/apple-touch-icon.png',
  '/icons/iamiot-icon.svg',
]
const DASHBOARD_SHELL = '/dashboard-ui/'
const STATIC_DESTINATIONS = new Set(['font', 'image', 'script', 'style', 'worker'])

self.addEventListener('install', (event) => {
  event.waitUntil((async () => {
    const cache = await caches.open(CACHE_NAME)
    await cache.addAll(CORE_ASSETS)
    try {
      await cache.add(DASHBOARD_SHELL)
    } catch (error) {
      console.warn('Dashboard shell was not available for offline caching.', error)
    }
    await self.skipWaiting()
  })())
})

self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    const cacheNames = await caches.keys()
    await Promise.all(
      cacheNames
        .filter((name) => name.startsWith('iamiot-') && name !== CACHE_NAME)
        .map((name) => caches.delete(name)),
    )
    await self.clients.claim()
  })())
})

self.addEventListener('fetch', (event) => {
  const { request } = event
  const url = new URL(request.url)
  if (
    request.method !== 'GET' ||
    url.origin !== self.location.origin ||
    url.pathname.endsWith('.php') ||
    url.pathname.startsWith('/api/')
  ) {
    return
  }

  if (request.mode === 'navigate') {
    if (!url.pathname.startsWith(DASHBOARD_SHELL)) return

    event.respondWith((async () => {
      try {
        return await fetch(request)
      } catch {
        const cachedShell = await caches.match(DASHBOARD_SHELL)
        if (cachedShell) return cachedShell
        return caches.match('/offline.html')
      }
    })())
    return
  }

  if (!STATIC_DESTINATIONS.has(request.destination)) return

  event.respondWith((async () => {
    const cache = await caches.open(CACHE_NAME)
    try {
      const response = await fetch(request)
      if (
        response.ok &&
        response.type === 'basic' &&
        !/no-store/i.test(response.headers.get('Cache-Control') ?? '')
      ) {
        await cache.put(request, response.clone())
      }
      return response
    } catch (error) {
      const cachedResponse = await cache.match(request)
      if (cachedResponse) return cachedResponse
      throw error
    }
  })())
})
