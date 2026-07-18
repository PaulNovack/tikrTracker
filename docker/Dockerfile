FROM ubuntu:24.04

ENV DEBIAN_FRONTEND=noninteractive \
    TZ=UTC \
    COMPOSER_ALLOW_SUPERUSER=1 \
    NVM_DIR=/usr/local/nvm \
    NODE_VERSION=22

# Install system dependencies
RUN apt-get update && apt-get install -y --no-install-recommends \
    # PHP 8.4 and extensions
    software-properties-common \
    && add-apt-repository -y ppa:ondrej/php \
    && apt-get update \
    && apt-get install -y --no-install-recommends \
    php8.4 \
    php8.4-cli \
    php8.4-common \
    php8.4-curl \
    php8.4-mbstring \
    php8.4-mysql \
    php8.4-xml \
    php8.4-zip \
    php8.4-bcmath \
    php8.4-redis \
    php8.4-intl \
    php8.4-readline \
    php8.4-gd \
    php8.4-sqlite3 \
    # Apache + PHP module
    apache2 \
    libapache2-mod-php8.4 \
    # Python 3.12 and pip
    python3.12 \
    python3.12-venv \
    python3-pip \
    # Build tools for Python packages
    build-essential \
    python3.12-dev \
    # System utilities
    curl \
    unzip \
    git \
    supervisor \
    lm-sensors \
    libcap2-bin \
    cron \
    # Cleanup
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* \
    && echo "==> PHP $(php -v | head -1 | awk '{print $2}') installed with extensions:" \
    && php -m | grep -E 'redis|pdo_mysql|mbstring|xml|zip|bcmath|intl|gd|curl' | sort | xargs echo "    "

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
RUN echo "==> Composer $(composer --version | awk '{print $3}') installed"

# Install Node.js via nvm
RUN echo "==> Installing Node.js ${NODE_VERSION} via nvm..." \
    && mkdir -p ${NVM_DIR} \
    && curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/v0.40.1/install.sh | bash \
    && . ${NVM_DIR}/nvm.sh \
    && nvm install ${NODE_VERSION} \
    && nvm alias default ${NODE_VERSION} \
    && nvm use default \
    && ln -s ${NVM_DIR}/versions/node/$(nvm current)/bin/node /usr/local/bin/node \
    && ln -s ${NVM_DIR}/versions/node/$(nvm current)/bin/npm /usr/local/bin/npm \
    && ln -s ${NVM_DIR}/versions/node/$(nvm current)/bin/npx /usr/local/bin/npx \
    && echo "==> Node.js $(node -v) / npm v$(npm -v) installed"

# Add node/npm to PATH (fallback)
ENV NODE_PATH=${NVM_DIR}/versions/node/v${NODE_VERSION}/lib/node_modules \
    PATH=${NVM_DIR}/versions/node/v${NODE_VERSION}/bin:${PATH}

# Create Python virtual environment (matching the existing project path convention)
RUN echo "==> Creating Python 3.12 virtual environment..." \
    && python3.12 -m venv /var/www/html/laravel-invest/.venv \
    && echo "==> Python venv created at .venv/"

# Install Python dependencies into the venv
COPY python_ml/requirements.txt /tmp/python_ml_requirements.txt
COPY alpaca_python_api/requirements.txt /tmp/alpaca_python_api_requirements.txt
RUN echo "==> Installing Python ML dependencies (numpy, pandas, xgboost, sklearn)..." \
    && /var/www/html/laravel-invest/.venv/bin/pip install --no-cache-dir -r /tmp/python_ml_requirements.txt \
    && echo "==> Installing Alpaca Python API dependencies..." \
    && /var/www/html/laravel-invest/.venv/bin/pip install --no-cache-dir -r /tmp/alpaca_python_api_requirements.txt \
    && rm /tmp/python_ml_requirements.txt /tmp/alpaca_python_api_requirements.txt \
    && echo "==> Python packages installed:" \
    && /var/www/html/laravel-invest/.venv/bin/pip list 2>/dev/null | head -20

# Configure Apache
RUN a2enmod rewrite headers \
    && a2dissite 000-default \
    && echo "ServerName localhost" >> /etc/apache2/apache2.conf

# Copy Apache virtual host config
COPY docker/apache.conf /etc/apache2/sites-available/laravel-invest.conf
RUN a2ensite laravel-invest

# Copy PHP custom ini
COPY docker/php.ini /etc/php/8.4/cli/conf.d/99-custom.ini
COPY docker/php.ini /etc/php/8.4/apache2/conf.d/99-custom.ini

# Copy Supervisor config
COPY docker/supervisord.conf /etc/supervisor/conf.d/laravel-invest.conf

# Copy entrypoint
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Set up working directory
WORKDIR /var/www/html/laravel-invest

# Create storage directories and set permissions
RUN mkdir -p /var/www/html/laravel-invest/storage/logs \
    /var/www/html/laravel-invest/storage/framework/cache \
    /var/www/html/laravel-invest/storage/framework/sessions \
    /var/www/html/laravel-invest/storage/framework/views \
    /var/www/html/laravel-invest/storage/framework/testing \
    /var/www/html/laravel-invest/bootstrap/cache

EXPOSE 8080 8081

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["supervisord", "-n", "-c", "/etc/supervisor/supervisord.conf"]
