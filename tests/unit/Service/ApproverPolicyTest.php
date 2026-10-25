<?php

declare(strict_types=1);

namespace OCA\TimeBank\Tests\Unit\Service;

use OCA\TimeBank\Chapter\ChapterDirectory;
use OCA\TimeBank\Chapter\NationalOrganizers;
use OCA\TimeBank\Service\ApproverPolicy;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\TestCase;

/**
 * Who may decide about a member. A chapter's facilitator group alone gives the power; national
 * organizers (and the legacy GSUS group, until its members move) decide for everyone.
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
			'National Organizers' => ['nora'],
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
		$manager->method('groupExists')->willReturnCallback(fn (string $g) => isset($this->groups[$g]));
		$manager->method('search')->willReturnCallback(fn () => array_map($make, array_keys($this->groups)));
		$manager->method('get')->willReturnCallback(fn (string $g) => isset($this->groups[$g]) ? $make($g) : null);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnArgument(2);

		return new ApproverPolicy(new ChapterDirectory($manager, $config), $manager, new NationalOrganizers($manager, $config));
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

	public function testNationalOrganizersDecideForEveryoneButThemselves(): void {
		$this->assertSame(['as' => 'national', 'chapter' => null], $this->policy->capacity('nora', 'bob'));
		$this->assertSame('national', $this->policy->capacity('nora', 'carol')['as'], 'any state');
		$this->assertSame('national', $this->policy->capacity('nora', 'stranger')['as'], 'someone with no chapter at all');
		$this->assertNull($this->policy->capacity('nora', 'nora'));
	}

	public function testTheLegacyFallbackGroupCountsAsNationalUntilMoved(): void {
		$this->assertSame('national', $this->policy->capacity('gina', 'bob')['as']);
		$this->assertSame('facilitator', $this->policy->capacity('fay', 'bob')['as'], 'the more specific reason is named first');
	}

	public function testLosingTheNationalGroupRemovesTheAbilityAtOnce(): void {
		$this->groups['National Organizers'] = [];

		$this->assertFalse($this->build()->mayDecide('nora', 'bob'));
	}

	public function testNotificationsGoToFacilitatorsThenNationalOrganizersThenAdmins(): void {
		$this->assertEqualsCanonicalizing(['fay', 'onlyfac'], $this->policy->recipients('bob'));
		$this->assertSame(['gus'], $this->policy->recipients('carol'));
		$this->assertEqualsCanonicalizing(['nora', 'gina', 'fay'], $this->policy->recipients('stranger'), 'no chapter: national organizers');

		$this->groups['GSUS'] = [];
		$this->groups['National Organizers'] = [];
		$this->assertSame(['root'], $this->build()->recipients('stranger'), 'no national organizers either: admins');
	}

	public function testTheClaimantIsNeverTold(): void {
		$this->assertNotContains('fay', $this->policy->recipients('fay'));
	}
}
