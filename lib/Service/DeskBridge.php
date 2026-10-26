<?php

declare(strict_types=1);

namespace OCA\TimeBank\Service;

use OCA\TimeBank\Db\RequestMapper;
use OCA\TimeBank\Schema\LayerException;
use OCA\TimeBank\Schema\Viewer;

/**
 * Puts a "need" from the organizer desk on the request board, so members can volunteer for it.
 *
 * The facilitator who does it becomes the request's requester (they coordinate it and accept
 * volunteers) and the board shows it as "posted for the desk". Only what the facilitator types
 * into the form is copied, plus the row's ZIP: names, contact details and the organizer-only
 * fields of the row never travel. A row goes on the board once; the request keeps the row's id
 * as its source reference ("desk:NO-F3-17").
 */
class DeskBridge {

	public const string SOURCE_PREFIX = 'desk:';
	private const string LAYER = 'needs_offers';

	public function __construct(
		private LayerService $layers,
		private RequestService $requests,
		private RequestMapper $requestMapper,
		private CategoryService $categories,
		private GovernanceLog $log,
	) {
	}

	public static function sourceRef(string $uid): string {
		return self::SOURCE_PREFIX . $uid;
	}

	/**
	 * @param array<string, mixed> $input title, description, categoryId, hoursBudget, priority (optional)
	 * @return array<string, mixed> the presented request
	 * @throws LayerException
	 * @throws InvalidStateException
	 */
	public function putOnBoard(Viewer $viewer, string $layer, int $rowId, array $input): array {
		if ($layer !== self::LAYER) {
			throw LayerException::invalid('Only a need from the needs and offers board can be put on the request board');
		}
		$row = $this->layers->workableRow($viewer, $layer, $rowId);
		$data = $this->layers->dataOf($row);
		if (($data['need_or_offer'] ?? null) !== 'need') {
			throw LayerException::invalid('Only a need can be put on the request board; an offer is a person who could help');
		}
		$zip = Input::string($data['zip'] ?? null);
		if ($zip === null || $zip === '') {
			throw LayerException::invalid('This row has no ZIP code, so it cannot go on the board');
		}
		$ref = self::sourceRef($row->getUid());
		if ($this->requestMapper->findBySourceRef($ref) !== null) {
			throw LayerException::conflict('This row is already on the request board');
		}

		$title = trim(Input::string($input['title'] ?? null) ?? '');
		$description = trim(Input::string($input['description'] ?? null) ?? '');
		$hours = Input::float($input['hoursBudget'] ?? null);
		$categoryId = Input::float($input['categoryId'] ?? null);
		if (mb_strlen($title) < 5 || mb_strlen($title) > 200) {
			throw LayerException::invalid('The title must be 5 to 200 characters');
		}
		if (mb_strlen($description) < 20) {
			throw LayerException::invalid('The description must be at least 20 characters');
		}
		if ($hours === null || $hours <= 0 || $hours > 1000) {
			throw LayerException::invalid('The hours budget must be above 0 and at most 1000');
		}
		if ($categoryId === null) {
			throw LayerException::invalid('Choose a category');
		}
		$this->categories->requireUsable((int)$categoryId);

		$request = $this->requests->createRequest($viewer->userId, [
			'title' => $title,
			'description' => $description,
			'categoryId' => (int)$categoryId,
			'hoursBudget' => $hours,
			'priority' => Input::string($input['priority'] ?? null) ?? 'normal',
			'zip' => $zip,
			'sourceRef' => $ref,
		]);
		$this->log->record($viewer->userId, 'desk_row.put_on_board', null, [
			'uid' => $row->getUid(),
			'request' => $request->getId(),
		]);

		return $this->requests->present($request, $viewer->userId);
	}
}
