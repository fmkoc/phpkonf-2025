# OpenSwoole 25 Demo Projects with PHP 8.4 for PHPKonf 2025

What we are going to need

- **PHP 8.4.X** 
- **OpenSwoole 25.X** 

## Running Projects

- After installation is complete, you can run the PHP files in the project folder:

### HTTP Server Example

- This is just a simple project to test OpenSwoole

```bash
php http_server.php
```

- And then open http://localhost:9051 and see logs from CLI


### XOX Game Server Example

- This is way more complex project to work on OpenSwoole

```bash
php xox_server.php
```

- And then open `xox_client.html` with your browser. You can use different tabs to test the app.



# Installing Environment

## Windows Installation (with WSL)

### 0. Requirements
- WSL must be installed. You can download it from Microsoft Store.

### 1. WSL Ubuntu Installation

- Install Ubuntu using Windows Subsystem for Linux (WSL), with PowerShell:

```powershell
wsl --install -d Ubuntu
```

### 2. Update Ubuntu System & Add PHP Repository

```bash
sudo apt update
sudo apt install -y software-properties-common
sudo add-apt-repository ppa:ondrej/php
sudo apt update
```

### 3. Install PHP 8.4 and Required Extensions

- Install PHP and required extensions:

```bash
sudo apt install -y php8.4 php8.4-cli php8.4-common php8.4-dev php8.4-mysql php8.4-pgsql php8.4-xml php8.4-mbstring php8.4-curl php-pear build-essential libcurl4-openssl-dev
```

### 4. Verify PHP and PECL Versions

- Check the installations:

```bash
php -v
pecl version
sudo pecl channel-update pecl.php.net
```

### 5. Install OpenSwoole Extension

- Install the OpenSwoole extension using PECL:

```bash
pecl install openswoole
```

### 6. Enable OpenSwoole Extension

- Verify that the extension file is installed:

```bash
find /usr/lib/php/ -name openswoole.so
```

- Create the configuration file:

```bash
sudo bash -c "cat > /etc/php/8.4/mods-available/openswoole.ini << EOF
; Configuration for Open Swoole
; priority=30
extension=openswoole
EOF"
```

- Enable the extension:

```bash
sudo phpenmod -s cli openswoole
```

### 7. Verify Installation

- Check that OpenSwoole is properly installed:

```bash
php -m | grep openswoole
```

### 8. Windows File System Access (Optional)

- Create a symbolic link for easy access to Windows files:
- Requires `C:\WSL` directory

```bash
ln -s /mnt/c/WSL ~/wsl
```

## macOS Installation

### 0. Requirements
    - Homebrew must be installed on your system.
    - Details: https://brew.sh/

### 1. Install PHP with Homebrew

- First update Homebrew and install PHP:

```bash
brew update
brew install php
```

### 2. Verify Installations

- Check PHP and PECL versions:

```bash
which php && php -v
which pecl && pecl version
```

### 3. Set Environment Variables

- Set required environment variables for OpenSwoole compilation:

```bash
export CPPFLAGS="-I$(brew --prefix openssl)/include -I$(brew --prefix pcre2)/include"
export LDFLAGS="-L$(brew --prefix openssl)/lib -L$(brew --prefix pcre2)/lib"
export PKG_CONFIG_PATH="$(brew --prefix openssl)/lib/pkgconfig:$(brew --prefix pcre2)/lib/pkgconfig"
```

### 4. Set Up Permissions

- Set up required folder permissions for PECL:
- Here "20240924" folder may vary depending on your PHP version. Make sure the folder is correct
- Important Note: Never do anything related to homebrew, pecl, and php as "root".

```bash
sudo chown -R $(whoami) /opt/homebrew/
sudo chown -R $(whoami) /private/tmp/pear
mkdir -p /opt/homebrew/lib/php/pecl/20240924
```

### 5. Install OpenSwoole

- Install the OpenSwoole extension:

```bash
pecl install openswoole
```

### 6. Update PHP Configuration

- Add the OpenSwoole extension to PHP configuration:

```bash
echo "extension=openswoole" > /opt/homebrew/etc/php/8.4/conf.d/99-openswoole.ini
```

### 7. Verify Installation

- Check that OpenSwoole is properly installed:

```bash
php -m | grep openswoole
```

### 8. Kill PHP Processes (if needed)

- Kill processes use 9501 port if occupied

```bash
kill -9 $(lsof -t -i :9501)
```
