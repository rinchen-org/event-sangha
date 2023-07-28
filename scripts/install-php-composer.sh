#!/usr/bin/env bash
set -ex
CONDA_ROOT=$(cd "$CONDA_PREFIX" && cd ../../ && pwd)
source "${CONDA_ROOT}/etc/profile.d/conda.sh"

conda activate rinchen

mkdir -p /tmp/php
cd /tmp/php

# ref: https://getcomposer.org/download/
php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
php -r "if (hash_file('sha384', 'composer-setup.php') === 'e21205b207c3ff031906575712edab6f13eb0b361f2085f1f1237b7126d785e826a450292b6cfd1d64d92e6563bbde02') { echo 'Installer verified'; } else { echo 'Installer corrupt'; unlink('composer-setup.php'); } echo PHP_EOL;"
php composer-setup.php --install-dir="$CONDA_PREFIX/bin" --filename=composer
php -r "unlink('composer-setup.php');"
