<?php

declare(strict_types=1);

namespace OCA\TimeBank\Tests\Unit\Service;

use OCA\TimeBank\Service\ApproverPolicy;
use OCA\TimeBank\Service\ViewerResolver;
use OCP\IConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\TestCase;

/**
 * Who may decide about a member. Chapter powers need both the chapter group and its
 * facilitator group; the fallback group only covers members without a facilitator.
 */
final class ApproverPolicyTest extends TestCase {

	/** @var array<string, string[]> group id => user ids */
	private array $groups = [];
	private ApproverPolicy $policy;

	protected function setUp(): void {
		parent::setUp();
		$this->groups = [
			'admin' => ['root'],
			'chapter-mt-hood' => ['bob', 'fay', 'onlyfac2'],
			'chapter-mt-hood-facilitator' => ['fay', 'onlyfac', 'onlyfac2x'],
			'chapter-gresham' => ['carol', 'gus'],
			'chapter-gresham-facilitator' => ['gus'],
			'GSUS' => ['gina', 'fay'],
		];
		$this->policy = $this->build();
	}

	private function build(): ApproverPolicy {
		$manager = $this->createMock(IGroupManager::class);
		$manager->method('isAdmin')->willReturnCallback(fn (string $u) => in_array($u, $this->groups['admin'] ?? [], true));
		$manager->method('isInGroup')->willReturnCallback(fn (string $u, string $g) => in_array($u, $this->groups[$g] ?? [], true));
		$manager->method('get')->willReturnCallback(function (string $g) {
			if (!isset($this->groups[$g])) {
				return null;
			}
			$group = $this->createMock(IGroup::class);
			$group->method('getUsers')->willReturn(array_map(function (string $id) {
				$user = $this->createMock(IUser::class);
				$user->method('getUID')->willReturn($id);
				return $user;
			}, $this->groups[$g]));
			return $group;
		});

		$viewers = new ViewerResolver($manager);
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnArgument(2);

		return new ApproverPolicy($viewers, $manager, $config);
	}

	public function testAdminsMayDecideExceptOnThemselves(): void {
		$this->assertSame('admin', $this->policy->capacity('root', 'bob')['as']);
		$this->assertNull($this->policy->capacity('root', 'root'));
	}

	public function testAFacilitatorNeedsBothGroupsForTheMembersChapter(): void {
		$this->assertSame(['as' => 'facilitator', 'chapter' => 'mt-hood'], $this->policy->capacity('fay', 'bob'));

		$this->assertNull($this->policy->capacity('onlyfac', 'bob'), 'the facilitator group alone does nothing');
		$this->assertNull($this->policy->capacity('onlyfac2', 'bob'), 'the chapter group alone does nothing');
		$this->assertNull($this->policy->capacity('gus', 'bob'), 'a facilitator of another chapter cannot decide');
	}

	public function testNobodyDecidesAboutThemselvesNotEvenAFacilitator(): void {
		$this->assertNull($this->policy->capacity('fay', 'fay'));
	}

	public function testTheFallbackGroupCoversMembersWithoutAFacilitator(): void {
		$this->groups['chapter-gresham-facilitator'] = [];
		$policy = $this->build();

		$this->assertSame('fallback', $policy->capacity('gina', 'carol')['as']);
		$this->assertSame('fallback', $policy->capacity('gina', 'stranger')['as'], 'someone with no chapter at all');
	}

	public function testTheFallbackNeverOverridesAChapterThatHasFacilitators(): void {
		$this->assertNull($this->policy->capacity('gina', 'bob'));
		$this->assertNull($this->policy->capacity('gina', 'carol'), 'gresham has gus');
	}

	public function testAFacilitatorWhoIsTheOnlyOneDoesNotCountForThemselves(): void {
		$this->groups['chapter-gresham'] = ['gus'];
		$policy = $this->build();

		$this->assertSame('fallback', $policy->capacity('gina', 'gus')['as']);
		$this->assertNull($policy->capacity('gus', 'gus'));
	}

	public function testNotificationsGoToFacilitatorsThenFallbackThenAdmins(): void {
		$this->assertSame(['fay'], $this->policy->recipients('bob'), 'facilitators with both groups only');
		$this->assertSame(['gus'], $this->policy->recipients('carol'));
		$this->assertSame(['gina', 'fay'], $this->policy->recipients('stranger'), 'no chapter: the fallback group');

		$this->groups['GSUS'] = [];
		$this->assertSame(['root'], $this->build()->recipients('stranger'), 'no fallback either: admins');
	}

	public function testTheClaimantIsNeverTold(): void {
		$this->assertNotContains('fay', $this->policy->recipients('fay'));
	}
}
