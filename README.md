# griotte
GR IoT


## Installing as a service

```
sudo cp griotte.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable griotte.service
sudo systemctl start griotte.service
```

Read the logs with:
```
# -b means "since last boot" and -f means "follow/tail"
journalctl -u griotte -b -f
```
