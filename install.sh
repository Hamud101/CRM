#!/usr/bin/env bash
# One-command local install for the CRM.
#
# It writes .env with your answers, builds the Docker image, starts the CRM and
# the worker, waits for the app to answer, and adds a desktop shortcut.
# Re-running it is how you update: it keeps your answers and never touches the
# data volume, so your leads and settings survive.
set -euo pipefail

cd "$(dirname "$0")"

say() { printf '%s\n' "$*"; }

# Prompt on stderr, return the answer on stdout, so callers can capture it.
ask() {
    local q="$1" def="${2:-}" reply
    if [ -n "$def" ]; then printf '%s [%s]: ' "$q" "$def" >&2; else printf '%s: ' "$q" >&2; fi
    read -r reply || reply=''
    printf '%s' "${reply:-$def}"
}

yesno() {
    local q="$1" reply
    printf '%s [y/N]: ' "$q" >&2
    read -r reply || reply=''
    case "$reply" in y|Y|yes|YES|Yes) return 0 ;; *) return 1 ;; esac
}

# Strip characters that would break the KEY="value" lines in .env.
clean() { printf '%s' "$1" | tr -d "\"'" | tr -d '\\\n'; }

# Read one value out of .env without sourcing it.
env_get() {
    grep -E "^$1=" .env 2>/dev/null | head -n1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//' || true
}

case "$(uname -s)" in
    Linux)   OS=linux ;;
    Darwin)  OS=mac ;;
    MINGW*|MSYS*|CYGWIN*) OS=windows ;;
    *)       OS=other ;;
esac

DOCKER="docker"
if ! command -v docker >/dev/null 2>&1 || ! docker compose version >/dev/null 2>&1; then
    if [ "$OS" = linux ]; then
        say "Docker (with Compose) is not installed yet."
        if yesno "Install Docker now? It needs your password (sudo)"; then
            curl -fsSL https://get.docker.com | sudo sh
            sudo usermod -aG docker "$USER"
            sudo systemctl enable --now docker
        else
            say "No problem. Install Docker later from https://docs.docker.com/engine/install/ and run ./install.sh again."
            exit 1
        fi
    else
        say "Install Docker Desktop from https://www.docker.com/products/docker-desktop/ , start it, then run ./install.sh again."
        exit 1
    fi
fi

if ! docker info >/dev/null 2>&1; then
    if [ "$OS" = linux ]; then
        if docker info 2>&1 | grep -qi 'permission denied'; then
            # Installed just now, so the group change is not live yet.
            DOCKER="sudo docker"
            say "Using sudo for this run. After you log out and back in, you will not need it."
        else
            say "Starting the Docker service..."
            sudo systemctl start docker
            if ! docker info >/dev/null 2>&1; then
                say "Docker did not start. Check \`sudo systemctl status docker\`."
                exit 1
            fi
        fi
    else
        say "Start Docker Desktop, then run ./install.sh again."
        exit 1
    fi
fi

if [ -f .env ]; then
    say "Using your answers from last time (.env)."
else
    BIZ="$(ask 'Business name (press Enter to skip)')"
    SENDER="$(ask 'Your first name, for signing emails (press Enter to skip)')"

    TZ_DEFAULT="America/Chicago"
    if command -v timedatectl >/dev/null 2>&1; then
        TZ_DEFAULT="$(timedatectl show -p Timezone --value 2>/dev/null || true)"
    fi
    if [ -z "${TZ_DEFAULT:-}" ] && [ -L /etc/localtime ]; then
        TZ_DEFAULT="$(readlink /etc/localtime | sed 's|.*/zoneinfo/||')"
    fi
    [ -n "${TZ_DEFAULT:-}" ] || TZ_DEFAULT="America/Chicago"
    ZONE="$(ask 'Timezone' "$TZ_DEFAULT")"

    while :; do
        PORT="$(ask 'Port' '8080')"
        case "$PORT" in ''|*[!0-9]*) say "Enter a number between 1024 and 65535."; continue ;; esac
        if [ "$PORT" -lt 1024 ] || [ "$PORT" -gt 65535 ]; then
            say "Enter a number between 1024 and 65535."; continue
        fi
        break
    done

    BIZ="$(clean "$BIZ")"
    SENDER="$(clean "$SENDER")"
    ZONE="$(clean "$ZONE")"
    PORT="$(clean "$PORT")"
    {
        echo "# Written by install.sh. Read only on the first start; after that"
        echo "# the settings live in the CRM's data."
        echo "CRM_BUSINESS=\"$BIZ\""
        echo "CRM_SENDER=\"$SENDER\""
        echo "CRM_TZ=\"$ZONE\""
        echo "CRM_PORT=\"$PORT\""
        echo "CRM_URL=\"http://localhost:$PORT/\""
    } > .env
    chmod 600 .env
fi

# Read the answers back, so both paths use the same source.
BIZ="$(env_get CRM_BUSINESS)"
PORT="$(env_get CRM_PORT)"; PORT="${PORT:-8080}"

say "Building and starting the CRM. The first run takes a few minutes."
$DOCKER compose up -d --build

say "Waiting for the CRM to answer on port $PORT..."
i=0
until curl -fsS -o /dev/null "http://localhost:$PORT/"; do
    i=$((i + 1))
    if [ "$i" -ge 60 ]; then
        say "The CRM did not start in time. Recent logs:"
        $DOCKER compose logs --tail 50 crm
        exit 1
    fi
    sleep 2
done

# The shortcut is a nicety; never fail the install over it.
(
set +e
if [ -n "$BIZ" ]; then
    name="$BIZ CRM"
    slug="$(printf '%s' "$BIZ" | tr '[:upper:]' '[:lower:]' | sed 's/[^a-z0-9]/-/g')"
    base="crm-$slug"
else
    name="CRM"
    base="crm"
fi
if [ "$OS" = linux ]; then
    appdir="$HOME/.local/share/applications"
    mkdir -p "$appdir"
    desktop="$appdir/$base.desktop"
    {
        echo '[Desktop Entry]'
        echo 'Type=Application'
        echo "Name=$name"
        echo "Exec=xdg-open http://localhost:$PORT/"
        echo 'Icon=x-office-address-book'
        echo 'Terminal=false'
        echo 'Categories=Office;'
    } > "$desktop"
    chmod +x "$desktop"
    ddir="$(xdg-user-dir DESKTOP 2>/dev/null || true)"
    [ -n "$ddir" ] || ddir="$HOME/Desktop"
    if [ -d "$ddir" ]; then
        cp "$desktop" "$ddir/$base.desktop"
        chmod +x "$ddir/$base.desktop"
        gio set "$ddir/$base.desktop" metadata::trusted true 2>/dev/null || true
    fi
fi
if [ "$OS" = mac ]; then
    cat > "$HOME/Desktop/$name.webloc" <<EOF
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>URL</key>
    <string>http://localhost:$PORT/</string>
</dict>
</plist>
EOF
fi
if [ "$OS" = windows ]; then
    {
        echo '[InternetShortcut]'
        echo "URL=http://localhost:$PORT/"
    } > "${USERPROFILE:-$HOME}/Desktop/$name.url"
fi
) || true

say ""
say "Done. Your CRM is at http://localhost:$PORT/ (no password: it only opens on this computer). It starts by itself when the computer starts."
case "$OS" in
    linux)   xdg-open "http://localhost:$PORT/" >/dev/null 2>&1 || true ;;
    mac)     open "http://localhost:$PORT/" >/dev/null 2>&1 || true ;;
    windows) cmd.exe /c start "" "http://localhost:$PORT/" >/dev/null 2>&1 || true ;;
esac
