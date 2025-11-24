<?php

declare(strict_types=1);

return [
	'routes' => [
		// Page routes
		['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],

		// Request API
		['name' => 'request#index', 'url' => '/api/requests', 'verb' => 'GET'],
		['name' => 'request#myRequests', 'url' => '/api/requests/my', 'verb' => 'GET'],
		['name' => 'request#show', 'url' => '/api/requests/{id}', 'verb' => 'GET'],
		['name' => 'request#create', 'url' => '/api/requests', 'verb' => 'POST'],
		['name' => 'request#update', 'url' => '/api/requests/{id}', 'verb' => 'PUT'],
		['name' => 'request#complete', 'url' => '/api/requests/{id}/complete', 'verb' => 'POST'],
		['name' => 'request#cancel', 'url' => '/api/requests/{id}/cancel', 'verb' => 'POST'],

		// Volunteer API
		['name' => 'volunteer#offer', 'url' => '/api/requests/{requestId}/volunteer', 'verb' => 'POST'],
		['name' => 'volunteer#withdraw', 'url' => '/api/volunteers/{id}', 'verb' => 'DELETE'],
		['name' => 'volunteer#accept', 'url' => '/api/volunteers/{id}/accept', 'verb' => 'POST'],
		['name' => 'volunteer#decline', 'url' => '/api/volunteers/{id}/decline', 'verb' => 'POST'],
		['name' => 'volunteer#myOffers', 'url' => '/api/volunteers/my', 'verb' => 'GET'],

		// Comment API
		['name' => 'comment#create', 'url' => '/api/requests/{requestId}/comments', 'verb' => 'POST'],
		['name' => 'comment#delete', 'url' => '/api/comments/{id}', 'verb' => 'DELETE'],

		// Earning API
		['name' => 'earning#claim', 'url' => '/api/earnings/claim', 'verb' => 'POST'],
		['name' => 'earning#myClaims', 'url' => '/api/earnings/my', 'verb' => 'GET'],
		['name' => 'earning#pending', 'url' => '/api/earnings/pending', 'verb' => 'GET'],
		['name' => 'earning#voting', 'url' => '/api/earnings/voting', 'verb' => 'GET'],
		['name' => 'earning#approve', 'url' => '/api/earnings/{id}/approve', 'verb' => 'POST'],
		['name' => 'earning#reject', 'url' => '/api/earnings/{id}/reject', 'verb' => 'POST'],
		['name' => 'earning#sendToVote', 'url' => '/api/earnings/{id}/send-to-vote', 'verb' => 'POST'],
		['name' => 'earning#vote', 'url' => '/api/earnings/{id}/vote', 'verb' => 'POST'],

		// Ledger API
		['name' => 'ledger#index', 'url' => '/api/ledger', 'verb' => 'GET'],
		['name' => 'ledger#myTransactions', 'url' => '/api/ledger/my', 'verb' => 'GET'],
		['name' => 'ledger#userTransactions', 'url' => '/api/ledger/user/{userId}', 'verb' => 'GET'],

		// Balance API
		['name' => 'balance#my', 'url' => '/api/balance/my', 'verb' => 'GET'],
		['name' => 'balance#all', 'url' => '/api/balance/all', 'verb' => 'GET'],
		['name' => 'balance#adjust', 'url' => '/api/balance/adjust', 'verb' => 'POST'],

		// Category API
		['name' => 'category#index', 'url' => '/api/categories', 'verb' => 'GET'],
		['name' => 'category#show', 'url' => '/api/categories/{id}', 'verb' => 'GET'],
		['name' => 'category#create', 'url' => '/api/categories', 'verb' => 'POST'],
		['name' => 'category#update', 'url' => '/api/categories/{id}', 'verb' => 'PUT'],
		['name' => 'category#destroy', 'url' => '/api/categories/{id}', 'verb' => 'DELETE'],
	],
];
