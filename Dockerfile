FROM composer:2 AS composer

FROM php:8.5-cli-bookworm

ARG APP_UID=1000
ARG APP_GID=1000

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        bash \
        ca-certificates \
        curl \
        git \
        gosu \
        procps \
        unzip \
        zip \
        libicu-dev \
        libonig-dev \
        libsqlite3-dev \
        libzip-dev \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install \
        intl \
        mbstring \
        pcntl \
        pdo_sqlite \
        zip

COPY --from=composer /usr/bin/composer /usr/local/bin/composer

RUN set -eux; \
    if getent group "$APP_GID" >/dev/null; then \
        : "gid $APP_GID already exists"; \
    else \
        groupadd --gid "$APP_GID" app; \
    fi; \
    app_group="$(getent group "$APP_GID" | cut -d: -f1)"; \
    if getent passwd app >/dev/null; then \
        current_uid="$(getent passwd app | cut -d: -f3)"; \
        if [ "$current_uid" != "$APP_UID" ]; then \
            usermod -o -u "$APP_UID" app; \
        fi; \
        usermod -g "$app_group" -s /bin/bash app; \
        current_home="$(getent passwd app | cut -d: -f6)"; \
        if [ "$current_home" != "/home/app" ]; then \
            usermod -d /home/app -m app; \
        fi; \
    else \
        if getent passwd "$APP_UID" >/dev/null; then \
            useradd -o --uid "$APP_UID" --gid "$app_group" --create-home --home-dir /home/app --shell /bin/bash app; \
        else \
            useradd --uid "$APP_UID" --gid "$app_group" --create-home --home-dir /home/app --shell /bin/bash app; \
        fi; \
    fi

WORKDIR /app

COPY docker/docker-entrypoint.sh /usr/local/bin/docker-entrypoint

RUN chmod +x /usr/local/bin/docker-entrypoint

USER root

ENTRYPOINT ["/usr/local/bin/docker-entrypoint"]

CMD ["bash"]
