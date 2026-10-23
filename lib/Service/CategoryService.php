<?php

declare(strict_types=1);

namespace OCA\TimeBank\Service;

use OCA\TimeBank\Db\Category;
use OCA\TimeBank\Db\CategoryMapper;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Service categories. Every change is written to the public governance log. A category
 * that is in active use cannot be deleted, and one that history points at cannot be
 * deleted either: archive it instead, which hides it from pickers but keeps history readable.
 */
class CategoryService {

	public const MAX_EARN_RATE = 5.0;

	public function __construct(
		private CategoryMapper $categoryMapper,
		private GovernanceLog $log,
	) {
	}

	/**
	 * Every category, archived ones included, so old requests can still show their name.
	 * Pickers hide the archived ones.
	 *
	 * @return Category[]
	 */
	public function getAll(): array {
		return $this->categoryMapper->findAll();
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function get(int $id): Category {
		return $this->categoryMapper->find($id);
	}

	/**
	 * What would stop this category from being deleted, for the admin screen.
	 *
	 * @return array{inProgress: int, openWithOffers: int, claimsInFlight: int, references: int, canDelete: bool}
	 * @throws DoesNotExistException
	 */
	public function usage(int $id): array {
		$this->categoryMapper->find($id);
		$use = $this->categoryMapper->activeUse($id);
		$references = $this->categoryMapper->countReferences($id);

		return $use + [
			'references' => $references,
			'canDelete' => array_sum($use) === 0 && $references === 0,
		];
	}

	/**
	 * @param array<string, mixed> $data
	 * @throws InvalidStateException
	 */
	public function create(array $data, string $actorId): Category {
		$name = $this->validName($data['name'] ?? null);
		if ($this->categoryMapper->findOneByName($name) !== null) {
			throw new InvalidStateException('A category with this name already exists');
		}

		$category = new Category();
		$category->setName($name);
		$category->setDescription($data['description'] ?? null);
		$category->setEarnRate($this->validRate($data['earnRate'] ?? 1.0));
		$category->setIcon($data['icon'] ?? null);
		$category->setCreatedAt(new \DateTime());
		$category = $this->categoryMapper->insert($category);

		$this->log->record($actorId, 'category.created', $category->getName(), [
			'earnRate' => (float)$category->getEarnRate(),
		]);

		return $category;
	}

	/**
	 * @param array<string, mixed> $data
	 * @throws DoesNotExistException
	 * @throws InvalidStateException
	 */
	public function update(int $id, array $data, string $actorId): Category {
		$category = $this->categoryMapper->find($id);
		$changes = [];

		if (isset($data['name']) && $data['name'] !== $category->getName()) {
			$name = $this->validName($data['name']);
			$other = $this->categoryMapper->findOneByName($name);
			if ($other !== null && $other->getId() !== $id) {
				throw new InvalidStateException('A category with this name already exists');
			}
			$changes['name'] = ['from' => $category->getName(), 'to' => $name];
			$category->setName($name);
		}
		if (isset($data['description'])) {
			$category->setDescription($data['description']);
		}
		if (isset($data['earnRate'])) {
			$rate = $this->validRate($data['earnRate']);
			if ((float)$rate !== (float)$category->getEarnRate()) {
				$changes['earnRate'] = ['from' => (float)$category->getEarnRate(), 'to' => (float)$rate];
			}
			$category->setEarnRate($rate);
		}
		if (isset($data['icon'])) {
			$category->setIcon($data['icon']);
		}

		$category = $this->categoryMapper->update($category);
		if ($changes !== []) {
			$this->log->record($actorId, 'category.updated', $category->getName(), $changes);
		}

		return $category;
	}

	/**
	 * Hide a category from pickers. Requests and claims already using it carry on.
	 *
	 * @throws DoesNotExistException
	 */
	public function archive(int $id, string $actorId): Category {
		$category = $this->categoryMapper->find($id);
		if ($category->getArchivedAt() === null) {
			$category->setArchivedAt(new \DateTime());
			$category = $this->categoryMapper->update($category);
			$this->log->record($actorId, 'category.archived', $category->getName());
		}

		return $category;
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function restore(int $id, string $actorId): Category {
		$category = $this->categoryMapper->find($id);
		if ($category->getArchivedAt() !== null) {
			$category->setArchivedAt(null);
			$category = $this->categoryMapper->update($category);
			$this->log->record($actorId, 'category.restored', $category->getName());
		}

		return $category;
	}

	/**
	 * @throws DoesNotExistException
	 * @throws InvalidStateException when the category is in use or history points at it
	 */
	public function delete(int $id, string $actorId): void {
		$category = $this->categoryMapper->find($id);
		$usage = $this->usage($id);

		if ($usage['inProgress'] + $usage['openWithOffers'] + $usage['claimsInFlight'] > 0) {
			throw new InvalidStateException(sprintf(
				'This category is in use (%d in progress, %d open with offers, %d claims waiting). Archive it instead, or delete it once that work is finished.',
				$usage['inProgress'],
				$usage['openWithOffers'],
				$usage['claimsInFlight'],
			));
		}
		if ($usage['references'] > 0) {
			throw new InvalidStateException('Past requests, claims or statistics use this category, so it cannot be deleted. Archive it instead.');
		}

		$this->categoryMapper->delete($category);
		$this->log->record($actorId, 'category.deleted', $category->getName());
	}

	/**
	 * @throws InvalidStateException
	 */
	public function requireUsable(int $id): void {
		try {
			$category = $this->categoryMapper->find($id);
		} catch (DoesNotExistException) {
			throw new InvalidStateException('That category does not exist');
		}
		if ($category->getArchivedAt() !== null) {
			throw new InvalidStateException('That category is archived and can no longer be chosen');
		}
	}

	/**
	 * @throws InvalidStateException
	 */
	private function validName(mixed $name): string {
		$name = trim((string)$name);
		if (mb_strlen($name) < 2 || mb_strlen($name) > 100) {
			throw new InvalidStateException('A category name must be 2 to 100 characters');
		}

		return $name;
	}

	/**
	 * @throws InvalidStateException
	 */
	private function validRate(mixed $rate): string {
		if (!is_numeric($rate) || (float)$rate <= 0 || (float)$rate > self::MAX_EARN_RATE) {
			throw new InvalidStateException('The earn rate must be above 0 and at most ' . self::MAX_EARN_RATE);
		}

		return number_format((float)$rate, 2, '.', '');
	}
}
