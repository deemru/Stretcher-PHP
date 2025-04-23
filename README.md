# Stretcher-PHP

Stretcher is a minimal request throttling proxy server.

- Serializes requests per IP
- Throttles requests based on their consumption
- Configurable window and target
- Easy to deploy and monitor

## Basic usage

```bash
php Stretcher.php --listen=127.0.0.1:8080 --upstream=127.0.0.1:80 --timeout=12 --window=12 --target=4 --concurrency=64 --maxbytes=65536 --debug=true
```

## Configuration

| Option | Description | Default Value |
|--------|-------------|---------------|
| `--listen` | Listen address and port | 127.0.0.1:8080 |
| `--upstream` | Upstream server address and port | 127.0.0.1:80 |
| `--timeout` | Request timeout in seconds | 12 |
| `--window` | Time window for rate limiting in seconds | 12 |
| `--target` | Target requests per window | 4 |
| `--concurrency` | Maximum concurrent requests per IP | 64 |
| `--maxbytes` | Maximum request body size in bytes | 65536 |
| `--debug` | Enable debug logging | true |

Example usage:
```bash
php Stretcher.php --listen=127.0.0.1:8080 --upstream=127.0.0.1:80 --timeout=12 --window=12 --target=4 --concurrency=64 --maxbytes=65536 --debug=true
```

## Installation

### Manual installation

```bash
apt update
apt install php-cli php-curl git -y
```

### Install Composer
```bash
php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
php composer-setup.php --install-dir=/usr/local/bin --filename=composer
rm composer-setup.php
```

### Create user and setup directory
```bash
useradd -m -s /bin/bash stretcher
mkdir /stretcher
chown -R stretcher /stretcher
```

### Clone repository and install dependencies
```bash
sudo -u stretcher bash -c "cd /stretcher && git clone https://github.com/deemru/Stretcher-PHP.git"
sudo -u stretcher bash -c "cd /stretcher/Stretcher-PHP && git pull && composer update -o --no-cache"
```

## Running

### Start the service
```bash
sudo -u stretcher bash -c "cd /stretcher/Stretcher-PHP && php Stretcher.php --upstream=127.0.0.1:80 --debug=true"
```

### For automatic startup on boot
```bash
echo "@reboot stretcher cd /stretcher/Stretcher-PHP && php Stretcher.php --upstream=127.0.0.1:80 --debug=true | systemd-cat -t Stretcher &" >> /etc/crontab
```

## Monitoring

### View the logs
```bash
journalctl -t Stretcher -f -n 100
```