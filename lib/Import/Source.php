<?php

declare(strict_types=1);

namespace OCA\TimeBank\Import;

/**
 * Where an import reads from. A source only reads; it never writes to what it reads.
 * Rows come back as the old system stored them, so a source from an older NextTime may lack
 * columns the current one has; the planner treats a missing column as empty.
 */
interface Source {

	/** @return list<array<string, mixed>> */
	public function requests(): array;

	/** @return list<array<string, mixed>> offers of help on requests */
	public function volunteers(): array;

	/** @return list<array<string, mixed>> hour claims */
	public function earnings(): array;

	/** @return array<string, string> the old category ids mapped to their names */
	public function categoryNames(): array;
}
