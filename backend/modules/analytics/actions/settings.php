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
	private $accountId, $accountName, $webPropertyId, $webPropertyName, $profileId, $profileName;

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
		 * 6. lock it down (display linked info)
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
		elseif(!empty($this->token) && empty($this->profileId))
		{
			$this->loadStep3();
		}

		// account is linked, display info
		elseif(!empty($this->profileId))
		{
			$this->loadStep4();
		}

		$this->display();
	}

	/**
	 * Load settings required for communicating with Google Analytics.
	 *
	 * A nice refactoring might be to create a seperate class for the linked profile and store all
	 * the data there.
	 */
	private function loadData()
	{
		$this->clientId = $this->get('google.client')->getClientId();
		$this->clientSecret = $this->get('google.client')->getClientSecret();
		$this->token = $this->get('google.client')->getAccessToken();
		$this->accountId = BackendModel::getModuleSetting($this->getModule(), 'account_id');
		$this->accountName = BackendModel::getModuleSetting($this->getModule(), 'account_name');
		$this->webPropertyId = BackendModel::getModuleSetting($this->getModule(), 'web_property_id');
		$this->webPropertyName = BackendModel::getModuleSetting($this->getModule(), 'web_property_name');
		$this->profileId = BackendModel::getModuleSetting($this->getModule(), 'profile_id');
		$this->profileName = BackendModel::getModuleSetting($this->getModule(), 'profile_name');
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

				$this->get('google.client')->setClientId($this->clientId);
				$this->get('google.client')->setClientSecret($this->clientSecret);

				$this->redirect($this->get('google.client')->createAuthUrl());
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
			$this->get('google.client')->authenticate();

			$this->token = $this->get('google.client')->getAccessToken();

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
		/*
		 * An accounts tree will be build and made accessible to our javascript. Via javascript we will
		 * dynamically (re)build our form elements based on the selection. We however add all possible
		 * values to the dropdowns so we can validate if the POSTed value is valid.
		 */
		$accountsTree = array();
		$accountsValues = array();
		$webPropertiesValues = array();
		$profilesValues = array();
		$accounts = $this->get('fork.analytics.service')->getAccounts();
		$webProperties = $this->get('fork.analytics.service')->getWebProperties();
		$profiles = $this->get('fork.analytics.service')->getProfiles();

		foreach($accounts as $account)
		{
			$account['web_properties'] = array();
			$accountsTree[$account['id']] = $account;
			$accountsValues[$account['id']] = $account['name'];
		}
		foreach($webProperties as $webProperty)
		{
			$webProperty['profiles'] = array();
			$accountsTree[$webProperty['account_id']]['web_properties'][$webProperty['id']] = $webProperty;
			$webPropertiesValues[$webProperty['id']] = $webProperty['name'];
		}
		foreach($profiles as $profile)
		{
			$accountsTree[$profile['account_id']]['web_properties'][$profile['web_property_id']]['profiles'][$profile['id']] = $profile;
			$profilesValues[] = array('value' => $profile['id'], 'label' => $profile['name']);
		}
		$this->header->addJsData($this->getModule(), 'gaAccountTree', $accountsTree);

		// form will be (re)build via javascript
		$frm = new BackendForm('linkProfile');
		$frm->addDropdown('ga_account', $accountsValues)->setDefaultElement('');
		$frm->addDropdown('web_property', $webPropertiesValues)->setDefaultElement('');
		$frm->addRadiobutton('profile', $profilesValues);

		if($frm->isSubmitted())
		{
			$frm->getField('ga_account')->isFilled(BL::err('FieldIsRequired'));
			$frm->getField('web_property')->isFilled(BL::err('FieldIsRequired'));
			$frm->getField('profile')->isFilled(BL::err('FieldIsRequired'));

			if($frm->isCorrect())
			{
				$accountId = $frm->getField('ga_account')->getValue();
				$accountName = $accountsValues[$accountId];
				$webPropertyId = $frm->getField('web_property')->getValue();
				$webPropertyName = $webPropertiesValues[$webPropertyId];
				$profileId = $frm->getField('profile')->getValue();

				foreach($profilesValues as $profile)
				{
					if($profile['value'] == $profileId)
					{
						$profileName = $profile['label'];
						break;
					}
				}

				BackendModel::setModuleSetting($this->getModule(), 'account_id', $accountId);
				BackendModel::setModuleSetting($this->getModule(), 'account_name', $accountName);
				BackendModel::setModuleSetting($this->getModule(), 'web_property_id', $webPropertyId);
				BackendModel::setModuleSetting($this->getModule(), 'web_property_name', $webPropertyName);
				BackendModel::setModuleSetting($this->getModule(), 'profile_id', $profileId);
				BackendModel::setModuleSetting($this->getModule(), 'profile_name', $profileName);

				$this->redirect(BackendModel::createURLForAction($this->getAction()) . '&report=saved');
			}
		}

		$this->tpl->assign('step3', true);
		$this->tpl->assign('hasProfiles', (count($accountsTree) > 0));
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
		$this->tpl->assign('webProfileName', $this->profileName);
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
		BackendModel::setModuleSetting($this->getModule(), 'profile_id', null);
		BackendModel::setModuleSetting($this->getModule(), 'profile_name', null);
		BackendModel::setModuleSetting($this->getModule(), 'universal_analytics', null);

		BackendAnalyticsModel::removeCacheFiles();
		BackendAnalyticsModel::clearTables();

		$this->redirect(BackendModel::createURLForAction($this->getAction()) . '&report=removed');
	}
}
