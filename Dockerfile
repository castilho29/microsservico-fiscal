# Imagem oficial do PHP, versão CLI (suficiente pro servidor embutido)
FROM php:8.3-cli

# Extensões que o NFePHP e o Composer precisam -- as mesmas que você
# teve que habilitar no php.ini do seu PC (soap, zip), mais as
# dependências de sistema pra compilar elas.
RUN apt-get update && apt-get install -y \
    libzip-dev \
    libxml2-dev \
    unzip \
    git \
    && docker-php-ext-install soap zip \
    && rm -rf /var/lib/apt/lists/*

# Composer (gerenciador de pacotes do PHP)
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY . .

# Instala as bibliotecas (NFePHP, etc) já sem as coisas de desenvolvimento
RUN composer install --no-dev --optimize-autoloader

# O Render decide a porta em tempo real através da variável $PORT --
# por isso não fixamos 8080 direto, deixamos ele mandar.
ENV PORT=8080
EXPOSE 8080

CMD ["sh", "-c", "php -S 0.0.0.0:$PORT -t public"]
