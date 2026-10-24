<?php

declare(strict_types=1);

namespace OCA\TimeBank\Intake;

use OCA\TimeBank\AppInfo\Application;
use OCA\TimeBank\Db\IntakeLog;
use OCA\TimeBank\Db\IntakeLogMapper;
use OCA\TimeBank\Schema\RowValidator;
use OCA\TimeBank\Schema\SchemaSpec;
use OCA\TimeBank\Service\GovernanceLog;
use OCA\TimeBank\Service\InvalidStateException;
use OCA\TimeBank\Service\LayerService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Brings submissions of chosen Nextcloud Forms into the organizer desk as unpublished rows
 * with `source = form`. A background job calls sync() every few minutes; "Check now" calls it
 * too. Each submission is looked at once: it becomes a row whose ID names its origin
 * (NO-F<form>-<submission>) or is logged as rejected, with the reason, until someone retries.
 *
 * It reads Forms' own tables, which have kept their layout across versions. Nothing is
 * written to Forms.
 */
class FormsIntake {

	public const CONFIG_KEY = 'forms_intake';
	/** Submissions taken per form per run, so one big backlog cannot stall the job. */
	private const BATCH = 100;

	public function __construct(
		private IDBConnection $db,
		private IConfig $config,
		private IntakeLogMapper $log,
		private LayerService $layers,
		private GovernanceLog $governance,
		private LoggerInterface $logger,
	) {
	}

	public function formsAvailable(): bool {
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('id')->from('forms_v2_forms')->setMaxResults(1);
			$qb->executeQuery()->closeCursor();

			return true;
		} catch (\Throwable) {
			return false;
		}
	}

	/**
	 * @return list<array{formId: int, layer: string}>
	 */
	public function enabled(): array {
		$decoded = json_decode($this->config->getAppValue(Application::APP_ID, self::CONFIG_KEY, '[]'), true);
		$out = [];
		foreach (is_array($decoded) ? $decoded : [] as $entry) {
			if (is_array($entry) && isset($entry['formId'], $entry['layer']) && $this->layerAccepts((string)$entry['layer'])) {
				$out[] = ['formId' => (int)$entry['formId'], 'layer' => (string)$entry['layer']];
			}
		}

		return $out;
	}

	/**
	 * @param list<array{formId: int, layer: string}> $forms
	 * @throws InvalidStateException
	 */
	public function setEnabled(string $actorId, array $forms): void {
		$clean = [];
		foreach ($forms as $entry) {
			$layer = (string)($entry['layer'] ?? '');
			$formId = (int)($entry['formId'] ?? 0);
			if (!$this->layerAccepts($layer)) {
				throw new InvalidStateException("The layer $layer does not take form submissions");
			}
			if ($formId <= 0 || $this->questions($formId) === []) {
				throw new InvalidStateException("Form $formId was not found or has no questions");
			}
			$clean[$formId] = ['formId' => $formId, 'layer' => $layer];
		}

		$before = $this->enabled();
		$this->config->setAppValue(Application::APP_ID, self::CONFIG_KEY, json_encode(array_values($clean), JSON_THROW_ON_ERROR));
		$this->governance->record($actorId, 'setting.forms_intake', null, [
			'from' => array_column($before, 'formId'),
			'to' => array_keys($clean),
		]);
	}

	/**
	 * @return list<array{id: int, title: string, active: bool}>
	 */
	public function availableForms(): array {
		if (!$this->formsAvailable()) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'title', 'state')->from('forms_v2_forms')->orderBy('id', 'DESC');
		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();

		$out = [];
		foreach ($rows as $row) {
			// state 2 is archived
			if ((int)$row['state'] === 2) {
				continue;
			}
			$out[] = ['id' => (int)$row['id'], 'title' => (string)$row['title'], 'active' => (int)$row['state'] === 0];
		}

		return $out;
	}

	/**
	 * How a form's questions would feed a layer, for the admin to check before switching it on.
	 *
	 * @return array{mapped: list<array{question: string, field: string}>, ignored: list<string>, missing: list<string>, defaulted: list<string>}
	 * @throws InvalidStateException
	 */
	public function preview(int $formId, string $layer): array {
		if (!$this->layerAccepts($layer)) {
			throw new InvalidStateException("The layer $layer does not take form submissions");
		}
		$questions = $this->questions($formId);
		if ($questions === []) {
			throw new InvalidStateException("Form $formId was not found or has no questions");
		}

		$fields = SchemaSpec::layer($layer)['fields'];
		$mapping = FormsMapper::mapQuestions($questions, $fields);
		$text = array_column($questions, 'text', 'id');
		$mapped = [];
		foreach ($mapping['map'] as $questionId => $field) {
			$mapped[] = ['question' => $text[$questionId], 'field' => $field];
		}

		return [
			'mapped' => $mapped,
			'ignored' => $mapping['ignored'],
			'missing' => FormsMapper::missingRequired($fields, $mapping['map']),
			'defaulted' => ['posted', 'expires', 'status'],
		];
	}

	/**
	 * @return array{imported: int, rejected: int}
	 */
	public function sync(): array {
		$totals = ['imported' => 0, 'rejected' => 0];
		if (!$this->formsAvailable()) {
			return $totals;
		}

		foreach ($this->enabled() as $entry) {
			try {
				$result = $this->syncForm($entry['formId'], $entry['layer']);
				$totals['imported'] += $result['imported'];
				$totals['rejected'] += $result['rejected'];
			} catch (\Throwable $e) {
				// One broken form must not stop the others, or the job.
				$this->logger->warning('NextTime could not read form ' . $entry['formId'], ['exception' => $e]);
			}
		}

		return $totals;
	}

	/**
	 * @return array{imported: int, rejected: int}
	 */
	public function syncForm(int $formId, string $layer): array {
		$questions = $this->questions($formId);
		$fields = SchemaSpec::layer($layer)['fields'];
		$mapping = FormsMapper::mapQuestions($questions, $fields);
		$prefix = SchemaSpec::layer($layer)['uid_prefix'];

		$done = array_flip($this->log->processedIds($formId));
		$imported = 0;
		$rejected = 0;
		foreach ($this->newSubmissions($formId, array_keys($done)) as $submission) {
			$uid = $prefix . '-F' . $formId . '-' . $submission['id'];
			$answers = $this->answers($submission['id']);
			$input = FormsMapper::buildInput($fields, $mapping['map'], $answers, $submission['timestamp']);
			$result = RowValidator::validate($fields, $input);

			if ($result['errors'] !== []) {
				$this->record($formId, $submission['id'], 'rejected', $layer, null, implode('; ', $result['errors']));
				$rejected++;
				continue;
			}
			try {
				$this->layers->importFromForm($layer, $uid, $result['data']);
				$this->record($formId, $submission['id'], 'imported', $layer, $uid, null);
				$imported++;
			} catch (\Throwable $e) {
				$this->record($formId, $submission['id'], 'rejected', $layer, null, 'Could not save: ' . $e->getMessage());
				$rejected++;
			}
		}

		return ['imported' => $imported, 'rejected' => $rejected];
	}

	private function layerAccepts(string $layer): bool {
		$spec = SchemaSpec::layer($layer);

		return $spec !== null && !empty($spec['form']) && empty($spec['aggregate_only']);
	}

	private function record(int $formId, int $submissionId, string $status, string $layer, ?string $uid, ?string $reason): void {
		$entry = new IntakeLog();
		$entry->setFormId($formId);
		$entry->setSubmissionId($submissionId);
		$entry->setStatus($status);
		$entry->setLayer($layer);
		$entry->setUid($uid);
		$entry->setReason($reason === null ? null : mb_substr($reason, 0, 1000));
		$entry->setCreatedAt(new \DateTime());
		$this->log->insert($entry);
	}

	/**
	 * @return list<array{id: int, text: string, name: ?string}>
	 */
	private function questions(int $formId): array {
		if (!$this->formsAvailable()) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'text', 'name')
			->from('forms_v2_questions')
			->where($qb->expr()->eq('form_id', $qb->createNamedParameter($formId, IQueryBuilder::PARAM_INT)))
			->orderBy('order', 'ASC');
		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();

		return array_map(fn (array $r) => ['id' => (int)$r['id'], 'text' => (string)$r['text'], 'name' => $r['name'] === null ? null : (string)$r['name']], $rows);
	}

	/**
	 * @param list<int> $skip
	 * @return list<array{id: int, timestamp: int}>
	 */
	private function newSubmissions(int $formId, array $skip): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'timestamp')
			->from('forms_v2_submissions')
			->where($qb->expr()->eq('form_id', $qb->createNamedParameter($formId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC');
		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();

		$out = [];
		$skipSet = array_flip($skip);
		foreach ($rows as $row) {
			if (!isset($skipSet[(int)$row['id']])) {
				$out[] = ['id' => (int)$row['id'], 'timestamp' => (int)$row['timestamp']];
				if (count($out) >= self::BATCH) {
					break;
				}
			}
		}

		return $out;
	}

	/**
	 * @return array<int, list<string>> question id => answers
	 */
	private function answers(int $submissionId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('question_id', 'text')
			->from('forms_v2_answers')
			->where($qb->expr()->eq('submission_id', $qb->createNamedParameter($submissionId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC');
		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();

		$out = [];
		foreach ($rows as $row) {
			$out[(int)$row['question_id']][] = (string)$row['text'];
		}

		return $out;
	}
}
