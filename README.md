# Craft Analytics

Google Analytics 4 dashboard plugin for Craft CMS 5.

![Packagist Version](https://img.shields.io/packagist/v/sidecar/craft-analytics)
![License](https://img.shields.io/packagist/l/sidecar/craft-analytics)

## Features

- **Dashboard overview** — Users, sessions, and page views for today, 7 days, and 30 days with percentage comparison vs previous period
- **Daily traffic chart** — Line chart showing users, sessions, and page views over the last 30 days
- **Top pages** — Most visited pages with title, path, views, and trend
- **Traffic sources** — Channel breakdown with doughnut chart
- **Key events** — GA4 key events (conversions) with counts and trends
- **Engagement stats** — Average session duration, bounce rate, pages per session, new vs returning users
- **Dashboard widget** — Compact summary widget for the Craft dashboard
- **Permissions** — Granular user permissions (View Analytics / Manage Analytics)
- **Translations** — English and French included
- **Caching** — All API responses cached (configurable duration)
- **OAuth2** — Secure connection via Google OAuth2 (no service account JSON needed)

## Requirements

- Craft CMS 5.3+
- PHP 8.2+
- A Google Cloud project with the **Google Analytics Data API** enabled
- A GA4 property

## Installation

```bash
composer require sidecar/craft-analytics
php craft plugin/install craft-analytics
```

Or install via the Craft CMS Plugin Store.

## Google Cloud Setup

1. Go to [Google Cloud Console](https://console.cloud.google.com/)
2. Create or select a project
3. Enable the **Google Analytics Data API** (APIs & Services > Library)
4. Create OAuth 2.0 credentials:
   - Go to APIs & Services > Credentials
   - Click **Create Credentials** > **OAuth client ID**
   - Application type: **Web application**
   - Add the **Authorized redirect URI** shown in the plugin settings page
5. Copy the **Client ID** and **Client Secret**

## Plugin Configuration

1. Go to **Settings > Plugins > Analytics** in the Craft CP
2. Enter your **GA4 Property ID** (numeric ID from GA4 Admin > Property Settings, e.g. `123456789`)
3. Enter your **OAuth Client ID** and **OAuth Client Secret**
4. Click **Save**
5. Click **Connect to Google** and authorize access
6. You're connected — visit the **Analytics** section in the CP sidebar

### Environment Variables

All settings support environment variables:

```env
GA4_PROPERTY_ID=123456789
GA4_OAUTH_CLIENT_ID=your-client-id.apps.googleusercontent.com
GA4_OAUTH_CLIENT_SECRET=your-client-secret
```

Then in plugin settings, use `$GA4_PROPERTY_ID`, `$GA4_OAUTH_CLIENT_ID`, `$GA4_OAUTH_CLIENT_SECRET`.

## Permissions

The plugin registers two permissions under **Settings > Users**:

| Permission | Description |
|---|---|
| **View Analytics** | Access the Analytics CP page and dashboard widget |
| **Manage Analytics** | Clear cache and refresh data (nested under View) |

Admin users always have full access. OAuth connection and plugin settings are restricted to admins only.

## Cache

API responses are cached for 5 minutes by default. You can change this in the plugin settings (Cache Duration field). Click **Clear Cache & Refresh** on the Analytics page to force a refresh.

## Translations

The plugin ships with English and French translations. Translation files are located in `src/translations/`.

## License

MIT

## Credits

Developed by [Sidecar](https://github.com/alexandreSideCar).
