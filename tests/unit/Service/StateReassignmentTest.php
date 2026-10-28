<?php

declare(strict_types=1);

namespace OCA\TimeBank\Tests\Unit\Service;

use OCA\TimeBank\Chapter\Chapter;
use OCA\TimeBank\Chapter\ChapterDirectory;
use OCA\TimeBank\Db\LayerRow;
use OCA\TimeBank\Db\LayerRowMapper;
use OCA\TimeBank\Db\Request;
use OCA\TimeBank\Db\RequestMapper;
use OCA\TimeBank\Schema\Viewer;
use OCA\TimeBank\Service\GovernanceLog;
use OCA\TimeBank\Service\InvalidStateException;
use OCA\TimeBank\Service\NotAuthorizedException;
use OCA\TimeBank\Service\RequestPresenter;
use OCA\TimeBank\Service\StateReassignment;
use OCA\TimeBank\Service\ViewerResolver;
use OCP\IGroupManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Moving a request or desk row to another state (security plan, Phase 2 and Decision 5): a
 * facilitator of the old or new state, a national organizer or an admin may; every move is logged.
 */
final class StateReassignmentTest extends TestCase {

	private Request $request;
	private LayerRow $row;
	private GovernanceLog&MockObject $log;
	/** @var list<array{0: string, 1: string, 2: ?string, 3: array<string, mixed>}> */
	private array $logged = [];
	private StateReassignment $service;

	protected function setUp(): void {
		parent::setUp();
		$viewers = [
			'ana' => new Viewer('ana', false, false, [], true),
			'fern' => new Viewer('fern', false, true, ['oregon'], true),
			'wes' => new Viewer('wes', false, true, ['washington'], true),
			'ida' => new Viewer('ida', false, true, ['idaho'], true),
			'nora' => new Viewer('nora', true, false, [], false),
			'root' => Viewer::member('root'),
		];

		$this->request = new Request();
		$this->request->setId(1);
		$this->request->setRequesterId('ana');
		$this->request->setTitle('Help moving a couch');
		$this->request->setZip('97055');
		$this->request->setChapter('oregon');

		$this->row = new LayerRow();
		$this->row->setId(5);
		$this->row->setLayer('needs_offers');
		$this->row->setUid('NO-M5');
		$this->row->setData('{"zip":"97055"}');
		$this->row->setChapter('oregon');

		$directory = $this->createMock(ChapterDirectory::class);
		$directory->method('get')->willReturnCallback(fn (string $slug) => in_array($slug, ['oregon', 'washington', 'idaho'], true)
			? new Chapter($slug, ucfirst($slug), ucfirst($slug), ucfirst($slug) . ' Facilitators', null)
			: null);
		$directory->method('all')->willReturnCallback(fn () => [
			'idaho' => new Chapter('idaho', 'Idaho', 'Idaho', 'Idaho Facilitators', 'ID'),
			'oregon' => new Chapter('oregon', 'Oregon', 'Oregon', 'Oregon Facilitators', 'OR'),
			'washington' => new Chapter('washington', 'Washington', 'Washington', 'Washington Facilitators', 'WA'),
		]);
		$directory->method('chapterForZip')->willReturnCallback(fn (?string $zip) => $zip === null ? null : 'oregon');

		$resolver = $this->createMock(ViewerResolver::class);
		$resolver->method('forUser')->willReturnCallback(fn (string $u) => $viewers[$u]);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(fn (string $u) => $u === 'root');

		$requests = $this->createMock(RequestMapper::class);
		$requests->method('find')->willReturn($this->request);
		$requests->method('update')->willReturnArgument(0);
		$rows = $this->createMock(LayerRowMapper::class);
		$rows->method('find')->willReturn($this->row);
		$rows->method('update')->willReturnArgument(0);

		$this->log = $this->createMock(GovernanceLog::class);
		$this->log->method('record')->willReturnCallback(function (string $actor, string $action, ?string $subject = null, array $details = []) {
			$this->logged[] = [$actor, $action, $subject, $details];
		});

		$presenter = new RequestPresenter($directory, $resolver, $this->createMock(\OCA\TimeBank\Db\VolunteerMapper::class), $this->createMock(IGroupManager::class));
		$this->service = new StateReassignment($requests, $rows, $presenter, $directory, $resolver, $groups, $this->log);
	}

	public function testAMemberCannotMoveARequest(): void {
		$this->expectException(NotAuthorizedException::class);
		$this->service->moveRequest(1, 'ana', 'washington');
	}

	public function testAFacilitatorOfTheOldStateMovesItAndItIsLogged(): void {
		$moved = $this->service->moveRequest(1, 'fern', 'washington');

		$this->assertSame('washington', $moved->getChapterOverride());
		$this->assertSame('washington', $moved->getChapter());
		$this->assertSame([['fern', 'request.state_changed', null, [
			'request' => 1,
			'title' => 'Help moving a couch',
			'from' => 'oregon',
			'to' => 'washington',
		]]], $this->logged);
	}

	public function testAFacilitatorOfTheNewStateMayPullItIn(): void {
		$this->assertSame('washington', $this->service->moveRequest(1, 'wes', 'washington')->getChapter());
	}

	public function testAFacilitatorOfNeitherStateMayNot(): void {
		try {
			$this->service->moveRequest(1, 'ida', 'washington');
			$this->fail('an Idaho facilitator cannot move an Oregon request to Washington');
		} catch (NotAuthorizedException) {
			$this->assertNull($this->request->getChapterOverride(), 'a refused move changes nothing');
		}
	}

	public function testNationalOrganizersAndAdminsMayMoveAnything(): void {
		$this->assertSame('idaho', $this->service->moveRequest(1, 'nora', 'idaho')->getChapter());
		$this->assertSame('washington', $this->service->moveRequest(1, 'root', 'washington')->getChapter());
	}

	public function testAnUnknownStateOrNoChangeIsRefused(): void {
		foreach (['atlantis', 'oregon'] as $to) {
			try {
				$this->service->moveRequest(1, 'nora', $to);
				$this->fail("moving to $to should be refused");
			} catch (InvalidStateException) {
				$this->addToAssertionCount(1);
			}
		}
		$this->assertSame([], $this->logged, 'a refused move is not logged');
	}

	public function testClearingTheOverrideGoesBackToTheZipsState(): void {
		$this->service->moveRequest(1, 'fern', 'washington');
		$back = $this->service->moveRequest(1, 'wes', null);

		$this->assertNull($back->getChapterOverride());
		$this->assertSame('oregon', $back->getChapter());
		$this->assertSame('oregon', $this->logged[1][3]['to']);
	}

	public function testDeskRowsFollowTheSameRules(): void {
		try {
			$this->service->moveDeskRow('needs_offers', 5, 'wes', 'idaho');
			$this->fail('a Washington facilitator cannot move an Oregon row to Idaho');
		} catch (NotAuthorizedException) {
			$this->addToAssertionCount(1);
		}

		$result = $this->service->moveDeskRow('needs_offers', 5, 'fern', 'washington');
		$this->assertSame(['uid' => 'NO-M5', 'chapter' => 'washington', 'chapterOverride' => 'washington'], $result);
		$this->assertSame('desk_row.state_changed', $this->logged[0][1]);
		$this->assertSame(['layer' => 'needs_offers', 'uid' => 'NO-M5', 'from' => 'oregon', 'to' => 'washington'], $this->logged[0][3]);
	}

	public function testAggregateOnlyLayersAreNotMoved(): void {
		$this->expectException(InvalidStateException::class);
		$this->service->moveDeskRow('survey', 5, 'nora', 'washington');
	}

	public function testChoicesAreEveryStateForThoseWhoMayMoveAndNoneForMembers(): void {
		$this->assertSame([], $this->service->choicesFor('ana'));
		$this->assertSame(['idaho', 'oregon', 'washington'], array_column($this->service->choicesFor('fern'), 'slug'));
	}
}
