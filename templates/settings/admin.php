<?php

declare(strict_types=1);

/** @var array $_ */

?>

<div id="timebank-admin-settings" class="section">
	<h2>Time Bank Settings</h2>

	<div class="setting-item">
		<label for="require-admin-approval">
			<input type="checkbox" id="require-admin-approval" name="require_admin_approval"
				<?php echo $_['require_admin_approval'] === 'yes' ? 'checked' : ''; ?>>
			Require admin approval for earning claims
		</label>
		<p class="settings-hint">When enabled, all earning claims must be approved by an administrator before hours are credited.</p>
	</div>

	<div class="setting-item">
		<label for="enable-voting">
			<input type="checkbox" id="enable-voting" name="enable_voting"
				<?php echo $_['enable_voting'] === 'yes' ? 'checked' : ''; ?>>
			Enable community voting for claim approvals
		</label>
		<p class="settings-hint">Allow claims to be sent to community vote for democratic approval.</p>
	</div>

	<div class="setting-item">
		<label for="required-votes">Required votes for approval:</label>
		<input type="number" id="required-votes" name="required_votes"
			value="<?php echo $_['required_votes']; ?>" min="1" max="10">
		<p class="settings-hint">Number of votes needed before a claim is automatically approved or rejected.</p>
	</div>

	<div class="setting-item">
		<label for="allow-negative-balance">
			<input type="checkbox" id="allow-negative-balance" name="allow_negative_balance"
				<?php echo $_['allow_negative_balance'] === 'yes' ? 'checked' : ''; ?>>
			Allow negative balances
		</label>
		<p class="settings-hint">Let users spend more hours than they have earned (borrowing against future work).</p>
	</div>

	<div class="setting-item">
		<label for="max-negative-balance">Maximum negative balance:</label>
		<input type="number" id="max-negative-balance" name="max_negative_balance"
			value="<?php echo $_['max_negative_balance']; ?>" min="0" step="0.5">
		<p class="settings-hint">Maximum hours a user can borrow (0 = no limit when negative balances are allowed).</p>
	</div>
</div>

<style>
#timebank-admin-settings {
	max-width: 700px;
}

#timebank-admin-settings h2 {
	margin-bottom: 20px;
}

.setting-item {
	margin-bottom: 20px;
	padding: 15px;
	background: var(--color-background-hover);
	border-radius: 8px;
}

.setting-item label {
	display: block;
	font-weight: 500;
	margin-bottom: 5px;
}

.setting-item input[type="checkbox"] {
	margin-right: 8px;
}

.setting-item input[type="number"] {
	width: 80px;
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: 4px;
}

.settings-hint {
	color: var(--color-text-lighter);
	font-size: 13px;
	margin-top: 5px;
}
</style>
