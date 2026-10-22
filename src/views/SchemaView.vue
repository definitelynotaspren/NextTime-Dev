<!--
SPDX-FileCopyrightText: 2026 NextTime contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
import { ref, onMounted } from 'vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'

interface Field {
	name: string
	type: string
	required: boolean
	visibility: string
	choices: string | null
	description: string
}

interface Layer {
	group: string
	uid_prefix: string
	title: string
	geometry: string
	form?: boolean
	aggregate_only?: boolean
	fields: Field[]
}

interface Schema {
	systemFields: Field[]
	layers: Record<string, Layer>
	lookups: Record<string, string[]>
	chapters: { slug: string, zips: string[] }[]
}

const schema = ref<Schema | null>(null)
const error = ref<string | null>(null)
const loading = ref(true)
const openLayer = ref<string | null>(null)

onMounted(async () => {
	try {
		const { data } = await axios.get<Schema>(generateUrl('/apps/timebank/api/schema'))
		schema.value = data
	} catch (e: unknown) {
		const err = e as { response?: { data?: { error?: string } } }
		error.value = err.response?.data?.error || 'Could not load the schema'
	} finally {
		loading.value = false
	}
})

const toggle = (name: string) => {
	openLayer.value = openLayer.value === name ? null : name
}
</script>

<template>
	<div class="schema-view">
		<h1>Data schema</h1>
		<p class="intro">
			Every layer and field, with who may see it. Fields marked organizer or chapter are
			never sent to people outside those roles.
		</p>

		<NcLoadingIcon v-if="loading" :size="44" />

		<NcEmptyContent v-else-if="error" name="Not available">
			<template #description>
				{{ error }}
			</template>
		</NcEmptyContent>

		<template v-else-if="schema">
			<section v-for="(layer, name) in schema.layers" :key="name" class="layer">
				<button class="layer-head" type="button" @click="toggle(String(name))">
					<strong>{{ layer.title }}</strong>
					<span class="meta">{{ name }} · {{ layer.uid_prefix }} · {{ layer.geometry }}</span>
					<span v-if="layer.aggregate_only" class="badge badge-warn">aggregate only</span>
				</button>

				<table v-if="openLayer === name" class="fields">
					<thead>
						<tr>
							<th>Field</th>
							<th>Type</th>
							<th>Visibility</th>
							<th>Required</th>
							<th>Choices</th>
							<th>Notes</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="field in schema.systemFields.concat(layer.fields)" :key="field.name">
							<td><code>{{ field.name }}</code></td>
							<td>{{ field.type }}</td>
							<td><span :class="['badge', `vis-${field.visibility}`]">{{ field.visibility }}</span></td>
							<td>{{ field.required ? 'yes' : '' }}</td>
							<td>
								<span v-if="field.choices">{{ field.choices }}: {{ schema.lookups[field.choices]?.join(', ') }}</span>
							</td>
							<td>{{ field.description }}</td>
						</tr>
					</tbody>
				</table>
			</section>

			<section class="chapters">
				<h2>Chapters</h2>
				<ul>
					<li v-for="chapter in schema.chapters" :key="chapter.slug">
						<strong>{{ chapter.slug }}</strong>: ZIPs {{ chapter.zips.join(', ') }}
					</li>
				</ul>
			</section>
		</template>
	</div>
</template>

<style scoped>
.schema-view {
	padding: 16px;
	max-width: 1100px;
}

.layer {
	margin-bottom: 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.layer-head {
	display: flex;
	gap: 12px;
	align-items: center;
	width: 100%;
	padding: 12px;
	background: none;
	border: 0;
	text-align: left;
	cursor: pointer;
	color: var(--color-main-text);
}

.meta {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.fields {
	width: 100%;
	border-collapse: collapse;
	font-size: 0.9em;
}

.fields th,
.fields td {
	padding: 6px 10px;
	border-top: 1px solid var(--color-border);
	text-align: left;
	vertical-align: top;
}

.badge {
	padding: 2px 8px;
	border-radius: 999px;
	font-size: 0.8em;
	background: var(--color-background-dark);
}

.vis-public { background: var(--color-success-text); color: #fff; }
.vis-facilitator { background: var(--color-primary-element); color: #fff; }
.vis-chapter { background: var(--color-warning); }
.vis-organizer { background: var(--color-error); color: #fff; }
.badge-warn { background: var(--color-warning); }

.chapters {
	margin-top: 24px;
}
</style>
