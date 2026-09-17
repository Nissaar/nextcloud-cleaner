<!--
  - SPDX-FileCopyrightText: 2026 Nissaar
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->

<template>
	<div class="pc-settings">
		<h2>{{ t('nextcloud_cleaner', 'Settings') }}</h2>

		<section class="pc-settings__section">
			<h3>{{ t('nextcloud_cleaner', 'What happens when you confirm a deletion') }}</h3>

			<NcCheckboxRadioSwitch
				:modelValue="local.mode"
				value="trash"
				name="pc-mode"
				type="radio"
				@update:modelValue="set('mode', $event)">
				{{ t('nextcloud_cleaner', 'Move to the Nextcloud trash') }}
			</NcCheckboxRadioSwitch>
			<p class="pc-settings__hint">
				{{ trashAvailable
					? t('nextcloud_cleaner', 'A real deletion. The files leave your library and your server’s retention policy decides how long they stay recoverable.')
					: t('nextcloud_cleaner', 'The trash app is disabled on this server, so this option deletes permanently and cannot be undone.') }}
			</p>

			<NcCheckboxRadioSwitch
				:modelValue="local.mode"
				value="folder"
				name="pc-mode"
				type="radio"
				@update:modelValue="set('mode', $event)">
				{{ t('nextcloud_cleaner', 'Collect them in a folder') }}
			</NcCheckboxRadioSwitch>
			<p class="pc-settings__hint">
				{{ t('nextcloud_cleaner', 'Deletes nothing. The files are moved together so you can look through them in Files and delete them yourself.') }}
			</p>

			<NcTextField
				v-if="local.mode === 'folder'"
				:modelValue="local.targetFolder"
				:label="t('nextcloud_cleaner', 'Collection folder')"
				:helperText="t('nextcloud_cleaner', 'Created if it does not exist. It is left out of the index, so collected photos will not come back around for review.')"
				@update:modelValue="set('targetFolder', $event)" />
		</section>

		<section class="pc-settings__section">
			<h3>{{ t('nextcloud_cleaner', 'What gets indexed') }}</h3>

			<NcTextField
				:modelValue="local.sourceFolder"
				:label="t('nextcloud_cleaner', 'Folder to go through')"
				:helperText="t('nextcloud_cleaner', 'Use / for everything, or narrow it to something like /Photos. Changing this needs a rebuild to take effect.')"
				@update:modelValue="set('sourceFolder', $event)" />

			<NcCheckboxRadioSwitch
				:modelValue="local.skipDecided"
				type="switch"
				@update:modelValue="set('skipDecided', $event)">
				{{ t('nextcloud_cleaner', 'Hide photos you have already judged') }}
			</NcCheckboxRadioSwitch>
			<p class="pc-settings__hint">
				{{ t('nextcloud_cleaner', 'On by default, so reopening a month picks up where you left off instead of starting over.') }}
			</p>
		</section>

		<section class="pc-settings__section">
			<h3>{{ t('nextcloud_cleaner', 'Index') }}</h3>
			<p class="pc-settings__hint">
				{{ t('nextcloud_cleaner', 'Rebuilding reads every photo again and works out its date from scratch. Your verdicts are kept.') }}
			</p>
			<NcButton @click="$emit('rebuild')">
				{{ t('nextcloud_cleaner', 'Rebuild index') }}
			</NcButton>
		</section>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcTextField from '@nextcloud/vue/components/NcTextField'

export default {
	name: 'SettingsPanel',

	components: { NcButton, NcCheckboxRadioSwitch, NcTextField },

	props: {
		config: {
			type: Object,
			required: true,
		},

		trashAvailable: {
			// Defaults to false so an unknown trash state warns rather than reassures.
			type: Boolean,
			default: false,
		},
	},

	emits: ['save', 'rebuild'],

	data() {
		return {
			local: { ...this.config },
			timer: null,
		}
	},

	watch: {
		config: {
			handler(value) {
				this.local = { ...value }
			},

			deep: true,
		},
	},

	beforeUnmount() {
		clearTimeout(this.timer)
	},

	methods: {
		t,

		/**
		 * Applies a change locally at once and saves it shortly after.
		 *
		 * The delay is there for the text fields: saving on every keystroke would
		 * write a dozen half-typed folder paths, and one of them would be the one that
		 * gets created.
		 *
		 * @param {string} key the setting
		 * @param {string|boolean} value its new value
		 */
		set(key, value) {
			this.local = { ...this.local, [key]: value }
			clearTimeout(this.timer)
			const patch = { [key]: value }
			const delay = typeof value === 'string' ? 700 : 0
			this.timer = setTimeout(() => this.$emit('save', patch), delay)
		},
	},
}
</script>

<style scoped>
.pc-settings {
	padding: 24px max(16px, 4%);
	max-width: 680px;
	margin: 0 auto;
}

.pc-settings h2 {
	margin: 0 0 8px;
}

.pc-settings__section {
	margin-top: 32px;
}

.pc-settings__section h3 {
	margin: 0 0 12px;
}

.pc-settings__hint {
	margin: 4px 0 16px 28px;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}
</style>
