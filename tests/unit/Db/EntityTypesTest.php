<?php

declare(strict_types=1);

namespace OCA\TimeBank\Tests\Unit\Db;

use OCP\AppFramework\Db\Entity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Guards against a bug class that broke every read in v0.2.0: an entity
 * property typed ?\DateTime without addType(..., 'datetime'). The mapper then
 * hydrates the DB string straight into the typed property and PHP throws
 * "Cannot assign string to property ... of type ?DateTime" (HTTP 500).
 */
final class EntityTypesTest extends TestCase {

	public static function entityClasses(): array {
		$cases = [];
		foreach (glob(__DIR__ . '/../../../lib/Db/*.php') as $file) {
			$name = basename($file, '.php');
			if (str_ends_with($name, 'Mapper')) {
				continue;
			}
			$class = 'OCA\\TimeBank\\Db\\' . $name;
			if (is_subclass_of($class, Entity::class)) {
				$cases[$name] = [$class];
			}
		}
		return $cases;
	}

	#[DataProvider('entityClasses')]
	public function testDateTimePropertiesAreTyped(string $class): void {
		/** @var Entity $entity */
		$entity = new $class();
		$types = $entity->getFieldTypes();

		$checked = 0;
		foreach ((new \ReflectionClass($class))->getProperties() as $prop) {
			$type = $prop->getType();
			if ($type instanceof \ReflectionNamedType && $type->getName() === \DateTime::class) {
				$checked++;
				$this->assertSame(
					'datetime',
					$types[$prop->getName()] ?? null,
					"$class::\${$prop->getName()} is ?\\DateTime but has no addType('{$prop->getName()}', 'datetime')",
				);
			}
		}
		$this->addToAssertionCount($checked === 0 ? 1 : 0);
	}

	#[DataProvider('entityClasses')]
	public function testRowHydrationDoesNotThrow(string $class): void {
		$row = [];
		foreach ((new \ReflectionClass($class))->getProperties() as $prop) {
			$type = $prop->getType();
			if ($type instanceof \ReflectionNamedType && $type->getName() === \DateTime::class) {
				// Simulate what the DB layer returns for a datetime column.
				$row[strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $prop->getName()))] = '2026-01-15 10:30:00';
			}
		}
		$entity = $class::fromRow($row);
		$this->assertInstanceOf($class, $entity);
	}
}
