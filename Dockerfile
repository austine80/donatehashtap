# Use official PHP + Apache image
FROM php:8.2-apache

# Install system dependencies for cURL
RUN apt-get update && apt-get install -y \
    libcurl4-openssl-dev \
    pkg-config \
    unzip \
    && docker-php-ext-install curl \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Enable Apache mod_rewrite (optional if needed)
RUN a2enmod rewrite

# Copy project files to Apache web root
COPY . /var/www/html/

# Set working directory
WORKDIR /var/www/html/

# Expose port (optional, Render handles this)
EXPOSE 80
