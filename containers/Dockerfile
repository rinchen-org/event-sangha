FROM webdevops/php-nginx:7.4

ARG UID=1000
ARG GID=1000

RUN apt-get update -y \
  && apt-get install -y \
    sudo \
    tzdata \
    openssl \
  && rm -rf /var/lib/apt/lists/* \
    /var/cache/apt/archives \
    /tmp/*

# RUN addgroup --gid ${GID} devops \
#   && useradd --uid ${UID} --gid ${GID} -ms /bin/bash devops \
#   && echo "devops ALL=(ALL) NOPASSWD: ALL" > /etc/sudoers.d/devops \
#   && chmod 0440 /etc/sudoers.d/devops
