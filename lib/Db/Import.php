<?php

declare(strict_types=1);

namespace OCA\TimeBank\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * One import from another database: staged by one admin, finalized or cancelled by another.
 * The counts are public (they go in the governance log); the report names accounts that did not
 * match and is for admins only.
 *
 * @method int getId()
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method string getSourceKind()
 * @method void setSourceKind(string $sourceKind)
 * @method string getSourceLabel()
 * @method void setSourceLabel(string $sourceLabel)
 * @method string getStagedBy()
 * @method void setStagedBy(string $stagedBy)
 * @method \DateTime getStagedAt()
 * @method void setStagedAt(\DateTime $stagedAt)
 * @method \DateTime getExpiresAt()
 * @method void setExpiresAt(\DateTime $expiresAt)
 * @method string|null getDecidedBy()
 * @method void setDecidedBy(?string $decidedBy)
 * @method \DateTime|null getDecidedAt()
 * @method void setDecidedAt(?\DateTime $decidedAt)
 * @method string|null getCounts()
 * @method void setCounts(?string $counts)
 * @method string|null getReport()
 * @method void setReport(?string $report)
 */
class Import extends Entity implements JsonSerializable {
	public const string STAGED = 'staged';
	public const string FINALIZED = 'finalized';
	public const string CANCELLED = 'cancelled';
	public const string EXPIRED = 'expired';

	protected ?string $status = null;
	protected ?string $sourceKind = null;
	protected ?string $sourceLabel = null;
	protected ?string $stagedBy = null;
	protected ?\DateTime $stagedAt = null;
	protected ?\DateTime $expiresAt = null;
	protected ?string $decidedBy = null;
	protected ?\DateTime $decidedAt = null;
	protected ?string $counts = null;
	protected ?string $report = null;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('stagedAt', 'datetime');
		$this->addType('expiresAt', 'datetime');
		$this->addType('decidedAt', 'datetime');
	}

	/** @return array<string, mixed> */
	public function countsData(): array {
		$data = json_decode($this->counts ?? '[]', true);
		if (!is_array($data)) {
			return [];
		}
		/** @var array<string, mixed> $data */

		return $data;
	}

	/** @return array<string, mixed> */
	public function reportData(): array {
		$data = json_decode($this->report ?? '[]', true);
		if (!is_array($data)) {
			return [];
		}
		/** @var array<string, mixed> $data */

		return $data;
	}

	/** @return array<string, mixed> without the report, which names accounts */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'status' => $this->status,
			'sourceKind' => $this->sourceKind,
			'sourceLabel' => $this->sourceLabel,
			'stagedBy' => $this->stagedBy,
			'stagedAt' => $this->stagedAt?->format('Y-m-d H:i:s'),
			'expiresAt' => $this->expiresAt?->format('Y-m-d H:i:s'),
			'decidedBy' => $this->decidedBy,
			'decidedAt' => $this->decidedAt?->format('Y-m-d H:i:s'),
			'counts' => $this->countsData(),
		];
	}
}
