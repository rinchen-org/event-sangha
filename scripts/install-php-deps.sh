#!/usr/bin/env bash
set -ex
CONDA_ROOT=$(cd "$CONDA_PREFIX" && cd ../../ && pwd)
source "${CONDA_ROOT}/etc/profile.d/conda.sh"

conda activate rinchen

PROJECT_PATH="$( cd "$( dirname "${BASH_SOURCE[0]}")" && cd .. && pwd )"

cd "$PROJECT_PATH"

composer require --dev phpstan/phpstan
composer require --dev squizlabs/php_codesniffer
