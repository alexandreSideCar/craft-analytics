<?php

namespace sidecar\craftanalytics\controllers;

use Craft;
use craft\web\Controller;
use sidecar\craftanalytics\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

class AnalyticsController extends Controller
{
    protected array|int|bool $allowAnonymous = false;

    private function requireViewPermission(): void
    {
        $user = Craft::$app->getUser()->getIdentity();
        if (!$user || (!$user->admin && !$user->can(Plugin::PERMISSION_VIEW))) {
            throw new ForbiddenHttpException('User is not authorized to view analytics.');
        }
    }

    private function requireManagePermission(): void
    {
        $user = Craft::$app->getUser()->getIdentity();
        if (!$user || (!$user->admin && !$user->can(Plugin::PERMISSION_MANAGE))) {
            throw new ForbiddenHttpException('User is not authorized to manage analytics.');
        }
    }

    public function actionIndex(): Response
    {
        $this->requireViewPermission();

        $service = Plugin::$plugin->analytics;
        $isConfigured = $service->isConfigured();
        $canManage = Craft::$app->getUser()->getIdentity()->admin
            || Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_MANAGE);

        $summary = $isConfigured ? $service->getSummaryStats() : [];
        $topPages = $isConfigured ? $service->getTopPages() : [];
        $trafficSources = $isConfigured ? $service->getTrafficSources() : [];
        $dailyStats = $isConfigured ? $service->getDailyStats() : [];
        $keyEvents = $isConfigured ? $service->getKeyEvents() : [];
        $engagement = $isConfigured ? $service->getEngagementStats() : [];
        $searchConsole = ($isConfigured && $service->isSearchConsoleEnabled()) ? $service->getSearchConsoleStats() : [];

        return $this->renderTemplate('craft-analytics/_cp/index', [
            'isConfigured' => $isConfigured,
            'canManage' => $canManage,
            'summary' => $summary,
            'topPages' => $topPages,
            'trafficSources' => $trafficSources,
            'dailyStats' => $dailyStats,
            'keyEvents' => $keyEvents,
            'engagement' => $engagement,
            'searchConsole' => $searchConsole,
        ]);
    }

    public function actionRefresh(): Response
    {
        $this->requireManagePermission();
        $this->requirePostRequest();

        Plugin::$plugin->analytics->clearCache();

        Craft::$app->getSession()->setNotice(Craft::t('craft-analytics', 'Analytics cache cleared.'));

        return $this->redirect('craft-analytics');
    }

    public function actionConnect(): Response
    {
        $this->requireAdmin();

        $authUrl = Plugin::$plugin->analytics->getAuthUrl();

        return $this->redirect($authUrl);
    }

    public function actionCallback(): Response
    {
        $this->requireAdmin();

        $code = Craft::$app->getRequest()->getQueryParam('code');
        $error = Craft::$app->getRequest()->getQueryParam('error');

        if ($error) {
            Craft::$app->getSession()->setError(Craft::t('craft-analytics', 'Google authorization denied: {error}', ['error' => $error]));
            return $this->redirect('settings/plugins/craft-analytics');
        }

        if (empty($code)) {
            Craft::$app->getSession()->setError(Craft::t('craft-analytics', 'No authorization code received.'));
            return $this->redirect('settings/plugins/craft-analytics');
        }

        try {
            Plugin::$plugin->analytics->handleOAuthCallback($code);
            Craft::$app->getSession()->setNotice(Craft::t('craft-analytics', 'Successfully connected to Google Analytics!'));
        } catch (\Throwable $e) {
            Craft::error('Analytics OAuth error: ' . $e->getMessage(), __METHOD__);
            Craft::$app->getSession()->setError(Craft::t('craft-analytics', 'Connection failed: {error}', ['error' => $e->getMessage()]));
        }

        return $this->redirect('settings/plugins/craft-analytics');
    }

    public function actionDisconnect(): Response
    {
        $this->requireAdmin();
        $this->requirePostRequest();

        Plugin::$plugin->analytics->disconnect();

        Craft::$app->getSession()->setNotice(Craft::t('craft-analytics', 'Disconnected from Google Analytics.'));

        return $this->redirect('settings/plugins/craft-analytics');
    }
}
