#!/usr/bin/env bash
set -euo pipefail

usage() {
    cat <<'EOF'
Usage: bash scripts/install-php-composer.sh [--dry-run] [--yes]

Install system PHP CLI, required extensions, and Composer on Ubuntu >= 22.04.
Uses configured APT repositories; does not add PPAs or modify Conda.
Run as your normal user: only APT commands use sudo when needed.

  --dry-run   Print the package commands without sudo, downloads, or writes.
  --yes, -y   Accept APT's package-installation prompt (sudo may still prompt).
  --help, -h  Show this help.

Does not install vendor/ or frontend packages, run migrations/tests, configure
mail or a production web server, or modify the application database or .env.
EOF
}

check_ubuntu() {
    local release_file="${1:-/etc/os-release}" ID='' VERSION_ID=''
    if [[ ! -r "$release_file" ]]; then
        echo 'Cannot identify Ubuntu: /etc/os-release is unavailable.' >&2
        return 1
    fi
    # shellcheck source=/dev/null
    source "$release_file"
    if [[ "$ID" != ubuntu || ! "$VERSION_ID" =~ ^[0-9]+\.[0-9]+$ ]] ||
        ! /usr/bin/dpkg --compare-versions "$VERSION_ID" ge 22.04; then
        echo 'This installer requires Ubuntu >= 22.04; no packages were changed.' >&2
        return 1
    fi
}

run() {
    printf '+ '
    printf '%q ' "$@"
    printf '\n'
    if (( ! dry_run )); then
        "$@"
    fi
}

system_php() {
    # Do not accidentally verify a PHP executable from an active Conda env.
    /usr/bin/php "$@"
}

verify_runtime() {
    system_php --version || return $?
    # PHP variables must not be expanded by Bash.
    # shellcheck disable=SC2016
    system_php -r '
        if (PHP_VERSION_ID < 80100 || PHP_VERSION_ID >= 90000) {
            fwrite(STDERR, "This project requires PHP >= 8.1 and < 9.0. Check /usr/bin/php and update-alternatives --config php.\n");
            exit(1);
        }
        $required = ["sqlite3", "pdo_sqlite", "curl", "dom", "libxml", "mbstring",
            "xml", "xmlwriter", "SimpleXML", "tokenizer", "json", "zip",
            "session", "openssl", "fileinfo"];
        $missing = array_filter($required, fn($name) => !extension_loaded($name));
        if ($missing) {
            fwrite(STDERR, "Missing PHP extensions: " . implode(", ", $missing) . "\nCheck /usr/bin/php --ini and any PHPRC/PHP_INI_SCAN_DIR overrides.\n");
            exit(1);
        }
        echo "Required application and test extensions are enabled.\n";
    ' || return $?
    system_php /usr/bin/composer --no-plugins --no-scripts --version
}

main() {
    local dry_run=0
    local -a apt_prefix=() install_options=()
    local -a packages=(
        php-cli php-sqlite3 php-curl php-xml php-mbstring php-zip
        composer ca-certificates unzip
    )
    while (( $# )); do
        case "$1" in
            --dry-run) dry_run=1 ;;
            --yes|-y) install_options=(--yes) ;;
            --help|-h) usage; return 0 ;;
            *) echo "Unknown option: $1" >&2; usage >&2; return 2 ;;
        esac
        shift
    done
    check_ubuntu
    if (( EUID != 0 )); then
        if (( ! dry_run )) && [[ ! -x /usr/bin/sudo ]]; then
            echo 'sudo is required, or ask an administrator to run this script.' >&2
            return 1
        fi
        apt_prefix=(/usr/bin/sudo)
    fi
    echo 'Installing the Ubuntu CLI runtime only; Conda and application data are unchanged.'
    echo 'Composer/php-mbstring require the Ubuntu universe repository to be enabled.'
    run "${apt_prefix[@]}" /usr/bin/apt-get update
    run "${apt_prefix[@]}" /usr/bin/apt-get install --no-install-recommends --no-remove \
        "${install_options[@]}" "${packages[@]}"
    if (( dry_run )); then
        echo 'Dry run: would verify system PHP 8.1–8.x, required extensions, and /usr/bin/composer.'
        return 0
    fi
    verify_runtime
    echo 'System PHP and Composer verification passed.'
    if [[ "$(command -v php || true)" != /usr/bin/php && "$(command -v php || true)" != /bin/php ]]; then
        echo 'Warning: your PATH selects a different PHP. Remove the conflicting PHP from PATH before using Makim.' >&2
    fi
    cat <<'EOF'

Next, from the repository root as your normal user (not sudo):
  conda activate rinchen
  /usr/bin/php /usr/bin/composer validate --strict
  /usr/bin/php /usr/bin/composer install
  /usr/bin/php /usr/bin/composer check-platform-reqs
  npm ci --prefix frontend
  npm run build --prefix frontend

If Composer reports a manifest/lock mismatch, stop and review it separately.
Do not use composer update or --ignore-platform-reqs to hide setup failures.
Mail transport, database setup, and production web-server configuration are separate.
EOF
}

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
    main "$@"
fi
