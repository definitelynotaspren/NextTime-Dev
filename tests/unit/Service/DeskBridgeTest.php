<?php

declare(strict_types=1);

namespace OCA\TimeBank\Tests\Unit\Service;

use OCA\TimeBank\Db\LayerRow;
use OCA\TimeBank\Db\Request;
use OCA\TimeBank\Db\RequestMapper;
use OCA\TimeBank\Schema\LayerException;
use OCA\TimeBank\Schema\Viewer;
use OCA\TimeBank\Service\CategoryService;
use OCA\TimeBank\Service\DeskBridge;
use OCA\TimeBank\Service\GovernanceLog;
use OCA\TimeBank\Service\InvalidStateException;
use OCA\TimeBank\Service\LayerService;
use OCA\TimeBank\Service\RequestService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Putting a desk need on the request board: what is copied, who may, and only once.
 */
final class DeskBridgeTest extends TestCase {

	private LayerService&MockObject $layers;
	private RequestService&MockObject $requests;
	private RequestMapper&MockObject $requestMapper;
	private CategoryService&MockObject $categories;
	private GovernanceLog&MockObject $log;
	private DeskBridge $bridge;
	/** @var array<string, mixed> */
	private array $rowData = [];

	protected function setUp(): void {
		parent::setUp();
		$this->rowData = [
			'need_or_offer' => 'need',
			'zip' => '97055',
			'summary' => 'Ride to a clinic',
			'requester_contact' => 'Mrs. Lee, 555-0100',
		];
		$row = new LayerRow();
		$row->setUid('NO-F3-17');
		$this->layers = $this->createMock(LayerService::class);
		$this->layers->method('workableRow')->willReturn($row);
		$this->layers->method('dataOf')->willReturnCallback(fn () => $this->rowData);
		$this->requests = $this->createMock(RequestService::class);
		$this->requestMapper = $this->createMock(RequestMapper::class);
		$this->categories = $this->createMock(CategoryService::class);
		$this->log = $this->createMock(GovernanceLog::class);
		$this->bridge = new DeskBridge($this->layers, $this->requests, $this->requestMapper, $this->categories, $this->log);
	}

	/** @return array<string, mixed> */
	private function input(): array {
		return ['title' => 'Ride to a clinic', 'description' => 'A neighbor needs a ride on Tuesday morning.', 'categoryId' => 1, 'hoursBudget' => 2];
	}

	private function fern(): Viewer {
		return new Viewer('fern', false, true, ['oregon'], true);
	}

	public function testOnlyTheTypedTextAndTheZipAreCopied(): void {
		$request = new Request();
		$request->setId(40);
		$this->requests->expects($this->once())->method('createRequest')->willReturnCallback(function (string $user, array $data) use ($request) {
			$this->assertSame('fern', $user, 'the facilitator becomes the requester');
			$this->assertSame('97055', $data['zip']);
			$this->assertSame('desk:NO-F3-17', $data['sourceRef']);
			$this->assertStringNotContainsString('555', (string)json_encode($data));
			$this->assertArrayNotHasKey('location', $data);

			return $request;
		});
		$this->requests->method('present')->willReturn(['id' => 40]);
		$this->log->expects($this->once())->method('record')->with('fern', 'desk_row.put_on_board', null, ['uid' => 'NO-F3-17', 'request' => 40]);

		$this->assertSame(['id' => 40], $this->bridge->putOnBoard($this->fern(), 'needs_offers', 1, $this->input()));
	}

	public function testAnOfferIsNotARequest(): void {
		$this->rowData['need_or_offer'] = 'offer';
		$this->expectException(LayerException::class);
		$this->bridge->putOnBoard($this->fern(), 'needs_offers', 1, $this->input());
	}

	public function testOtherLayersCannotGoOnTheBoard(): void {
		$this->expectException(LayerException::class);
		$this->bridge->putOnBoard($this->fern(), 'actions', 1, $this->input());
	}

	public function testARowGoesOnTheBoardOnlyOnce(): void {
		$this->requestMapper->method('findBySourceRef')->with('desk:NO-F3-17')->willReturn(new Request());
		$this->requests->expects($this->never())->method('createRequest');
		try {
			$this->bridge->putOnBoard($this->fern(), 'needs_offers', 1, $this->input());
			$this->fail('expected a conflict');
		} catch (LayerException $e) {
			$this->assertSame(409, $e->getCode());
		}
	}

	public function testARowWithoutAZipCannotBePlaced(): void {
		unset($this->rowData['zip']);
		$this->expectException(LayerException::class);
		$this->bridge->putOnBoard($this->fern(), 'needs_offers', 1, $this->input());
	}

	public function testTheBoardRulesStillApply(): void {
		foreach ([
			['title' => 'Hi'],
			['description' => 'too short'],
			['hoursBudget' => 0],
			['hoursBudget' => 2000],
			['categoryId' => null],
		] as $bad) {
			try {
				$this->bridge->putOnBoard($this->fern(), 'needs_offers', 1, $bad + $this->input());
				$this->fail('should be refused: ' . json_encode($bad));
			} catch (LayerException) {
				$this->addToAssertionCount(1);
			}
		}
	}

	public function testAnArchivedCategoryIsRefused(): void {
		$this->categories->method('requireUsable')->willThrowException(new InvalidStateException('archived'));
		$this->expectException(InvalidStateException::class);
		$this->bridge->putOnBoard($this->fern(), 'needs_offers', 1, $this->input());
	}
}
