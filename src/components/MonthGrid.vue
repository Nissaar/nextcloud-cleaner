<!--
  - SPDX-FileCopyrightText: 2026 Nissaar
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->

<template>
	<div class="pc-months">
		<div class="pc-months__head">
			<h2>{{ t('photocleaner', 'Pick a month') }}</h2>
			<p class="pc-months__sub">
				{{ subtitle }}
			</p>

			<div class="pc-months__filters">
				<NcCheckboxRadioSwitch
					v-for="option in filters"
					:key="option.id"
					:modelValue="filter"
					:value="option.id"
					:buttonVariant="true"
					name="pc-month-filter"
					type="radio"
					buttonVariantGrouped="horizontal"
					@update:modelValue="filter = $event">
					{{ option.label }}
				</NcCheckboxRadioSwitch>
			</div>
		</div>

		<NcEmptyContent
			v-if="!visible.length"
			:name="emptyTitle"
			:description="emptyDescription">
			<template #icon>
				<CalendarMonth />
			</template>
		</NcEmptyContent>

		<ul v-else class="pc-months__grid">
			<li v-for="month in visible" :key="month.month">
				<button class="pc-month" :class="{ 'pc-month--done': month.done }" @click="$emit('open', month.month)">
					<span class="pc-month__name">{{ label(month.month) }}</span>
					<span class="pc-month__count">
						{{ month.done
							? t('photocleaner', 'All {total} reviewed', { total: month.total })
							: t('photocleaner', '{remaining} of {total} left', { remaining: month.remaining, total: month.total }) }}
					</span>
					<span class="pc-month__bar">
						<span class="pc-month__fill" :style="{ width: progress(month) }" />
					</span>
					<NcButton
						v-if="month.reviewed > 0"
						class="pc-month__reset"
						variant="tertiary"
						:aria-label="t('photocleaner', 'Review this month again')"
						@click.stop="$emit('reset', month.month)">
						<template #icon>
							<Restore :size="18" />
						</template>
					</NcButton>
				</button>
			</li>
		</ul>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import CalendarMonth from 'vue-material-design-icons/CalendarMonth.vue'
import Restore from 'vue-material-design-icons/Restore.vue'
import { monthLabel } from '../format.js'

export default {
	name: 'MonthGrid',

	components: { CalendarMonth, NcButton, NcCheckboxRadioSwitch, NcEmptyContent, Restore },

	props: {
		months: {
			type: Array,
			required: true,
		},

		summary: {
			type: Object,
			required: true,
		},

		scanning: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['open', 'reset'],

	data() {
		return {
			// Defaults to what is left to do. With a decade of months on screen, a
			// badge on the finished ones still leaves you hunting for the unfinished.
			filter: 'todo',
		}
	},

	computed: {
		filters() {
			return [
				{ id: 'todo', label: t('photocleaner', 'To review') },
				{ id: 'done', label: t('photocleaner', 'Done') },
				{ id: 'all', label: t('photocleaner', 'All') },
			]
		},

		visible() {
			if (this.filter === 'done') {
				return this.months.filter((m) => m.done)
			}
			if (this.filter === 'all') {
				return this.months
			}
			return this.months.filter((m) => !m.done)
		},

		subtitle() {
			if (!this.summary.indexed) {
				return this.scanning
					? t('photocleaner', 'Reading your library…')
					: t('photocleaner', 'No photos indexed yet')
			}
			return t('photocleaner', '{left} photos still to go through, across {months} months', {
				left: this.summary.photosLeft,
				months: this.summary.monthsToReview,
			})
		},

		emptyTitle() {
			if (this.scanning) {
				return t('photocleaner', 'Still reading your library')
			}
			if (this.filter === 'todo' && this.months.length) {
				return t('photocleaner', 'Every month is done')
			}
			return t('photocleaner', 'Nothing here yet')
		},

		emptyDescription() {
			if (this.scanning) {
				return t('photocleaner', 'Months appear as they are found.')
			}
			if (this.filter === 'todo' && this.months.length) {
				return t('photocleaner', 'Switch to All to go back over one.')
			}
			return t('photocleaner', 'Once your photos have been indexed, the months they were taken in show up here.')
		},
	},

	methods: {
		t,
		label: monthLabel,

		progress(month) {
			if (!month.total) {
				return '0%'
			}
			return `${Math.round((month.reviewed / month.total) * 100)}%`
		},
	},
}
</script>

<style scoped>
.pc-months {
	padding: 24px max(16px, 4%);
	max-width: 1100px;
	margin: 0 auto;
}

.pc-months__head h2 {
	margin: 0;
}

.pc-months__sub {
	color: var(--color-text-maxcontrast);
	margin: 4px 0 16px;
}

.pc-months__filters {
	display: flex;
	margin-bottom: 24px;
}

.pc-months__grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(190px, 1fr));
	gap: 12px;
	list-style: none;
	padding: 0;
	margin: 0;
}

.pc-month {
	position: relative;
	display: flex;
	flex-direction: column;
	gap: 6px;
	width: 100%;
	padding: 16px;
	text-align: start;
	background-color: var(--color-main-background);
	border: 2px solid var(--color-border);
	border-radius: var(--border-radius-large);
	cursor: pointer;
}

.pc-month:hover,
.pc-month:focus-visible {
	border-color: var(--color-primary-element);
	background-color: var(--color-background-hover);
}

.pc-month--done {
	opacity: 0.65;
}

.pc-month__name {
	font-weight: 600;
}

.pc-month__count {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.pc-month__bar {
	display: block;
	height: 4px;
	border-radius: 2px;
	background-color: var(--color-background-darker);
	overflow: hidden;
}

.pc-month__fill {
	display: block;
	height: 100%;
	background-color: var(--color-primary-element);
}

.pc-month__reset {
	position: absolute;
	top: 6px;
	inset-inline-end: 6px;
}
</style>
