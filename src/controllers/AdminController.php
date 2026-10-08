<?php

declare(strict_types=1);

namespace Rhymix\Modules\Simple_video_learning\Src\Controllers;

use Context;
use ModuleModel;
use Rhymix\Framework\Session;
use Rhymix\Modules\Simple_video_learning\Src\Models\ConfigModel;
use Rhymix\Modules\Simple_video_learning\Src\ModuleBase;

class AdminController extends ModuleBase
{
    public function dispSimple_video_learningAdminConfig(): void
    {
        $this->ensureDatabaseReady(true);

        $model = new ConfigModel();
        $output = $model->ensureApiToken();
        if (!$output->toBool()) {
            throw new \RuntimeException((string)$output->getMessage());
        }
        $config = $model->get();

        // If the previous POST failed, Rhymix restores submitted values here.
        // Showing them again prevents the form from looking as if it was reset.
        $inputError = Context::get('INPUT_ERROR');
        if (is_array($inputError)) {
            foreach (['target_mid', 'author_name', 'default_threshold', 'max_upload_mb'] as $key) {
                if (array_key_exists($key, $inputError)) {
                    $config->{$key} = $inputError[$key];
                }
            }
        }

        $instances = ModuleModel::getMidList((object)['module' => 'simple_video_learning']);
        $frontMid = '';
        if ($instances) {
            $first = reset($instances);
            $frontMid = $first && !empty($first->mid) ? (string)$first->mid : '';
        }

        $configuredTarget = trim((string)$config->target_mid);
        if ($configuredTarget !== '') {
            $configuredModule = ModuleModel::getModuleInfoByMid($configuredTarget);
            if (!$configuredModule || (string)($configuredModule->module ?? '') !== 'simple_video_learning') {
                $config->target_mid = $frontMid;
            }
        } elseif ($frontMid !== '') {
            $config->target_mid = $frontMid;
        }

        Context::set('simple_video_learning_config', $config);
        Context::set('simple_video_learning_new_global_api_token', Session::get('simple_video_learning.new_global_api_token'));
        Session::set('simple_video_learning.new_global_api_token', null);
        Context::set('simple_video_learning_front_mid', $frontMid);
        Context::set('simple_video_learning_csrf_token', Session::getGenericToken());
        $this->setTemplatePath($this->module_path . 'views/admin/');
        $this->setTemplateFile('config');
    }

    public function dispSimple_video_learningAdminApiGuide(): void
    {
        $this->setTemplatePath($this->module_path . 'views/admin/');
        $this->setTemplateFile('api_guide');
    }

    public function procSimple_video_learningAdminRegenerateApiToken()
    {
        $model = new ConfigModel();
        $output = $model->rotateApiToken();
        if (!$output->toBool()) {
            return $output;
        }

        $this->setMessage('API 토큰을 재생성했습니다. 기존 전역 토큰은 즉시 사용할 수 없습니다.');
        $this->setRedirectUrl(Context::get('success_return_url'));
    }

    public function procSimple_video_learningAdminConfig()
    {
        $vars = Context::getRequestVars();
        $targetMid = trim((string)($vars->target_mid ?? ''));

        if ($targetMid !== '') {
            $moduleInfo = ModuleModel::getModuleInfoByMid($targetMid);
            if (!$moduleInfo) {
                return new \BaseObject(-1, '이러닝 메뉴 MID를 찾을 수 없습니다: ' . $targetMid);
            }
            if ((string)($moduleInfo->module ?? '') !== 'simple_video_learning') {
                return new \BaseObject(-1, '대상 MID는 Simple Video Learning 이러닝 메뉴여야 합니다: ' . $targetMid);
            }
        }

        $model = new ConfigModel();
        $model->set($vars);
        $output = $model->save();
        if (!$output->toBool()) {
            return $output;
        }

        $this->setMessage('success_saved');
        $this->setRedirectUrl(Context::get('success_return_url'));
    }
}
