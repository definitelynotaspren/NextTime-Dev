<?php

declare(strict_types=1);

/** @var array $_ */

?>

<div id="timebank-admin-settings" class="section">
	<h2>NextTime: how decisions are made</h2>
	<p class="settings-hint">
		These rules are fixed on purpose, so nobody can quietly change how hours work. Anything an admin can
		change inside NextTime is written to the public governance log that every member can read.
	</p>

	<ul class="rules">
		<li><strong>Approving hours.</strong> An admin approves a claim, or sends it to a community vote. Three votes
			from other members decide a vote. Nobody can approve, reject, send or vote on their own claim.</li>
		<li><strong>Balances.</strong> A balance can never go below zero.</li>
		<li><strong>Manual adjustments.</strong> Up to <?php p($_['adjustment_threshold']); ?> hours one admin can adjust a
			balance. Larger ones need a second person: another admin, or a facilitator of the member's chapter.
			Nobody adjusts their own balance. Change the threshold, with a reason, under Adjustments in NextTime.</li>
		<li><strong>Categories.</strong> Admins manage them in NextTime. A category in use cannot be deleted; archive it
			instead.</li>
		<li><strong>Comments.</strong> Three flags from different members hide a comment. Admins and the
			<code>timebank-organizer</code> group review flagged comments.</li>
	</ul>

	<p>
		<a class="button" href="<?php p(\OCP\Server::get(\OCP\IURLGenerator::class)->linkToRoute('timebank.page.index')); ?>#/governance">Open the governance log</a>
	</p>
</div>

<style>
#timebank-admin-settings {
	max-width: 700px;
}

#timebank-admin-settings .rules li {
	margin-bottom: 10px;
}

#timebank-admin-settings .settings-hint {
	color: var(--color-text-maxcontrast);
}
</style>
