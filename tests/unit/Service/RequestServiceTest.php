<?php

declare(strict_types=1);

namespace OCA\TimeBank\Tests\Unit\Service;

use OCA\TimeBank\Db\CommentMapper;
use OCA\TimeBank\Db\Request;
use OCA\TimeBank\Db\RequestMapper;
use OCA\TimeBank\Db\UserStatsMapper;
use OCA\TimeBank\Db\Volunteer;
use OCA\TimeBank\Db\VolunteerMapper;
use OCA\TimeBank\Db\Withdrawal;
use OCA\TimeBank\Db\WithdrawalMapper;
use OCA\TimeBank\Service\InvalidStateException;
use OCA\TimeBank\Service\NotAuthorizedException;
use OCA\TimeBank\Service\RequestService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Lifecycle rules for completing, cancelling and editing a request. The mappers are
 * mocked, so no database is needed. Three of these tests reproduce bugs that were
 * confirmed on a running server before the rules were tightened.
 */
final class RequestServiceTest extends TestCase {

	private RequestMapper&MockObject $requests;
	private VolunteerMapper&MockObject $volunteers;
	private UserStatsMapper&MockObject $stats;
	private WithdrawalMapper&MockObject $withdrawals;
	private RequestService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->requests = $this->createMock(RequestMapper::class);
		$this->volunteers = $this->createMock(VolunteerMapper::class);
		$this->stats = $this->createMock(UserStatsMapper::class);
		$this->withdrawals = $this->createMock(WithdrawalMapper::class);
		$this->service = new RequestService(
			$this->requests,
			$this->volunteers,
			$this->createMock(CommentMapper::class),
			$this->stats,
			$this->withdrawals,
		);

		$this->requests->method('update')->willReturnArgument(0);
		$this->volunteers->method('update')->willReturnArgument(0);
	}

	private function request(string $status, string $owner = 'alice', int $id = 1): Request {
		$request = new Request();
		$request->setId($id);
		$request->setRequesterId($owner);
		$request->setStatus($status);
		$request->setCategoryId(7);
		return $request;
	}

	private function offer(int $id, string $status, string $who, string $hours, int $requestId = 1): Volunteer {
		$offer = new Volunteer();
		$offer->setId($id);
		$offer->setRequestId($requestId);
		$offer->setVolunteerId($who);
		$offer->setProposedHours($hours);
		$offer->setStatus($status);
		return $offer;
	}

	// ---- completing ----------------------------------------------------------

	public function testCompletingCreditsEveryAcceptedVolunteerHourForHour(): void {
		$bob = $this->offer(5, 'accepted', 'bob', '2');
		$carol = $this->offer(6, 'accepted', 'carol', '3.5');
		$dave = $this->offer(7, 'offered', 'dave', '4');
		$declined = $this->offer(8, 'declined', 'erin', '9');
		$request = $this->request('in_progress');
		$this->requests->method('find')->willReturn($request);
		$this->volunteers->method('findByRequest')->willReturn([$bob, $carol, $dave, $declined]);

		$credited = [];
		$this->stats->method('incrementStats')->willReturnCallback(function (string $user, int $category, float $hours) use (&$credited) {
			$credited[$user] = [$category, $hours];
		});

		$done = $this->service->completeRequest(1, 'alice');

		$this->assertSame('completed', $done->getStatus());
		$this->assertSame(['bob' => [7, 2.0], 'carol' => [7, 3.5]], $credited, 'each accepted volunteer gets their own hours, not a share');
		$this->assertSame('completed', $bob->getStatus());
		$this->assertSame('completed', $carol->getStatus());
		$this->assertSame('offered', $dave->getStatus(), 'pending offers are left alone');
		$this->assertSame('declined', $declined->getStatus());
	}

	public function testCompletingWithAVolunteerFromAnotherRequestIsRefused(): void {
		// Reproduces the live bug: alice completed her request using carol's offer on bob's request.
		$mine = $this->offer(5, 'accepted', 'bob', '2', 1);
		$this->requests->method('find')->willReturn($this->request('in_progress', 'alice', 1));
		$this->volunteers->method('findByRequest')->with(1)->willReturn([$mine]);
		$this->volunteers->expects($this->never())->method('update');
		$this->stats->expects($this->never())->method('incrementStats');

		$this->expectException(InvalidStateException::class);
		$this->expectExceptionMessage('has not been accepted for this request');
		$this->service->completeRequest(1, 'alice', 6);
	}

	public function testAnAcceptedVolunteerOfThisRequestMayBeNamed(): void {
		$bob = $this->offer(5, 'accepted', 'bob', '2');
		$this->requests->method('find')->willReturn($this->request('in_progress'));
		$this->volunteers->method('findByRequest')->willReturn([$bob]);

		$this->assertSame('completed', $this->service->completeRequest(1, 'alice', 5)->getStatus());
	}

	public function testFinishedRequestsCannotBeCompletedAgain(): void {
		// Reproduces the live bug: complete after cancel (and twice) counted the volunteer's stats again.
		foreach (['completed', 'cancelled'] as $status) {
			$this->requests = $this->createMock(RequestMapper::class);
			$this->requests->method('find')->willReturn($this->request($status));
			$this->requests->method('update')->willReturnArgument(0);
			$stats = $this->createMock(UserStatsMapper::class);
			$stats->expects($this->never())->method('incrementStats');
			$service = new RequestService($this->requests, $this->volunteers, $this->createMock(CommentMapper::class), $stats, $this->withdrawals);

			try {
				$service->completeRequest(1, 'alice');
				$this->fail("A $status request must not be completed again");
			} catch (InvalidStateException $e) {
				$this->assertStringContainsString('already finished', $e->getMessage());
			}
		}
	}

	public function testCompletingNeedsAnAcceptedVolunteer(): void {
		$this->requests->method('find')->willReturn($this->request('open'));
		$this->volunteers->method('findByRequest')->willReturn([$this->offer(5, 'offered', 'bob', '2')]);

		$this->expectException(InvalidStateException::class);
		$this->expectExceptionMessage('Accept at least one volunteer');
		$this->service->completeRequest(1, 'alice');
	}

	public function testOnlyTheRequesterCanComplete(): void {
		$this->requests->method('find')->willReturn($this->request('in_progress', 'alice'));

		$this->expectException(NotAuthorizedException::class);
		$this->service->completeRequest(1, 'mallory');
	}

	// ---- cancelling ----------------------------------------------------------

	public function testOpenAndInProgressRequestsCanBeCancelled(): void {
		foreach (['open', 'in_progress'] as $status) {
			$this->requests = $this->createMock(RequestMapper::class);
			$this->requests->method('find')->willReturn($this->request($status));
			$this->requests->method('update')->willReturnArgument(0);
			$service = new RequestService($this->requests, $this->volunteers, $this->createMock(CommentMapper::class), $this->stats, $this->withdrawals);

			$this->assertSame('cancelled', $service->cancelRequest(1, 'alice')->getStatus());
		}
	}

	public function testACompletedRequestCannotBeCancelled(): void {
		// Reproduces the live bug: a completed request could be flipped to cancelled.
		$this->requests->method('find')->willReturn($this->request('completed'));

		$this->expectException(InvalidStateException::class);
		$this->service->cancelRequest(1, 'alice');
	}

	public function testOnlyTheRequesterCanCancel(): void {
		$this->requests->method('find')->willReturn($this->request('open', 'alice'));

		$this->expectException(NotAuthorizedException::class);
		$this->service->cancelRequest(1, 'mallory');
	}

	// ---- request details -----------------------------------------------------

	public function testOnlyTheRequesterSeesWhyPeopleWithdrew(): void {
		$this->requests->method('find')->willReturn($this->request('open', 'alice'));
		$this->volunteers->method('findByRequest')->willReturn([]);
		$entry = new Withdrawal();
		$entry->setId(1);
		$entry->setRequestId(1);
		$entry->setVolunteerId('bob');
		$entry->setOfferStatus('accepted');
		$entry->setProposedHours('2');
		$entry->setReason('Family emergency');
		$this->withdrawals->method('findByRequest')->willReturn([$entry]);

		$forRequester = $this->service->getRequestDetails(1, 'alice');
		$this->assertSame('Family emergency', $forRequester['withdrawals'][0]['reason']);

		foreach (['bob', 'carol', null] as $viewer) {
			$this->assertArrayNotHasKey('withdrawals', $this->service->getRequestDetails(1, $viewer), 'reasons stay private to the requester');
		}
	}

	// ---- editing -------------------------------------------------------------

	public function testARequestCanBeEditedOnlyWhileOpen(): void {
		$this->requests->method('find')->willReturn($this->request('open'));
		$this->assertSame('New title', $this->service->updateRequest(1, 'alice', ['title' => 'New title'])->getTitle());

		foreach (['in_progress', 'completed', 'cancelled'] as $status) {
			$requests = $this->createMock(RequestMapper::class);
			$requests->method('find')->willReturn($this->request($status));
			$service = new RequestService($requests, $this->volunteers, $this->createMock(CommentMapper::class), $this->stats, $this->withdrawals);

			try {
				$service->updateRequest(1, 'alice', ['title' => 'Sneaky edit']);
				$this->fail("A $status request must not be editable");
			} catch (InvalidStateException) {
				$this->addToAssertionCount(1);
			}
		}
	}

	public function testOnlyTheRequesterCanEdit(): void {
		$this->requests->method('find')->willReturn($this->request('open', 'alice'));

		$this->expectException(NotAuthorizedException::class);
		$this->service->updateRequest(1, 'mallory', ['title' => 'Not mine']);
	}

	public function testEditsKeepTheLimitsOfPostingARequest(): void {
		$this->requests->method('find')->willReturn($this->request('open'));

		$bad = [
			['title' => 'abc'],
			['description' => 'too short'],
			['hoursBudget' => 0],
			['hoursBudget' => 1001],
			['priority' => 'whenever'],
		];
		foreach ($bad as $change) {
			try {
				$this->service->updateRequest(1, 'alice', $change);
				$this->fail('Should refuse ' . json_encode($change));
			} catch (InvalidStateException) {
				$this->addToAssertionCount(1);
			}
		}

		$edited = $this->service->updateRequest(1, 'alice', ['categoryId' => 9, 'hoursBudget' => 2.5, 'priority' => 'high']);
		$this->assertSame(9, $edited->getCategoryId());
		$this->assertSame('high', $edited->getPriority());
	}
}
