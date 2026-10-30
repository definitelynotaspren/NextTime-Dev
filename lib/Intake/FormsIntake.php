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
use OCP\IAppConfig;
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
 *
 * @psalm-import-type Layer from SchemaSpec
 */
class FormsIntake {

	public const string CONFIG_KEY = 'forms_intake';
	/** Submissions taken per form per run, so one big backlog cannot stall the job. */
	private const int BATCH = 100;

	public function __construct(
		private IDBConnection $db,
		private IAppConfig $config,
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
		$decoded = json_decode($this->config->getValueString(Application::APP_ID, self::CONFIG_KEY, '[]'), true);
		$out = [];
		foreach (is_array($decoded) ? $decoded : [] as $entry) {
			if (is_array($entry) && isset($entry['formId'], $entry['layer']) && $this->layerAccepts((string)$entry['layer'])) {
				$out[] = ['formId' => (int)$entry['formId'], 'layer' => (string)$entry['layer']];
			}
		}

		return $out;
	}

	/**
	 * @param list<mixed> $forms entries of {formId, layer}, straight from the request
	 * @throws InvalidStateException
	 */
	public function setEnabled(string $actorId, array $forms): void {
		$clean = [];
		foreach ($forms as $entry) {
			$layer = is_array($entry) ? ($entry['layer'] ?? null) : null;
			$layer = is_string($layer) ? $layer : '';
			$formId = is_array($entry) ? ($entry['formId'] ?? null) : null;
			$formId = is_numeric($formId) ? (int)$formId : 0;
			if (!$this->layerAccepts($layer)) {
				throw new InvalidStateException("The layer $layer does not take form submissions");
			}
			if ($formId <= 0 || $this->questions($formId) === []) {
				throw new InvalidStateException("Form $formId was not found or has no questions");
			}
			$clean[$formId] = ['formId' => $formId, 'layer' => $layer];
		}

		$before = $this->enabled();
		$this->config->setValueString(Application::APP_ID, self::CONFIG_KEY, json_encode(array_values($clean), JSON_THROW_ON_ERROR));
		$this->governance->record($actorId, 'setting.forms_intake', null, [
			'from' => array_column($before, 'formId'),
			'to' => array_keys($clean),
		]);
	}

	/**
	 * The forms that were added, with their titles. Only forms someone added by their link
	 * are listed, never every form on the server, so nobody sees titles of forms they were
	 * not given.
	 *
	 * @return list<array{formId: int, layer: string, title: string, exists: bool}>
	 */
	public function addedForms(): array {
		$out = [];
		foreach ($this->enabled() as $entry) {
			$info = $this->formInfo($entry['formId']);
			$out[] = $entry + ['title' => $info['title'] ?? '', 'exists' => $info !== null];
		}

		return $out;
	}

	/**
	 * Finds the form a pasted link points to. The link must be on this server and carry the
	 * form's code: the Forms share link (.../apps/forms/s/<code>) or the address of the form
	 * itself (.../apps/forms/<code>, also /edit and /results). The bare code works too.
	 *
	 * @return array{id: int, title: string, questions: int, owner: string}
	 * @throws InvalidStateException with a message that says what to paste instead
	 */
	public function findByLink(string $link, string $serverHost): array {
		$link = trim($link);
		if ($link === '') {
			throw new InvalidStateException('Paste the form\'s link first');
		}
		if (!$this->formsAvailable()) {
			throw new InvalidStateException('The Nextcloud Forms app is not installed or enabled on this server');
		}

		$hash = self::hashFromLink($link, $serverHost);
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'title', 'owner_id', 'state')
			->from('forms_v2_forms')
			->where($qb->expr()->eq('hash', $qb->createNamedParameter($hash)));
		$result = $qb->executeQuery();
		/** @var array<string, mixed>|false $row */
		$row = $result->fetch();
		$result->closeCursor();

		if ($row === false) {
			throw new InvalidStateException('No form on this server has that code. Copy the link again from the Forms app.');
		}
		if ((int)$row['state'] === 2) {
			throw new InvalidStateException('That form is archived. Restore it in the Forms app first.');
		}

		return [
			'id' => (int)$row['id'],
			'title' => (string)$row['title'],
			'questions' => count($this->questions((int)$row['id'])),
			'owner' => (string)$row['owner_id'],
		];
	}

	/**
	 * The form's code from a pasted link or from the bare code.
	 *
	 * @throws InvalidStateException
	 */
	public static function hashFromLink(string $link, string $serverHost): string {
		$link = trim($link);
		if (preg_match('/^[A-Za-z0-9]{8,64}$/', $link) === 1) {
			return $link;
		}
		if (!preg_match('#^https?://#i', $link)) {
			throw new InvalidStateException('That is not a link. Copy the form\'s link from the Forms app (Share, then the share link), for example https://your-server/index.php/apps/forms/s/AbCdEf123456');
		}

		$parts = parse_url($link);
		if ($parts === false) {
			throw new InvalidStateException('That link could not be read. Copy it again from the Forms app.');
		}
		$host = $parts['host'] ?? '';
		$expected = preg_replace('/:\d+$/', '', $serverHost) ?? $serverHost;
		if ($host === '' || strcasecmp($host, $expected) !== 0) {
			throw new InvalidStateException("That link is on $host, not on this server ($expected). Only forms in this Nextcloud's Forms app can be added.");
		}
		if (!preg_match('#/apps/forms/(?:s/)?([A-Za-z0-9]{8,64})(?:/|$)#', $parts['path'] ?? '', $m)) {
			throw new InvalidStateException('That link is not to a form. Use the Forms share link (.../apps/forms/s/<code>) or the address of the form while it is open in the Forms app.');
		}

		return $m[1];
	}

	/**
	 * @return array{title: string}|null
	 */
	private function formInfo(int $formId): ?array {
		if (!$this->formsAvailable()) {
			return null;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('title')
			->from('forms_v2_forms')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($formId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		/** @var array<string, mixed>|false $row */
		$row = $result->fetch();
		$result->closeCursor();

		return $row === false ? null : ['title' => (string)$row['title']];
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

		$fields = $this->spec($layer)['fields'];
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
		$spec = $this->spec($layer);
		$fields = $spec['fields'];
		$mapping = FormsMapper::mapQuestions($questions, $fields);
		$prefix = $spec['uid_prefix'];

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

	/**
	 * @return Layer
	 * @throws InvalidStateException
	 */
	private function spec(string $layer): array {
		$spec = SchemaSpec::layer($layer);
		if ($spec === null) {
			throw new InvalidStateException("Unknown layer $layer");
		}

		return $spec;
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
		/** @var list<array<string, mixed>> $rows */
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
		/** @var list<array<string, mixed>> $rows */
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
		/** @var list<array<string, mixed>> $rows */
		$rows = $result->fetchAll();
		$result->closeCursor();

		$out = [];
		foreach ($rows as $row) {
			$out[(int)$row['question_id']][] = (string)$row['text'];
		}

		return $out;
	}
}
