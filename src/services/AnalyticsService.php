<?php

namespace sidecar\craftanalytics\services;

use Craft;
use craft\base\Component;
use craft\helpers\App;
use Google\Analytics\Data\V1beta\BetaAnalyticsDataClient;
use Google\Analytics\Data\V1beta\DateRange;
use Google\Analytics\Data\V1beta\Dimension;
use Google\Analytics\Data\V1beta\Metric;
use Google\Analytics\Data\V1beta\OrderBy;
use Google\Analytics\Data\V1beta\OrderBy\MetricOrderBy;
use Google\Auth\Credentials\UserRefreshCredentials;
use sidecar\craftanalytics\Plugin;

class AnalyticsService extends Component
{
    private ?BetaAnalyticsDataClient $_client = null;

    private const SCOPES = ['https://www.googleapis.com/auth/analytics.readonly'];
    private const CACHE_PREFIX = 'craft-analytics:';

    public function isConfigured(): bool
    {
        $settings = Plugin::$plugin->getSettings();
        $propertyId = App::parseEnv($settings->propertyId);
        $clientId = App::parseEnv($settings->oauthClientId);
        $clientSecret = App::parseEnv($settings->oauthClientSecret);

        return !empty($propertyId) && !empty($clientId) && !empty($clientSecret) && $settings->isConnected();
    }

    public function getAuthUrl(): string
    {
        $settings = Plugin::$plugin->getSettings();
        $clientId = App::parseEnv($settings->oauthClientId);
        $redirectUri = $this->getRedirectUri();

        $params = [
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => implode(' ', self::SCOPES),
            'access_type' => 'offline',
            'prompt' => 'consent',
        ];

        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
    }

    public function getRedirectUri(): string
    {
        return rtrim(Craft::$app->getSites()->getPrimarySite()->getBaseUrl(), '/') .
            '/' . Craft::$app->getConfig()->getGeneral()->cpTrigger .
            '/craft-analytics/oauth/callback';
    }

    public function handleOAuthCallback(string $code): void
    {
        $settings = Plugin::$plugin->getSettings();
        $clientId = App::parseEnv($settings->oauthClientId);
        $clientSecret = App::parseEnv($settings->oauthClientSecret);
        $redirectUri = $this->getRedirectUri();

        $response = Craft::createGuzzleClient()->post('https://oauth2.googleapis.com/token', [
            'form_params' => [
                'code' => $code,
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'redirect_uri' => $redirectUri,
                'grant_type' => 'authorization_code',
            ],
        ]);

        $data = json_decode((string) $response->getBody(), true);

        if (empty($data['refresh_token'])) {
            throw new \RuntimeException('No refresh token received. Please revoke access and try again.');
        }

        $settings->oauthAccessToken = $data['access_token'] ?? '';
        $settings->oauthRefreshToken = $data['refresh_token'];
        $settings->oauthExpiresAt = time() + ($data['expires_in'] ?? 3600);

        Craft::$app->getPlugins()->savePluginSettings(Plugin::$plugin, $settings->toArray());
    }

    public function disconnect(): void
    {
        $settings = Plugin::$plugin->getSettings();
        $settings->oauthAccessToken = '';
        $settings->oauthRefreshToken = '';
        $settings->oauthExpiresAt = 0;

        Craft::$app->getPlugins()->savePluginSettings(Plugin::$plugin, $settings->toArray());
        $this->clearCache();
    }

    public function getSummaryStats(): array
    {
        $duration = Plugin::$plugin->getSettings()->cacheDuration;

        return Craft::$app->getCache()->getOrSet(self::CACHE_PREFIX . 'summary', function () {
            return $this->_fetchSummaryStats();
        }, $duration);
    }

    public function getTrafficSources(): array
    {
        $duration = Plugin::$plugin->getSettings()->cacheDuration;

        return Craft::$app->getCache()->getOrSet(self::CACHE_PREFIX . 'traffic-sources', function () {
            return $this->_fetchTrafficSources();
        }, $duration);
    }

    public function getTopPages(int $limit = 10): array
    {
        $duration = Plugin::$plugin->getSettings()->cacheDuration;

        return Craft::$app->getCache()->getOrSet(self::CACHE_PREFIX . 'top-pages', function () use ($limit) {
            return $this->_fetchTopPages($limit);
        }, $duration);
    }

    public function getDailyStats(int $days = 30): array
    {
        $duration = Plugin::$plugin->getSettings()->cacheDuration;

        return Craft::$app->getCache()->getOrSet(self::CACHE_PREFIX . "daily-{$days}", function () use ($days) {
            return $this->_fetchDailyStats($days);
        }, $duration);
    }

    public function getKeyEvents(): array
    {
        $duration = Plugin::$plugin->getSettings()->cacheDuration;

        return Craft::$app->getCache()->getOrSet(self::CACHE_PREFIX . 'key-events', function () {
            return $this->_fetchKeyEvents();
        }, $duration);
    }

    public function getEngagementStats(): array
    {
        $duration = Plugin::$plugin->getSettings()->cacheDuration;

        return Craft::$app->getCache()->getOrSet(self::CACHE_PREFIX . 'engagement', function () {
            return $this->_fetchEngagementStats();
        }, $duration);
    }

    public function clearCache(): void
    {
        $cache = Craft::$app->getCache();
        foreach (['summary', 'traffic-sources', 'top-pages', 'daily-30', 'key-events', 'engagement'] as $key) {
            $cache->delete(self::CACHE_PREFIX . $key);
        }
    }

    private function _fetchSummaryStats(): array
    {
        try {
            $client = $this->_getClient();
            $propertyId = $this->_getPropertyId();

            $periods = [
                'today' => ['today', 'today', 'yesterday', 'yesterday'],
                'week' => ['7daysAgo', 'today', '14daysAgo', '8daysAgo'],
                'month' => ['30daysAgo', 'today', '60daysAgo', '31daysAgo'],
            ];

            $metrics = [
                new Metric(['name' => 'activeUsers']),
                new Metric(['name' => 'sessions']),
                new Metric(['name' => 'screenPageViews']),
            ];

            $result = [];

            foreach ($periods as $key => [$start, $end, $prevStart, $prevEnd]) {
                $response = $client->runReport([
                    'property' => $propertyId,
                    'dateRanges' => [
                        new DateRange(['start_date' => $start, 'end_date' => $end]),
                        new DateRange(['start_date' => $prevStart, 'end_date' => $prevEnd]),
                    ],
                    'metrics' => $metrics,
                ]);

                $rows = iterator_to_array($response->getRows());
                $current = $rows[0] ?? null;
                $previous = $rows[1] ?? null;

                $currentValues = [
                    'users' => $current ? (int) $current->getMetricValues()[0]->getValue() : 0,
                    'sessions' => $current ? (int) $current->getMetricValues()[1]->getValue() : 0,
                    'pageviews' => $current ? (int) $current->getMetricValues()[2]->getValue() : 0,
                ];

                $previousValues = [
                    'users' => $previous ? (int) $previous->getMetricValues()[0]->getValue() : 0,
                    'sessions' => $previous ? (int) $previous->getMetricValues()[1]->getValue() : 0,
                    'pageviews' => $previous ? (int) $previous->getMetricValues()[2]->getValue() : 0,
                ];

                $result[$key] = $currentValues;
                $result[$key]['change'] = [];
                foreach (['users', 'sessions', 'pageviews'] as $metric) {
                    $result[$key]['change'][$metric] = $this->_percentChange($currentValues[$metric], $previousValues[$metric]);
                }
            }

            return $result;
        } catch (\Throwable $e) {
            Craft::error('Analytics: Failed to fetch summary stats: ' . $e->getMessage(), __METHOD__);
            return ['error' => $e->getMessage()];
        }
    }

    private function _fetchTopPages(int $limit): array
    {
        try {
            $client = $this->_getClient();
            $propertyId = $this->_getPropertyId();

            $dims = [
                new Dimension(['name' => 'pagePath']),
                new Dimension(['name' => 'pageTitle']),
            ];
            $metrics = [new Metric(['name' => 'screenPageViews'])];
            $orderBys = [
                new OrderBy([
                    'metric' => new MetricOrderBy(['metric_name' => 'screenPageViews']),
                    'desc' => true,
                ]),
            ];

            // Current period
            $current = $client->runReport([
                'property' => $propertyId,
                'dateRanges' => [new DateRange(['start_date' => '30daysAgo', 'end_date' => 'today'])],
                'dimensions' => $dims,
                'metrics' => $metrics,
                'orderBys' => $orderBys,
                'limit' => $limit * 5,
            ]);

            // Previous period
            $previous = $client->runReport([
                'property' => $propertyId,
                'dateRanges' => [new DateRange(['start_date' => '60daysAgo', 'end_date' => '31daysAgo'])],
                'dimensions' => $dims,
                'metrics' => $metrics,
                'orderBys' => $orderBys,
                'limit' => $limit * 5,
            ]);

            // Aggregate current by path
            $byPath = [];
            foreach ($current->getRows() as $row) {
                $path = $row->getDimensionValues()[0]->getValue();
                $title = $row->getDimensionValues()[1]->getValue();
                $views = (int) $row->getMetricValues()[0]->getValue();

                if (!isset($byPath[$path])) {
                    $byPath[$path] = ['title' => $title, 'path' => $path, 'views' => $views, 'prevViews' => 0, 'titleViews' => $views];
                } else {
                    $byPath[$path]['views'] += $views;
                    if ($views > $byPath[$path]['titleViews']) {
                        $byPath[$path]['title'] = $title;
                        $byPath[$path]['titleViews'] = $views;
                    }
                }
            }

            // Add previous period data
            foreach ($previous->getRows() as $row) {
                $path = $row->getDimensionValues()[0]->getValue();
                $views = (int) $row->getMetricValues()[0]->getValue();

                if (isset($byPath[$path])) {
                    $byPath[$path]['prevViews'] += $views;
                }
            }

            // Sort by current views and take top $limit
            usort($byPath, fn($a, $b) => $b['views'] <=> $a['views']);
            $pages = array_slice($byPath, 0, $limit);

            return array_map(fn($p) => [
                'title' => $p['title'],
                'path' => $p['path'],
                'views' => $p['views'],
                'change' => $this->_percentChange($p['views'], $p['prevViews']),
            ], $pages);
        } catch (\Throwable $e) {
            Craft::error('Analytics: Failed to fetch top pages: ' . $e->getMessage(), __METHOD__);
            return ['error' => $e->getMessage()];
        }
    }

    private function _fetchTrafficSources(): array
    {
        try {
            $client = $this->_getClient();
            $propertyId = $this->_getPropertyId();

            $dims = [new Dimension(['name' => 'sessionDefaultChannelGroup'])];
            $metrics = [new Metric(['name' => 'sessions'])];
            $orderBys = [
                new OrderBy([
                    'metric' => new MetricOrderBy(['metric_name' => 'sessions']),
                    'desc' => true,
                ]),
            ];

            // Current period
            $current = $client->runReport([
                'property' => $propertyId,
                'dateRanges' => [new DateRange(['start_date' => '30daysAgo', 'end_date' => 'today'])],
                'dimensions' => $dims,
                'metrics' => $metrics,
                'orderBys' => $orderBys,
            ]);

            // Previous period
            $previous = $client->runReport([
                'property' => $propertyId,
                'dateRanges' => [new DateRange(['start_date' => '60daysAgo', 'end_date' => '31daysAgo'])],
                'dimensions' => $dims,
                'metrics' => $metrics,
                'orderBys' => $orderBys,
            ]);

            // Build previous lookup
            $prevLookup = [];
            foreach ($previous->getRows() as $row) {
                $prevLookup[$row->getDimensionValues()[0]->getValue()] = (int) $row->getMetricValues()[0]->getValue();
            }

            $sources = [];
            foreach ($current->getRows() as $row) {
                $source = $row->getDimensionValues()[0]->getValue();
                $sessions = (int) $row->getMetricValues()[0]->getValue();
                $prev = $prevLookup[$source] ?? 0;

                $sources[] = [
                    'source' => $source,
                    'sessions' => $sessions,
                    'change' => $this->_percentChange($sessions, $prev),
                ];
            }

            return $sources;
        } catch (\Throwable $e) {
            Craft::error('Analytics: Failed to fetch traffic sources: ' . $e->getMessage(), __METHOD__);
            return ['error' => $e->getMessage()];
        }
    }

    private function _fetchDailyStats(int $days): array
    {
        try {
            $client = $this->_getClient();
            $propertyId = $this->_getPropertyId();

            $response = $client->runReport([
                'property' => $propertyId,
                'dateRanges' => [new DateRange(['start_date' => "{$days}daysAgo", 'end_date' => 'today'])],
                'dimensions' => [new Dimension(['name' => 'date'])],
                'metrics' => [
                    new Metric(['name' => 'activeUsers']),
                    new Metric(['name' => 'sessions']),
                    new Metric(['name' => 'screenPageViews']),
                ],
                'orderBys' => [
                    new OrderBy([
                        'dimension' => new OrderBy\DimensionOrderBy(['dimension_name' => 'date']),
                    ]),
                ],
            ]);

            $daily = [];
            foreach ($response->getRows() as $row) {
                $dateStr = $row->getDimensionValues()[0]->getValue();
                $daily[] = [
                    'date' => substr($dateStr, 0, 4) . '-' . substr($dateStr, 4, 2) . '-' . substr($dateStr, 6, 2),
                    'users' => (int) $row->getMetricValues()[0]->getValue(),
                    'sessions' => (int) $row->getMetricValues()[1]->getValue(),
                    'pageviews' => (int) $row->getMetricValues()[2]->getValue(),
                ];
            }

            return $daily;
        } catch (\Throwable $e) {
            Craft::error('Analytics: Failed to fetch daily stats: ' . $e->getMessage(), __METHOD__);
            return ['error' => $e->getMessage()];
        }
    }

    private function _fetchKeyEvents(): array
    {
        try {
            $client = $this->_getClient();
            $propertyId = $this->_getPropertyId();

            $dims = [new Dimension(['name' => 'eventName'])];
            $metrics = [new Metric(['name' => 'keyEvents'])];
            $orderBys = [
                new OrderBy([
                    'metric' => new MetricOrderBy(['metric_name' => 'keyEvents']),
                    'desc' => true,
                ]),
            ];

            // Current period
            $current = $client->runReport([
                'property' => $propertyId,
                'dateRanges' => [new DateRange(['start_date' => '30daysAgo', 'end_date' => 'today'])],
                'dimensions' => $dims,
                'metrics' => $metrics,
                'orderBys' => $orderBys,
            ]);

            // Previous period
            $previous = $client->runReport([
                'property' => $propertyId,
                'dateRanges' => [new DateRange(['start_date' => '60daysAgo', 'end_date' => '31daysAgo'])],
                'dimensions' => $dims,
                'metrics' => $metrics,
                'orderBys' => $orderBys,
            ]);

            // Build previous lookup
            $prevLookup = [];
            foreach ($previous->getRows() as $row) {
                $prevLookup[$row->getDimensionValues()[0]->getValue()] = (int) $row->getMetricValues()[0]->getValue();
            }

            $events = [];
            $total = 0;
            $prevTotal = 0;
            foreach ($current->getRows() as $row) {
                $name = $row->getDimensionValues()[0]->getValue();
                $count = (int) $row->getMetricValues()[0]->getValue();
                $prev = $prevLookup[$name] ?? 0;
                $total += $count;
                $prevTotal += $prev;

                $events[] = [
                    'name' => $name,
                    'count' => $count,
                    'change' => $this->_percentChange($count, $prev),
                ];
            }

            // Add previous-only events to total
            foreach ($prevLookup as $name => $prev) {
                if (!isset(array_column($events, null, 'name')[$name])) {
                    $prevTotal += $prev;
                }
            }

            return [
                'events' => $events,
                'total' => $total,
                'totalChange' => $this->_percentChange($total, $prevTotal),
            ];
        } catch (\Throwable $e) {
            Craft::error('Analytics: Failed to fetch key events: ' . $e->getMessage(), __METHOD__);
            return ['error' => $e->getMessage()];
        }
    }

    private function _fetchEngagementStats(): array
    {
        try {
            $client = $this->_getClient();
            $propertyId = $this->_getPropertyId();

            $metrics = [
                new Metric(['name' => 'averageSessionDuration']),
                new Metric(['name' => 'bounceRate']),
                new Metric(['name' => 'screenPageViewsPerSession']),
                new Metric(['name' => 'newUsers']),
                new Metric(['name' => 'activeUsers']),
            ];

            // Current period
            $current = $client->runReport([
                'property' => $propertyId,
                'dateRanges' => [
                    new DateRange(['start_date' => '30daysAgo', 'end_date' => 'today']),
                    new DateRange(['start_date' => '60daysAgo', 'end_date' => '31daysAgo']),
                ],
                'metrics' => $metrics,
            ]);

            $rows = iterator_to_array($current->getRows());
            $cur = $rows[0] ?? null;
            $prev = $rows[1] ?? null;

            $avgDuration = $cur ? (float) $cur->getMetricValues()[0]->getValue() : 0;
            $bounceRate = $cur ? (float) $cur->getMetricValues()[1]->getValue() : 0;
            $pagesPerSession = $cur ? (float) $cur->getMetricValues()[2]->getValue() : 0;
            $newUsers = $cur ? (int) $cur->getMetricValues()[3]->getValue() : 0;
            $totalUsers = $cur ? (int) $cur->getMetricValues()[4]->getValue() : 0;

            $prevAvgDuration = $prev ? (float) $prev->getMetricValues()[0]->getValue() : 0;
            $prevBounceRate = $prev ? (float) $prev->getMetricValues()[1]->getValue() : 0;
            $prevPagesPerSession = $prev ? (float) $prev->getMetricValues()[2]->getValue() : 0;
            $prevNewUsers = $prev ? (int) $prev->getMetricValues()[3]->getValue() : 0;

            $returningUsers = $totalUsers - $newUsers;

            return [
                'avgDuration' => round($avgDuration),
                'avgDurationChange' => $this->_percentChange((int) round($avgDuration), (int) round($prevAvgDuration)),
                'bounceRate' => round($bounceRate * 100, 1),
                'bounceRateChange' => $prevBounceRate > 0
                    ? round((($bounceRate - $prevBounceRate) / $prevBounceRate) * 100, 1)
                    : 0.0,
                'pagesPerSession' => round($pagesPerSession, 1),
                'pagesPerSessionChange' => $prevPagesPerSession > 0
                    ? round((($pagesPerSession - $prevPagesPerSession) / $prevPagesPerSession) * 100, 1)
                    : 0.0,
                'newUsers' => $newUsers,
                'newUsersChange' => $this->_percentChange($newUsers, $prevNewUsers),
                'returningUsers' => $returningUsers,
            ];
        } catch (\Throwable $e) {
            Craft::error('Analytics: Failed to fetch engagement stats: ' . $e->getMessage(), __METHOD__);
            return ['error' => $e->getMessage()];
        }
    }

    private function _getClient(): BetaAnalyticsDataClient
    {
        if ($this->_client === null) {
            $settings = Plugin::$plugin->getSettings();
            $clientId = App::parseEnv($settings->oauthClientId);
            $clientSecret = App::parseEnv($settings->oauthClientSecret);
            $refreshToken = $settings->oauthRefreshToken;

            if (empty($refreshToken)) {
                throw new \RuntimeException('Not connected to Google Analytics. Please authorize in plugin settings.');
            }

            $credentials = new UserRefreshCredentials(
                self::SCOPES,
                [
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'refresh_token' => $refreshToken,
                ]
            );

            $this->_client = new BetaAnalyticsDataClient([
                'credentials' => $credentials,
            ]);
        }

        return $this->_client;
    }

    private function _percentChange(int $current, int $previous): float
    {
        if ($previous > 0) {
            return round((($current - $previous) / $previous) * 100, 1);
        }

        return $current > 0 ? 100.0 : 0.0;
    }

    private function _getPropertyId(): string
    {
        $settings = Plugin::$plugin->getSettings();
        $propertyId = App::parseEnv($settings->propertyId);

        if (empty($propertyId)) {
            throw new \RuntimeException('GA4 Property ID is not configured.');
        }

        if (!str_starts_with($propertyId, 'properties/')) {
            $propertyId = 'properties/' . $propertyId;
        }

        return $propertyId;
    }
}
