<?php

declare(strict_types=1);

namespace OCA\TimeBank\Tests\Unit\Service;

use OCA\TimeBank\Db\Category;
use OCA\TimeBank\Db\CategoryMapper;
use OCA\TimeBank\Service\CategoryService;
use OCA\TimeBank\Service\GovernanceLog;
use OCA\TimeBank\Service\InvalidStateException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * A category in use cannot be deleted, history pins it, archiving hides it, changes are logged.
 */
final class CategoryServiceTest extends TestCase {

	private CategoryMapper&MockObject $mapper;
	private GovernanceLog&MockObject $log;
	private CategoryService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->mapper = $this->createMock(CategoryMapper::class);
		$this->log = $this->createMock(GovernanceLog::class);
		$this->service = new CategoryService($this->mapper, $this->log);
		$this->mapper->method('update')->willReturnArgument(0);
	}

	private function category(): Category {
		$c = new Category();
		$c->setId(3);
		$c->setName('Gardening');
		$c->setEarnRate('1.00');
		$this->mapper->method('find')->willReturn($c);
		return $c;
	}

	private function use(int $inProgress, int $openWithOffers, int $claims, int $references): void {
		$this->mapper->method('activeUse')->willReturn(['inProgress' => $inProgress, 'openWithOffers' => $openWithOffers, 'claimsInFlight' => $claims]);
		$this->mapper->method('countReferences')->willReturn($references);
	}

	public function testACategoryInActiveUseCannotBeDeleted(): void {
		$this->category();
		foreach ([[1, 0, 0], [0, 1, 0], [0, 0, 1]] as [$a, $b, $c]) {
			$mapper = $this->createMock(CategoryMapper::class);
			$mapper->method('find')->willReturn($this->category());
			$mapper->method('activeUse')->willReturn(['inProgress' => $a, 'openWithOffers' => $b, 'claimsInFlight' => $c]);
			$mapper->method('countReferences')->willReturn(5);
			$mapper->expects($this->never())->method('delete');
			try {
				(new CategoryService($mapper, $this->log))->delete(3, 'admin');
				$this->fail('Should refuse');
			} catch (InvalidStateException $e) {
				$this->assertStringContainsString('in use', $e->getMessage());
			}
		}
	}

	public function testHistoryAloneAlsoBlocksDeletionAndPointsToArchive(): void {
		$this->category();
		$this->use(0, 0, 0, 12);
		$this->mapper->expects($this->never())->method('delete');

		$this->expectExceptionMessage('Archive it instead');
		$this->service->delete(3, 'admin');
	}

	public function testUnusedCategoriesCanBeDeletedAndItIsLogged(): void {
		$this->category();
		$this->use(0, 0, 0, 0);
		$this->mapper->expects($this->once())->method('delete');
		$this->log->expects($this->once())->method('record')->with('admin', 'category.deleted', 'Gardening');

		$this->service->delete(3, 'admin');
	}

	public function testArchivingAndRestoringAreLoggedAndIdempotent(): void {
		$c = $this->category();
		$this->log->expects($this->exactly(2))->method('record');

		$this->assertNotNull($this->service->archive(3, 'admin')->getArchivedAt());
		$this->service->archive(3, 'admin');
		$this->assertNull($this->service->restore(3, 'admin')->getArchivedAt());
		$this->assertNull($c->getArchivedAt());
	}

	public function testRateAndNameAreValidatedAndRateChangesAreLogged(): void {
		$this->category();
		$this->mapper->method('findOneByName')->willReturn(null);

		foreach ([['earnRate' => 0], ['earnRate' => 9], ['name' => ' ']] as $bad) {
			try {
				$this->service->update(3, $bad, 'admin');
				$this->fail('Should refuse ' . json_encode($bad));
			} catch (InvalidStateException) {
				$this->addToAssertionCount(1);
			}
		}

		$this->log->expects($this->once())->method('record')->with('admin', 'category.updated', 'Gardening', ['earnRate' => ['from' => 1.0, 'to' => 1.5]]);
		$this->service->update(3, ['earnRate' => 1.5], 'admin');
	}

	public function testArchivedCategoriesCannotTakeNewWork(): void {
		$c = $this->category();
		$c->setArchivedAt(new \DateTime());

		$this->expectException(InvalidStateException::class);
		$this->service->requireUsable(3);
	}
}
