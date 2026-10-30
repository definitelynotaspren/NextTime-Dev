<?php

declare(strict_types=1);

namespace OCA\TimeBank\Service;

use OCA\TimeBank\Chapter\Electorate;
use OCA\TimeBank\Db\CategoryMapper;
use OCA\TimeBank\Db\Earning;
use OCA\TimeBank\Db\EarningMapper;
use OCA\TimeBank\Db\Vote;
use OCA\TimeBank\Db\VoteMapper;
use OCA\TimeBank\Notification\ClaimNotifier;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\IRootFolder;

class EarningService {

	private EarningMapper $earningMapper;
	private CategoryMapper $categoryMapper;
	private VoteMapper $voteMapper;
	private BalanceService $balanceService;
	private TransactionService $transactionService;
	private ApproverPolicy $policy;
	private ClaimNotifier $notifier;

	public function __construct(
		EarningMapper $earningMapper,
		CategoryMapper $categoryMapper,
		VoteMapper $voteMapper,
		BalanceService $balanceService,
		TransactionService $transactionService,
		ApproverPolicy $policy,
		ClaimNotifier $notifier,
		private IRootFolder $rootFolder,
		private Electorate $electorate,
	) {
		$this->earningMapper = $earningMapper;
		$this->categoryMapper = $categoryMapper;
		$this->voteMapper = $voteMapper;
		$this->balanceService = $balanceService;
		$this->transactionService = $transactionService;
		$this->policy = $policy;
		$this->notifier = $notifier;
	}

	/**
	 * @param array<string, mixed> $data
	 * @throws InvalidStateException when the evidence is not a file the claimant can read
	 */
	public function submitClaim(string $userId, array $data): Earning {
		$evidenceFileId = Input::int($data['evidenceFileId'] ?? null);
		if ($evidenceFileId !== null && !$this->canRead($userId, $evidenceFileId)) {
			throw new InvalidStateException('The evidence file was not found in your files');
		}

		$category = $this->categoryMapper->find((int)$data['categoryId']);

		$earning = new Earning();
		$earning->setUserId($userId);
		$earning->setCategoryId((int)$data['categoryId']);
		$earning->setHoursClaimed((string)$data['hoursClaimed']);

		$actualHours = (float)$data['hoursClaimed'] * (float)$category->getEarnRate();
		$earning->setActualHoursEarned((string)$actualHours);

		$earning->setDescription(Input::string($data['description'] ?? null) ?? '');
		$earning->setStatus('pending');

		$earning->setEvidenceFileId($evidenceFileId);

		$earning->setCreatedAt(new \DateTime());
		$earning = $this->earningMapper->insert($earning);
		$this->notifier->submitted($earning);

		return $earning;
	}

	/**
	 * Every pending claim, for everyone to see. canDecide says whether this person may approve,
	 * reject or send it to a vote, with the capacity they would act in (admin, facilitator of a
	 * chapter, national organizer). Never on their own claims.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function getPendingClaims(string $viewerId): array {
		$rows = [];
		foreach ($this->earningMapper->findByStatus('pending') as $claim) {
			$capacity = $this->policy->capacity($viewerId, $claim->getUserId());
			$row = $claim->jsonSerialize();
			$row['canDecide'] = $capacity !== null;
			$row['decidingAs'] = $capacity['as'] ?? null;
			$row['decidingChapter'] = $capacity['chapter'] ?? null;
			$rows[] = $row;
		}

		return $rows;
	}

	/**
	 * Every claim in a vote, with its votes, for everyone to see. canVote says whether this person
	 * may still vote: they are in the claimant's electorate (see Electorate) and have not voted.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function getVotingClaims(string $viewerId): array {
		$rows = [];
		foreach ($this->earningMapper->findByStatus('voting') as $claim) {
			$votes = $this->voteMapper->findByEarning($claim->getId());
			$voted = in_array($viewerId, array_map(fn ($v) => $v->getVoterId(), $votes), true);
			$data = $claim->jsonSerialize();
			$data['votes'] = array_map(fn ($v) => $v->jsonSerialize(), $votes);
			$data['approveCount'] = count(array_filter($votes, fn ($v) => $v->getVote() === 'approve'));
			$data['rejectCount'] = count(array_filter($votes, fn ($v) => $v->getVote() === 'reject'));
			$data['canVote'] = !$voted && $this->mayVote($claim, $viewerId);
			$rows[] = $data;
		}

		return $rows;
	}

	/** Decision 2: chapter members of the claimant's state vote (see Electorate), never the claimant. */
	private function mayVote(Earning $claim, string $voterId): bool {
		return in_array($voterId, $this->electorate->forMember($claim->getUserId()), true);
	}

	/**
	 * @return Earning[]
	 */
	public function getUserClaims(string $userId): array {
		return $this->earningMapper->findByUser($userId);
	}

	/**
	 * Approves a pending claim. Nobody can approve their own claim: someone else always
	 * looks at it.
	 *
	 * @throws DoesNotExistException
	 * @throws InvalidStateException
	 * @throws NotAuthorizedException
	 */
	public function approveClaim(int $earningId, string $adminUserId): Earning {
		$earning = $this->earningMapper->find($earningId);
		$this->requireDecidable($earning, $adminUserId, 'pending', true);

		return $this->applyApproval($earning, $adminUserId);
	}

	private function applyApproval(Earning $earning, string $approverId): Earning {
		$earning->setStatus('approved');
		$earning->setApproverId($approverId);
		$earning->setApprovedAt(new \DateTime());

		$this->earningMapper->update($earning);

		$this->balanceService->addHours(
			$earning->getUserId(),
			(float)$earning->getActualHoursEarned()
		);

		$this->transactionService->recordEarning($earning);
		$this->notifier->decided($earning);

		return $earning;
	}

	/**
	 * @throws DoesNotExistException
	 * @throws InvalidStateException
	 * @throws NotAuthorizedException
	 */
	public function rejectClaim(int $earningId, string $adminUserId, string $reason): Earning {
		$earning = $this->earningMapper->find($earningId);
		$this->requireDecidable($earning, $adminUserId, 'pending', true);

		return $this->applyRejection($earning, $adminUserId, $reason);
	}

	private function applyRejection(Earning $earning, string $approverId, string $reason): Earning {
		$earning->setStatus('rejected');
		$earning->setApproverId($approverId);
		$earning->setRejectionReason($reason);
		$earning = $this->earningMapper->update($earning);
		$this->notifier->decided($earning);

		return $earning;
	}

	/**
	 * @throws DoesNotExistException
	 * @throws InvalidStateException
	 * @throws NotAuthorizedException
	 */
	public function sendToVoting(int $earningId, string $adminUserId): Earning {
		$earning = $this->earningMapper->find($earningId);
		$this->requireDecidable($earning, $adminUserId, 'pending', true);

		$earning->setStatus('voting');
		$earning->setApproverId($adminUserId);
		$earning = $this->earningMapper->update($earning);
		$this->notifier->decided($earning);

		return $earning;
	}

	/**
	 * Records a vote for an earning claim and automatically resolves the claim
	 * if the required number of votes has been reached.
	 *
	 * @return array{complete: bool, result: string|null}
	 * @throws \Exception if the voter has already voted on this claim
	 */
	public function recordVote(int $earningId, string $voterId, string $vote, ?string $comment = null): array {
		$this->validateVoterEligibility($earningId, $voterId);
		$this->createVoteRecord($earningId, $voterId, $vote, $comment);

		$votingResult = $this->checkVotingComplete($earningId);

		if ($votingResult['complete'] && $votingResult['result'] !== null) {
			$this->resolveClaimByVote($earningId, $votingResult['result']);
		}

		return $votingResult;
	}

	/**
	 * Validates that a voter hasn't already voted on a claim.
	 *
	 * @throws \Exception if the voter has already voted, or the claim is not open for voting
	 */
	private function validateVoterEligibility(int $earningId, string $voterId): void {
		$earning = $this->earningMapper->find($earningId);
		$this->requireDecidable($earning, $voterId, 'voting', false);
		if (!$this->mayVote($earning, $voterId)) {
			throw new NotAuthorizedException('Only chapter members of this member\'s state can vote on this claim');
		}

		if ($this->voteMapper->hasVoted($earningId, $voterId)) {
			throw new \Exception('You have already voted on this claim');
		}
	}

	/**
	 * Creates and persists a vote record.
	 */
	private function createVoteRecord(int $earningId, string $voterId, string $vote, ?string $comment): void {
		$voteRecord = new Vote();
		$voteRecord->setEarningId($earningId);
		$voteRecord->setVoterId($voterId);
		$voteRecord->setVote($vote);

		if ($comment !== null && $comment !== '') {
			$voteRecord->setComment($comment);
		}

		$voteRecord->setCreatedAt(new \DateTime());
		$this->voteMapper->insert($voteRecord);
	}

	/**
	 * Checks if voting is complete and determines the result.
	 *
	 * @return array{complete: bool, result: string|null}
	 */
	private function checkVotingComplete(int $earningId): array {
		$votes = $this->voteMapper->findByEarning($earningId);
		$requiredVotes = 3;

		if (count($votes) < $requiredVotes) {
			return ['complete' => false, 'result' => null];
		}

		$approvals = count(array_filter($votes, fn ($v) => $v->getVote() === 'approve'));
		$rejections = count($votes) - $approvals;

		$result = $approvals > $rejections ? 'approved' : 'rejected';

		return ['complete' => true, 'result' => $result];
	}

	/**
	 * Applies the voting result to the claim.
	 *
	 * @throws DoesNotExistException
	 */
	private function resolveClaimByVote(int $earningId, string $result): void {
		$earning = $this->earningMapper->find($earningId);
		if ($result === 'approved') {
			$this->applyApproval($earning, 'voting-system');
		} else {
			$this->applyRejection($earning, 'voting-system', 'Rejected by vote');
		}
	}

	/**
	 * Whether the file is in the claimant's own files or shared with them, and readable. A file id
	 * is just a number, so without this a claim could point at anyone's file.
	 */
	private function canRead(string $userId, int $fileId): bool {
		try {
			foreach ($this->rootFolder->getUserFolder($userId)->getById($fileId) as $node) {
				if ($node->isReadable()) {
					return true;
				}
			}
		} catch (\Exception) {
			// No such user folder, or storage unavailable: treat as not readable.
		}

		return false;
	}

	/**
	 * The claim must be in the expected state, and the person deciding must not be the one
	 * who made the claim, whoever they are. Approving, rejecting and sending to a vote also
	 * need to be an approver for the claimant (see ApproverPolicy). Who may vote is checked
	 * separately, in validateVoterEligibility().
	 *
	 * @throws InvalidStateException
	 * @throws NotAuthorizedException
	 */
	private function requireDecidable(Earning $earning, string $deciderId, string $expectedStatus, bool $needsApprover): void {
		if ($earning->getUserId() === $deciderId) {
			throw new NotAuthorizedException('You cannot decide on your own claim. Someone else has to look at it.');
		}
		if ($needsApprover && !$this->policy->mayDecide($deciderId, $earning->getUserId())) {
			throw new NotAuthorizedException('Only an admin, a facilitator of this member\'s state, or a national organizer can decide on this claim');
		}
		if ($earning->getStatus() !== $expectedStatus) {
			throw new InvalidStateException('This claim is ' . $earning->getStatus() . ', so that is not possible now');
		}
	}
}
