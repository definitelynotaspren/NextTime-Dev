<?php

declare(strict_types=1);

namespace OCA\TimeBank\Tests\Unit\Chapter;

use OCA\TimeBank\Chapter\Chapter;
use OCA\TimeBank\Chapter\ChapterDirectory;
use OCA\TimeBank\Chapter\Electorate;
use OCA\TimeBank\Chapter\NationalOrganizers;
use PHPUnit\Framework\TestCase;

/**
 * Who votes about a member: their state's chapter members, else the national organizers.
 */
final class ElectorateTest extends TestCase {

	/** @var array<string, list<string>> group id => users */
	private array $groups = [
		'Oregon' => ['bob', 'ann', 'dan'],
		'Oregon Facilitators' => ['fay'],
		'Idaho' => ['ida', 'ian'],
		'Idaho Facilitators' => [],
	];
	private Electorate $electorate;

	protected function setUp(): void {
		parent::setUp();
		$directory = $this->createMock(ChapterDirectory::class);
		$directory->method('memberChapters')->willReturnCallback(fn (string $u) => match (true) {
			in_array($u, $this->groups['Oregon'], true) => ['oregon'],
			in_array($u, $this->groups['Idaho'], true) => ['idaho'],
			default => [],
		});
		$directory->method('get')->willReturnCallback(fn (string $slug) => new Chapter($slug, ucfirst($slug), ucfirst($slug), ucfirst($slug) . ' Facilitators', null));
		$directory->method('usersOf')->willReturnCallback(fn (string $gid) => $this->groups[$gid] ?? []);
		$nationals = $this->createMock(NationalOrganizers::class);
		$nationals->method('users')->willReturn(['nora', 'nate', 'nell', 'fay']);

		$this->electorate = new Electorate($directory, $nationals);
	}

	public function testTheStatesMembersAndFacilitatorsVoteButNotTheMember(): void {
		$this->assertEqualsCanonicalizing(['ann', 'dan', 'fay'], $this->electorate->forMember('bob'));
	}

	public function testExcludedPeopleDoNotVote(): void {
		// Without fay, Oregon has only ann and dan: too few, so the national organizers vote.
		$this->assertEqualsCanonicalizing(['nora', 'nate', 'nell'], $this->electorate->forMember('bob', ['fay']));
	}

	public function testAStateWithFewerThanThreeVotersGoesToNationalOrganizers(): void {
		$this->assertEqualsCanonicalizing(['nora', 'nate', 'nell', 'fay'], $this->electorate->forMember('ida'));
	}

	public function testSomeoneWithNoStateIsVotedOnByNationalOrganizers(): void {
		$this->assertEqualsCanonicalizing(['nora', 'nate', 'nell', 'fay'], $this->electorate->forMember('stranger'));
		$this->assertNotContains('nora', $this->electorate->forMember('nora'), 'never the member');
	}
}
