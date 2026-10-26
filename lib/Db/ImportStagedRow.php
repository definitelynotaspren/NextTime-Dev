<?php

declare(strict_types=1);

namespace OCA\TimeBank\Db;

use OCP\AppFramework\Db\Entity;

/**
 * A row of a staged import, held as JSON until a second admin finalizes it. Holds people's
 * words, so it is read only by the import code and deleted when the import is decided.
 *
 * @method int getId()
 * @method int getImportId()
 * @method void setImportId(int $importId)
 * @method string getKind()
 * @method void setKind(string $kind)
 * @method string getSourceRef()
 * @method void setSourceRef(string $sourceRef)
 * @method string getData()
 * @method void setData(string $data)
 */
class ImportStagedRow extends Entity {
	protected ?int $importId = null;
	protected ?string $kind = null;
	protected ?string $sourceRef = null;
	protected ?string $data = null;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('importId', 'integer');
	}

	/** @return array<string, mixed> */
	public function dataArray(): array {
		$data = json_decode($this->data ?? '[]', true);

		return is_array($data) ? $data : [];
	}
}
