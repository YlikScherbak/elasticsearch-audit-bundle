# Infection needs two things a workstation need not have: a coverage driver, and a
# filesystem that PHP can read quickly.
#
#   docker build -t es-audit-infection tools/infection -f tools/infection/Dockerfile .
#
# **The coverage driver** is pcov rather than Xdebug, because that is what the coverage
# job in CI uses, and a mutation score that depends on which driver measured it is not
# worth reading.
#
# **The filesystem** is why the dependencies are installed into the image instead of
# being mounted with the rest of the project. Infection runs the covering tests once per
# mutant, and each of those runs loads the autoloader, PHPUnit and half of Symfony —
# thousands of files, every time. Read through a Windows bind mount that is about eight
# times slower than native, which does not merely make the run long: mutants start
# exceeding the timeout, Infection counts a timeout as a mutant killed, and the score
# comes out higher the slower the machine is. The first baseline measured that way
# reported 99% with 1347 of 1841 mutants "timed out", including mutations that cannot
# loop at all.
#
# So vendor/ lives in the image and only the project's own files are mounted — a few
# hundred, and they are the ones being edited between runs. Rebuild when composer.json
# changes, which is rarely; see run.sh for the rest.
FROM php:8.3-cli

RUN apt-get update \
 && apt-get install -y --no-install-recommends $PHPIZE_DEPS git unzip \
 && pecl install pcov \
 && docker-php-ext-enable pcov \
 && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# The bundle's own dependencies, resolved the way CI resolves them: composer.lock is not
# committed for a library, so this is a fresh resolution rather than a pinned one.
COPY composer.json ./
RUN composer install --no-interaction --no-progress --no-scripts

# And Infection itself, kept out of the bundle's require-dev because it wants PHP 8.3
# while the bundle supports 8.1.
COPY tools/infection/composer.json tools/infection/composer.json
RUN composer install --working-dir=tools/infection --no-interaction --no-progress
