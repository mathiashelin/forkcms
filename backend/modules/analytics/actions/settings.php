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

		// we are missing info to be able to connect to the api
		if(empty($this->clientId) || empty($this->clientSecret) || empty($this->token))
		{
			$this->loadStep1();
			$this->loadStep2();
		}

		// a token is available, display accounts
		elseif(!empty($this->token))
		{
			$this->loadStep3();
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

				$this->redirect(BackendModel::createURLForAction($this->getAction()));
			}
		}

		$this->tpl->assign('step3', true);
		$this->tpl->assign('hasProfiles', (count($profilesValues) > 0));
		$frm->parse($this->tpl);
	}

	/**
	 * Gets all the needed parameters to link a google analytics account to fork
	 */
	private function getAnalyticsParameters()
	{
		$remove = SpoonFilter::getGetValue('remove', array('session_token', 'table_id'), null);

		// something has to be removed before proceeding
		if(!empty($remove))
		{
			// the session token has te be removed
			if($remove == 'session_token')
			{
				// remove all parameters from the module settings
				BackendModel::setModuleSetting($this->getModule(), 'session_token', null);
			}

			// remove all profile parameters from the module settings
			BackendModel::setModuleSetting($this->getModule(), 'account_name', null);
			BackendModel::setModuleSetting($this->getModule(), 'table_id', null);
			BackendModel::setModuleSetting($this->getModule(), 'profile_title', null);
			BackendModel::setModuleSetting($this->getModule(), 'web_property_id', null);

			// remove cache files
			BackendAnalyticsModel::removeCacheFiles();

			// clear tables
			BackendAnalyticsModel::clearTables();
		}

		// get session token, account name, the profile's table id, the profile's title
		$this->sessionToken = BackendModel::getModuleSetting($this->getModule(), 'session_token', null);
		$this->accountName = BackendModel::getModuleSetting($this->getModule(), 'account_name', null);
		$this->tableId = BackendModel::getModuleSetting($this->getModule(), 'table_id', null);
		$this->profileTitle = BackendModel::getModuleSetting($this->getModule(), 'profile_title', null);
		$this->apiKey = BackendModel::getModuleSetting($this->getModule(), 'api_key', null);

		// no session token
		if(!isset($this->sessionToken))
		{
			$token = SpoonFilter::getGetValue('token', null, null);

			// a one time token is given in the get parameters
			if(!empty($token) && $token !== 'true')
			{
				// get google analytics instance
				$ga = BackendAnalyticsHelper::getGoogleAnalyticsInstance();

				// get a session token
				$this->sessionToken = $ga->getSessionToken($token);

				// store the session token in the settings
				BackendModel::setModuleSetting($this->getModule(), 'session_token', $this->sessionToken);
			}
		}

		// session id is present but there is no table_id
		if(isset($this->sessionToken) && !isset($this->tableId))
		{
			// get google analytics instance
			$ga = BackendAnalyticsHelper::getGoogleAnalyticsInstance();

			try
			{
				// get all possible profiles in this account
				$this->profiles = $ga->getAnalyticsAccountList($this->sessionToken);
			}
			catch(GoogleAnalyticsException $e)
			{
				// bad request, probably means the API key is wrong
				if($e->getCode() == '400')
				{
					// reset token so we can alter the API key
					BackendModel::setModuleSetting($this->getModule(), 'session_token', null);

					$this->redirect(BackendModel::createURLForAction('settings') . '&error=invalid-api-key');
				}
			}

			// not authorized
			if($this->profiles == 'UNAUTHORIZED')
			{
				// remove invalid session token
				BackendModel::setModuleSetting($this->getModule(), 'session_token', null);

				// redirect to the settings page without parameters
				$this->redirect(BackendModel::createURLForAction('settings'));
			}

			// everything went fine
			elseif(is_array($this->profiles))
			{
				$tableId = SpoonFilter::getGetValue('table_id', null, null);

				// a table id is given in the get parameters
				if(!empty($tableId))
				{
					$profiles = array();

					// set the table ids as keys
					foreach($this->profiles as $profile) $profiles[$profile['tableId']] = $profile;

					// correct table id
					if(isset($profiles[$tableId]))
					{
						// save table id and account title
						$this->tableId = $tableId;
						$this->accountName = $profiles[$this->tableId]['profileName'];
						$this->profileTitle = $profiles[$this->tableId]['title'];
						$webPropertyId = $profiles[$this->tableId]['webPropertyId'];

						// store the table id and account title in the settings
						BackendModel::setModuleSetting($this->getModule(), 'account_name', $this->accountName);
						BackendModel::setModuleSetting($this->getModule(), 'table_id', $this->tableId);
						BackendModel::setModuleSetting($this->getModule(), 'profile_title', $this->profileTitle);
						BackendModel::setModuleSetting($this->getModule(), 'web_property_id', $webPropertyId);
					}
				}
			}
		}
	}

	/**
	 * Load settings form
	 */
	private function loadTrackingTypeForm()
	{
		$this->frmTrackingType = new BackendForm('trackingType');

		$types = array();
		$types[] = array('label' => 'Universal Analytics', 'value' => 'universal_analytics');
		$types[] = array('label' => 'Classic Google Analytics', 'value' => 'classic_analytics');
		$types[] = array('label' => 'Display Advertising (stats.g.doubleclick.net/dc.js)', 'value' => 'display_advertising');

		$this->frmTrackingType->addRadiobutton(
			'type',
			$types,
			BackendModel::getModuleSetting($this->URL->getModule(), 'tracking_type', 'universal_analytics')
		);
	}

	/**
	 * Validates the tracking url form.
	 */
	private function validateTrackingTypeForm()
	{
		// form is submitted
		if($this->frmTrackingType->isSubmitted())
		{
			// form is validated
			if($this->frmTrackingType->isCorrect())
			{
				BackendModel::setModuleSetting(
					$this->getModule(),
					'tracking_type',
					$this->frmTrackingType->getField('type')->getValue()
				);
				BackendModel::triggerEvent($this->getModule(), 'after_saved_tracking_type_settings');
				$this->redirect(BackendModel::createURLForAction('settings') . '&report=saved');
			}
		}
	}

	/**
	 * Parse
	 */
	protected function parse()
	{
		parent::parse();

		if(!isset($this->sessionToken))
		{
			// show the link to the google account authentication form
			$this->tpl->assign('NoSessionToken', true);
			$this->tpl->assign('Wizard', true);

			// build the link to the google account authentication form
			$redirectUrl = SITE_URL . '/' . (strpos($this->URL->getQueryString(), '?') === false ? $this->URL->getQueryString() : substr($this->URL->getQueryString(), 0, strpos($this->URL->getQueryString(), '?')));
			$googleAccountAuthenticationForm = sprintf(BackendAnalyticsModel::GOOGLE_ACCOUNT_AUTHENTICATION_URL, urlencode($redirectUrl), urlencode(BackendAnalyticsModel::GOOGLE_ACCOUNT_AUTHENTICATION_SCOPE));

			// create form
			$this->frmApiKey = new BackendForm('apiKey');
			$this->frmApiKey->addText('key', $this->apiKey);

			if($this->frmApiKey->isSubmitted())
			{
				$this->frmApiKey->getField('key')->isFilled(BL::err('FieldIsRequired'));

				if($this->frmApiKey->isCorrect())
				{
					BackendModel::setModuleSetting($this->getModule(), 'api_key', $this->frmApiKey->getField('key')->getValue());
					$this->redirect($googleAccountAuthenticationForm);
				}
			}

			$this->frmApiKey->parse($this->tpl);
		}

		// session token is present but no table id
		elseif(isset($this->sessionToken) && isset($this->profiles) && !isset($this->tableId))
		{
			// show all possible accounts with their profiles
			$this->tpl->assign('NoTableId', true);
			$this->tpl->assign('Wizard', true);

			$accounts = array();

			// no profiles or not authorized
			if(!empty($this->profiles) && $this->profiles !== 'UNAUTHORIZED')
			{
				$accounts[''][0] = BL::msg('ChooseWebsiteProfile');

				// prepare accounts array
				foreach((array) $this->profiles as $profile)
				{
					$accounts[$profile['accountName']][$profile['tableId']] = $profile['profileName'] . ' (' . $profile['webPropertyId'] . ')';
				}

				// there are accounts
				if(!empty($accounts))
				{
					// sort accounts
					uksort($accounts, array('BackendAnalyticsSettings', 'sortAccounts'));

					// create form
					$this->frmLinkProfile = new BackendForm('linkProfile', BackendModel::createURLForAction(), 'get');
					$this->frmLinkProfile->addDropdown('table_id', $accounts);
					$this->frmLinkProfile->parse($this->tpl);

					if($this->frmLinkProfile->isSubmitted())
					{
						if($this->frmLinkProfile->getField('table_id')->getValue() == '0') $this->tpl->assign('ddmTableIdError', BL::err('FieldIsRequired'));
					}

					// parse accounts
					$this->tpl->assign('accounts', true);
				}
			}
		}

		// everything is fine
		elseif(isset($this->sessionToken) && isset($this->tableId) && isset($this->accountName))
		{
			// show the linked account
			$this->tpl->assign('EverythingIsPresent', true);

			// show the title of the linked account and profile
			$this->tpl->assign('accountName', $this->accountName);
			$this->tpl->assign('profileTitle', $this->profileTitle);
		}

		// Parse tracking url form
		$this->frmTrackingType->parse($this->tpl);
	}

	/**
	 * Helper function to sort accounts
	 *
	 * @param array $account1 First account for comparison.
	 * @param array $account2 Second account for comparison.
	 * @return int
	 */
	public static function sortAccounts($account1, $account2)
	{
		if(strtolower($account1) > strtolower($account2)) return 1;
		if(strtolower($account1) < strtolower($account2)) return -1;
		return 0;
	}
}
