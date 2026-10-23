<?php

declare(strict_types=1);

namespace OCA\TimeBank\Service;

use OCA\TimeBank\Db\GovernanceEntry;
use OCA\TimeBank\Db\GovernanceEntryMapper;

/**
 * The public record of decisions that change how the community's hours work: category
 * changes, manual adjustments, setting changes. Every member can read it. Entries are
 * only ever added.
 */
class GovernanceLog {

	public function __construct(
		private GovernanceEntryMapper $mapper,
	) {
	}

	/**
	 * @param array<string, mixed> $details old and new values, a reason; shown to every member
	 */
	public function record(string $actorId, string $action, ?string $subject = null, array $details = []): void {
		$entry = new GovernanceEntry();
		$entry->setActorId($actorId);
		$entry->setAction($action);
		$entry->setSubject($subject);
		$entry->setDetails($details === [] ? null : json_encode($details, JSON_UNESCAPED_UNICODE));
		$entry->setCreatedAt(new \DateTime());
		$this->mapper->insert($entry);
	}

	/**
	 * @return array{entries: list<array<string, mixed>>, total: int, limit: int, offset: int}
	 */
	public function getPage(int $limit = 50, int $offset = 0): array {
		$limit = max(1, min(200, $limit));
		$offset = max(0, $offset);

		return [
			'entries' => array_map(fn (GovernanceEntry $e) => $e->jsonSerialize(), $this->mapper->findRecent($limit, $offset)),
			'total' => $this->mapper->countAll(),
			'limit' => $limit,
			'offset' => $offset,
		];
	}
}
