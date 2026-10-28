<?php

declare(strict_types=1);

namespace OCA\TimeBank\Tests\Unit\Service;

use OCA\TimeBank\Db\Request;
use OCA\TimeBank\Db\RequestMapper;
use OCA\TimeBank\Db\Volunteer;
use OCA\TimeBank\Db\VolunteerMapper;
use OCA\TimeBank\Db\Withdrawal;
use OCA\TimeBank\Db\WithdrawalMapper;
use OCA\TimeBank\Notification\OfferNotifier;
use OCA\TimeBank\Service\InvalidStateException;
use OCA\TimeBank\Service\NotAuthorizedException;
use OCA\TimeBank\Service\VolunteerService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Lifecycle rules for offers of help. The mappers are mocked, so no database is needed.
 */
final class VolunteerServiceTest extends TestCase {

	private VolunteerMapper&MockObject $volunteers;
	private RequestMapper&MockObject $requests;
	private WithdrawalMapper&MockObject $withdrawals;
	private OfferNotifier&MockObject $notifier;
	private VolunteerService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->volunteers = $this->createMock(VolunteerMapper::class);
		$this->requests = $this->createMock(RequestMapper::class);
		$this->withdrawals = $this->createMock(WithdrawalMapper::class);
		$this->notifier = $this->createMock(OfferNotifier::class);
		$this->service = new VolunteerService($this->volunteers, $this->requests, $this->withdrawals, $this->notifier);

		$this->volunteers->method('insert')->willReturnArgument(0);
		$this->volunteers->method('update')->willReturnArgument(0);
		$this->requests->method('update')->willReturnArgument(0);
	}

	private function request(string $status = 'open', string $owner = 'alice', int $id = 1): Request {
		$request = new Request();
		$request->setId($id);
		$request->setRequesterId($owner);
		$request->setStatus($status);
		$request->setCategoryId(7);
		return $request;
	}

	private function offer(int $id, string $status = 'offered', string $who = 'bob', int $requestId = 1, string $hours = '2'): Volunteer {
		$offer = new Volunteer();
		$offer->setId($id);
		$offer->setRequestId($requestId);
		$offer->setVolunteerId($who);
		$offer->setProposedHours($hours);
		$offer->setStatus($status);
		return $offer;
	}

	private function noEarlierOffer(): void {
		$this->volunteers->method('findByRequestAndVolunteer')->willThrowException(new DoesNotExistException('none'));
	}

	// ---- offering ------------------------------------------------------------

	public function testSeveralPeopleCanOfferOnTheSameRequest(): void {
		$this->requests->method('find')->willReturn($this->request('in_progress'));
		$this->noEarlierOffer();

		$offer = $this->service->offer(1, 'carol', ['proposedHours' => 3, 'message' => 'I can help too']);

		$this->assertSame('offered', $offer->getStatus());
		$this->assertSame('carol', $offer->getVolunteerId());
	}

	public function testOfferingDoesNotChangeTheRequestStatus(): void {
		$this->requests->method('find')->willReturn($this->request('open'));
		$this->noEarlierOffer();
		$this->requests->expects($this->never())->method('update');

		$this->service->offer(1, 'bob', ['proposedHours' => 2]);
	}

	public function testOffersAreRefusedOnFinishedRequests(): void {
		foreach (['completed', 'cancelled'] as $status) {
			$service = new VolunteerService($this->volunteers, $this->requests = $this->createMock(RequestMapper::class), $this->withdrawals, $this->notifier);
			$this->requests->method('find')->willReturn($this->request($status));

			try {
				$service->offer(1, 'bob', ['proposedHours' => 2]);
				$this->fail("An offer on a $status request should be refused");
			} catch (InvalidStateException $e) {
				$this->assertStringContainsString('not accepting volunteers', $e->getMessage());
			}
		}
	}

	public function testRequesterCannotVolunteerForTheirOwnRequest(): void {
		$this->requests->method('find')->willReturn($this->request('open', 'alice'));

		$this->expectException(InvalidStateException::class);
		$this->service->offer(1, 'alice', ['proposedHours' => 2]);
	}

	public function testOfferedHoursMustBeSensible(): void {
		$this->requests->method('find')->willReturn($this->request('open'));
		$this->noEarlierOffer();

		foreach ([0, -1, 1001] as $hours) {
			try {
				$this->service->offer(1, 'bob', ['proposedHours' => $hours]);
				$this->fail("$hours hours should be refused");
			} catch (InvalidStateException) {
				$this->addToAssertionCount(1);
			}
		}
	}

	public function testSecondOfferFromTheSamePersonIsRefused(): void {
		$this->requests->method('find')->willReturn($this->request('open'));
		$this->volunteers->method('findByRequestAndVolunteer')->willReturn($this->offer(5));

		$this->expectException(InvalidStateException::class);
		$this->expectExceptionMessage('already volunteered');
		$this->service->offer(1, 'bob', ['proposedHours' => 2]);
	}

	// ---- accepting and declining --------------------------------------------

	public function testFirstAcceptanceMovesTheRequestInProgress(): void {
		$request = $this->request('open');
		$this->requests->method('find')->willReturn($request);
		$this->volunteers->method('find')->willReturn($this->offer(5));

		$accepted = $this->service->accept(5, 'alice');

		$this->assertSame('accepted', $accepted->getStatus());
		$this->assertSame('in_progress', $request->getStatus());
	}

	public function testAcceptingKeepsTheOfferedHoursUnlessTheRequesterSettlesOnOthers(): void {
		$request = $this->request('open');
		$request->setHoursBudget('4');
		$this->requests->method('find')->willReturn($request);
		$offer = $this->offer(5);
		$offer->setProposedHours('2');
		$this->volunteers->method('find')->willReturn($offer);

		$this->assertSame(2.0, $this->service->accept(5, 'alice')->effectiveHours(), 'offered hours by default');

		$offer->setStatus('offered');
		$accepted = $this->service->accept(5, 'alice', 3.5);
		$this->assertSame(3.5, $accepted->effectiveHours());
		$this->assertSame(2.0, (float)$accepted->getProposedHours(), 'the offer itself is not rewritten');
	}

	public function testNoticesGoOutOnOfferingAndAcceptingAndClearOnAnswering(): void {
		$request = $this->request('open');
		$this->requests->method('find')->willReturn($request);
		$this->noEarlierOffer();
		$this->notifier->expects($this->once())->method('offered');
		$this->service->offer(1, 'bob', ['proposedHours' => 2]);

		$this->volunteers->method('find')->willReturn($this->offer(5));
		$this->notifier->expects($this->once())->method('accepted');
		$this->notifier->expects($this->once())->method('answered');
		$this->service->accept(5, 'alice');
	}

	public function testAgreedHoursStayBetweenWhatWasPostedAndWhatWasOffered(): void {
		$request = $this->request('open');
		$request->setHoursBudget('4');
		$this->requests->method('find')->willReturn($request);
		$offer = $this->offer(5);
		$offer->setProposedHours('2');
		$this->volunteers->method('find')->willReturn($offer);

		foreach ([1.5, 4.5, 0.0, -1.0] as $hours) {
			try {
				$this->service->accept(5, 'alice', $hours);
				$this->fail("$hours should be refused");
			} catch (InvalidStateException $e) {
				$this->assertStringContainsString('between 2 ', $e->getMessage());
				$this->assertSame('offered', $offer->getStatus());
			}
		}
		foreach ([2.0, 4.0, 3.0] as $hours) {
			$offer->setStatus('offered');
			$this->assertSame($hours, $this->service->accept(5, 'alice', $hours)->effectiveHours());
		}
	}

	public function testAnOfferAboveTheBudgetMayBeSettledDownToTheBudget(): void {
		$request = $this->request('open');
		$request->setHoursBudget('2');
		$this->requests->method('find')->willReturn($request);
		$offer = $this->offer(5);
		$offer->setProposedHours('5');
		$this->volunteers->method('find')->willReturn($offer);

		$this->assertSame(2.0, $this->service->accept(5, 'alice', 2.0)->effectiveHours());
	}

	public function testSeveralVolunteersCanBeAcceptedAndNoOneIsAutoDeclined(): void {
		$request = $this->request('in_progress');
		$this->requests->method('find')->willReturn($request);
		$second = $this->offer(6, 'offered', 'carol');
		$this->volunteers->method('find')->willReturn($second);
		// Accepting must touch only this offer; no other offer is declined.
		$this->volunteers->expects($this->once())->method('update');

		$this->assertSame('accepted', $this->service->accept(6, 'alice')->getStatus());
		$this->assertSame('in_progress', $request->getStatus());
	}

	public function testOnlyTheRequesterCanAcceptOrDecline(): void {
		$this->requests->method('find')->willReturn($this->request('open', 'alice'));
		$this->volunteers->method('find')->willReturn($this->offer(5));

		$this->expectException(NotAuthorizedException::class);
		$this->service->accept(5, 'mallory');
	}

	public function testOnlyAPendingOfferCanBeAcceptedOrDeclined(): void {
		$this->requests->method('find')->willReturn($this->request('in_progress'));
		$this->volunteers->method('find')->willReturn($this->offer(5, 'accepted'));

		foreach (['accept', 'decline'] as $action) {
			try {
				$this->service->$action(5, 'alice');
				$this->fail("$action on an already accepted offer should be refused");
			} catch (InvalidStateException) {
				$this->addToAssertionCount(1);
			}
		}
	}

	public function testNothingCanBeAcceptedOnAFinishedRequest(): void {
		$this->requests->method('find')->willReturn($this->request('cancelled'));
		$this->volunteers->method('find')->willReturn($this->offer(5));

		$this->expectException(InvalidStateException::class);
		$this->service->accept(5, 'alice');
	}

	// ---- withdrawing ---------------------------------------------------------

	public function testWithdrawingAcceptedHelpNeedsAReason(): void {
		$this->requests->method('find')->willReturn($this->request('in_progress'));
		$this->volunteers->method('find')->willReturn($this->offer(5, 'accepted'));
		$this->volunteers->expects($this->never())->method('delete');
		$this->withdrawals->expects($this->never())->method('insert');

		foreach ([null, '', '  ', 'no'] as $reason) {
			try {
				$this->service->withdraw(5, 'bob', $reason);
				$this->fail('A missing or too short reason should be refused');
			} catch (InvalidStateException $e) {
				$this->assertStringContainsString('why you are withdrawing', $e->getMessage());
			}
		}
	}

	public function testWithdrawingAcceptedHelpIsLoggedAndReopensTheRequest(): void {
		$request = $this->request('in_progress');
		$this->requests->method('find')->willReturn($request);
		$this->volunteers->method('find')->willReturn($this->offer(5, 'accepted', 'bob', 1, '2.5'));
		$this->volunteers->method('findByRequest')->willReturn([]);
		$this->volunteers->expects($this->once())->method('delete');

		$logged = null;
		$this->withdrawals->expects($this->once())->method('insert')
			->willReturnCallback(function (Withdrawal $entry) use (&$logged) {
				$logged = $entry;
				return $entry;
			});

		$this->service->withdraw(5, 'bob', '  Family emergency  ');

		$this->assertSame('Family emergency', $logged->getReason());
		$this->assertSame('bob', $logged->getVolunteerId());
		$this->assertSame(1, $logged->getRequestId());
		$this->assertSame('accepted', $logged->getOfferStatus());
		$this->assertSame('2.5', $logged->getProposedHours());
		$this->assertSame('open', $request->getStatus(), 'with no accepted helpers left the request reopens');
	}

	public function testRequestStaysInProgressWhileAnotherAcceptedVolunteerRemains(): void {
		$request = $this->request('in_progress');
		$this->requests->method('find')->willReturn($request);
		$this->volunteers->method('find')->willReturn($this->offer(5, 'accepted'));
		$this->volunteers->method('findByRequest')->willReturn([$this->offer(6, 'accepted', 'carol')]);

		$this->service->withdraw(5, 'bob', 'Something came up');

		$this->assertSame('in_progress', $request->getStatus());
	}

	public function testWithdrawingAPendingOfferNeedsNoReasonAndIsNotLogged(): void {
		$request = $this->request('open');
		$this->requests->method('find')->willReturn($request);
		$this->volunteers->method('find')->willReturn($this->offer(5, 'offered'));
		$this->volunteers->method('findByRequest')->willReturn([]);
		$this->volunteers->expects($this->once())->method('delete');
		$this->withdrawals->expects($this->never())->method('insert');

		$this->service->withdraw(5, 'bob');

		$this->assertSame('open', $request->getStatus());
	}

	public function testNobodyCanWithdrawSomeoneElsesOffer(): void {
		$this->requests->method('find')->willReturn($this->request('in_progress'));
		$this->volunteers->method('find')->willReturn($this->offer(5, 'accepted', 'bob'));
		$this->volunteers->expects($this->never())->method('delete');

		$this->expectException(NotAuthorizedException::class);
		$this->service->withdraw(5, 'carol', 'not my offer');
	}

	public function testOffersCannotBeWithdrawnOnceTheRequestIsFinished(): void {
		$this->requests->method('find')->willReturn($this->request('completed'));
		$this->volunteers->method('find')->willReturn($this->offer(5, 'completed'));
		$this->volunteers->expects($this->never())->method('delete');

		$this->expectException(InvalidStateException::class);
		$this->service->withdraw(5, 'bob', 'too late');
	}
}
