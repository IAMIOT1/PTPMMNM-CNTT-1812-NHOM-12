# LinguaDesk Dashboard

Responsive React + TypeScript workspace UI for the PHP translation management system. The dashboard is built as an isolated Vite app so it does not replace the existing PHP routes.

## Run locally

Development server (serves source files with Vite HMR):

```sh
npm ci
npm run dev
```

Production-like local server (serves only the built `dist/` output under `/dashboard-ui/`):

```sh
npm ci
npm run build
npm run serve:production
```

Open `http://localhost:4173/dashboard-ui/`. Do not use the development server when verifying production routing.

To run the PHP application locally with its dashboard route mapped to `dashboard-ui/dist/`:

```sh
npm run build
cd ..
php -S 127.0.0.1:8000 -t . php-dev-router.php
```

The PHP router never falls back to the source directory; it returns an error if the build is missing.

Quality checks:

```sh
npm run build
npm run lint
```

## PHP API integration

- The dashboard calls the same-origin `/api_dashboard.php` endpoint by default. Set `VITE_API_BASE_URL` only when the PHP API is hosted at a different base URL.
- The endpoint requires an authenticated PHP session. It loads the current user's accessible projects, assignments, translations, locales, and audit history from PostgreSQL.
- Workspace changes use JSON `POST` requests with the session CSRF token. The API enforces account and project permissions; the browser persists only appearance and queue preferences.
- Translation suggestions are requested through the PHP API, so provider configuration and credentials remain server-side. Translation drafts, review decisions, and audit events are persisted in the database.
- The dashboard displays a loading state while the initial workspace request is in flight and exposes retry/sign-in guidance when the API request fails.

## Deployment

The root Dockerfile builds this app and serves its static output under `/dashboard-ui/`. Vite and React Router use that production base path, while the development server runs at `/`.

All eight workspace routes are available under the dashboard base path: `/dashboard`, `/projects`, `/translation`, `/review`, `/users`, `/logs`, `/analytics`, and `/settings`.
