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

		$this->loadServices();

		$error = false;
		$action = $this->getContainer()->has('url') ? $this->getContainer()->get('url')->getAction() : null;

		if(BackendModel::getModuleSetting('analytics', 'account_id') == '') $error = true;

		// missing settings, so redirect to the index-page to show a message (except on the index- and settings-page)
		if($error && $action != 'settings' && $action != 'index')
		{
			SpoonHTTP::redirect(BackendModel::createURLForAction('index'));
		}
	}

	/**
	 * Initiate services in to the DI container so they are available in the GA module.
	 */
	public function loadServices()
	{
		$client = new Google_Client();
		$service = new Google_AnalyticsService($client);

		$client->setApplicationName(BackendModel::getModuleSetting('core', 'site_title_' . BL::getWorkingLanguage()));
		$client->setScopes(array('https://www.googleapis.com/auth/analytics.readonly'));
		$client->setUseObjects(true);

		// redirect URL is not needed when we are not in moduleAction context
		if($this->getContainer()->has('url'))
		{
			// remove dynamic parameters so it matches exactly with the redirect uri set up in Google Console (will fail otherwise)
			$redirectUrl = SITE_URL . BackendModel::createURLForAction('settings');
			$redirectUrl = substr($redirectUrl, 0, stripos($redirectUrl, '?'));
			$client->setRedirectUri($redirectUrl);
		}

		$clientId = BackendModel::getModuleSetting($this->getModule(), 'client_id');
		$clientSecret = BackendModel::getModuleSetting($this->getModule(), 'client_secret');
		$token = BackendModel::getModuleSetting($this->getModule(), 'token');
		if(!empty($clientId)) $client->setClientId($clientId);
		if(!empty($clientSecret)) $client->setClientSecret($clientSecret);
		if(!empty($token)) $client->setAccessToken($token);

		BackendModel::getContainer()->set('google.client', $client);
		BackendModel::getContainer()->set('google.analytics.service', $service);

		$service = new BackendAnalyticsService($this->getKernel());
		$service->setProfileId(BackendModel::getModuleSetting($this->getModule(), 'profile_id'));
		BackendModel::getContainer()->set('fork.analytics.service', $service);
	}
}
