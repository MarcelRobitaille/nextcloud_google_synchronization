<?php

/**
 * Nextcloud - google
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Julien Veyssier
 * @copyright Julien Veyssier 2020
 */

namespace OCA\Google\BackgroundJob;

use OCA\Google\Service\GoogleContactsAPIService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

class ImportContactsJob extends TimedJob {

	private GoogleContactsAPIService $service;

	public function __construct(ITimeFactory $timeFactory, GoogleContactsAPIService $service) {
		parent::__construct($timeFactory);
		$this->service = $service;
		parent::setInterval(1);
	}

	/**
	 * @param array{user_id: string, address_book_key: int, address_book_uri: string, address_book_name: string} $argument
	 */
	#[\Override]
	protected function run($argument): void {
		echo(date('Y-m-d H:i:s') . ' Importing ' . $argument['address_book_name'] . '...');
		$result = $this->service->safeImportContacts(
			$argument['user_id'],
			$argument['address_book_uri'],
			$argument['address_book_key'],
			$argument['address_book_name'],
		);
		if (isset($result['error'])) {
			echo(' error: ' . $result['error'] . PHP_EOL);
		} else {
			echo(' done. Added ' . $result['nbAdded'] . ', updated ' . $result['nbUpdated'] . PHP_EOL);
		}
	}
}
