alias griottelogs='docker service logs -f -t -n20 griotte_web'
alias griotteps='docker ps --filter name=griotte_web -q --no-trunc'
alias griottestop='docker stop "$(docker ps --filter name=griotte_web -q)"'
alias griotteexec='docker exec -it "$(docker ps --filter name=griotte_web -q)" sh'
alias watchbuffer='docker exec -it "$(docker ps --filter name=griotte_web -q)" sh -c "time watch \"cat /var/www/run/buffer.csv | expand\""'
alias dbstats="time /usr/local/bin/dbstats"
