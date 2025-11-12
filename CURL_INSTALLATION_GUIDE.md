# PHP cURL Extension Installation Guide

This guide helps you install the PHP cURL extension, which is required for the Google OAuth refresh token script to work optimally.

## Quick Test

First, check if cURL is installed:

```bash
php -m | grep curl
```

Or create a PHP file with:
```php
<?php
if (function_exists('curl_init')) {
    echo "cURL is installed and working!";
} else {
    echo "cURL is NOT installed.";
}
?>
```

## Installation by Operating System

### Windows (XAMPP/WAMP/MAMP/Laragon)

#### Option 1: Enable cURL in php.ini (Easiest)
1. Find your `php.ini` file:
   - **XAMPP**: `C:\xampp\php\php.ini`
   - **WAMP**: `C:\wamp\bin\php\php[version]\php.ini`
   - **Laragon**: `C:\laragon\bin\php\php[version]\php.ini`

2. Open `php.ini` in a text editor

3. Find this line (around line 1900):
   ```ini
   ;extension=curl
   ```

4. Remove the semicolon to uncomment it:
   ```ini
   extension=curl
   ```

5. Save the file and restart Apache/Nginx

#### Option 2: Use Laravel Valet (Recommended for Windows)
```bash
# Install PHP development package
valet install

# Or reinstall to ensure all extensions
valet install --force
```

#### Option 3: Install PHP with cURL included
Download PHP from [php.net](https://www.php.net/downloads.php) and choose a package that includes cURL.

### Windows (Docker/Laradock)
If you're using Docker, update your Dockerfile:

```dockerfile
# For Ubuntu/Debian based images
RUN apt-get update && apt-get install -y php-curl

# For Alpine based images
RUN apk add --no-cache php-curl
```

### macOS

#### Option 1: Homebrew (Recommended)
```bash
# Install PHP with cURL
brew install php

# Or reinstall to ensure extensions
brew reinstall php
```

#### Option 2: MAMP
1. Go to MAMP application
2. File → Edit Template → PHP → [your PHP version]
3. Find `;extension=curl` and uncomment it
4. Save and restart MAMP

#### Option 3: Laravel Valet
```bash
# Install/Update PHP with all extensions
brew install php
valet install
```

### Linux (Ubuntu/Debian)

#### Option 1: APT Package Manager
```bash
# Update package list
sudo apt update

# Install PHP cURL extension
sudo apt install php-curl

# For specific PHP versions
sudo apt install php8.2-curl  # For PHP 8.2
sudo apt install php8.1-curl  # For PHP 8.1
sudo apt install php8.0-curl  # For PHP 8.0
```

#### Option 2: Update PHP version
```bash
# Install PHP with all extensions
sudo apt install php php-curl php-mbstring php-xml php-zip
```

### Linux (CentOS/RHEL/Fedora)

#### CentOS/RHEL 7/8
```bash
# Enable EPEL repository
sudo yum install epel-release

# Install PHP cURL
sudo yum install php-curl

# Or for specific versions
sudo yum install php82-curl
```

#### Fedora
```bash
# Install PHP cURL
sudo dnf install php-curl

# Or for specific versions
sudo dnf install php-curl
```

### Linux (Alpine/Docker)
```bash
# Install cURL for PHP
apk add --no-cache php-curl

# Or for specific versions
apk add --no-cache php82-curl
```

## Verification

After installation, verify cURL is working:

1. **Command Line Check**:
   ```bash
   php -m | grep curl
   ```

2. **Web Server Check**:
   - Create `test-curl.php` with:
   ```php
   <?php
   phpinfo();
   ?>
   ```
   - Access it in browser and search for "cURL"

3. **Laravel Check**:
   ```bash
   php artisan tinker
   >>> function_exists('curl_init')
   >>> true
   ```

## Troubleshooting

### Issue: cURL still not found after installation

**Solution 1**: Restart web server completely
```bash
# Apache
sudo systemctl restart apache2
sudo systemctl restart httpd

# Nginx + PHP-FPM
sudo systemctl restart nginx
sudo systemctl restart php8.2-fpm

# Laragon/XAMPP
# Restart all services through the control panel
```

**Solution 2**: Check php.ini location
```bash
# Find your php.ini file
php --ini

# Or in Laravel
php artisan about | grep php.ini
```

**Solution 3**: Verify extension is loaded
```bash
php -i | grep extension_dir
ls /path/to/extensions/ | grep curl
```

### Issue: Multiple PHP versions

If you have multiple PHP versions installed:

```bash
# Check which PHP your web server uses
php -v  # Command line
# Check your web server configuration for PHP version

# Install cURL for all versions
sudo apt install php8.2-curl php8.1-curl php8.0-curl
```

### Issue: Docker containers

```dockerfile
# Add to your Dockerfile
RUN docker-php-ext-install curl

# Or for Alpine
RUN apk add --no-cache php-curl
```

## Alternative: Use the Fallback Script

If you cannot install cURL immediately, the Google OAuth script has been updated to work without cURL using `file_get_contents()` as a fallback.

However, cURL is recommended because:
- Better performance
- More reliable HTTPS connections
- Better error handling
- Support for advanced features like timeouts and proxies

## What If Nothing Works?

If you're unable to install cURL, you have these options:

1. **Use the web-based script**: The `get_google_refresh_token.php` now works without cURL with proper header handling
2. **Use Google OAuth Playground**: [https://developers.google.com/oauthplayground](https://developers.google.com/oauthplayground)
3. **Try a different server**: Consider a hosting environment with cURL pre-installed
4. **Contact your hosting provider**: They can enable cURL for you

## Minimum Requirements for Google OAuth Script

The modified Google OAuth script works with:
- **cURL enabled** (recommended): Full functionality with best performance
- **cURL disabled + allow_url_fopen enabled**: **Now fully functional** with proper OAuth headers
- **Both disabled**: Script will not work

**Note**: The fallback method has been recently improved to properly handle OAuth token requests with correct Content-Type and Content-Length headers, eliminating the 400 Bad Request errors that occurred with the previous implementation.

Check `allow_url_fopen`:
```bash
php -i | grep allow_url_fopen
```

Enable in `php.ini` if needed:
```ini
allow_url_fopen = On
```

## Security Notes

- cURL is generally more secure than `file_get_contents()` for HTTP requests
- Keep PHP updated to the latest version for security patches
- Only enable extensions you actually use
- Regularly update your system packages

## Summary

- **cURL is the preferred solution** for the Google OAuth script
- **Windows**: Uncomment `extension=curl` in php.ini
- **Linux**: Install `php-curl` package
- **macOS**: Use Homebrew or enable in MAMP
- **Fallback available** if installation fails
- **Restart web server** after installation

Once cURL is installed and working, the Google OAuth refresh token script should function normally with optimal performance and reliability.