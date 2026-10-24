<?php

declare(strict_types=1);

namespace OCA\TimeBank\Tests\Unit\Chapter;

use OCA\TimeBank\Chapter\ChapterDirectory;
use OCP\IConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\TestCase;

/**
 * Chapters are found from group names alone. The group list below follows what the
 * organization's Nextcloud really holds: states, "<State> Facilitators", committees,
 * and one cross-chapter "Chapter Facilitators" group.
 */
final class ChapterDirectoryTest extends TestCase {

	/** @var array<string, array{name: string, users: string[]}> gid => group */
	private array $groups = [];
	private string $zipSetting = '';

	protected function setUp(): void {
		parent::setUp();
		$this->groups = [];
		foreach ([
			'New York City Facilitator' => ['nina'],
			'New York City' => ['nora'],
			'Accessibility' => ['abe'],
			'Arizona' => ['ari'],
			'Arkansas' => ['arlo'],
			'Arkansas Facilitators' => ['ann'],
			'Chapter Facilitators' => ['ann', 'cal'],
			'Colorado' => ['cody', 'cal'],
			'Colorado Facilitators' => ['cal', 'cass'],
			'New York' => ['nate'],
			'Education' => ['ed'],
		] as $name => $users) {
			$this->groups[$name] = ['name' => $name, 'users' => $users];
		}
		$this->zipSetting = '';
	}

	private function directory(): ChapterDirectory {
		$manager = $this->createMock(IGroupManager::class);
		$make = function (string $gid) {
			$group = $this->createMock(IGroup::class);
			$group->method('getGID')->willReturn($gid);
			$group->method('getDisplayName')->willReturn($this->groups[$gid]['name']);
			$group->method('getUsers')->willReturn(array_map(function (string $id) {
				$user = $this->createMock(IUser::class);
				$user->method('getUID')->willReturn($id);
				return $user;
			}, $this->groups[$gid]['users']));
			return $group;
		};
		$manager->method('search')->willReturnCallback(fn () => array_map($make, array_keys($this->groups)));
		$manager->method('get')->willReturnCallback(fn (string $gid) => isset($this->groups[$gid]) ? $make($gid) : null);
		$manager->method('isInGroup')->willReturnCallback(fn (string $u, string $g) => in_array($u, $this->groups[$g]['users'] ?? [], true));
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(fn ($app, $key, $default) => $key === 'chapter_zip_prefixes' ? $this->zipSetting : $default);

		return new ChapterDirectory($manager, $config);
	}

	public function testStatesWithAFacilitatorGroupBecomeChapters(): void {
		$chapters = $this->directory()->all();

		$this->assertSame(['new-york-city', 'arkansas', 'colorado'], array_keys($chapters));
		$this->assertSame('AR', $chapters['arkansas']->state);
		$this->assertSame('Arkansas', $chapters['arkansas']->memberGroup);
		$this->assertSame('Arkansas Facilitators', $chapters['arkansas']->facilitatorGroup);
		$this->assertSame('New York City Facilitator', $chapters['new-york-city']->facilitatorGroup, 'singular works too');
	}

	public function testCommitteesAndStatesWithoutFacilitatorsAreNotChaptersAndAreReported(): void {
		$report = $this->directory()->report();

		$this->assertNotContains('arizona', array_column($report['chapters'], 'slug'));
		$this->assertNotContains('education', array_column($report['chapters'], 'slug'));
		$this->assertEqualsCanonicalizing(['Arizona', 'New York'], array_column($report['stateGroupsWithoutFacilitators'], 'name'), 'a state with no facilitator group is surfaced, committees are not');
		$this->assertSame(['Chapter Facilitators'], array_column($report['unpairedFacilitatorGroups'], 'name'));
	}

	public function testTheFacilitatorGroupAloneMakesAFacilitator(): void {
		$directory = $this->directory();

		$this->assertSame(['colorado'], $directory->facilitatedChapters('cass'), 'cass is in Colorado Facilitators only');
		$this->assertSame([], $directory->memberChapters('cass'));
		$this->assertSame(['colorado'], $directory->memberChapters('cody'));
		$this->assertSame([], $directory->facilitatedChapters('cody'), 'belonging gives no powers');
		$this->assertSame(['arkansas'], $directory->facilitatedChapters('ann'));
	}

	public function testLosingTheFacilitatorGroupTakesTheRoleAwayAtOnce(): void {
		$this->assertSame(['colorado'], $this->directory()->facilitatedChapters('cass'));

		$this->groups['Colorado Facilitators']['users'] = ['cal'];

		$this->assertSame([], $this->directory()->facilitatedChapters('cass'));
	}

	public function testChapterForZipUsesTheStateThenCityPrefixes(): void {
		$directory = $this->directory();

		$this->assertSame('colorado', $directory->chapterForZip('80202'));
		$this->assertSame('arkansas', $directory->chapterForZip('72201'));
		$this->assertSame('new-york-city', $directory->chapterForZip('10001'), 'Manhattan goes to the city chapter');
		$this->assertNull($directory->chapterForZip('12207'), 'Albany is New York state, which has no facilitator group here');
		$this->assertNull($directory->chapterForZip('97055'), 'Oregon has no chapter');
		$this->assertNull($directory->chapterForZip('9705'));
		$this->assertNull($directory->chapterForZip(null));
	}

	public function testAStateChapterTakesItsZipsWhenThereIsNoCityOverride(): void {
		$this->groups['New York Facilitators'] = ['name' => 'New York Facilitators', 'users' => ['nell']];

		$directory = $this->directory();

		$this->assertSame('new-york', $directory->chapterForZip('12207'));
		$this->assertSame('new-york-city', $directory->chapterForZip('10001'), 'the city chapter still wins inside the city');
	}

	public function testPairingIgnoresCaseSpacingAndHyphens(): void {
		$this->groups = [
			'colorado' => ['name' => 'colorado', 'users' => []],
			'colorado-facilitator' => ['name' => 'colorado-facilitator', 'users' => ['cal']],
		];

		$this->assertSame(['colorado'], array_keys($this->directory()->all()));
	}

	public function testNamesComeFromTheDisplayNameWhileRoleChecksUseTheGroupId(): void {
		$this->groups = [
			'a1b2c3' => ['name' => 'Texas', 'users' => ['tess']],
			'd4e5f6' => ['name' => 'Texas Facilitators', 'users' => ['tom']],
		];
		$directory = $this->directory();

		$this->assertSame('a1b2c3', $directory->get('texas')->memberGroup);
		$this->assertSame(['texas'], $directory->facilitatedChapters('tom'));
		$this->assertSame(['texas'], $directory->memberChapters('tess'));
	}

	public function testTheZipPrefixSettingOverridesTheDefaults(): void {
		$this->zipSetting = '{"New York City":["100"]}';

		$directory = $this->directory();

		$this->assertSame('new-york-city', $directory->chapterForZip('10001'));
		$this->assertNull($directory->chapterForZip('11201'), 'Brooklyn is no longer listed');
	}
}
