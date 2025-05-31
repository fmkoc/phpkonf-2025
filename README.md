# PHP ile Asenkron Programlama: Swoole & PHP 8.4 | Asynchronous Programming with PHP: Swoole & PHP 8.4

> **TR:** Bu proje, PHPKonf 2025 konferansı için hazırlanmış "PHP ile Asenkron Programlama: Swoole & PHP 8.4" başlıklı sunum için geliştirilmiş demo uygulamalarını içermektedir. OpenSwoole 25 ve PHP 8.4 kullanarak asenkron programlama, HTTP sunucu oluşturma ve gerçek zamanlı oyun geliştirme örnekleri sunmaktadır. Sunumun PDF dosyası `presentation.pdf` olarak projeye dahil edilmiştir.

> **EN:** This project contains demo applications developed for the presentation titled "Asynchronous Programming with PHP: Swoole & PHP 8.4" prepared for PHPKonf 2025 conference. It demonstrates asynchronous programming, HTTP server creation, and real-time game development examples using OpenSwoole 25 and PHP 8.4. The presentation PDF file is included in the project as `presentation.pdf`.

## 📋 Project Contents

- `presentation.pdf` - PHPKonf 2025 presentation slides
- `http_server.php` - Simple HTTP server example
- `xox_server.php` - Real-time XOX (Tic-tac-toe) game server
- `xox_client.html` - Game client interface
- `README.md` - Installation and usage guide

## 🔧 Requirements

To run these demo applications, you'll need:

- **PHP 8.4.X** or higher
- **OpenSwoole 25.X** extension

## 🚀 Quick Start

After completing the installation process, you can run the demonstration projects:

### 🌐 HTTP Server Demo

A simple HTTP server implementation demonstrating basic OpenSwoole functionality.

**Start the server:**
```bash
php http_server.php
```

**Test the server:**
- Open [http://localhost:9051](http://localhost:9051) in your browser
- Monitor server logs in the CLI output

### 🎮 XOX Game Server Demo

An advanced real-time multiplayer game demonstrating WebSocket capabilities and concurrent connection handling.

**Start the game server:**
```bash
php xox_server.php
```

**Play the game:**
- Open `xox_client.html` in your web browser
- Use multiple browser tabs to simulate different players
- Enjoy real-time multiplayer tic-tac-toe!
---

# 📦 Installation Guide

Choose your operating system for detailed installation instructions:

## 🪟 Windows Installation (WSL)

### Prerequisites
- Windows Subsystem for Linux (WSL) must be installed
- Available from Microsoft Store

### Step 1: Install WSL Ubuntu

Install Ubuntu using Windows Subsystem for Linux via PowerShell:

```powershell
wsl --install -d Ubuntu
```

### Step 2: Update System & Add PHP Repository

Update the Ubuntu system and add the PHP repository:

```bash
sudo apt update
sudo apt install -y software-properties-common
sudo add-apt-repository ppa:ondrej/php
sudo apt update
```

### Step 3: Install PHP 8.4 & Extensions

Install PHP 8.4 with all required extensions:

```bash
sudo apt install -y php8.4 php8.4-cli php8.4-common php8.4-dev php8.4-mysql php8.4-pgsql php8.4-xml php8.4-mbstring php8.4-curl php-pear build-essential libcurl4-openssl-dev
```

### Step 4: Verify Installation

Verify PHP and PECL installations:

```bash
php -v
pecl version
sudo pecl channel-update pecl.php.net
```

### Step 5: Install OpenSwoole Extension

Install the OpenSwoole extension via PECL:

```bash
pecl install openswoole
```

### Step 6: Configure OpenSwoole Extension

Verify the extension installation:

```bash
find /usr/lib/php/ -name openswoole.so
```

Create the configuration file:

```bash
sudo bash -c "cat > /etc/php/8.4/mods-available/openswoole.ini << EOF
; Configuration for OpenSwoole
; priority=30
extension=openswoole
EOF"
```

Enable the extension:

```bash
sudo phpenmod -s cli openswoole
```

### Step 7: Verify OpenSwoole Installation

Confirm OpenSwoole is properly installed:

```bash
php -m | grep openswoole
```

### Step 8: Windows File System Access (Optional)

Create a symbolic link for easy access to Windows files:

> **Note:** Requires creating a `C:\WSL` directory first

```bash
ln -s /mnt/c/WSL ~/wsl
```

## 🍎 macOS Installation

### Prerequisites
- Homebrew must be installed on your system
- Visit [https://brew.sh/](https://brew.sh/) for installation instructions

### Step 1: Install PHP via Homebrew

Update Homebrew and install PHP:

```bash
brew update
brew install php
```

### Step 2: Verify Installation

Check PHP and PECL versions:

```bash
which php && php -v
which pecl && pecl version
```

### Step 3: Configure Environment Variables

Set required environment variables for OpenSwoole compilation:

```bash
export CPPFLAGS="-I$(brew --prefix openssl)/include -I$(brew --prefix pcre2)/include"
export LDFLAGS="-L$(brew --prefix openssl)/lib -L$(brew --prefix pcre2)/lib"
export PKG_CONFIG_PATH="$(brew --prefix openssl)/lib/pkgconfig:$(brew --prefix pcre2)/lib/pkgconfig"
```

### Step 4: Configure Permissions

Set up required folder permissions for PECL:

> **Important:** The folder name "20240924" may vary depending on your PHP version. Verify the correct folder name.
> 
> **Note:** Never run Homebrew, PECL, or PHP commands as root user.

```bash
sudo chown -R $(whoami) /opt/homebrew/
sudo chown -R $(whoami) /private/tmp/pear
mkdir -p /opt/homebrew/lib/php/pecl/20240924
```

### Step 5: Install OpenSwoole Extension

Install the OpenSwoole extension:

```bash
pecl install openswoole
```

### Step 6: Update PHP Configuration

Add OpenSwoole extension to PHP configuration:

```bash
echo "extension=openswoole" > /opt/homebrew/etc/php/8.4/conf.d/99-openswoole.ini
```

### Step 7: Verify Installation

Confirm OpenSwoole is properly installed:

```bash
php -m | grep openswoole
```

### Step 8: Troubleshooting (If Needed)

If port 9501 is occupied, terminate conflicting processes:

```bash
kill -9 $(lsof -t -i :9501)
```
