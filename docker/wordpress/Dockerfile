FROM wordpress:latest

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Install git, unzip (dependencies for fnm/node)
ENV COREPACK_ENABLE_DOWNLOAD_PROMPT=0
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip curl gnupg passwd lsb-release \
    && rm -rf /var/lib/apt/lists/*

# Install fnm and Node.js 24
ENV FNM_DIR=/opt/fnm
RUN mkdir -p $FNM_DIR \
    && curl -fsSL https://fnm.vercel.app/install | bash -s -- --install-dir /usr/local/bin --skip-shell \
    && fnm install 24 \
    && fnm default 24

# Add Node.js to PATH
ENV PATH=$FNM_DIR/aliases/default/bin:$PATH

# Configure npm global directory for www-data
ENV NPM_CONFIG_PREFIX=/var/www/.npm-global
ENV PATH=$PATH:/var/www/.npm-global/bin

# Install gemini-cli and setup permissions
RUN mkdir -p $NPM_CONFIG_PREFIX \
    && npm install -g @google/gemini-cli@latest \
    && corepack enable \
    && chown -R www-data:www-data $NPM_CONFIG_PREFIX

# Change shell for www-data to bash
RUN usermod -s /bin/bash www-data

# Install WP-CLI
RUN curl -O https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \
    && chmod +x wp-cli.phar \
    && mv wp-cli.phar /usr/local/bin/wp

# Copy PHP config (will be mounted via docker-compose or copied separately)
# PHP config is in docker/config/php.ini

# Ensure www-data can write to its home directory (for VS Code Server)
RUN chown -R www-data:www-data /var/www

# Copy entrypoint script
COPY entrypoint.sh /usr/local/bin/docker-entrypoint-custom.sh
RUN chmod +x /usr/local/bin/docker-entrypoint-custom.sh

ENTRYPOINT ["/usr/local/bin/docker-entrypoint-custom.sh"]
CMD ["apache2-foreground"]
