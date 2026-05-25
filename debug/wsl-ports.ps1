## These commands are meant to be run once in an elevated PowerShell prompt
## adjust the IP address for your system

## in my case, I wanted Windows to forward local port 8081 to WSL as port 8081

## listenaddress: run the following command from PowerShell and look for the IPv4 Address in "Hyper-V Virtual Ethernet Adapter"
## CMD /S /C "ipconfig /all"
## NB: you are allowed the wildcard IP 0.0.0.0 in listen parameters, but not in target parameters

## connectaddress: run the following command from PowerShell and look for the address in front of "inet"
## wsl ip address show dev eth0

## NB: if the color scheme makes it hard to read the output from "ip address" in PowerShell, try this command instead from within WSL:
## cat <(ip a show dev eth0)


# check netsh rules
netsh interface portproxy show all

# add netsh rules for "incoming" and "graphs" endpoints
netsh interface portproxy add v4tov4 `
listenaddress=192.168.1.18 listenport=8081 `
connectaddress=172.25.245.99 connectport=8081

netsh interface portproxy add v4tov4 `
listenaddress=192.168.1.18 listenport=8082 `
connectaddress=172.25.245.99 connectport=8082

# remove the rules
netsh interface portproxy delete v4tov4 `
listenaddress=192.168.1.18 listenport=8081

netsh interface portproxy delete v4tov4 `
listenaddress=192.168.1.18 listenport=8082


## the following commands if the above wasn't enough
## they are not needed if your browser is on the same computer (for example the "graphs" service)
## but any networked device (like an ESP32) will likely require a public path to the "incoming" service

# check firewall rules
Get-NetFirewallRule | Where-Object DisplayName -like "*WSL*"

# add a firewall rule
New-NetFirewallRule `
-DisplayName "WSL 8081 Forward" `
-Direction Inbound `
-LocalPort 8081 `
-Protocol TCP `
-Action Allow

# remove a rule
Remove-NetFirewallRule -DisplayName "WSL 8081 Forward"
