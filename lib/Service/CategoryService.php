<?php

declare(strict_types=1);

namespace OCA\TimeBank\Service;

use OCA\TimeBank\Db\Category;
use OCA\TimeBank\Db\CategoryMapper;
use OCP\AppFramework\Db\DoesNotExistException;

class CategoryService {

	private CategoryMapper $categoryMapper;

	public function __construct(CategoryMapper $categoryMapper) {
		$this->categoryMapper = $categoryMapper;
	}

	/**
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
	 * @param array<string, mixed> $data
	 */
	public function create(array $data): Category {
		$category = new Category();
		$category->setName($data['name']);
		$category->setDescription($data['description'] ?? null);
		$category->setEarnRate((string)($data['earnRate'] ?? '1.00'));
		$category->setIcon($data['icon'] ?? null);
		$category->setCreatedAt(new \DateTime());

		return $this->categoryMapper->insert($category);
	}

	/**
	 * @param array<string, mixed> $data
	 * @throws DoesNotExistException
	 */
	public function update(int $id, array $data): Category {
		$category = $this->categoryMapper->find($id);

		if (isset($data['name'])) {
			$category->setName($data['name']);
		}
		if (isset($data['description'])) {
			$category->setDescription($data['description']);
		}
		if (isset($data['earnRate'])) {
			$category->setEarnRate((string)$data['earnRate']);
		}
		if (isset($data['icon'])) {
			$category->setIcon($data['icon']);
		}

		return $this->categoryMapper->update($category);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function delete(int $id): void {
		$category = $this->categoryMapper->find($id);
		$this->categoryMapper->delete($category);
	}
}
