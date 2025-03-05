# griotte

GR IoT

(lots of documentation is missing; please feel free to let me know if you are interested, that will boost me)


## Installing the webserver as a service

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
