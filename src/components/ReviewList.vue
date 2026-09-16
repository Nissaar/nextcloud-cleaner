<!--
  - SPDX-FileCopyrightText: 2026 Nissaar
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->

<template>
	<div class="pc-review">
		<div class="pc-review__head">
			<h2>{{ t('photocleaner', 'Marked for deletion') }}</h2>
			<p class="pc-review__sub">
				{{ t('photocleaner', 'This is the only screen that changes your files. Take anything out that you want to keep, then confirm.') }}
			</p>
		</div>

		<div v-if="loading" class="pc-review__centre">
			<NcLoadingIcon :size="44" />
		</div>

		<template v-else>
			<NcNoteCard v-if="mode === 'trash' && !trashAvailable" type="warning">
				{{ t('photocleaner', 'The trash app is disabled on this server, so deleting is permanent and cannot be undone. Switch to collecting files in a folder in Settings if you would rather check them first.') }}
			</NcNoteCard>
			<NcNoteCard v-else-if="mode === 'trash'" type="info">
				{{ t('photocleaner', 'These files move to your Nextcloud trash, where they stay until your server’s retention policy clears them.') }}
			</NcNoteCard>
			<NcNoteCard v-else type="info">
				{{ t('photocleaner', 'These files are moved into your collection folder. Nothing is deleted — you delete them yourself in Files.') }}
			</NcNoteCard>

			<NcEmptyContent
				v-if="!pending.length"
				:name="t('photocleaner', 'Nothing marked for deletion')"
				:description="t('photocleaner', 'Go through a month and anything you swipe away will be listed here first.')">
				<template #icon>
					<DeleteClock />
				</template>
			</NcEmptyContent>

			<template v-else>
				<div class="pc-review__actions">
					<NcButton variant="primary" :disabled="applying" @click="confirmApply">
						<template #icon>
							<NcLoadingIcon v-if="applying" :size="20" />
							<Delete v-else :size="20" />
						</template>
						{{ applying
							? t('photocleaner', 'Working…')
							: n('photocleaner', 'Delete %n photo', 'Delete %n photos', pending.length) }}
					</NcButton>
					<span class="pc-review__total">{{ totalSize }}</span>
				</div>

				<ul class="pc-review__grid">
					<li v-for="item in pending" :key="item.fileId" class="pc-tile">
						<img :src="preview(item.fileId, 300)" :alt="item.name" loading="lazy">
						<span class="pc-tile__name" :title="item.originPath">{{ item.name }}</span>
						<NcButton
							class="pc-tile__pull"
							variant="tertiary"
							:aria-label="t('photocleaner', 'Keep this one after all')"
							@click="pullOut(item)">
							<template #icon>
								<Close :size="18" />
							</template>
						</NcButton>
					</li>
				</ul>
			</template>

			<section v-if="applied.length" class="pc-review__history">
				<h3>{{ t('photocleaner', 'Already dealt with') }}</h3>
				<p class="pc-review__sub">
					{{ t('photocleaner', 'Bring any of these back if you change your mind.') }}
				</p>

				<ul class="pc-review__grid">
					<li v-for="item in applied" :key="item.fileId" class="pc-tile pc-tile--done">
						<img :src="preview(item.fileId, 300)" :alt="item.name" loading="lazy">
						<span class="pc-tile__name" :title="item.originPath">{{ item.name }}</span>
						<span class="pc-tile__when">{{ date(item.appliedAt) }}</span>
						<NcButton
							class="pc-tile__pull"
							variant="tertiary"
							:aria-label="t('photocleaner', 'Restore')"
							@click="restore(item)">
							<template #icon>
								<Restore :size="18" />
							</template>
						</NcButton>
					</li>
				</ul>
			</section>
		</template>

		<NcDialog
			v-if="confirming"
			:name="n('photocleaner', 'Delete %n photo?', 'Delete %n photos?', pending.length)"
			:message="confirmMessage"
			@closing="confirming = false">
			<template #actions>
				<NcButton variant="tertiary" @click="confirming = false">
					{{ t('photocleaner', 'Cancel') }}
				</NcButton>
				<NcButton variant="error" @click="apply">
					{{ mode === 'trash'
						? t('photocleaner', 'Move to trash')
						: t('photocleaner', 'Move to folder') }}
				</NcButton>
			</template>
		</NcDialog>
	</div>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import Close from 'vue-material-design-icons/Close.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import DeleteClock from 'vue-material-design-icons/DeleteClock.vue'
import Restore from 'vue-material-design-icons/Restore.vue'
import api, { previewUrl } from '../api.js'
import { dateLabel, sizeLabel } from '../format.js'

export default {
	name: 'ReviewList',

	components: {
		Close,
		Delete,
		DeleteClock,
		NcButton,
		NcDialog,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		Restore,
	},

	props: {
		mode: {
			type: String,
			default: 'trash',
		},

		trashAvailable: {
			// Defaults to false so an unknown trash state warns rather than reassures.
			type: Boolean,
			default: false,
		},
	},

	emits: ['changed'],

	data() {
		return {
			pending: [],
			applied: [],
			loading: true,
			applying: false,
			confirming: false,
		}
	},

	computed: {
		totalSize() {
			const bytes = this.pending.reduce((sum, item) => sum + (item.size ?? 0), 0)
			return bytes ? sizeLabel(bytes) : ''
		},

		confirmMessage() {
			if (this.mode === 'trash') {
				return this.trashAvailable
					? t('photocleaner', 'They go to your Nextcloud trash and can be restored from here until your server clears them.')
					: t('photocleaner', 'The trash is disabled on this server, so this cannot be undone.')
			}
			return t('photocleaner', 'They are moved into your collection folder. Nothing is deleted.')
		},
	},

	async mounted() {
		await this.load()
	},

	methods: {
		t,
		n,
		preview: previewUrl,
		date: dateLabel,

		async load() {
			this.loading = true
			try {
				const [pending, applied] = await Promise.all([api.pending(), api.applied()])
				this.pending = pending.decisions
				this.applied = applied.decisions
			} catch {
				showError(t('photocleaner', 'Could not load your list'))
			} finally {
				this.loading = false
			}
		},

		confirmApply() {
			this.confirming = true
		},

		async pullOut(item) {
			try {
				await api.undo(item.fileId)
				this.pending = this.pending.filter((d) => d.fileId !== item.fileId)
				this.$emit('changed')
			} catch {
				showError(t('photocleaner', 'Could not take that one out of the list'))
			}
		},

		async apply() {
			this.confirming = false
			this.applying = true
			try {
				const result = await api.apply()
				if (result.error) {
					showError(result.error)
				} else if (result.failed > 0) {
					showError(t('photocleaner', '{done} done, {failed} could not be changed', {
						done: result.succeeded,
						failed: result.failed,
					}))
				} else {
					showSuccess(n('photocleaner', '%n photo dealt with', '%n photos dealt with', result.succeeded))
				}
				await this.load()
				this.$emit('changed')
			} catch {
				showError(t('photocleaner', 'Nothing was changed — the request failed'))
			} finally {
				this.applying = false
			}
		},

		async restore(item) {
			try {
				const result = await api.restore([item.fileId])
				if (result.restored) {
					showSuccess(t('photocleaner', 'Brought back'))
				} else {
					showError(Object.values(result.failures)[0] ?? t('photocleaner', 'Could not bring that back'))
				}
				await this.load()
				this.$emit('changed')
			} catch {
				showError(t('photocleaner', 'Could not bring that back'))
			}
		},
	},
}
</script>

<style scoped>
.pc-review {
	padding: 24px max(16px, 4%);
	max-width: 1100px;
	margin: 0 auto;
}

.pc-review__head h2 {
	margin: 0;
}

.pc-review__sub {
	color: var(--color-text-maxcontrast);
	margin: 4px 0 16px;
}

.pc-review__centre {
	display: flex;
	justify-content: center;
	padding: 48px 0;
}

.pc-review__actions {
	display: flex;
	align-items: center;
	gap: 12px;
	margin: 16px 0;
}

.pc-review__total {
	color: var(--color-text-maxcontrast);
}

.pc-review__grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
	gap: 10px;
	list-style: none;
	padding: 0;
	margin: 0;
}

.pc-tile {
	position: relative;
	display: flex;
	flex-direction: column;
	border-radius: var(--border-radius-large);
	overflow: hidden;
	background-color: var(--color-background-dark);
}

.pc-tile img {
	width: 100%;
	aspect-ratio: 1;
	object-fit: cover;
}

.pc-tile__name {
	padding: 6px 8px 0;
	font-size: 0.8em;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.pc-tile__when {
	padding: 0 8px 6px;
	font-size: 0.75em;
	color: var(--color-text-maxcontrast);
}

.pc-tile--done img {
	opacity: 0.55;
}

.pc-tile__pull {
	position: absolute;
	top: 4px;
	inset-inline-end: 4px;
	background-color: var(--color-main-background);
	border-radius: 50%;
}

.pc-review__history {
	margin-top: 40px;
	padding-top: 24px;
	border-top: 1px solid var(--color-border);
}

.pc-review__history h3 {
	margin: 0;
}
</style>
