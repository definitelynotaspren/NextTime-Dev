<?php

declare(strict_types=1);

namespace OCA\TimeBank\Tests\Unit\Service;

use OCA\TimeBank\Chapter\NationalOrganizers;
use OCA\TimeBank\Db\Comment;
use OCA\TimeBank\Db\CommentFlag;
use OCA\TimeBank\Db\CommentFlagMapper;
use OCA\TimeBank\Db\CommentMapper;
use OCA\TimeBank\Service\CommentService;
use OCA\TimeBank\Service\InvalidStateException;
use OCA\TimeBank\Service\NotAuthorizedException;
use OCP\IAppConfig;
use OCP\IGroupManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Community flagging and moderator removal. Mappers are mocked; the flag store is a plain array.
 */
final class CommentServiceTest extends TestCase {

	private CommentMapper&MockObject $comments;
	private CommentFlagMapper&MockObject $flagMapper;
	private IGroupManager&MockObject $groups;
	private CommentService $service;
	/** @var CommentFlag[] */
	private array $flags = [];

	protected function setUp(): void {
		parent::setUp();
		$this->comments = $this->createMock(CommentMapper::class);
		$this->flagMapper = $this->createMock(CommentFlagMapper::class);
		$this->groups = $this->createMock(IGroupManager::class);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnArgument(2);
		$this->service = new CommentService($this->comments, $this->flagMapper, $this->groups, new NationalOrganizers($this->groups, $config));

		$this->groups->method('isAdmin')->willReturnCallback(fn (string $u) => $u === 'admin');
		$this->groups->method('search')->willReturn([]);
		$this->groups->method('isInGroup')->willReturnCallback(fn (string $u, string $g) => $u === 'lead' && $g === 'timebank-organizer');

		$this->comments->method('update')->willReturnArgument(0);
		$this->flagMapper->method('update')->willReturnArgument(0);
		$this->flagMapper->method('insert')->willReturnCallback(function (CommentFlag $f) {
			$f->setId(count($this->flags) + 1);
			$this->flags[] = $f;
			return $f;
		});
		$this->flagMapper->method('findByCommentAndUser')->willReturnCallback(function (int $c, string $u) {
			foreach ($this->flags as $f) {
				if ($f->getCommentId() === $c && $f->getUserId() === $u) {
					return $f;
				}
			}
			return null;
		});
		$this->flagMapper->method('findActiveByComments')->willReturnCallback(
			fn (array $ids) => array_values(array_filter($this->flags, fn (CommentFlag $f) => in_array($f->getCommentId(), $ids, true) && $f->getDismissedAt() === null)),
		);
		$this->flagMapper->method('findActiveByComment')->willReturnCallback(
			fn (int $id) => array_values(array_filter($this->flags, fn (CommentFlag $f) => $f->getCommentId() === $id && $f->getDismissedAt() === null)),
		);
	}

	private function comment(int $id = 5, string $author = 'alice'): Comment {
		$comment = new Comment();
		$comment->setId($id);
		$comment->setRequestId(1);
		$comment->setUserId($author);
		$comment->setComment('Some words');
		$comment->setCreatedAt(new \DateTime());
		$this->comments->method('find')->willReturn($comment);
		$this->comments->method('findByRequest')->willReturn([$comment]);
		return $comment;
	}

	private function flagBy(string ...$users): void {
		foreach ($users as $user) {
			$this->service->flag(5, $user, 'rude');
		}
	}

	public function testThreeDistinctFlagsHideTheTextFromMembersButNotModerators(): void {
		$this->comment();
		$this->flagBy('bob', 'carol');
		$this->assertSame('visible', $this->service->getForViewer(1, 'dave')[0]['state']);

		$this->flagBy('dave');
		$member = $this->service->getForViewer(1, 'erin')[0];
		$this->assertSame('hidden', $member['state']);
		$this->assertNull($member['comment']);
		$this->assertSame(3, $member['flagCount']);
		$this->assertArrayNotHasKey('flags', $member, 'reasons are for moderators');

		$moderator = $this->service->getForViewer(1, 'lead')[0];
		$this->assertSame('Some words', $moderator['comment']);
		$this->assertCount(3, $moderator['flags']);
	}

	public function testAMemberCanFlagOnlyOnceAndNotTheirOwnComment(): void {
		$this->comment(5, 'alice');
		$this->service->flag(5, 'bob', null);

		try {
			$this->service->flag(5, 'bob', null);
			$this->fail('A second flag from the same member must be refused');
		} catch (InvalidStateException) {
			$this->addToAssertionCount(1);
		}

		$this->expectException(InvalidStateException::class);
		$this->service->flag(5, 'alice', null);
	}

	public function testOnlyModeratorsCanRemoveOrDismiss(): void {
		$this->comment();

		foreach (['bob', 'alice'] as $user) {
			try {
				$this->service->remove(5, $user);
				$this->fail("$user must not remove a comment, not even their own");
			} catch (NotAuthorizedException) {
				$this->addToAssertionCount(1);
			}
		}
		$this->expectException(NotAuthorizedException::class);
		$this->service->dismissFlags(5, 'bob');
	}

	public function testRemovalIsSoftAndRecordsWho(): void {
		$comment = $this->comment();
		$this->service->remove(5, 'admin');

		$this->assertSame('admin', $comment->getRemovedBy());
		$member = $this->service->getForViewer(1, 'bob')[0];
		$this->assertSame('removed', $member['state']);
		$this->assertNull($member['comment']);
		$this->assertSame('admin', $member['removedBy']);
		$this->assertSame('Some words', $this->service->getForViewer(1, 'lead')[0]['comment'], 'the audit trail keeps the text');
	}

	public function testDismissingFlagsRestoresAHiddenComment(): void {
		$this->comment();
		$this->flagBy('bob', 'carol', 'dave');
		$this->assertSame('hidden', $this->service->getForViewer(1, 'erin')[0]['state']);

		$this->service->dismissFlags(5, 'lead');
		$restored = $this->service->getForViewer(1, 'erin')[0];
		$this->assertSame('visible', $restored['state']);
		$this->assertSame('Some words', $restored['comment']);
		$this->assertSame(0, $restored['flagCount']);
	}

	public function testRemovedCommentsCannotBeFlaggedAndEmptyOnesCannotBePosted(): void {
		$this->comment();
		$this->service->remove(5, 'admin');
		try {
			$this->service->flag(5, 'bob', null);
			$this->fail('Flagging a removed comment must be refused');
		} catch (InvalidStateException) {
			$this->addToAssertionCount(1);
		}

		$this->expectException(InvalidStateException::class);
		$this->service->create(1, 'bob', ['comment' => '   ']);
	}
}
