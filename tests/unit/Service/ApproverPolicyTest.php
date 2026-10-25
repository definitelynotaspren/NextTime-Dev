<?php

declare(strict_types=1);

namespace OCA\TimeBank\Tests\Unit\Service;

use OCA\TimeBank\Chapter\ChapterDirectory;
use OCA\TimeBank\Service\ApproverPolicy;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\TestCase;

/**
 * Who may decide about a member. A chapter's facilitator group alone gives the power; the
 * fallback group only covers members without a facilitator.
 */
final class ApproverPolicyTest extends TestCase {

	/** @var array<string, string[]> group id => user ids */
	private array $groups = [];
	private ApproverPolicy $policy;

	protected function setUp(): void {
		parent::setUp();
		$this->groups = [
			'admin' => ['root'],
			'Oregon' => ['bob', 'fay'],
			'Oregon Facilitators' => ['fay', 'onlyfac'],
			'Washington' => ['carol'],
			'Washington Facilitators' => ['gus'],
			'GSUS' => ['gina', 'fay'],
		];
		$this->policy = $this->build();
	}

	private function build(): ApproverPolicy {
		$manager = $this->createMock(IGroupManager::class);
		$make = function (string $gid) {
			$group = $this->createMock(IGroup::class);
			$group->method('getGID')->willReturn($gid);
			$group->method('getDisplayName')->willReturn($gid);
			$group->method('getUsers')->willReturn(array_map(function (string $id) {
				$user = $this->createMock(IUser::class);
				$user->method('getUID')->willReturn($id);
				return $user;
			}, $this->groups[$gid]));
			return $group;
		};
		$manager->method('isAdmin')->willReturnCallback(fn (string $u) => in_array($u, $this->groups['admin'] ?? [], true));
		$manager->method('isInGroup')->willReturnCallback(fn (string $u, string $g) => in_array($u, $this->groups[$g] ?? [], true));
		$manager->method('search')->willReturnCallback(fn () => array_map($make, array_keys($this->groups)));
		$manager->method('get')->willReturnCallback(fn (string $g) => isset($this->groups[$g]) ? $make($g) : null);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnArgument(2);

		return new ApproverPolicy(new ChapterDirectory($manager, $config), $manager, $config);
	}

	public function testAdminsMayDecideExceptOnThemselves(): void {
		$this->assertSame('admin', $this->policy->capacity('root', 'bob')['as']);
		$this->assertNull($this->policy->capacity('root', 'root'));
	}

	public function testTheFacilitatorGroupAloneIsEnough(): void {
		$this->assertSame(['as' => 'facilitator', 'chapter' => 'oregon'], $this->policy->capacity('fay', 'bob'));
		$this->assertSame('facilitator', $this->policy->capacity('onlyfac', 'bob')['as'], 'not in the Oregon group, still a facilitator');
	}

	public function testBelongingToAChapterGivesNoPowers(): void {
		$this->assertNull($this->policy->capacity('bob', 'fay'));
		$this->assertNull($this->policy->capacity('gus', 'bob'), 'a facilitator of another chapter cannot decide');
	}

	public function testNobodyDecidesAboutThemselvesNotEvenAFacilitator(): void {
		$this->assertNull($this->policy->capacity('fay', 'fay'));
	}

	public function testLosingTheFacilitatorGroupRemovesTheAbilityAtOnce(): void {
		$this->assertTrue($this->policy->mayDecide('onlyfac', 'bob'));

		$this->groups['Oregon Facilitators'] = ['fay'];

		$this->assertFalse($this->build()->mayDecide('onlyfac', 'bob'));
	}

	public function testTheFallbackGroupCoversMembersWithoutAFacilitator(): void {
		$this->groups['Washington Facilitators'] = [];
		$policy = $this->build();

		$this->assertSame('fallback', $policy->capacity('gina', 'carol')['as']);
		$this->assertSame('fallback', $policy->capacity('gina', 'stranger')['as'], 'someone with no chapter at all');
	}

	public function testTheFallbackNeverOverridesAChapterThatHasFacilitators(): void {
		$this->assertNull($this->policy->capacity('gina', 'bob'));
		$this->assertNull($this->policy->capacity('gina', 'carol'), 'Washington has gus');
	}

	public function testNotificationsGoToFacilitatorsThenFallbackThenAdmins(): void {
		$this->assertEqualsCanonicalizing(['fay', 'onlyfac'], $this->policy->recipients('bob'));
		$this->assertSame(['gus'], $this->policy->recipients('carol'));
		$this->assertSame(['gina', 'fay'], $this->policy->recipients('stranger'), 'no chapter: the fallback group');

		$this->groups['GSUS'] = [];
		$this->assertSame(['root'], $this->build()->recipients('stranger'), 'no fallback either: admins');
	}

	public function testTheClaimantIsNeverTold(): void {
		$this->assertNotContains('fay', $this->policy->recipients('fay'));
	}
}
