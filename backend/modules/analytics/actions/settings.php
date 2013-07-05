<?php

/*
 * This file is part of Fork CMS.
 *
 * For the full copyright and license information, please view the license
 * file that was distributed with this source code.
 */

/**
 * This is the settings-action, it will display a form to set general analytics settings
 *
 * @author Annelies Van Extergem <annelies.vanextergem@netlash.com>
 * @author Dieter Vanden Eynde <dieter.vandeneynde@wijs.be>
 */
class BackendAnalyticsSettings extends BackendBaseActionEdit
{
	/**
	 * @var Google_Client
	 */
	private $client;

	/**
	 * @var Google_AnalyticsService
	 */
	private $service;

	/**
	 * Google API authentication info.
	 *
	 * @var string
	 */
	private $clientId, $clientSecret;

	/**
	 * The currently linked info
	 *
	 * @var	string
	 */
	private $accountId, $accountName, $webPropertyId, $webPropertyName;

	/**
	 * The session token
	 *
	 * @var	string
	 */
	private $token;

	public function execute()
	{
		parent::execute();

		/*
		 * Flow:
		 * 1. via form fill in client id and secret
		 * 2. save and redirect to oauth dialog
		 * 3. catch errors or save token (as json string)
		 * 4. fetch accounts and profiles (create dropdown)
		 * 5. let them save the account/profile id
		 * 6. lock it down
		 */
		$this->loadData();

		// removing the link
		if(SpoonFilter::getGetValue('remove', array('session'), null) == 'session')
		{
			$this->removeLink();
		}

		// we are missing info to be able to connect to the api
		elseif(empty($this->clientId) || empty($this->clientSecret) || empty($this->token))
		{
			$this->loadStep1();
			$this->loadStep2();
		}

		// a token is available, display accounts
		elseif(!empty($this->token) && empty($this->accountId))
		{
			$this->loadStep3();
		}

		// account is linked, display info
		elseif(!empty($this->accountId))
		{
			$this->loadStep4();
		}

		$this->display();
	}

	private function loadData()
	{
		$this->client = new Google_Client();
		$this->service = new Google_AnalyticsService($this->client);

		$this->client->setApplicationName(BackendModel::getModuleSetting('core', 'site_title_' . BL::getWorkingLanguage()));
		$this->client->setScopes(array('https://www.googleapis.com/auth/analytics.readonly'));
		$this->client->setUseObjects(true);

		// remove dynamic parameters so it matches exactly with the redirect uri set up in Google Console (will fail otherwise)
		$redirectUrl = SITE_URL . BackendModel::createURLForAction($this->getAction());
		$redirectUrl = substr($redirectUrl, 0, stripos($redirectUrl, '?'));
		$this->client->setRedirectUri($redirectUrl);

		$this->clientId = BackendModel::getModuleSetting($this->getModule(), 'client_id');
		$this->clientSecret = BackendModel::getModuleSetting($this->getModule(), 'client_secret');
		if(!empty($this->clientId)) $this->client->setClientId($this->clientId);
		if(!empty($this->clientSecret)) $this->client->setClientSecret($this->clientSecret);

		$this->token = BackendModel::getModuleSetting($this->getModule(), 'token');
		if(!empty($this->token)) $this->client->setAccessToken($this->token);

		$this->accountId = BackendModel::getModuleSetting($this->getModule(), 'account_id');
		$this->accountName = BackendModel::getModuleSetting($this->getModule(), 'account_name');
		$this->webPropertyId = BackendModel::getModuleSetting($this->getModule(), 'web_property_id');
		$this->webPropertyName = BackendModel::getModuleSetting($this->getModule(), 'web_property_name');
	}

	/**
	 * Before interacting with the Google API, we require some client information.
	 * This method will create a form, allowing the user to fill in that info.
	 */
	private function loadStep1()
	{
		$frm = new BackendForm('clientInfo');
		$frm->addText('client_id', $this->clientId);
		$frm->addText('client_secret', $this->clientSecret);

		if($frm->isSubmitted())
		{
			$frm->getField('client_id')->isFilled(BL::err('FieldIsRequired'));
			$frm->getField('client_secret')->isFilled(BL::err('FieldIsRequired'));

			if($frm->isCorrect())
			{
				$this->clientId = $frm->getField('client_id')->getValue();
				$this->clientSecret = $frm->getField('client_secret')->getValue();

				BackendModel::setModuleSetting($this->getModule(), 'client_id', $this->clientId);
				BackendModel::setModuleSetting($this->getModule(), 'client_secret', $this->clientSecret);

				$this->client->setClientId($this->clientId);
				$this->client->setClientSecret($this->clientSecret);

				$this->redirect($this->client->createAuthUrl());
			}
		}

		$this->tpl->assign('step1', true);
		$frm->parse($this->tpl);
	}

	/**
	 * After authenticating at Google, you'll be redirected back with a 'code' variable
	 * in the query string. This method will save that code and request an access token.
	 */
	private function loadStep2()
	{
		if(isset($_GET['code']))
		{
			$this->client->authenticate();

			$this->token = $this->client->getAccessToken();

			BackendModel::setModuleSetting($this->getModule(), 'token', $this->token);

			$this->redirect(BackendModel::createURLForAction($this->getAction()));
		}
	}

	/**
	 * Fetch all accounts and profiles using the current access token. Build a form around it
	 * so the user can choose which profile/account to link.
	 */
	private function loadStep3()
	{
		$accounts = $this->service->management_accounts->listManagementAccounts();
		$webProperties = $this->service->management_webproperties->listManagementWebproperties("~all");

		$webPropertiesByAccount = array();
		foreach($accounts->items as $account)
		{
			$webPropertiesByAccount[$account->id] = array(
				'label' => $account->name,
				'properties' => array()
			);
		}
		foreach($webProperties->items as $webProperty)
		{
			$webPropertiesByAccount[$webProperty->accountId]['properties'][$webProperty->id] = $webProperty->name . ' (' . $webProperty->id . ')';
		}

		$profilesValues = array();
		foreach($webPropertiesByAccount as $id => $account)
		{
			$profilesValues[$id] = $account['properties'];
		}

		$frm = new BackendForm('linkProfile');
		$frm->addDropdown('profiles', $profilesValues)->setDefaultElement('');

		if($frm->isSubmitted())
		{
			$frm->getField('profiles')->isFilled(BL::err('FieldIsRequired'));

			if($frm->isCorrect())
			{
				$accountId = null;
				$accountName = null;
				$webPropertyId = $frm->getField('profiles')->getValue();
				$webPropertyName = null;
				foreach($webProperties->items as $webProperty)
				{
					if($webProperty->id == $webPropertyId)
					{
						$webPropertyName = $webProperty->name;
						$accountId = $webProperty->accountId;
					}
				}
				foreach($accounts->items as $account)
				{
					if($account->id == $accountId)
					{
						$accountName = $account->name;
					}
				}

				BackendModel::setModuleSetting($this->getModule(), 'account_id', $accountId);
				BackendModel::setModuleSetting($this->getModule(), 'account_name', $accountName);
				BackendModel::setModuleSetting($this->getModule(), 'web_property_id', $webPropertyId);
				BackendModel::setModuleSetting($this->getModule(), 'web_property_name', $webPropertyName);

				$this->redirect(BackendModel::createURLForAction($this->getAction()) . '&report=saved');
			}
		}

		$this->tpl->assign('step3', true);
		$this->tpl->assign('hasProfiles', (count($profilesValues) > 0));
		$frm->parse($this->tpl);
	}

	/**
	 * A link has been made with Google Analytics. All info required for communication with the API
	 * is available. This method will display that info.
	 *
	 * Allow settting of tracking type.
	 */
	private function loadStep4()
	{
		$frm = new BackendForm('trackingType');

		$types = array();
		$types[] = array('label' => 'Universal Analytics', 'value' => 'universal_analytics');
		$types[] = array('label' => 'Classic Google Analytics', 'value' => 'classic_analytics');
		$types[] = array('label' => 'Display Advertising (stats.g.doubleclick.net/dc.js)', 'value' => 'display_advertising');

		$frm->addRadiobutton(
			'type',
			$types,
			BackendModel::getModuleSetting($this->URL->getModule(), 'tracking_type', 'universal_analytics')
		);

		if($frm->isSubmitted())
		{
			if($frm->isCorrect())
			{
				BackendModel::setModuleSetting(
					$this->getModule(),
					'tracking_type',
					$frm->getField('type')->getValue()
				);
				BackendModel::triggerEvent($this->getModule(), 'after_saved_tracking_type_settings');
				$this->redirect(BackendModel::createURLForAction($this->getAction()) . '&report=saved');
			}
		}

		$frm->parse($this->tpl);
		$this->tpl->assign('step4', true);
		$this->tpl->assign('accountName', $this->accountName);
		$this->tpl->assign('webPropertyName', $this->webPropertyName);
		$this->tpl->assign('webPropertyId', $this->webPropertyId);
	}

	/**
	 * Removes all information which allows us to connect with the API.
	 */
	private function removeLink()
	{
		BackendModel::setModuleSetting($this->getModule(), 'client_id', null);
		BackendModel::setModuleSetting($this->getModule(), 'client_secret', null);
		BackendModel::setModuleSetting($this->getModule(), 'token', null);
		BackendModel::setModuleSetting($this->getModule(), 'account_id', null);
		BackendModel::setModuleSetting($this->getModule(), 'account_name', null);
		BackendModel::setModuleSetting($this->getModule(), 'web_property_id', null);
		BackendModel::setModuleSetting($this->getModule(), 'web_property_name', null);
		BackendModel::setModuleSetting($this->getModule(), 'universal_analytics', null);

		BackendAnalyticsModel::removeCacheFiles();
		BackendAnalyticsModel::clearTables();

		$this->redirect(BackendModel::createURLForAction($this->getAction()) . '&report=removed');
	}
}
