<?php

namespace Backend\Modules\Analytics\Actions;

use Backend\Core\Engine\Base\ActionIndex;
use Backend\Core\Engine\Model;
use Backend\Modules\Analytics\Form\SettingsType;

/**
 * This is the settings-action (default), it will be used to couple your analytics
 * account
 */
final class Settings extends ActionIndex
{
    public function execute()
    {
        parent::execute();

        $settingsForm = new SettingsType(
            'settings',
            $this->get('fork.settings'),
            $this->get('analytics.google_analytics_service')
        );

        if ($settingsForm->handle()) {
            $this->redirect(Model::createURLForAction('Settings'));
        }
        $settingsForm->parse($this->tpl);

        if ($this->get('fork.settings')->get($this->getModule(), 'web_property_id')) {
            $this->tpl->assign(
                'web_property_id',
                $this->get('fork.settings')->get($this->getModule(), 'web_property_id')
            );
        }
        if ($this->get('fork.settings')->get($this->getModule(), 'profile')) {
            $this->tpl->assign(
                'profile',
                $this->get('fork.settings')->get($this->getModule(), 'profile')
            );
        }

        $this->display();
    }
}
