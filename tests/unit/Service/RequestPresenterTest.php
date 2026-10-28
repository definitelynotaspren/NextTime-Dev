<?php

declare(strict_types=1);

namespace OCA\TimeBank\Tests\Unit\Service;

use OCA\TimeBank\Chapter\ChapterDirectory;
use OCA\TimeBank\Db\Request;
use OCA\TimeBank\Db\Volunteer;
use OCA\TimeBank\Db\VolunteerMapper;
use OCA\TimeBank\Schema\Viewer;
use OCA\TimeBank\Service\RequestPresenter;
use OCA\TimeBank\Service\ViewerResolver;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;

/**
 * The Location visibility table of the security plan, row by row. ana posts from Sandy, Oregon
 * (97055) with an exact address and point.
 */
final class RequestPresenterTest extends TestCase {

	/** @var array<string, Viewer> */
	private array $viewers = [];
	/** @var list<Volunteer> */
	private array $offers = [];
	private RequestPresenter $presenter;

	protected function setUp(): void {
		parent::setUp();
		$this->viewers = [
			'ana' => new Viewer('ana', false, false, [], true),
			'otto' => new Viewer('otto', false, false, [], true),
			'nina' => Viewer::member('nina'),
			'admin' => Viewer::member('admin'),
			'fern' => new Viewer('fern', false, true, ['oregon'], true),
			'wes' => new Viewer('wes', false, true, ['washington'], true),
			'nora' => new Viewer('nora', true, false, [], false),
		];

		$directory = $this->createMock(ChapterDirectory::class);
		$directory->method('chapterForZip')->willReturnCallback(fn (?string $zip) => match (substr((string)$zip, 0, 2)) {
			'97' => 'oregon',
			'98' => 'washington',
			default => null,
		});
		$resolver = $this->createMock(ViewerResolver::class);
		$resolver->method('forUser')->willReturnCallback(fn (string $u) => $this->viewers[$u]);
		$volunteers = $this->createMock(VolunteerMapper::class);
		$volunteers->method('findByVolunteer')->willReturnCallback(
			fn (string $u) => array_values(array_filter($this->offers, fn (Volunteer $v) => $v->getVolunteerId() === $u)),
		);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(fn (string $u) => $u === 'admin');

		$this->presenter = new RequestPresenter($directory, $resolver, $volunteers, $groups);
	}

	private function request(): Request {
		$request = new Request();
		$request->setId(1);
		$request->setRequesterId('ana');
		$request->setTitle('Help moving a couch');
		$request->setStatus('open');
		$request->setZip('97055');
		$request->setLocation('123 Main St, Sandy');
		$request->setLatitude(45.3975);
		$request->setLongitude(-122.2612);
		return $request;
	}

	private function offer(string $who, string $status): void {
		$offer = new Volunteer();
		$offer->setRequestId(1);
		$offer->setVolunteerId($who);
		$offer->setStatus($status);
		$this->offers[] = $offer;
	}

	private function assertExact(string $who, string $why = ''): void {
		$data = $this->presenter->forUser($this->request(), $who);
		$this->assertTrue($data['exactLocation'], "$who should see the exact location. $why");
		$this->assertSame('123 Main St, Sandy', $data['location']);
		$this->assertSame(45.3975, $data['latitude']);
		$this->assertSame(-122.2612, $data['longitude']);
	}

	private function assertZipOnly(string $who, string $why = ''): void {
		$data = $this->presenter->forUser($this->request(), $who);
		$this->assertFalse($data['exactLocation'], "$who should see the ZIP only. $why");
		$this->assertSame('97055', $data['zip']);
		$this->assertNull($data['location']);
		$this->assertNull($data['latitude']);
		$this->assertNull($data['longitude']);
		$this->assertStringNotContainsString('Main St', (string)json_encode($data));
		$this->assertStringNotContainsString('45.3975', (string)json_encode($data));
	}

	public function testTheRequesterAlwaysSeesExact(): void {
		$this->assertExact('ana');
	}

	public function testMembersAndChapterMembersSeeZipOnly(): void {
		$this->assertZipOnly('nina');
		$this->assertZipOnly('otto');
	}

	public function testTheFacilitatorOfTheRequestsStateSeesExact(): void {
		$this->assertExact('fern');
	}

	public function testAFacilitatorOfAnotherStateSeesZipOnly(): void {
		$this->assertZipOnly('wes');
	}

	public function testNationalOrganizersAndAdminsSeeZipOnly(): void {
		$this->assertZipOnly('nora');
		$this->assertZipOnly('admin', 'Decision 4: admins see zip only in the app');
	}

	public function testAnAcceptedVolunteerSeesExact(): void {
		foreach (['otto', 'nina', 'wes', 'nora'] as $who) {
			$this->offer($who, 'accepted');
			$this->assertExact($who, 'once accepted');
		}
	}

	public function testAnOfferNotAcceptedGivesZipOnly(): void {
		foreach (['offered', 'declined', 'withdrawn', 'completed'] as $status) {
			$this->offers = [];
			$this->offer('otto', $status);
			$this->assertZipOnly('otto', "offer $status");
		}
	}

	public function testAnAcceptedOfferOnAnotherRequestGivesNothing(): void {
		$this->offer('otto', 'accepted');
		$this->offers[0]->setRequestId(2);
		$this->assertZipOnly('otto');
	}

	public function testAReassignedRequestFollowsTheOverride(): void {
		$request = $this->request();
		$request->setChapterOverride('washington');

		$this->assertTrue($this->presenter->forUser($request, 'wes')['exactLocation']);
		$this->assertFalse($this->presenter->forUser($request, 'fern')['exactLocation']);
		$this->assertSame('washington', $this->presenter->forUser($request, 'nina')['chapter']);
	}

	public function testZipOnlyViewersGetTheZipCenterForTheMap(): void {
		$data = $this->presenter->forUser($this->request(), 'nina');
		$this->assertSame(['latitude' => 45.3611, 'longitude' => -122.1579], $data['zipCenter']);
	}

	public function testTheEntityItselfNoLongerCarriesTheLocation(): void {
		$json = $this->request()->jsonSerialize();
		$this->assertArrayNotHasKey('location', $json);
		$this->assertArrayNotHasKey('latitude', $json);
		$this->assertArrayNotHasKey('longitude', $json);
		$this->assertSame('97055', $json['zip']);
	}

	public function testManyRequestsLookUpTheViewersOffersOnce(): void {
		$volunteers = $this->createMock(VolunteerMapper::class);
		$volunteers->expects($this->once())->method('findByVolunteer')->willReturn([]);
		$directory = $this->createMock(ChapterDirectory::class);
		$resolver = $this->createMock(ViewerResolver::class);
		$resolver->expects($this->once())->method('forUser')->willReturn(Viewer::member('nina'));

		$presenter = new RequestPresenter($directory, $resolver, $volunteers, $this->createMock(IGroupManager::class));
		$presenter->forUser($this->request(), 'nina');
		$presenter->forUser($this->request(), 'nina');
	}

	public function testThePosterIsHiddenFromOrdinaryMembersByDefault(): void {
		foreach (['nina', 'otto', 'wes'] as $who) {
			$data = $this->presenter->forUser($this->request(), $who);
			$this->assertNull($data['requesterId'], "$who must not be told who posted");
			$this->assertFalse($data['isMine']);
			$this->assertStringNotContainsString('ana', (string)json_encode($data));
		}
	}

	public function testThePosterIsShownToThePosterStaffAdminsAndAcceptedVolunteers(): void {
		foreach (['ana', 'fern', 'nora', 'admin'] as $who) {
			$this->assertSame('ana', $this->presenter->forUser($this->request(), $who)['requesterId'], $who);
		}
		$this->assertTrue($this->presenter->forUser($this->request(), 'ana')['isMine']);

		$this->offer('otto', 'accepted');
		$this->assertSame('ana', $this->presenter->forUser($this->request(), 'otto')['requesterId']);
		$this->offers = [];
		$this->offer('nina', 'offered');
		$this->assertNull($this->presenter->forUser($this->request(), 'nina')['requesterId']);
	}

	public function testAPosterWhoOptedInIsShownToEveryone(): void {
		$request = $this->request();
		$request->setShowUsername(true);

		$this->assertSame('ana', $this->presenter->forUser($request, 'nina')['requesterId']);
		$this->assertTrue($this->presenter->forUser($request, 'nina')['showUsername']);
	}

	public function testHiddenPosterNamesThePosterOnlyForThoseWhoMayNotSeeIt(): void {
		$this->assertSame('ana', $this->presenter->hiddenPoster($this->request(), 'nina'));
		$this->assertNull($this->presenter->hiddenPoster($this->request(), 'fern'));
		$this->assertNull($this->presenter->hiddenPoster($this->request(), 'ana'));
	}
}
