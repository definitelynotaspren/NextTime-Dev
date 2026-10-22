<?php

declare(strict_types=1);

namespace OCA\TimeBank\Schema;

/**
 * Layer, field and lookup definitions for the organizer desk.
 *
 * Ported from the mapping app's schema_spec.py. The spec is the source of
 * truth for field visibility: FieldPolicy enforces it on every read.
 *
 * Field shape: name, type, required, visibility, choices, description.
 * Visibility, widest to narrowest: public, facilitator, chapter, organizer.
 */
final class SchemaSpec {

	public const GROUP_ORGANIZER = 'timebank-organizer';
	public const GROUP_FACILITATOR = 'timebank-facilitator';

	/**
	 * Fields that identify a person or give a private location. Must never be
	 * public. SchemaSpecTest fails if any of these is loosened.
	 */
	public const SENSITIVE_FIELDS = [
		'requester_contact',
		'reporter',
		'organizer_contact',
		'contact_internal',
		'notes_internal',
		'lat',
		'lon',
		'submitted',
	];

	public const ISSUE_DOMAINS = [
		'water_cost', 'water_quality', 'housing_cost', 'displacement',
		'development', 'air_quality', 'wildfire_smoke', 'extreme_heat',
		'food_access', 'healthcare_access', 'illness_uptick', 'transit',
		'utility_cost', 'labor', 'childcare', 'immigration_enforcement',
	];

	/** @return array<string, list<string>> */
	public static function lookups(): array {
		return [
			'yes_no' => ['yes', 'no'],
			'issue_domain' => self::ISSUE_DOMAINS,
			'resource_type' => [
				'food_pantry', 'community_fridge', 'hot_meal', 'free_clinic',
				'mental_health', 'shelter', 'warming_center', 'cooling_center',
				'clean_air_center', 'showers', 'laundry', 'phone_charging',
				'wifi', 'legal_aid', 'harm_reduction', 'baby_supplies',
				'clothing', 'tools_library', 'other',
			],
			'cost' => ['free', 'sliding_scale', 'donation', 'low_cost'],
			'site_status' => ['active', 'temporarily_closed', 'closed'],
			'need_or_offer' => ['need', 'offer'],
			'mutual_aid_category' => [
				'food', 'transport', 'housing', 'childcare', 'elder_care',
				'pet_care', 'supplies', 'skills', 'money', 'translation',
				'tech_help', 'other',
			],
			'urgency' => ['today', 'this_week', 'this_month', 'ongoing'],
			'contact_route' => ['discord', 'chapter_organizer', 'hotline', 'in_person'],
			'match_status' => ['open', 'matched', 'closed'],
			'action_type' => [
				'chapter_meeting', 'rally', 'march', 'picket', 'canvass',
				'teach_in', 'training', 'distribution', 'cleanup', 'clinic',
				'public_hearing', 'testimony', 'town_hall', 'social', 'other',
			],
			'location_visibility' => ['exact', 'area_only', 'rsvp_only'],
			'action_status' => ['scheduled', 'cancelled', 'postponed', 'done'],
			'source_type' => ['firsthand', 'news', 'public_record', 'agency_data'],
			'severity' => ['low', 'moderate', 'high', 'critical'],
			'severity_0_3' => ['0', '1', '2', '3'],
			'bill_effect' => ['likely_helps', 'likely_harms', 'mixed', 'unclear'],
			'decision' => ['approve', 'reject', 'unpublish'],
			'visibility' => ['public', 'facilitator', 'chapter', 'organizer'],
		];
	}

	/**
	 * Chapters and the Nextcloud group whose members facilitate each one.
	 * A ZIP may be listed exactly or as a prefix ending in "*".
	 *
	 * @return array<string, array{zips: list<string>, group: string}>
	 */
	public static function chapters(): array {
		return [
			'mt-hood' => [
				'zips' => ['97055', '97049', '97011', '97067', '97028'],
				'group' => 'chapter-mt-hood',
			],
			'gresham' => [
				'zips' => ['97030', '97080', '97233', '97236'],
				'group' => 'chapter-gresham',
			],
		];
	}

	/** @return array<string, array<string, mixed>> */
	public static function layers(): array {
		$districtFields = [];
		foreach (self::ISSUE_DOMAINS as $domain) {
			$districtFields[] = self::field('sev_' . $domain, 'choice', false, 'public', 'severity_0_3',
				str_replace('_', ' ', ucfirst($domain)) . ': 0 = not at all, 3 = severely');
		}

		return [
			'resources' => [
				'group' => 'mutual_aid', 'uid_prefix' => 'RS', 'title' => 'Mutual aid resource sites',
				'geometry' => 'point_exact', 'form' => true,
				'fields' => [
					self::field('name', 'text', true, 'public'),
					self::field('resource_type', 'choice', true, 'public', 'resource_type'),
					self::field('address', 'text', false, 'public', null, 'Geocoded to place the pin.'),
					self::field('zip', 'zip', true, 'public', null, '5-digit ZIP. Decides which chapter reviews the row.'),
					self::field('lat', 'number', false, 'organizer', null, 'Latitude (WGS84). Optional if address given.'),
					self::field('lon', 'number', false, 'organizer', null, 'Longitude (WGS84). Optional if address given.'),
					self::field('hours', 'text', false, 'public', null, 'Plain words, e.g. Tue/Thu 4-7pm.'),
					self::field('cost', 'choice', false, 'public', 'cost'),
					self::field('id_required', 'bool', false, 'public', 'yes_no'),
					self::field('requirements', 'text', false, 'public'),
					self::field('languages', 'text', false, 'public'),
					self::field('accessibility', 'text', false, 'public', null, 'Step-free entry, ASL, seating, etc.'),
					self::field('contact_public', 'text', false, 'public'),
					self::field('status', 'choice', true, 'public', 'site_status'),
					self::field('last_verified', 'date', true, 'public', null, 'Date someone last confirmed this info.'),
					self::field('verified_by', 'text', false, 'chapter', null, 'Who confirmed it.'),
					self::field('contact_internal', 'text', false, 'chapter', null, 'Site coordinator contact.'),
					self::field('notes_internal', 'text', false, 'organizer', null, 'Never published.'),
				],
			],
			'needs_offers' => [
				'group' => 'mutual_aid', 'uid_prefix' => 'NO', 'title' => 'Needs and offers board',
				'geometry' => 'point_snapped', 'snap_res' => 8, 'form' => true,
				'fields' => [
					self::field('need_or_offer', 'choice', true, 'public', 'need_or_offer'),
					self::field('category', 'choice', true, 'public', 'mutual_aid_category'),
					self::field('summary', 'text', true, 'public', null, 'Please leave out names and addresses.'),
					self::field('area_hint', 'text', false, 'public', null, 'Only an area. Never a home address.'),
					self::field('zip', 'zip', true, 'public', null, '5-digit ZIP. Decides which chapter reviews the row.'),
					self::field('lat', 'number', false, 'organizer', null, 'Approx point; snapped before publishing.'),
					self::field('lon', 'number', false, 'organizer', null, 'Approx point; snapped before publishing.'),
					self::field('urgency', 'choice', true, 'public', 'urgency'),
					self::field('posted', 'date', true, 'public'),
					self::field('expires', 'date', true, 'public', null, 'Leave blank for 30 days.'),
					self::field('contact_route', 'choice', true, 'public', 'contact_route', 'Responses go through the chapter, never a personal number.'),
					self::field('status', 'choice', true, 'public', 'match_status'),
					self::field('handler', 'text', false, 'chapter', null, 'Organizer handling it.'),
					self::field('requester_contact', 'text', false, 'organizer', null, 'How an organizer can reach the poster.'),
				],
			],
			'actions' => [
				'group' => 'local_actions', 'uid_prefix' => 'AC', 'title' => 'Local actions and events',
				'geometry' => 'point_by_visibility', 'snap_res' => 8, 'form' => true,
				'fields' => [
					self::field('title', 'text', true, 'public'),
					self::field('action_type', 'choice', true, 'public', 'action_type'),
					self::field('issue_domain', 'choice', false, 'public', 'issue_domain'),
					self::field('description', 'text', false, 'public'),
					self::field('venue_name', 'text', false, 'public'),
					self::field('address', 'text', false, 'chapter', null, 'Shown publicly only if you choose an exact location.'),
					self::field('zip', 'zip', true, 'public', null, '5-digit ZIP. Decides which chapter reviews the row.'),
					self::field('lat', 'number', false, 'chapter'),
					self::field('lon', 'number', false, 'chapter'),
					self::field('location_visibility', 'choice', true, 'public', 'location_visibility'),
					self::field('start', 'datetime', true, 'public', null, 'Date and start time.'),
					self::field('end', 'datetime', false, 'public', null, 'Hidden from maps a day after this (or after the start).'),
					self::field('recurrence', 'text', false, 'public'),
					self::field('host', 'text', false, 'public'),
					self::field('accessibility', 'text', false, 'public'),
					self::field('languages', 'text', false, 'public'),
					self::field('childcare', 'bool', false, 'public', 'yes_no'),
					self::field('rsvp_url', 'url', false, 'public'),
					self::field('status', 'choice', true, 'public', 'action_status'),
					self::field('organizer_contact', 'text', false, 'chapter', null, 'Never published.'),
					self::field('notes_internal', 'text', false, 'organizer'),
				],
			],
			'issue_reports' => [
				'group' => 'critical_issues', 'uid_prefix' => 'IR', 'title' => 'Critical issue reports',
				'geometry' => 'point_snapped', 'snap_res' => 9, 'form' => true,
				'fields' => [
					self::field('issue_domain', 'choice', true, 'public', 'issue_domain'),
					self::field('subtype', 'text', false, 'public'),
					self::field('summary', 'text', true, 'public', null, 'Please leave out personal details.'),
					self::field('address', 'text', false, 'chapter', null, 'Never published.'),
					self::field('zip', 'zip', true, 'public', null, '5-digit ZIP. Decides which chapter reviews the row.'),
					self::field('lat', 'number', false, 'chapter'),
					self::field('lon', 'number', false, 'chapter'),
					self::field('observed', 'date', true, 'public'),
					self::field('source_type', 'choice', true, 'public', 'source_type'),
					self::field('source_url', 'url', false, 'public'),
					self::field('severity', 'choice', false, 'public', 'severity'),
					self::field('reporter', 'text', false, 'organizer', null, 'Never published.'),
				],
			],
			'survey' => [
				'group' => 'critical_issues', 'uid_prefix' => 'SV', 'title' => 'Chapter pain-point survey',
				'geometry' => 'aggregate', 'snap_res' => 7, 'min_count' => 5, 'form' => false,
				// Individual answers are never returned by any endpoint. Only
				// aggregates may be shown, and aggregation is not built yet.
				'aggregate_only' => true,
				'fields' => array_merge([
					self::field('chapter', 'text', true, 'public'),
					self::field('submitted', 'date', true, 'organizer'),
					self::field('zip', 'zip', true, 'organizer'),
					self::field('lat', 'number', false, 'organizer', null, 'Approx home area. Aggregated only.'),
					self::field('lon', 'number', false, 'organizer', null, 'Approx home area. Aggregated only.'),
				], $districtFields),
			],
			'bill_tags' => [
				'group' => 'legislative', 'uid_prefix' => 'BT', 'title' => 'Bills tagged to issue domains',
				'geometry' => 'none', 'form' => false,
				'fields' => [
					self::field('legiscan_bill_id', 'int', true, 'public', null, 'LegiScan bill_id.'),
					self::field('state', 'text', true, 'public', null, 'Two-letter state, or US.'),
					self::field('bill_number', 'text', true, 'public', null, 'e.g. HB 2001.'),
					self::field('session', 'text', false, 'public'),
					self::field('title', 'text', true, 'public'),
					self::field('url', 'url', false, 'public'),
					self::field('issue_domain', 'choice', true, 'public', 'issue_domain', 'One row per domain the bill touches.'),
					self::field('effect', 'choice', true, 'public', 'bill_effect'),
					self::field('rationale', 'text', false, 'public', null, 'One line on why.'),
					self::field('tagged_by', 'text', false, 'facilitator'),
					self::field('tagged_on', 'date', true, 'public'),
				],
			],
		];
	}

	/** @return array<string, mixed>|null */
	public static function layer(string $name): ?array {
		return self::layers()[$name] ?? null;
	}

	/**
	 * System fields every layer gets first. publish gates the public feed.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function systemFields(): array {
		return [
			self::field('uid', 'text', true, 'public', null, 'Stable ID. Never change or reuse it.'),
			self::field('publish', 'bool', true, 'public', 'yes_no', 'Only rows marked yes reach the public map.'),
		];
	}

	/** @return list<array<string, mixed>> system fields followed by the layer's own fields */
	public static function allFields(string $layer): array {
		$spec = self::layer($layer);
		return $spec === null ? [] : array_merge(self::systemFields(), $spec['fields']);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function field(
		string $name,
		string $type,
		bool $required,
		string $visibility,
		?string $choices = null,
		string $description = '',
	): array {
		return [
			'name' => $name,
			'type' => $type,
			'required' => $required,
			'visibility' => $visibility,
			'choices' => $choices,
			'description' => $description,
		];
	}
}
