<?php

/*
 * This file is part of Fork CMS.
 *
 * For the full copyright and license information, please view the license
 * file that was distributed with this source code.
 */

/**
 * This is the configuration-object for the analytics module
 *
 * @author Annelies Van Extergem <annelies.vanextergem@netlash.com>
 * @author Dieter Vanden Eynde <dieter.vandeneynde@wijs.be>
 */
class BackendAnalyticsConfig extends BackendBaseConfig
{
	/**
	 * @var	string
	 */
	protected $defaultAction = 'index';

	/**
	 * @var	array
	 */
	protected $disabledActions = array();

	/**
	 * Check if all required settings have been set
	 *
	 * @param string $module The module.
	 */
	public function __construct($module)
	{
		parent::__construct($module);

		$error = false;
		$action = Spoon::exists('url') ? Spoon::get('url')->getAction() : null;

		if(BackendModel::getModuleSetting('analytics', 'account_id') == '') $error = true;

		// missing settings, so redirect to the index-page to show a message (except on the index- and settings-page)
		if($error && $action != 'settings' && $action != 'index')
		{
			SpoonHTTP::redirect(BackendModel::createURLForAction('index'));
		}
	}
}
