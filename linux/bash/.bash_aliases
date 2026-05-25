#!/usr/bin/env bash
alias incominglogs='docker service logs -f -t -n20 griotte_incoming'
alias incomingps='docker ps --filter name=griotte_incoming -q --no-trunc'
alias incomingstop='docker stop "$(docker ps --filter name=griotte_incoming -q)"'
alias incomingexec='docker exec -it "$(docker ps --filter name=griotte_incoming -q)" sh'
alias watchbuffer='docker exec -it "$(docker ps --filter name=griotte_incoming -q)" sh -c "time watch \"cat /var/www/run/buffer.csv | expand\""'
alias dbstats="time /usr/local/bin/dbstats"
alias watchdb="time watch /usr/local/bin/dbstats"
alias graphlogs='docker service logs -f -t -n20 griotte_graphs'
alias graphps='docker ps --filter name=griotte_graphs -q --no-trunc'
alias graphstop='docker stop "$(docker ps --filter name=griotte_graphs -q)"'
alias graphexec='docker exec -it "$(docker ps --filter name=griotte_graphs -q)" sh'
