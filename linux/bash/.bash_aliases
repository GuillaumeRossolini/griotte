#!/usr/bin/env bash
alias incominglogs='docker service logs -f -t -n20 griotte_incoming'
alias incomingps='docker ps --filter name=griotte_incoming -q --no-trunc'
alias incomingstop='docker stop "$(docker ps --filter name=griotte_incoming -q)"'
alias incomingexec='docker exec -it "$(docker ps --filter name=griotte_incoming -q)" bash'
alias watchbuffer='docker exec -it "$(docker ps --filter name=griotte_incoming -q)" bash -c "cat /var/www/run/buffer.csv | expand"'
alias dbstats="time /usr/local/bin/dbstats"
alias watchdb="time watch /usr/local/bin/dbstats"
alias graphlogs='docker service logs -f -t -n20 griotte_graphs'
alias graphps='docker ps --filter name=griotte_graphs -q --no-trunc'
alias graphstop='docker stop "$(docker ps --filter name=griotte_graphs -q)"'
alias graphexec='docker exec -it "$(docker ps --filter name=griotte_graphs -q)" bash'
