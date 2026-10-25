<?php

declare(strict_types=1);

namespace OCA\TimeBank\Service;

use OCA\TimeBank\Chapter\ChapterDirectory;
use OCA\TimeBank\Db\LayerRow;
use OCA\TimeBank\Db\LayerRowMapper;
use OCA\TimeBank\Db\Request;
use OCA\TimeBank\Db\RequestMapper;
use OCA\TimeBank\Schema\SchemaSpec;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;

/**
 * Moves a request or an organizer-desk row to another state (Decision 5 of the security plan).
 * Normally the ZIP decides; this sets an override that wins over the ZIP until it is cleared.
 *
 * Who may: a facilitator of the state it is in now or of the state it moves to, a national
 * organizer, or a Nextcloud admin. Every move is written to the public governance log.
 */
class StateReassignment {

	public function __construct(
		private RequestMapper $requests,
		private LayerRowMapper $rows,
		private RequestPresenter $presenter,
		private ChapterDirectory $chapters,
		private ViewerResolver $viewers,
		private IGroupManager $groups,
		private GovernanceLog $log,
	) {
	}

	/**
	 * @param string|null $to chapter slug, or null to go back to the state of the request's ZIP
	 * @throws DoesNotExistException
	 * @throws InvalidStateException
	 * @throws NotAuthorizedException
	 */
	public function moveRequest(int $id, string $actorId, ?string $to): Request {
		$request = $this->requests->find($id);
		$from = $this->presenter->chapterOf($request);
		// Work out the new state on a copy, so a refused move leaves the request untouched.
		$moved = clone $request;
		$moved->setChapterOverride($to);
		$target = $this->presenter->chapterOf($moved);
		$this->requireMove($actorId, $from, $target, $to);

		$request->setChapterOverride($to);
		$request->setChapter($target);
		$request->setUpdatedAt(new \DateTime());
		$request = $this->requests->update($request);
		$this->log->record($actorId, 'request.state_changed', $request->getRequesterId(), [
			'request' => $request->getId(),
			'title' => $request->getTitle(),
			'from' => $from,
			'to' => $target,
		]);

		return $request;
	}

	/**
	 * @param string|null $to chapter slug, or null to go back to the state of the row's ZIP
	 * @return array{uid: string, chapter: string|null, chapterOverride: string|null}
	 * @throws DoesNotExistException
	 * @throws InvalidStateException
	 * @throws NotAuthorizedException
	 */
	public function moveDeskRow(string $layer, int $id, string $actorId, ?string $to): array {
		$spec = SchemaSpec::layer($layer);
		if ($spec === null || !empty($spec['aggregate_only'])) {
			throw new InvalidStateException('Rows of this layer cannot be moved');
		}
		$row = $this->rows->find($layer, $id);
		$from = $this->chapterOfRow($row);
		$moved = clone $row;
		$moved->setChapterOverride($to);
		$target = $this->chapterOfRow($moved);
		$this->requireMove($actorId, $from, $target, $to);

		$row->setChapterOverride($to);
		$row->setChapter($target);
		$row->setUpdatedAt(new \DateTime());
		$row = $this->rows->update($row);
		$this->log->record($actorId, 'desk_row.state_changed', null, [
			'layer' => $layer,
			'uid' => $row->getUid(),
			'from' => $from,
			'to' => $target,
		]);

		return ['uid' => $row->getUid(), 'chapter' => $target, 'chapterOverride' => $row->getChapterOverride()];
	}

	/**
	 * The states this person could move something into, for a picker; empty for people who
	 * can never move anything. The server still checks each move.
	 *
	 * @return list<array{slug: string, name: string}>
	 */
	public function choicesFor(string $actorId): array {
		$viewer = $this->viewers->forUser($actorId);
		if (!$viewer->nationalOrganizer && $viewer->facilitatorOf === [] && !$this->groups->isAdmin($actorId)) {
			return [];
		}

		$names = [];
		foreach ($this->chapters->all() as $chapter) {
			$names[$chapter->slug] = $chapter->name;
		}
		asort($names, SORT_STRING | SORT_FLAG_CASE);

		$choices = [];
		foreach ($names as $slug => $name) {
			$choices[] = ['slug' => $slug, 'name' => $name];
		}

		return $choices;
	}

	/**
	 * The desk row's state: its override, else its ZIP's. LayerService uses the same rule.
	 */
	public function chapterOfRow(LayerRow $row): ?string {
		return $row->getChapterOverride() ?? $this->chapters->chapterForZip(self::zipOf($row));
	}

	/**
	 * @throws InvalidStateException
	 * @throws NotAuthorizedException
	 */
	private function requireMove(string $actorId, ?string $from, ?string $target, ?string $to): void {
		if ($to !== null && $this->chapters->get($to) === null) {
			throw new InvalidStateException('No state or chapter called "' . $to . '"');
		}
		if ($from === $target) {
			throw new InvalidStateException('It is already in that state');
		}

		$viewer = $this->viewers->forUser($actorId);
		$facilitates = fn (?string $slug): bool => $slug !== null && in_array($slug, $viewer->facilitatorOf, true);
		if (!$viewer->nationalOrganizer && !$facilitates($from) && !$facilitates($target) && !$this->groups->isAdmin($actorId)) {
			throw new NotAuthorizedException('Only a facilitator of the old or new state, a national organizer or an admin can move this');
		}
	}

	private static function zipOf(LayerRow $row): ?string {
		$data = json_decode($row->getData(), true);

		return is_array($data) && isset($data['zip']) && is_scalar($data['zip']) ? (string)$data['zip'] : null;
	}
}
