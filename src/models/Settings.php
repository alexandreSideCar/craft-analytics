<?php

namespace sidecar\craftanalytics\models;

use craft\base\Model;

class Settings extends Model
{
    public string $propertyId = '';
    public bool $enableSearchConsole = false;
    public string $searchConsoleSiteUrl = '';
    public string $oauthClientId = '';
    public string $oauthClientSecret = '';
    public string $oauthAccessToken = '';
    public string $oauthRefreshToken = '';
    public int $oauthExpiresAt = 0;
    public int $cacheDuration = 300;

    public function defineRules(): array
    {
        return [
            [['propertyId', 'searchConsoleSiteUrl', 'oauthClientId', 'oauthClientSecret', 'oauthAccessToken', 'oauthRefreshToken'], 'string'],
            ['enableSearchConsole', 'boolean'],
            ['enableSearchConsole', 'default', 'value' => false],
            ['oauthExpiresAt', 'integer'],
            ['cacheDuration', 'integer', 'min' => 0],
            ['cacheDuration', 'default', 'value' => 300],
        ];
    }

    public function isConnected(): bool
    {
        return !empty($this->oauthRefreshToken);
    }
}
