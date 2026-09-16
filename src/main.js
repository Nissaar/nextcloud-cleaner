/**
 * SPDX-FileCopyrightText: 2026 Nissaar
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import { createApp } from 'vue'
import App from './App.vue'

const app = createApp(App)
app.config.globalProperties.t = t
app.config.globalProperties.n = n
app.mount('#photocleaner')
