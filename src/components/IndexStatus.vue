<!--
  - SPDX-FileCopyrightText: 2026 Nissaar
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->

<template>
	<div class="pc-index">
		<div v-if="scan.running" class="pc-index__line">
			<NcLoadingIcon :size="16" />
			<span>{{ t('photocleaner', 'Indexing — {found} so far', { found: scan.found }) }}</span>
		</div>
		<div v-else class="pc-index__line">
			<span>{{ indexedLabel }}</span>
		</div>

		<p v-if="scan.error" class="pc-index__error">
			{{ scan.error }}
		</p>

		<div class="pc-index__actions">
			<NcButton variant="tertiary" :disabled="scan.running" @click="$emit('scan')">
				{{ t('photocleaner', 'Check for new photos') }}
			</NcButton>
			<NcButton variant="tertiary" :disabled="scan.running" @click="$emit('rebuild')">
				{{ t('photocleaner', 'Rebuild index') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'

export default {
	name: 'IndexStatus',

	components: { NcButton, NcLoadingIcon },

	props: {
		scan: {
			type: Object,
			required: true,
		},

		summary: {
			type: Object,
			required: true,
		},
	},

	emits: ['scan', 'rebuild'],

	computed: {
		indexedLabel() {
			if (!this.summary.indexed) {
				return t('photocleaner', 'Nothing indexed yet')
			}
			return n('photocleaner', '%n photo indexed', '%n photos indexed', this.summary.indexed)
		},
	},

	methods: { t, n },
}
</script>

<style scoped>
.pc-index {
	padding: 8px 12px 12px;
	border-top: 1px solid var(--color-border);
	font-size: 0.9em;
	color: var(--color-text-maxcontrast);
}

.pc-index__line {
	display: flex;
	align-items: center;
	gap: 8px;
	min-height: 24px;
}

.pc-index__error {
	margin: 4px 0;
	color: var(--color-error);
}

.pc-index__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
	margin-top: 4px;
}
</style>
