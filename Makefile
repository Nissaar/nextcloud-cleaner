# SPDX-FileCopyrightText: 2026 Nissaar
# SPDX-License-Identifier: AGPL-3.0-or-later

app_name = photosweep
version = $(shell sed -ne 's/^\s*<version>\(.*\)<\/version>/\1/p' appinfo/info.xml)

project_dir = $(CURDIR)
build_dir = $(CURDIR)/build
package_dir = $(build_dir)/$(app_name)
artifact_dir = $(build_dir)/artifacts
tarball = $(artifact_dir)/$(app_name)-$(version).tar.gz

# Signing material. The key never enters the repository; CI writes it from a secret.
cert_dir = $(HOME)/.nextcloud/certificates

.PHONY: all
all: build

# --- development -----------------------------------------------------------

.PHONY: dev-setup
dev-setup: composer-install npm-install

.PHONY: composer-install
composer-install:
	composer install --prefer-dist

.PHONY: npm-install
npm-install:
	npm ci

.PHONY: build
build:
	npm run build

.PHONY: watch
watch:
	npm run watch

.PHONY: test
test: lint psalm phpunit

.PHONY: lint
lint:
	composer run cs:check
	npm run lint

.PHONY: psalm
psalm:
	composer run psalm

.PHONY: phpunit
phpunit:
	composer run test:unit

.PHONY: clean
clean:
	rm -rf $(build_dir) js css

# --- packaging -------------------------------------------------------------

# Assembles exactly what ships: no sources, no tests, no dev tooling. The app has
# no runtime composer dependencies, so there is no vendor/ directory to carry —
# Nextcloud autoloads OCA\PhotoSweep from lib/ by itself.
.PHONY: package
package: build
	rm -rf $(build_dir)
	mkdir -p $(package_dir) $(artifact_dir)
	cp -r appinfo $(package_dir)/
	cp -r lib $(package_dir)/
	cp -r templates $(package_dir)/
	cp -r img $(package_dir)/
	cp -r js $(package_dir)/
	# Source maps are 11MB of a 14MB app — four fifths of what every server would
	# download and store, to debug minified code almost nobody will debug. They stay
	# in the build directory and out of the release.
	find $(package_dir)/js -name '*.map' -delete
	[ -d l10n ] && cp -r l10n $(package_dir)/ || true
	cp COPYING $(package_dir)/
	cp README.md $(package_dir)/

# Signs the file listing inside the package. Needs a Nextcloud install to run occ
# against; the release workflow does this inside the official image.
.PHONY: sign
sign:
	@if [ ! -f $(cert_dir)/$(app_name).key ]; then \
		echo "No signing key at $(cert_dir)/$(app_name).key — see docs/PUBLISHING.md"; \
		exit 1; \
	fi
	php $(NEXTCLOUD_ROOT)/occ integrity:sign-app \
		--privateKey=$(cert_dir)/$(app_name).key \
		--certificate=$(cert_dir)/$(app_name).crt \
		--path=$(package_dir)

.PHONY: tarball
tarball:
	tar -czf $(tarball) -C $(build_dir) $(app_name)
	@echo "built $(tarball)"
	@sha512sum $(tarball) | tee $(tarball).sha512

# The full release artefact: assemble, sign the contents, tar it up.
.PHONY: appstore
appstore: package sign tarball
