<?php

declare(strict_types=1);

namespace OCA\TimeBank\Service;

use OCA\TimeBank\Db\CategoryMapper;
use OCA\TimeBank\Db\Earning;
use OCA\TimeBank\Db\EarningMapper;
use OCA\TimeBank\Db\Vote;
use OCA\TimeBank\Db\VoteMapper;
use OCP\AppFramework\Db\DoesNotExistException;

class EarningService {

	private EarningMapper $earningMapper;
	private CategoryMapper $categoryMapper;
	private VoteMapper $voteMapper;
	private BalanceService $balanceService;
	private TransactionService $transactionService;

	public function __construct(
		EarningMapper $earningMapper,
		CategoryMapper $categoryMapper,
		VoteMapper $voteMapper,
		BalanceService $balanceService,
		TransactionService $transactionService,
	) {
		$this->earningMapper = $earningMapper;
		$this->categoryMapper = $categoryMapper;
		$this->voteMapper = $voteMapper;
		$this->balanceService = $balanceService;
		$this->transactionService = $transactionService;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function submitClaim(string $userId, array $data): Earning {
		$category = $this->categoryMapper->find((int)$data['categoryId']);

		$earning = new Earning();
		$earning->setUserId($userId);
		$earning->setCategoryId((int)$data['categoryId']);
		$earning->setHoursClaimed((string)$data['hoursClaimed']);

		$actualHours = (float)$data['hoursClaimed'] * (float)$category->getEarnRate();
		$earning->setActualHoursEarned((string)$actualHours);

		$earning->setDescription($data['description']);
		$earning->setStatus('pending');

		if (isset($data['evidenceFileId'])) {
			$earning->setEvidenceFileId((int)$data['evidenceFileId']);
		}

		$earning->setCreatedAt(new \DateTime());

		return $this->earningMapper->insert($earning);
	}

	/**
	 * @return Earning[]
	 */
	public function getPendingClaims(): array {
		return $this->earningMapper->findByStatus('pending');
	}

	/**
	 * @return array<array-key, mixed>
	 */
	public function getVotingClaims(): array {
		$claims = $this->earningMapper->findByStatus('voting');

		return array_map(function ($claim) {
			$votes = $this->voteMapper->findByEarning($claim->getId());
			$data = $claim->jsonSerialize();
			$data['votes'] = array_map(fn ($v) => $v->jsonSerialize(), $votes);
			$data['approveCount'] = count(array_filter($votes, fn ($v) => $v->getVote() === 'approve'));
			$data['rejectCount'] = count(array_filter($votes, fn ($v) => $v->getVote() === 'reject'));
			return $data;
		}, $claims);
	}

	/**
	 * @return Earning[]
	 */
	public function getUserClaims(string $userId): array {
		return $this->earningMapper->findByUser($userId);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function approveClaim(int $earningId, string $adminUserId): Earning {
		$earning = $this->earningMapper->find($earningId);

		$earning->setStatus('approved');
		$earning->setApproverId($adminUserId);
		$earning->setApprovedAt(new \DateTime());

		$this->earningMapper->update($earning);

		$this->balanceService->addHours(
			$earning->getUserId(),
			(float)$earning->getActualHoursEarned()
		);

		$this->transactionService->recordEarning($earning);

		return $earning;
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function rejectClaim(int $earningId, string $adminUserId, string $reason): Earning {
		$earning = $this->earningMapper->find($earningId);

		$earning->setStatus('rejected');
		$earning->setApproverId($adminUserId);
		$earning->setRejectionReason($reason);

		return $this->earningMapper->update($earning);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function sendToVoting(int $earningId, string $adminUserId): Earning {
		$earning = $this->earningMapper->find($earningId);

		$earning->setStatus('voting');
		$earning->setApproverId($adminUserId);

		return $this->earningMapper->update($earning);
	}

	/**
	 * @return array{complete: bool, result: string|null}
	 */
	public function recordVote(int $earningId, string $voterId, string $vote, ?string $comment = null): array {
		if ($this->voteMapper->hasVoted($earningId, $voterId)) {
			throw new \Exception('You have already voted on this claim');
		}

		$voteRecord = new Vote();
		$voteRecord->setEarningId($earningId);
		$voteRecord->setVoterId($voterId);
		$voteRecord->setVote($vote);
		if ($comment) {
			$voteRecord->setComment($comment);
		}
		$voteRecord->setCreatedAt(new \DateTime());

		$this->voteMapper->insert($voteRecord);

		$votes = $this->voteMapper->findByEarning($earningId);
		$requiredVotes = 3;

		if (count($votes) >= $requiredVotes) {
			$approvals = count(array_filter($votes, fn ($v) => $v->getVote() === 'approve'));
			$rejections = count($votes) - $approvals;

			if ($approvals > $rejections) {
				$this->approveClaim($earningId, 'voting-system');
				return ['complete' => true, 'result' => 'approved'];
			} else {
				$this->rejectClaim($earningId, 'voting-system', 'Rejected by vote');
				return ['complete' => true, 'result' => 'rejected'];
			}
		}

		return ['complete' => false, 'result' => null];
	}
}
