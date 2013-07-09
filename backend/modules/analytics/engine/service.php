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
}
