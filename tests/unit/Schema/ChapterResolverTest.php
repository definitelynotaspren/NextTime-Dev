<?php

declare(strict_types=1);

namespace OCA\TimeBank\Tests\Unit\Schema;

use OCA\TimeBank\Schema\ChapterResolver;
use PHPUnit\Framework\TestCase;

class ChapterResolverTest extends TestCase {

	public function testMapsListedZipToChapter(): void {
		$this->assertSame('mt-hood', ChapterResolver::forZip('97055'));
		$this->assertSame('gresham', ChapterResolver::forZip('97233'));
	}

	public function testUnknownOrMalformedZipHasNoChapter(): void {
		$this->assertNull(ChapterResolver::forZip('10001'));
		$this->assertNull(ChapterResolver::forZip('9705'));
		$this->assertNull(ChapterResolver::forZip('97055 '));
		$this->assertNull(ChapterResolver::forZip(null));
	}
}
