#!/usr/bin/env bash
alias apilogs='docker service logs -f -t -n20 griotte_api'
alias apips='docker ps --filter name=griotte_api -q --no-trunc'
alias apistop='docker stop "$(docker ps --filter name=griotte_api -q)"'
alias apiexec='docker exec -it "$(docker ps --filter name=griotte_api -q)" sh'
alias watchbuffer='docker exec -it "$(docker ps --filter name=griotte_api -q)" sh -c "time watch \"cat /var/www/run/buffer.csv | expand\""'
alias dbstats="time /usr/local/bin/dbstats"
alias watchdb="time watch /usr/local/bin/dbstats"
alias graphlogs='docker service logs -f -t -n20 griotte_graphs'
alias graphps='docker ps --filter name=griotte_graphs -q --no-trunc'
alias graphstop='docker stop "$(docker ps --filter name=griotte_graphs -q)"'
alias graphexec='docker exec -it "$(docker ps --filter name=griotte_graphs -q)" sh'
