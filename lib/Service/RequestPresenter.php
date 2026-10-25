<?php

declare(strict_types=1);

namespace OCA\TimeBank\Service;

use OCA\TimeBank\Chapter\ChapterDirectory;
use OCA\TimeBank\Db\Request;
use OCA\TimeBank\Db\VolunteerMapper;
use OCA\TimeBank\Geo\ZipCenters;
use OCA\TimeBank\Schema\Viewer;

/**
 * The one place that decides who sees a request's exact location (its address text and point).
 * Every endpoint that returns a request sends it through here; Request::jsonSerialize() leaves the
 * location out, so a path that forgets this shows too little, never too much.
 *
 * Exact for: the requester; a facilitator of the request's state; a volunteer whose offer is accepted
 * right now (checked on every read, so declining or withdrawing takes it away). Everyone else,
 * national organizers and Nextcloud admins included, gets the ZIP and the ZIP area's center for the
 * map.
 */
class RequestPresenter {

	/** @var array<string, array{viewer: Viewer, accepted: array<int, true>}> */
	private array $byViewer = [];

	public function __construct(
		private ChapterDirectory $chapters,
		private ViewerResolver $viewers,
		private VolunteerMapper $volunteers,
	) {
	}

	/**
	 * The state the request belongs to: the override if someone reassigned it, else its ZIP's chapter.
	 */
	public function chapterOf(Request $request): ?string {
		return $request->getChapterOverride() ?? $this->chapters->chapterForZip($request->getZip());
	}

	/**
	 * @return array<string, mixed>
	 */
	public function forUser(Request $request, string $viewerId): array {
		$context = $this->context($viewerId);

		return $this->present($request, $context['viewer'], isset($context['accepted'][$request->getId()]));
	}

	public function maySeeExact(Request $request, Viewer $viewer, bool $acceptedVolunteer): bool {
		if ($viewer->userId === $request->getRequesterId() || $acceptedVolunteer) {
			return true;
		}
		$chapter = $this->chapterOf($request);

		return $chapter !== null && in_array($chapter, $viewer->facilitatorOf, true);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function present(Request $request, Viewer $viewer, bool $acceptedVolunteer): array {
		$exact = $this->maySeeExact($request, $viewer, $acceptedVolunteer);
		$center = ZipCenters::centerOf($request->getZip());

		return $request->jsonSerialize() + [
			'chapter' => $this->chapterOf($request),
			'exactLocation' => $exact,
			'location' => $exact ? $request->getLocation() : null,
			'latitude' => $exact ? $request->getLatitude() : null,
			'longitude' => $exact ? $request->getLongitude() : null,
			'zipCenter' => $center === null ? null : ['latitude' => $center[0], 'longitude' => $center[1]],
		];
	}

	/**
	 * The viewer's roles and the requests where they are an accepted volunteer, read once per
	 * HTTP request.
	 *
	 * @return array{viewer: Viewer, accepted: array<int, true>}
	 */
	private function context(string $viewerId): array {
		if (!isset($this->byViewer[$viewerId])) {
			$accepted = [];
			foreach ($this->volunteers->findByVolunteer($viewerId) as $offer) {
				if ($offer->getStatus() === 'accepted') {
					$accepted[$offer->getRequestId()] = true;
				}
			}
			$this->byViewer[$viewerId] = ['viewer' => $this->viewers->forUser($viewerId), 'accepted' => $accepted];
		}

		return $this->byViewer[$viewerId];
	}
}
