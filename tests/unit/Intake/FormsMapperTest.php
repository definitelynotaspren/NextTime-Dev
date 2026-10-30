<?php

declare(strict_types=1);

namespace OCA\TimeBank\Tests\Unit\Intake;

use OCA\TimeBank\Intake\FormsMapper;
use OCA\TimeBank\Schema\RowValidator;
use OCA\TimeBank\Schema\SchemaSpec;
use PHPUnit\Framework\TestCase;

/**
 * What a phone form's answers become, without a database.
 */
final class FormsMapperTest extends TestCase {

	/** @return list<array<string, mixed>> */
	private function fields(): array {
		return SchemaSpec::layer('needs_offers')['fields'];
	}

	/** @return list<array{id: int, text: string, name: ?string}> */
	private function questions(): array {
		return [
			['id' => 1, 'text' => 'Need or offer', 'name' => null],
			['id' => 2, 'text' => 'What kind of help?', 'name' => 'category'],
			['id' => 3, 'text' => 'Summary', 'name' => null],
			['id' => 4, 'text' => 'ZIP', 'name' => null],
			['id' => 5, 'text' => 'Urgency', 'name' => null],
			['id' => 6, 'text' => 'How should we reach you?', 'name' => 'requester_contact'],
			['id' => 7, 'text' => 'Contact route', 'name' => null],
			['id' => 8, 'text' => 'Your favourite colour', 'name' => null],
		];
	}

	public function testQuestionsFeedFieldsByInternalNameOrTextAndTheRestAreIgnored(): void {
		$result = FormsMapper::mapQuestions($this->questions(), $this->fields());

		$this->assertSame([1 => 'need_or_offer', 2 => 'category', 3 => 'summary', 4 => 'zip', 5 => 'urgency', 6 => 'requester_contact', 7 => 'contact_route'], $result['map']);
		$this->assertSame(['Your favourite colour'], $result['ignored']);
	}

	public function testAnAnswerToAnIgnoredQuestionIsNeverCarried(): void {
		$map = FormsMapper::mapQuestions($this->questions(), $this->fields())['map'];
		$input = FormsMapper::buildInput($this->fields(), $map, [8 => ['Blue'], 3 => ['Ride to dialysis']], 1_700_000_000);

		$this->assertStringNotContainsString('Blue', json_encode($input));
	}

	public function testChoiceAnswersAreMatchedToTheLookupAndDefaultsAreFilled(): void {
		$map = FormsMapper::mapQuestions($this->questions(), $this->fields())['map'];
		$input = FormsMapper::buildInput($this->fields(), $map, [
			1 => ['Need'], 2 => ['Transportation'], 3 => ['Ride to dialysis on Tuesdays'], 4 => ['80202'],
			5 => ['This week'], 6 => ['text 555-0100'], 7 => ['Chapter organizer'],
		], 1_700_000_000);

		$this->assertSame('need', $input['need_or_offer']);
		$this->assertSame('this_week', $input['urgency']);
		$this->assertSame('chapter_organizer', $input['contact_route']);
		$this->assertSame('2023-11-14', $input['posted']);
		$this->assertSame('2023-12-14', $input['expires'], '30 days when the form does not ask');
		$this->assertSame('open', $input['status']);
		$this->assertSame('no', $input['publish']);
	}

	public function testAChoiceMatchesOnASingleClearPrefix(): void {
		$questions = [['id' => 1, 'text' => 'Category', 'name' => null]];
		$map = FormsMapper::mapQuestions($questions, $this->fields())['map'];

		$this->assertSame('transport', FormsMapper::buildInput($this->fields(), $map, [1 => ['Transportation']], 0)['category']);
		$this->assertSame('elder_care', FormsMapper::buildInput($this->fields(), $map, [1 => ['Elder care']], 0)['category']);
		$this->assertSame('banana', FormsMapper::buildInput($this->fields(), $map, [1 => ['Banana']], 0)['category'], 'no match is left for the validator to refuse');
	}

	public function testAWellFormedSubmissionPassesTheDeskRules(): void {
		$map = FormsMapper::mapQuestions($this->questions(), $this->fields())['map'];
		$input = FormsMapper::buildInput($this->fields(), $map, [
			1 => ['Offer'], 2 => ['Transportation'], 3 => ['Weekend rides'], 4 => ['80202'],
			5 => ['Ongoing'], 7 => ['In person'],
		], 1_700_000_000);

		$result = RowValidator::validate($this->fields(), $input);

		$this->assertSame([], $result['errors']);
		$this->assertFalse($result['publish'], 'a phone submission is never published by itself');
	}

	public function testMissingRequiredAnswersAreReportedByTheValidator(): void {
		$map = FormsMapper::mapQuestions($this->questions(), $this->fields())['map'];
		$input = FormsMapper::buildInput($this->fields(), $map, [1 => ['Need'], 3 => ['Help'], 4 => ['8020']], 1_700_000_000);

		$errors = RowValidator::validate($this->fields(), $input)['errors'];

		$this->assertContains('zip must be a 5-digit ZIP', $errors);
		$this->assertContains('category is required', $errors);
	}

	public function testAFormMissingAQuestionForARequiredFieldIsFlaggedUpFront(): void {
		$questions = [['id' => 1, 'text' => 'Summary', 'name' => null]];
		$map = FormsMapper::mapQuestions($questions, $this->fields())['map'];

		$missing = FormsMapper::missingRequired($this->fields(), $map);

		$this->assertContains('zip', $missing);
		$this->assertContains('category', $missing);
		$this->assertNotContains('posted', $missing, 'defaults cover posted, expires and status');
	}

	public function testMultipleAnswersToATextQuestionAreJoined(): void {
		$questions = [['id' => 1, 'text' => 'Summary', 'name' => null]];
		$map = FormsMapper::mapQuestions($questions, $this->fields())['map'];

		$input = FormsMapper::buildInput($this->fields(), $map, [1 => ['Groceries', 'Ride']], 1_700_000_000);

		$this->assertSame('Groceries, Ride', $input['summary']);
	}
}
