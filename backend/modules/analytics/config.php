<?php

/*
 * This file is part of Fork CMS.
 *
 * For the full copyright and license information, please view the license
 * file that was distributed with this source code.
 */

use \Symfony\Component\HttpKernel\KernelInterface;

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
	 * @param \Symfony\Component\HttpKernel\KernelInterface $kernel
	 * @param string $module The module.
	 */
	public function __construct(KernelInterface $kernel, $module)
	{
		parent::__construct($kernel, $module);

		$error = false;
		$action = $this->getContainer()->has('url') ? $this->getContainer()->get('url')->getAction() : null;

		if(BackendModel::getModuleSetting('analytics', 'account_id') == '') $error = true;

		// missing settings, so redirect to the index-page to show a message (except on the index- and settings-page)
		if($error && $action != 'settings' && $action != 'index')
		{
			SpoonHTTP::redirect(BackendModel::createURLForAction('index'));
		}
	}
}
