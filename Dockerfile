# Voltikka Laravel Application - Production
FROM dunglas/frankenphp:1-php8.4

# Install system dependencies and PHP extensions
# Note: libjpeg62-turbo-dev for JPEG, libwebp-dev for WebP support in GD
# Note: chromium for Remotion video rendering
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libjpeg62-turbo-dev \
    libicu-dev \
    libonig-dev \
    libxml2-dev \
    libpq-dev \
    libwebp-dev \
    libzip-dev \
    zip \
    unzip \
    supervisor \
    nodejs \
    npm \
    chromium \
    default-mysql-client \
    $PHPIZE_DEPS \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install pdo pdo_pgsql pdo_mysql mbstring exif pcntl bcmath gd zip intl \
    && pecl install excimer redis \
    && docker-php-ext-enable excimer redis \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Set Chromium path for Remotion/Puppeteer
# Contract list warming must happen at container start (not image build),
# because it requires a live database connection.
ENV PUPPETEER_EXECUTABLE_PATH=/usr/bin/chromium \
    WARM_CONTRACT_CACHE_ON_START=1

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /app

# Copy application files
COPY laravel/ .

# Install PHP dependencies
RUN composer install --no-dev --optimize-autoloader

# Install Node.js dependencies and build assets
RUN npm ci && npm run build && rm -rf node_modules

# Copy and install Remotion project for video rendering
COPY remotion/ /app/remotion/
WORKDIR /app/remotion
RUN npm ci
WORKDIR /app

# Temporal worker (tasks/temporal-orchestration/, service voltikka-worker). uv installs the pinned
# Python and the locked dependencies of orchestration/uv.lock; the web service never starts it.
COPY --from=ghcr.io/astral-sh/uv:0.13.6 /uv /usr/local/bin/uv
ENV UV_PYTHON_INSTALL_DIR=/opt/python \
    UV_PROJECT_ENVIRONMENT=/opt/orchestration/.venv \
    UV_COMPILE_BYTECODE=1 \
    UV_LINK_MODE=copy
# The own S model package (forecast/) is a path dependency of the worker (../forecast in uv.lock).
COPY forecast/pyproject.toml /opt/forecast/
COPY forecast/src /opt/forecast/src
COPY orchestration/pyproject.toml orchestration/uv.lock /opt/orchestration/
COPY orchestration/src /opt/orchestration/src
RUN cd /opt/orchestration && uv sync --frozen --no-dev --python 3.13 && rm -rf /root/.cache/uv
# The model activities run `python -m $S_MODEL_MODULE`; the image always contains the package.
ENV S_MODEL_MODULE=voltikka_forecast.s
COPY docker-worker-entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/docker-worker-entrypoint.sh

# Create storage directories and set permissions
RUN mkdir -p storage/logs storage/framework/{cache,sessions,views} bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

# Copy supervisor configuration
COPY supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Copy and prepare entrypoint script
COPY docker-entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# Expose port
EXPOSE 8000

# Health check
HEALTHCHECK --interval=30s --timeout=3s --start-period=5s --retries=3 \
    CMD curl -f http://localhost:8000/up || exit 1

# Run entrypoint (handles storage setup, migrations, then starts supervisor)
ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
