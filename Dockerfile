FROM ghcr.io/thedevs-cz/php:8.5

ENV APP_ENV="prod" \
    APP_DEBUG=0 \
    PHP_OPCACHE_VALIDATE_TIMESTAMPS=0

RUN rm $PHP_INI_DIR/conf.d/docker-php-ext-xdebug.ini

COPY --link --chmod=755 .docker/on-startup.sh /docker-entrypoint.d/

COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-interaction --no-scripts

COPY . .

# Convention: this project uses PHP configs only, never YAML. Enforced by
# tests/Unit/Config/NoYamlConfigTest. We do NOT prune YAML files in the build —
# silent deletion masked a real prod outage (a Flex recipe dropped a YAML route,
# the prune nuked it, the route went missing only in prod). Trust the test.

# --omit=dev: the devDependencies are the Playwright browser suite, which this
# image can never run — it has no browser and no test code (tests/ is not
# excluded, but nothing invokes it). Measured on this tree: 28 MB installed with
# them, 8.3 MB without. The runtime deps (daisyui for the Tailwind compile,
# tom-select for the importmap) are unaffected.
#
# Note for the next person: these packages have NO postinstall hook and do NOT
# download a browser at install time (`playwright`, `@playwright/test` and
# `playwright-core` all ship empty/absent `scripts`; browsers come from an
# explicit `playwright install`). So this flag is a size and supply-surface
# decision, not a protection against a download.
RUN npm install --omit=dev
RUN bin/console importmap:install
RUN bin/console tailwind:build --minify
RUN bin/console asset-map:compile

# Run again to trigger scripts with application code present
RUN composer install --no-dev --no-interaction --classmap-authoritative

ARG APP_VERSION
ENV SENTRY_RELEASE="${APP_VERSION}"
