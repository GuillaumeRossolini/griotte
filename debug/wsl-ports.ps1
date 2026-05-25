## These commands are meant to be run once in an elevated PowerShell prompt
## adjust the IP address for your system

## in my case, I wanted Windows to forward local port 8081 to WSL as port 80

## listenaddress: run the following command from PowerShell and look for the IPv4 Address in "Hyper-V Virtual Ethernet Adapter"
## CMD /S /C "ipconfig /all"
## NB: you are allowed the wildcard IP 0.0.0.0 in listen parameters, but not in target parameters

## connectaddress: run the following command from PowerShell and look for the address in front of "inet"
## wsl ip address show dev eth0

## NB: if the color scheme makes it hard to read the output from "ip address" in PowerShell, try this command instead from within WSL:
## cat <(ip a show dev eth0)


# check netsh rules
netsh interface portproxy show all

# add a netsh rule
netsh interface portproxy add v4tov4 `
listenaddress=0.0.0.0 listenport=8081 `
connectaddress=172.25.245.99 connectport=80

# remove the rule
netsh interface portproxy delete v4tov4 `
listenaddress=0.0.0.0 listenport=8081


## the following command if the above wasn't enough (I didn't need them)

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
