<!--
  - SPDX-FileCopyrightText: 2026 Nissaar
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->

<template>
	<div class="pc-deck">
		<div class="pc-deck__bar">
			<NcButton variant="tertiary" @click="$emit('back')">
				<template #icon>
					<ArrowLeft :size="20" />
				</template>
				{{ t('nextcloud_cleaner', 'Months') }}
			</NcButton>

			<div class="pc-deck__title">
				<strong>{{ label(month) }}</strong>
				<span v-if="items.length" class="pc-deck__progress">
					{{ t('nextcloud_cleaner', '{done} of {total}', { done: Math.min(index + 1, items.length), total: items.length }) }}
				</span>
			</div>

			<NcButton
				variant="tertiary"
				:disabled="!history.length"
				:aria-label="t('nextcloud_cleaner', 'Undo')"
				@click="undo">
				<template #icon>
					<UndoVariant :size="20" />
				</template>
				{{ t('nextcloud_cleaner', 'Undo') }}
			</NcButton>
		</div>

		<NcProgressBar :value="progress" size="medium" />

		<div v-if="loading" class="pc-deck__centre">
			<NcLoadingIcon :size="44" />
		</div>

		<NcEmptyContent
			v-else-if="!items.length"
			:name="t('nextcloud_cleaner', 'Nothing left in this month')"
			:description="t('nextcloud_cleaner', 'Every photo here already has a verdict.')">
			<template #icon>
				<CheckAll />
			</template>
			<template #action>
				<NcButton @click="reviewAgain">
					{{ t('nextcloud_cleaner', 'Review this month again') }}
				</NcButton>
			</template>
		</NcEmptyContent>

		<NcEmptyContent
			v-else-if="finished"
			:name="t('nextcloud_cleaner', 'Month finished')"
			:description="summaryText">
			<template #icon>
				<CheckAll />
			</template>
			<template #action>
				<NcButton variant="primary" @click="$emit('back')">
					{{ t('nextcloud_cleaner', 'Back to months') }}
				</NcButton>
			</template>
		</NcEmptyContent>

		<div v-else class="pc-deck__stage">
			<!-- The next photo sits behind the current one so a decision reveals it
			     instantly rather than flashing an empty frame while it loads. -->
			<div v-if="next" class="pc-card pc-card--behind">
				<img :src="preview(next.fileId, 900)" :alt="next.name" loading="eager">
			</div>

			<div
				class="pc-card"
				:class="{ 'pc-card--dragging': dragging }"
				:style="cardStyle"
				@pointerdown="onPointerDown"
				@pointermove="onPointerMove"
				@pointerup="onPointerUp"
				@pointercancel="onPointerUp">
				<video
					v-if="current.isVideo && playing"
					class="pc-card__media"
					:src="video(current.path)"
					:poster="preview(current.fileId, 1200)"
					controls
					autoplay
					playsinline />
				<img
					v-else
					class="pc-card__media"
					:src="preview(current.fileId, 1200)"
					:alt="current.name"
					draggable="false">

				<button v-if="current.isVideo && !playing" class="pc-card__play" @click="playing = true">
					<Play :size="48" />
					<span class="hidden-visually">{{ t('nextcloud_cleaner', 'Play video') }}</span>
				</button>

				<span v-if="verdictHint" class="pc-card__stamp" :class="`pc-card__stamp--${verdictHint}`">
					{{ verdictHint === 'delete' ? t('nextcloud_cleaner', 'Delete') : t('nextcloud_cleaner', 'Keep') }}
				</span>

				<div class="pc-card__meta">
					<span class="pc-card__name" :title="current.path">{{ current.name }}</span>
					<span class="pc-card__detail" :title="sourceHint">
						{{ date(current.takenAt) }} · {{ size(current.size) }}
					</span>
				</div>
			</div>
		</div>

		<div v-if="!loading && items.length && !finished" class="pc-deck__actions">
			<NcButton
				class="pc-deck__delete"
				variant="error"
				wide
				@click="decide('delete')">
				<template #icon>
					<Delete :size="20" />
				</template>
				{{ t('nextcloud_cleaner', 'Delete') }}
			</NcButton>
			<NcButton variant="success" wide @click="decide('keep')">
				<template #icon>
					<Check :size="20" />
				</template>
				{{ t('nextcloud_cleaner', 'Keep') }}
			</NcButton>
		</div>

		<p v-if="!loading && items.length && !finished" class="pc-deck__hint">
			{{ t('nextcloud_cleaner', 'Drag the photo, use the buttons, or press the left and right arrow keys. Nothing is deleted until you confirm it on the Marked for deletion screen.') }}
		</p>
	</div>
</template>

<script>
import { showError } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcProgressBar from '@nextcloud/vue/components/NcProgressBar'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import Check from 'vue-material-design-icons/Check.vue'
import CheckAll from 'vue-material-design-icons/CheckAll.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import Play from 'vue-material-design-icons/Play.vue'
import UndoVariant from 'vue-material-design-icons/UndoVariant.vue'
import api, { fileUrl, previewUrl } from '../api.js'
import { dateLabel, dateSourceLabel, monthLabel, sizeLabel } from '../format.js'

/** How far the card must travel before the drag counts as a verdict. */
const COMMIT_DISTANCE = 120

export default {
	name: 'SwipeDeck',

	components: {
		ArrowLeft,
		Check,
		CheckAll,
		Delete,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcProgressBar,
		Play,
		UndoVariant,
	},

	props: {
		month: {
			type: String,
			required: true,
		},
	},

	emits: ['back', 'changed'],

	data() {
		return {
			items: [],
			index: 0,
			loading: true,
			playing: false,
			kept: 0,
			deleted: 0,
			/** Verdicts given this session, newest last — this is what undo walks back. */
			history: [],
			dragging: false,
			dragX: 0,
			pointerId: null,
			startX: 0,
		}
	},

	computed: {
		current() {
			return this.items[this.index] ?? null
		},

		next() {
			return this.items[this.index + 1] ?? null
		},

		finished() {
			return !this.loading && this.items.length > 0 && this.index >= this.items.length
		},

		progress() {
			if (!this.items.length) {
				return 0
			}
			return Math.round((this.index / this.items.length) * 100)
		},

		cardStyle() {
			if (!this.dragX) {
				return {}
			}
			return {
				transform: `translateX(${this.dragX}px) rotate(${this.dragX / 28}deg)`,
			}
		},

		verdictHint() {
			if (this.dragX <= -60) {
				return 'delete'
			}
			if (this.dragX >= 60) {
				return 'keep'
			}
			return null
		},

		sourceHint() {
			return this.current ? dateSourceLabel(this.current.dateSource) : ''
		},

		summaryText() {
			return t('nextcloud_cleaner', 'Kept {kept}, marked {deleted} for deletion.', {
				kept: this.kept,
				deleted: this.deleted,
			})
		},
	},

	async mounted() {
		await this.load()
		window.addEventListener('keydown', this.onKey)
	},

	beforeUnmount() {
		window.removeEventListener('keydown', this.onKey)
	},

	methods: {
		t,
		label: monthLabel,
		date: dateLabel,
		size: sizeLabel,
		preview: previewUrl,
		video: fileUrl,

		async load(skipDecided = null) {
			this.loading = true
			try {
				const result = await api.month(this.month, skipDecided)
				this.items = result.items
				this.index = 0
				this.history = []
				this.kept = 0
				this.deleted = 0
			} catch {
				showError(t('nextcloud_cleaner', 'Could not open that month'))
			} finally {
				this.loading = false
			}
		},

		async reviewAgain() {
			await api.resetMonth(this.month)
			await this.load(false)
			this.$emit('changed')
		},

		onKey(event) {
			if (this.loading || !this.current || event.metaKey || event.ctrlKey) {
				return
			}
			if (event.key === 'ArrowLeft') {
				event.preventDefault()
				this.decide('delete')
			} else if (event.key === 'ArrowRight') {
				event.preventDefault()
				this.decide('keep')
			} else if (event.key === 'z' && this.history.length) {
				event.preventDefault()
				this.undo()
			}
		},

		/**
		 * Records a verdict and moves on.
		 *
		 * The deck advances first and the request follows, because waiting on the
		 * network between every photo is what makes going through a thousand of them
		 * unbearable. A failure is surfaced and the item is put back.
		 *
		 * @param {string} verdict "keep" or "delete"
		 */
		async decide(verdict) {
			const item = this.current
			if (!item) {
				return
			}

			this.index += 1
			this.playing = false
			this.dragX = 0
			this.history.push({ item, verdict })
			if (verdict === 'keep') {
				this.kept += 1
			} else {
				this.deleted += 1
			}

			try {
				await api.record(item.fileId, verdict)
				this.$emit('changed')
			} catch {
				showError(t('nextcloud_cleaner', 'Could not save that decision'))
				this.stepBack()
			}
		},

		async undo() {
			const last = this.history[this.history.length - 1]
			if (!last) {
				return
			}
			this.stepBack()
			try {
				await api.undo(last.item.fileId)
				this.$emit('changed')
			} catch {
				showError(t('nextcloud_cleaner', 'Could not undo that'))
			}
		},

		/** Reverses the local bookkeeping of one verdict, without calling the server. */
		stepBack() {
			const last = this.history.pop()
			if (!last) {
				return
			}
			this.index = Math.max(0, this.index - 1)
			this.playing = false
			if (last.verdict === 'keep') {
				this.kept = Math.max(0, this.kept - 1)
			} else {
				this.deleted = Math.max(0, this.deleted - 1)
			}
		},

		onPointerDown(event) {
			if (this.playing || event.button !== 0) {
				return
			}
			this.pointerId = event.pointerId
			this.startX = event.clientX
			this.dragging = true
			event.currentTarget.setPointerCapture(event.pointerId)
		},

		onPointerMove(event) {
			if (!this.dragging || event.pointerId !== this.pointerId) {
				return
			}
			this.dragX = event.clientX - this.startX
		},

		onPointerUp(event) {
			if (!this.dragging) {
				return
			}
			if (event.pointerId === this.pointerId) {
				event.currentTarget.releasePointerCapture?.(event.pointerId)
			}
			this.dragging = false
			const travelled = this.dragX
			this.dragX = 0
			this.pointerId = null

			if (travelled <= -COMMIT_DISTANCE) {
				this.decide('delete')
			} else if (travelled >= COMMIT_DISTANCE) {
				this.decide('keep')
			}
		},
	},
}
</script>

<style scoped>
.pc-deck {
	display: flex;
	flex-direction: column;
	height: 100%;
	padding: 12px max(12px, 3%) 20px;
	gap: 8px;
}

.pc-deck__bar {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
}

.pc-deck__title {
	display: flex;
	flex-direction: column;
	align-items: center;
	line-height: 1.25;
}

.pc-deck__progress {
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
}

.pc-deck__centre {
	display: flex;
	align-items: center;
	justify-content: center;
	flex: 1;
}

.pc-deck__stage {
	position: relative;
	flex: 1;
	min-height: 0;
	display: flex;
	align-items: center;
	justify-content: center;
}

.pc-card {
	position: absolute;
	inset: 0;
	display: flex;
	align-items: center;
	justify-content: center;
	border-radius: var(--border-radius-large);
	overflow: hidden;
	background-color: var(--color-background-dark);
	touch-action: pan-y;
	user-select: none;
	cursor: grab;
}

.pc-card--dragging {
	cursor: grabbing;
	transition: none;
}

.pc-card:not(.pc-card--dragging) {
	transition: transform 0.18s ease-out;
}

.pc-card--behind {
	transform: scale(0.96);
	filter: brightness(0.6);
	pointer-events: none;
}

.pc-card__media {
	max-width: 100%;
	max-height: 100%;
	object-fit: contain;
}

.pc-card__play {
	position: absolute;
	inset: 0;
	margin: auto;
	width: 88px;
	height: 88px;
	display: flex;
	align-items: center;
	justify-content: center;
	border: none;
	border-radius: 50%;
	color: var(--color-primary-element-text);
	background-color: rgba(0, 0, 0, 0.55);
	cursor: pointer;
}

.pc-card__stamp {
	position: absolute;
	top: 24px;
	padding: 6px 16px;
	border: 3px solid;
	border-radius: var(--border-radius);
	font-size: 1.4em;
	font-weight: 700;
	text-transform: uppercase;
	letter-spacing: 0.08em;
	pointer-events: none;
}

.pc-card__stamp--delete {
	inset-inline-start: 24px;
	color: var(--color-error);
	border-color: var(--color-error);
	transform: rotate(-12deg);
}

.pc-card__stamp--keep {
	inset-inline-end: 24px;
	color: var(--color-success);
	border-color: var(--color-success);
	transform: rotate(12deg);
}

.pc-card__meta {
	position: absolute;
	inset-inline: 0;
	bottom: 0;
	display: flex;
	flex-direction: column;
	padding: 24px 16px 12px;
	color: #fff;
	background: linear-gradient(transparent, rgba(0, 0, 0, 0.72));
	pointer-events: none;
}

.pc-card__name {
	font-weight: 600;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.pc-card__detail {
	font-size: 0.85em;
	opacity: 0.85;
}

.pc-deck__actions {
	display: flex;
	gap: 12px;
	max-width: 560px;
	width: 100%;
	margin: 0 auto;
}

.pc-deck__hint {
	margin: 0;
	text-align: center;
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
}
</style>
