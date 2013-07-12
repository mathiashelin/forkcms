<?php

use \Symfony\Component\HttpKernel\KernelInterface;

/**
 * Wrapper for the Google_AnalyticsService.
 *
 * @author Dieter Vanden Eynde <dieter.vandeneynde@wijs.be>
 */
class BackendAnalyticsService extends KernelLoader
{
	/**
	 * @var Google_AnalyticsService
	 */
	private $gaService;

	/**
	 * @param \Symfony\Component\HttpKernel\KernelInterface $kernel
	 */
	public function __construct(KernelInterface $kernel)
	{
		parent::__construct($kernel);

		$this->gaService = $kernel->getContainer()->get('google.analytics.service');
	}

	/**
	 * Get all accounts linked to the current tokens.
	 *
	 * @param int[optional] $offset
	 * @return array
	 */
	public function getAccounts($startIndex = 1)
	{
		$accounts = array();

		$params = array('start-index' => $startIndex);
		$results = $this->gaService->management_accounts->listManagementAccounts($params);

		if($results !== null)
		{
			foreach($results->getItems() as $account)
			{
				$createdOn = new DateTime($account->getCreated());
				$updatedOn = new DateTime($account->getUpdated());

				$accounts[] = array(
					'id' => $account->getId(),
					'name' => $account->getName(),
					'created_on' => $createdOn->getTimestamp(),
					'updated_on' => $updatedOn->getTimestamp()
				);
			}

			// there is a next page, go fetch
			if(($startIndex + $results->getItemsPerPage()) <= $results->getTotalResults())
			{
				$accounts = array_merge($accounts, $this->getAccounts($startIndex + $results->getItemsPerPage()));
			}
		}

		return $accounts;
	}

	/**
	 * @param int[optional] $startIndex
	 * @return array
	 */
	public function getProfiles($startIndex = 1)
	{
		$profiles = array();

		$params = array('start-index' => $startIndex);
		$results = $this->gaService->management_profiles->listManagementProfiles('~all', '~all', $params);

		if($results !== null)
		{
			foreach($results->getItems() as $profile)
			{
				$createdOn = new DateTime($profile->getCreated());
				$updatedOn = new DateTime($profile->getUpdated());

				$profiles[] = array(
					'id' => $profile->getId(),
					'account_id' => $profile->getAccountId(),
					'web_property_id' => $profile->getWebPropertyId(),
					'name' => $profile->getName(),
					'websiteUrl' => $profile->getWebsiteUrl(),
					'created_on' => $createdOn->getTimestamp(),
					'updated_on' => $updatedOn->getTimestamp()
				);
			}

			// there is a next page, go fetch
			if(($startIndex + $results->getItemsPerPage()) <= $results->getTotalResults())
			{
				$profiles = array_merge($profiles, $this->getProfiles($startIndex + $results->getItemsPerPage()));
			}
		}

		return $profiles;
	}

	/**
	 * @param int[optional] $startIndex
	 * @return array
	 */
	public function getWebProperties($startIndex = 1)
	{
		$webProperties = array();

		$params = array('start-index' => $startIndex);
		$results = $this->gaService->management_webproperties->listManagementWebproperties('~all', $params);

		if($results !== null)
		{
			foreach($results->getItems() as $webProperty)
			{
				/*
				 * The API also returns a webproperty which represents the parent account. This webproperty will
				 * have no profiles and therefor will never be able to be linked to Fork. This webproperty is also
				 * not displayed in Google Analytics. To prevent confusion, we strip it here.
				 *
				 * (By preventing confusion, I probably caused confusion.)
				 */
				if($webProperty->getProfileCount() == 0) continue;

				$createdOn = new DateTime($webProperty->getCreated());
				$updatedOn = new DateTime($webProperty->getUpdated());

				$webProperties[] = array(
					'id' => $webProperty->getId(),
					'internal_id' => $webProperty->getInternalWebPropertyId(),
					'account_id' => $webProperty->getAccountId(),
					'name' => $webProperty->getName(),
					'websiteUrl' => $webProperty->getWebsiteUrl(),
					'profilesCount' => (int) $webProperty->getProfileCount(),
					'created_on' => $createdOn->getTimestamp(),
					'updated_on' => $updatedOn->getTimestamp()
				);
			}

			// there is a next page, go fetch
			if(($startIndex + $results->getItemsPerPage()) <= $results->getTotalResults())
			{
				$webProperties = array_merge($webProperties, $this->getWebProperties($startIndex + $results->getItemsPerPage()));
			}
		}

		return $webProperties;
	}

	/**
	 * @param $profileId
	 * @param DateTime $startDate
	 * @param DateTime $endDate
	 * @param array $metrics
	 * @param array[optional] $dimensions
	 * @param int[optional] $startIndex
	 */
	public function getData($profileId, DateTime $startDate, DateTime $endDate, array $metrics, array $dimensions = null, $startIndex = 1)
	{
		$gaMetrics = array();
		$gaDimensions = array();
		$gaParams = array('start-index' => $startIndex);

		// the API expects metrics/dimensions to be prefix with ga:
		$gaMetrics = array_map(array($this, 'addGaPrefix'), $metrics);
		if($dimensions !== null)
		{
			$gaDimensions = array_map(array($this, 'addGaPrefix'), $dimensions);
			$gaParams['dimensions'] = implode(',', $gaDimensions);
		}

		$response = $this->gaService->data_ga->get(
			'ga:' . $profileId,
			$startDate->format('Y-m-d'),
			$endDate->format('Y-m-d'),
			implode(',', $gaMetrics),
			$gaParams
		);

		// the column headers define the type of fields that are returned
		$columnHeaders = $response->getColumnHeaders();
		$results = array();
		foreach($response->getRows() as $row)
		{
			$item = array();
			foreach($row as $key => $value)
			{
				$header = $columnHeaders[$key];
				switch($header->getDataType())
				{
					case 'INTEGER':
						$value = (int) $value;
						break;
					case 'FLOAT':
						$value = (float) $value;
						break;
					case 'CURRENCY':
					case 'STRING':
						$value = (string) $value;
						break;
				}

				$item[$header->getName()] = $value;
			}
			$results[] = $item;
		}

		// there is a next page, go fetch
		if(($startIndex + $response->getItemsPerPage()) <= $response->getTotalResults())
		{
			$results = array_merge(
				$results,
				$this->getData(
					$profileId, $startDate, $endDate, $metrics, $dimensions,
					$startIndex + $response->getItemsPerPage()
				)
			);
		}

		return $results;
	}

	/**
	 * Prefixes a string with ga:.
	 * Validates if ga: is already prefixed.
	 *
	 * Can be used as callback for array_map.
	 *
	 * @param $string
	 * @return string
	 */
	protected function addGaPrefix($string)
	{
		// only add if it does not already start with ga:
		if(stripos('#' . $string, '#ga:') === false)
		{
			$string = 'ga:' . $string;
		}
		return $string;
	}
}
