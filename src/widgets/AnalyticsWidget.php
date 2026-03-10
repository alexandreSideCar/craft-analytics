<?php

namespace sidecar\craftanalytics\widgets;

use Craft;
use craft\base\Widget;
use sidecar\craftanalytics\Plugin;

class AnalyticsWidget extends Widget
{
    public static function displayName(): string
    {
        return Craft::t('craft-analytics', 'Google Analytics');
    }

    public static function icon(): ?string
    {
        return 'chart-bar';
    }

    public static function isSelectable(): bool
    {
        $user = Craft::$app->getUser()->getIdentity();
        return $user && ($user->admin || $user->can(Plugin::PERMISSION_VIEW));
    }

    public function getBodyHtml(): ?string
    {
        $service = Plugin::$plugin->analytics;
        $isConfigured = $service->isConfigured();
        $summary = $isConfigured ? $service->getSummaryStats() : [];

        return Craft::$app->getView()->renderTemplate('craft-analytics/_widgets/body', [
            'isConfigured' => $isConfigured,
            'summary' => $summary,
        ]);
    }
}
