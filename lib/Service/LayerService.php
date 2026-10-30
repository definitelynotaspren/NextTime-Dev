<?php

declare(strict_types=1);

namespace OCA\TimeBank\Service;

use OCA\TimeBank\Chapter\ChapterDirectory;
use OCA\TimeBank\Db\LayerRow;
use OCA\TimeBank\Db\LayerRowMapper;
use OCA\TimeBank\Db\RequestMapper;
use OCA\TimeBank\Geo\Coordinates;
use OCA\TimeBank\Schema\FieldPolicy;
use OCA\TimeBank\Schema\LayerException;
use OCA\TimeBank\Schema\RowValidator;
use OCA\TimeBank\Schema\SchemaSpec;
use OCA\TimeBank\Schema\Viewer;

/**
 * Reads and writes organizer-desk rows.
 *
 * Every row leaves this service through present() or publicFeed(), and both
 * pass each field through FieldPolicy. Controllers never serialize rows.
 *
 * @psalm-import-type Layer from SchemaSpec
 */
class LayerService {

	private const array ASSIGNMENT_STATUSES = ['assigned', 'in_progress', 'done'];

	/** Statuses that take a row out of the public feed. */
	private const array CLOSED_STATUSES = ['closed', 'cancelled', 'done'];

	private LayerRowMapper $rowMapper;

	public function __construct(
		LayerRowMapper $rowMapper,
		private ChapterDirectory $chapters,
		private RequestMapper $requests,
	) {
		$this->rowMapper = $rowMapper;
	}

	/**
	 * Rows the viewer may see, with fields filtered. Staff only.
	 *
	 * @param array{assigned?: string, source?: string} $filters assigned: "me" or "unassigned"
	 * @return list<array<string, mixed>>
	 */
	public function listRows(Viewer $viewer, string $layer, array $filters = []): array {
		$this->requireReadableLayer($layer);
		$this->requireStaff($viewer);

		$dbFilters = [];
		if (($filters['assigned'] ?? null) === 'me') {
			$dbFilters['assigned'] = $viewer->userId;
		} elseif (($filters['assigned'] ?? null) === 'unassigned') {
			$dbFilters['unassigned'] = true;
		}
		if (isset($filters['source']) && $filters['source'] !== '') {
			$dbFilters['source'] = $filters['source'];
		}

		$out = [];
		foreach ($this->rowMapper->findForLayer($layer, $dbFilters) as $row) {
			if (!$viewer->canSeeChapter($this->chapterOf($row))) {
				continue;
			}
			$out[] = $this->present($row, $viewer);
		}

		return $this->withBoardLinks($out);
	}

	/**
	 * Manual entry by an organizer. The row starts unpublished unless the
	 * organizer sets publish to yes.
	 *
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 * @throws LayerException
	 */
	public function create(Viewer $viewer, string $layer, array $input): array {
		$spec = $this->requireReadableLayer($layer);
		$this->requireStaff($viewer);

		$result = RowValidator::validate($spec['fields'], $input);
		if ($result['errors'] !== []) {
			throw LayerException::invalid(implode('; ', $result['errors']));
		}
		// Organizers add rows anywhere; a facilitator only inside a chapter they facilitate.
		if (!$viewer->canSeeChapter($this->chapters->chapterForZip(Input::string($result['data']['zip'] ?? null)))) {
			throw LayerException::forbidden('You can add rows only for ZIP codes in a chapter you facilitate');
		}

		$now = new \DateTime();
		$row = new LayerRow();
		$row->setLayer($layer);
		$row->setUid($this->newUid($layer, $spec['uid_prefix']));
		$row->setChapter($this->chapters->chapterForZip(Input::string($result['data']['zip'] ?? null)));
		$row->setPublish($result['publish']);
		$row->setSource('manual');
		$row->setData(json_encode($result['data'], JSON_THROW_ON_ERROR));
		$row->setCreatedBy($viewer->userId);
		$row->setCreatedAt($now);
		$row->setUpdatedAt($now);

		return $this->present($this->rowMapper->insert($row), $viewer);
	}

	/**
	 * A row that came from a Nextcloud form. Never published by itself; an organizer or the
	 * chapter's facilitator reviews and publishes it. The ID says where it came from:
	 * "NO-F3-17" is submission 17 of form 3, "NO-M1A2B3C" was typed in on the desk.
	 *
	 * @param array<string, mixed> $data already validated
	 * @throws LayerException
	 */
	public function importFromForm(string $layer, string $uid, array $data): void {
		$this->requireReadableLayer($layer);
		if ($this->rowMapper->findByUid($layer, $uid) !== null) {
			throw LayerException::conflict("Row $uid already exists");
		}

		$now = new \DateTime();
		$row = new LayerRow();
		$row->setLayer($layer);
		$row->setUid($uid);
		$row->setChapter($this->chapters->chapterForZip(Input::string($data['zip'] ?? null)));
		$row->setPublish(false);
		$row->setSource('form');
		$row->setData(json_encode($data, JSON_THROW_ON_ERROR));
		$row->setCreatedBy('forms');
		$row->setCreatedAt($now);
		$row->setUpdatedAt($now);

		$this->rowMapper->insert($row);
	}

	/**
	 * Claims a row. Organizers can claim any row; a facilitator only rows in a chapter they
	 * facilitate. Fails if someone else holds it.
	 *
	 * @return array<string, mixed>
	 * @throws LayerException
	 */
	public function assign(Viewer $viewer, string $layer, int $id, ?string $status = null): array {
		$this->requireReadableLayer($layer);
		$this->requireStaff($viewer);

		$status ??= 'assigned';
		$this->requireAssignmentStatus($status);

		$row = $this->findRow($layer, $id);
		$this->requireCanWork($row, $viewer);
		if ($row->getAssignedTo() !== null && $row->getAssignedTo() !== $viewer->userId) {
			throw LayerException::conflict('This row is already assigned to someone else');
		}

		$now = new \DateTime();
		$row->setAssignedTo($viewer->userId);
		$row->setAssignmentStatus($status);
		$row->setAssignedAt($now);
		$row->setUpdatedAt($now);

		return $this->present($this->rowMapper->update($row), $viewer);
	}

	/**
	 * Updates progress on a row the viewer holds.
	 *
	 * @return array<string, mixed>
	 * @throws LayerException
	 */
	public function setAssignmentStatus(Viewer $viewer, string $layer, int $id, string $status): array {
		$this->requireReadableLayer($layer);
		$this->requireAssignmentStatus($status);

		$row = $this->findRow($layer, $id);
		$this->requireHolder($row, $viewer);
		$this->requireCanWork($row, $viewer);

		$row->setAssignmentStatus($status);
		$row->setUpdatedAt(new \DateTime());

		return $this->present($this->rowMapper->update($row), $viewer);
	}

	/**
	 * Makes a row public (it then appears in the public feed, with public fields only and a
	 * rounded point) or takes it down. Whoever may work the row may do this.
	 *
	 * @return array<string, mixed>
	 * @throws LayerException
	 */
	public function setPublished(Viewer $viewer, string $layer, int $id, bool $publish): array {
		$this->requireReadableLayer($layer);
		$this->requireStaff($viewer);

		$row = $this->findRow($layer, $id);
		$this->requireCanWork($row, $viewer);

		$row->setPublish($publish);
		$row->setUpdatedAt(new \DateTime());

		return $this->present($this->rowMapper->update($row), $viewer);
	}

	/**
	 * Releases a row. The holder can; so can an organizer, so a row is never stuck with
	 * someone who has left or lost their role.
	 *
	 * Releases a row the viewer holds.
	 *
	 * @return array<string, mixed>
	 * @throws LayerException
	 */
	public function unassign(Viewer $viewer, string $layer, int $id): array {
		$this->requireReadableLayer($layer);

		$row = $this->findRow($layer, $id);
		if (!$viewer->nationalOrganizer) {
			$this->requireHolder($row, $viewer);
			$this->requireCanWork($row, $viewer);
		}

		$row->setAssignedTo(null);
		$row->setAssignmentStatus(null);
		$row->setAssignedAt(null);
		$row->setUpdatedAt(new \DateTime());

		return $this->present($this->rowMapper->update($row), $viewer);
	}

	/**
	 * Rows assigned to the viewer, optionally by layer and source.
	 *
	 * @return list<array<string, mixed>>
	 * @throws LayerException
	 */
	public function myAssignments(Viewer $viewer, ?string $layer = null, ?string $source = null): array {
		$this->requireStaff($viewer);

		$out = [];
		foreach ($this->rowMapper->findAssignedTo($viewer->userId, $layer, $source) as $row) {
			if (SchemaSpec::layer($row->getLayer()) === null) {
				continue;
			}
			// Someone who lost a chapter's facilitator role no longer sees its rows, held or not.
			if (!$viewer->canSeeChapter($this->chapterOf($row))) {
				continue;
			}
			$out[] = $this->present($row, $viewer);
		}

		return $this->withBoardLinks($out);
	}

	/**
	 * A row this person may work on (staff of its chapter, or a national organizer), for actions that
	 * go beyond the desk, such as putting it on the request board.
	 *
	 * @throws LayerException
	 */
	public function workableRow(Viewer $viewer, string $layer, int $id): LayerRow {
		$this->requireReadableLayer($layer);
		$this->requireStaff($viewer);
		$row = $this->findRow($layer, $id);
		$this->requireCanWork($row, $viewer);

		return $row;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function dataOf(LayerRow $row): array {
		return $this->decode($row->getData());
	}

	/**
	 * Marks the rows that are already on the board with the request's id.
	 *
	 * @param list<array<string, mixed>> $rows
	 * @return list<array<string, mixed>>
	 */
	private function withBoardLinks(array $rows): array {
		$onBoard = $this->requests->deskRequestIds();
		foreach ($rows as &$row) {
			$fields = $row['fields'] ?? null;
			$uid = is_array($fields) ? ($fields['uid'] ?? null) : null;
			$row['boardRequestId'] = is_string($uid) ? ($onBoard[DeskBridge::sourceRef($uid)] ?? null) : null;
		}

		return $rows;
	}

	/**
	 * GeoJSON for a public map. Only point_snapped layers qualify. Rows must be
	 * published and open, and carry only public fields. Points are rounded to
	 * an approximate area, so exact organizer coordinates never leave the server.
	 *
	 * @return array<string, mixed>
	 * @throws LayerException
	 */
	public function publicFeed(Viewer $viewer, string $layer): array {
		$spec = $this->requireReadableLayer($layer);
		if (($spec['geometry'] ?? '') !== 'point_snapped') {
			throw LayerException::notFound("Layer $layer has no public feed");
		}

		$today = (new \DateTime())->format('Y-m-d');
		$fields = SchemaSpec::allFields($layer);
		$features = [];

		foreach ($this->rowMapper->findPublished($layer) as $row) {
			$data = $this->decode($row->getData());

			$point = $this->pointFrom($data);
			if ($point === null) {
				continue;
			}
			if (in_array($data['status'] ?? null, self::CLOSED_STATUSES, true)) {
				continue;
			}
			if (isset($data['expires']) && $data['expires'] < $today) {
				continue;
			}

			$payload = array_merge(['uid' => $row->getUid(), 'publish' => 'yes'], $data);
			$properties = FieldPolicy::filter($fields, $payload, $viewer, $this->chapterOf($row));

			$features[] = [
				'type' => 'Feature',
				'geometry' => [
					'type' => 'Point',
					'coordinates' => Coordinates::toPublic($point[0], $point[1]),
				],
				'properties' => $properties + ['layer' => $layer],
			];
		}

		return [
			'type' => 'FeatureCollection',
			'features' => $features,
		];
	}

	/**
	 * The chapter that reviews this row, worked out from its ZIP each time rather than read
	 * from what was stored when it was written. If a chapter's group is added, renamed or
	 * removed, rows follow it, and a facilitator who loses the role loses the rows with it.
	 */
	/** The row's state: the override someone set (StateReassignment), else its ZIP's. */
	private function chapterOf(LayerRow $row): ?string {
		if ($row->getChapterOverride() !== null) {
			return $row->getChapterOverride();
		}
		$data = $this->decode($row->getData());
		$zip = isset($data['zip']) ? (string)$data['zip'] : null;

		return $this->chapters->chapterForZip($zip);
	}

	/**
	 * Builds the client view of a row. Callers must be staff.
	 *
	 * @return array<string, mixed>
	 */
	private function present(LayerRow $row, Viewer $viewer): array {
		$payload = array_merge(
			['uid' => $row->getUid(), 'publish' => $row->getPublish() ? 'yes' : 'no'],
			$this->decode($row->getData()),
		);

		$spec = SchemaSpec::layer($row->getLayer());
		$fields = $spec === null ? [] : SchemaSpec::allFields($row->getLayer());

		return [
			'id' => $row->getId(),
			'layer' => $row->getLayer(),
			'source' => $row->getSource(),
			'chapter' => $this->chapterOf($row),
			'assignedTo' => $row->getAssignedTo(),
			'assignmentStatus' => $row->getAssignmentStatus(),
			'assignedAt' => $row->getAssignedAt()?->format('Y-m-d H:i:s'),
			'fields' => FieldPolicy::filter($fields, $payload, $viewer, $this->chapterOf($row)),
		];
	}

	/**
	 * @return array<string, mixed>
	 * @throws LayerException if the layer is unknown or aggregate-only
	 */
	/**
	 * @return Layer
	 * @throws LayerException
	 */
	private function requireReadableLayer(string $layer): array {
		$spec = SchemaSpec::layer($layer);
		if ($spec === null) {
			throw LayerException::notFound("Unknown layer: $layer");
		}
		if (!empty($spec['aggregate_only'])) {
			throw LayerException::forbidden('This layer is aggregate-only; individual rows are never exposed');
		}

		return $spec;
	}

	private function requireStaff(Viewer $viewer): void {
		if (!$viewer->isStaff()) {
			throw LayerException::forbidden('Organizer or facilitator role required');
		}
	}

	/** Organizers work any row; a facilitator works rows of the chapters they facilitate. */
	private function requireCanWork(LayerRow $row, Viewer $viewer): void {
		if (!$viewer->canSeeChapter($this->chapterOf($row))) {
			throw LayerException::forbidden('You can work only rows in a chapter you facilitate');
		}
	}

	private function requireHolder(LayerRow $row, Viewer $viewer): void {
		if ($row->getAssignedTo() !== $viewer->userId) {
			throw LayerException::forbidden('Only the person holding this row can change it');
		}
	}

	private function requireAssignmentStatus(string $status): void {
		if (!in_array($status, self::ASSIGNMENT_STATUSES, true)) {
			throw LayerException::invalid('Status must be one of: ' . implode(', ', self::ASSIGNMENT_STATUSES));
		}
	}

	/**
	 * @throws LayerException
	 */
	private function findRow(string $layer, int $id): LayerRow {
		try {
			return $this->rowMapper->find($layer, $id);
		} catch (\OCP\AppFramework\Db\DoesNotExistException) {
			throw LayerException::notFound("No row $id in $layer");
		}
	}

	private function newUid(string $layer, string $prefix): string {
		for ($attempt = 0; $attempt < 5; $attempt++) {
			$uid = $prefix . '-M' . strtoupper(bin2hex(random_bytes(3)));
			if ($this->rowMapper->findByUid($layer, $uid) === null) {
				return $uid;
			}
		}

		throw LayerException::conflict('Could not allocate a unique ID; try again');
	}

	/**
	 * @param array<string, mixed> $data
	 * @return array{0: float, 1: float}|null
	 */
	private function pointFrom(array $data): ?array {
		if (!isset($data['lat'], $data['lon']) || !is_numeric($data['lat']) || !is_numeric($data['lon'])) {
			return null;
		}

		return Coordinates::validate((float)$data['lat'], (float)$data['lon']);
	}

	/**
	 * Corrupt JSON yields no fields, which fails closed.
	 *
	 * @return array<string, mixed>
	 */
	private function decode(string $json): array {
		$decoded = json_decode($json, true);
		if (!is_array($decoded)) {
			return [];
		}
		/** @var array<string, mixed> $decoded keys are the field names this service wrote */
		return $decoded;
	}
}
