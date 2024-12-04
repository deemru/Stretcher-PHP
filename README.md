```bash
useradd -m -s /bin/bash stretcher && mkdir /stretcher && chown -R stretcher /stretcher
sudo -u stretcher bash -c "cd /stretcher && git clone https://github.com/deemru/Stretcher.git"
sudo -u stretcher bash -c "cd /stretcher/Stretcher && composer update -o --no-cache"
```

```bash
sudo -u stretcher bash -c "nano /stretcher/Stretcher/config.php"
```
```php
<?php
$argv[1] = '0.0.0.0:8080'; // FROM
$argv[2] = '127.0.0.1:80'; // TO
$argv[3] = 12; // TIMEOUT
$argv[4] = 12; // WINDOW
$argv[5] = 4; // TARGET
$argv[6] = 64; // HARDLIMIT
$argv[7] = true; // DEBUG
```

```bash
nano /etc/crontab
```
```bash
@reboot stretcher nohup bash /stretcher/Stretcher/run.sh | systemd-cat -t Stretcher &
```

```bash
journalctl -t Stretcher -f -n 100
```