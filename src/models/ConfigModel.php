<?php

declare(strict_types=1);

namespace Rhymix\Modules\Simple_video_learning\Src\Models;

use ModuleController;
use ModuleModel;
use Rhymix\Framework\Session;

class ConfigModel
{
    protected string $configKey = 'simple_video_learning';
    protected object $config;
    private bool $tokenMigrationRequired = false;

    public function __construct()
    {
        $config = ModuleModel::getModuleConfig($this->configKey);
        if (!is_object($config)) {
            $config = new \stdClass();
        }
        // Rhymix returns the same cached object within a request. Do not mutate
        // it before migration is saved, or another instance may skip migration.
        $config = clone $config;

        $legacyToken = (string)($config->api_token ?? '');
        $config->api_token_hash = (string)($config->api_token_hash ?? '');
        if ($legacyToken !== '' && $config->api_token_hash === '') {
            $config->api_token_hash = hash('sha256', $legacyToken);
        }
        $this->tokenMigrationRequired = isset($config->api_token);
        unset($config->api_token);
        $config->api_token_prefix = $config->api_token_hash !== '' ? 'sha256:' . substr($config->api_token_hash, 0, 12) : '';
        $config->target_mid = (string)($config->target_mid ?? '');
        $config->author_name = (string)($config->author_name ?? 'Simple Video Learning');
        $config->default_threshold = max(1, min(100, (int)($config->default_threshold ?? 80)));
        $config->max_upload_mb = max(1, min(10240, (int)($config->max_upload_mb ?? 2048)));

        $this->config = $config;
    }

    public function get(): object
    {
        return clone $this->config;
    }

    public function set(object $vars): void
    {
        // The global API token has its own lifecycle. Normal settings saves must not
        // accidentally clear or rotate it.
        if (isset($vars->api_token) && trim((string)$vars->api_token) !== '') {
            $this->setTokenHash(trim((string)$vars->api_token));
        }
        $this->config->target_mid = trim((string)($vars->target_mid ?? ''));
        $this->config->author_name = trim((string)($vars->author_name ?? 'Simple Video Learning')) ?: 'Simple Video Learning';
        $this->config->default_threshold = max(1, min(100, (int)($vars->default_threshold ?? 80)));
        $this->config->max_upload_mb = max(1, min(10240, (int)($vars->max_upload_mb ?? 2048)));
    }

    public function ensureApiToken(): \BaseObject
    {
        if ($this->config->api_token_hash !== '') {
            return $this->migrateLegacyToken();
        }

        return $this->rotateApiToken();
    }

    public function rotateApiToken(): \BaseObject
    {
        $plain = self::generateApiToken();
        $this->setTokenHash($plain);
        $output = $this->save();
        if ($output->toBool()) {
            Session::set('simple_video_learning.new_global_api_token', $plain);
        }
        return $output;
    }

    private static function generateApiToken(): string
    {
        return 'slms_admin_' . bin2hex(random_bytes(32));
    }

    public function save(): \BaseObject
    {
        $output = ModuleController::getInstance()->insertModuleConfig($this->configKey, $this->config);
        if ($output->toBool()) {
            $this->tokenMigrationRequired = false;
        }
        return $output;
    }

    public function needsTokenMigration(): bool
    {
        return $this->tokenMigrationRequired;
    }

    public function migrateLegacyToken(): \BaseObject
    {
        return $this->tokenMigrationRequired ? $this->save() : new \BaseObject();
    }

    private function setTokenHash(string $plain): void
    {
        $this->config->api_token_hash = hash('sha256', $plain);
        $this->config->api_token_prefix = 'sha256:' . substr($this->config->api_token_hash, 0, 12);
    }
}
