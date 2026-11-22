#!/usr/bin/env bash
# v1.8 (19.11.2026): Paketfähigen Abholer und Python-cryptography je Zielsystem installieren.
# zielserver-vorbereiten-v1.8.sh
#
# v1.8
# von Dr. Thomas Kienzle / ChatGPT
#
# Changelog
# 2026-11-22: Aktive Webdienste nach Vergabe der Inbox-Gruppe zuverlässig neu starten und Fehler melden.
# 2026-11-19: Praxisserver mit SSH-Abholer oder lokaler Kienzlefon-Inbox aus dem Modulmenü installieren.
# 2026-11-18: Paketquelle vorab pruefen; Schluessel separat fuer das Fon-Setup vorbereiten.
# 2026-11-18 (Version unveraendert: v1.8)
# - installiert Telefonie-Proxy, Browsermodul und Schema fuer App 3.7
# 2026-09-29 (Version unveraendert: v1.8)
# - SMS-Queue-Bibliothek und PDO-SQLite fuer die optionale Queue-Anbindung bereitstellen; Worker bleibt separat einzurichten
# 2026-09-29 (Version unveraendert: v1.8)
# - verwendet den Produktnamen "kienzlefon app" in den Webinterface-Dialogen
# v1.8
# - behebt die Adminpasswort-Erzeugung: keine unsichtbaren Zeilenumbrueche mehr im gepatchten Passwort
# - generiert sechsstellige, gut lesbare Adminpasswoerter ohne mehrdeutige Zeichen
# v1.7
# - bootstrapped telepraxis-sms.php und sms-config.php in den Webroot
# - legt /srv/telepraxis/<benutzer>/config fuer SMS-Credentials ausserhalb des Webroots an
# - patcht den SMS-Credentials-Pfad in telepraxis-sms.php und sms-config.php
# - generiert oder uebernimmt Adminpasswoerter fuer Telepraxis-Webinterface und sms-config.php
# v1.6
# - legt die Web-App immer als /var/www/html/telepraxis-app.php ab
# - Fetch-Dienst, Keys und lokale Inbox bleiben weiterhin pro Benutzer getrennt
# v1.5
# - installiert auf Debian/Ubuntu-Zielsystemen gezielt php-curl und php-xml
# - prueft nach der Paketinstallation die PHP-Module curl, dom und SimpleXML
# v1.4
# - setzt die lokale Benutzerbasis auf owner=<benutzer>, group=tp-<benutzer>, mode=0710
# - erlaubt www-data damit nur das Durchqueren zur lokalen Inbox, damit die App JSON-Dateien lesen kann
# v1.3
# - patcht telepraxis-app.php nun auch bei aktuellem App-Stand mit TELEPRAXIS_INBOX_DIR-Konstante
# - bleibt rueckwaertskompatibel zum alten Platzhalter /srv/telepraxis/inbox
# v1.2
# - trennt SSH-Home/Key-Bereich strikt von schreibbarer Inbox
# - verwendet gemeinsame Gruppe tp-<benutzer> fuer Inbox statt www-data direkt in die Benutzer-Primärgruppe zu legen
# - historisch bis v1.5: telepraxis-app wurde mit Benutzerzusatz abgelegt
# - Fetch-Dienst und Fetch-Script bleiben pro Benutzer getrennt
# - Rechte fuer Inbox robuster mit setgid und optionalen Default-ACLs
# - Webstack-Neustart fuer nginx/Apache/PHP-FPM robuster
# v1.1
# - Rechte-/Gruppen-Setup fuer Inbox verfeinert
# - optionale Default-ACL fuer Inbox gesetzt, falls setfacl vorhanden ist
# - Webstack-Neustart robuster umgesetzt (Apache, nginx, PHP-FPM)
# - systemd-Dienst mit UMask=0002
# - Fetch-Script mit umask 0002 gepatcht
# - chown nicht mehr rekursiv ueber die komplette Basisstruktur
# v1.0
# - legt interaktiv einen Zielserver-Benutzer an oder verwendet ihn weiter
# - erzeugt SSH-Key fuer den Fetch vom Quellsystem
# - erzeugt RSA-Keypaar fuer die JSON-Entschluesselung
# - legt /srv/telepraxis/<benutzer>/inbox und /srv/telepraxis/<benutzer>/.encrypted an
# - bootstrapped telepraxis-app.php
# - bootstrapped telepraxis_fetch_and_decrypt.sh
# - patcht App- und Fetch-Konfiguration automatisch auf den gewaehlten Benutzer
# - richtet einen systemd-Dienst ein, der unter dem gewaehlten Benutzer laeuft
# - gibt am Ende SSH-Public-Key und RSA-Public-Key auf der Konsole aus

set -euo pipefail

UPSTREAM="https://raw.githubusercontent.com/thomaskien/IONOS_AI_Receptionist_Interface_Arztpraxis/refs/heads/main"
WEB_FILES=(telepraxis-app.php telepraxis-sms.php sms-config.php telepraxis-sms-queue.php
           telepraxis-telephony.php telepraxis-telephony.js telepraxis-telephony-schema.json)
FETCH_FILE="telepraxis_fetch_and_decrypt.sh"
AUDIO_HELPER="telepraxis-audio-delivery.py"
SOURCE_STAGE=""

BASE_ROOT="/srv/telepraxis"
WEBROOT_DIR="/var/www/html"
BIN_DIR="/usr/local/bin"
UNIT_DIR="/etc/systemd/system"
WEB_USER="www-data"

require_root() {
  if [ "$(id -u)" -ne 0 ]; then
    echo "Bitte als root ausfuehren."
    exit 1
  fi
}

need_cmd() {
  command -v "$1" >/dev/null 2>&1 || {
    echo "Fehlt: $1"
    exit 1
  }
}

install_php_extension_packages() {
  local packages=(php-curl php-xml php-sqlite3)
  if (( $1 )); then packages+=(python3-cryptography jq openssh-client openssl); fi
  if command -v apt-get >/dev/null 2>&1; then
    export DEBIAN_FRONTEND=noninteractive
    apt-get update
    apt-get install -y "${packages[@]}"
  else
    echo "Bitte benötigte Pakete auf diesem System bereitstellen: ${packages[*]}"
  fi
}

verify_php_extensions() {
  local modules
  modules="$(php -m)"
  grep -iq '^pdo_sqlite$' <<< "$modules" || {
    echo "PHP-PDO-SQLite-Unterstuetzung fehlt. Bitte php-sqlite3 installieren."
    exit 1
  }
  grep -iq '^curl$' <<< "$modules" || {
    echo "PHP-cURL-Unterstuetzung fehlt. Bitte php-curl installieren."
    exit 1
  }
  grep -iq '^dom$' <<< "$modules" || {
    echo "PHP-DOM-Unterstuetzung fehlt. Bitte php-xml installieren."
    exit 1
  }
  grep -iq '^SimpleXML$' <<< "$modules" || {
    echo "PHP-SimpleXML-Unterstuetzung fehlt. Bitte php-xml installieren."
    exit 1
  }
}

stage_programs() {
  local source_dir="$1" with_fetch="$2" name
  local files=("${WEB_FILES[@]}")
  if (( with_fetch )); then files+=("$FETCH_FILE" "$AUDIO_HELPER"); fi
  SOURCE_STAGE="$(mktemp -d)"
  trap 'rm -rf -- "$SOURCE_STAGE"' EXIT
  for name in "${files[@]}"; do
    if [[ -n "$source_dir" ]]; then
      if [[ ! -f "$source_dir/$name" || -L "$source_dir/$name" ]]; then
        echo "Regulaere Paketdatei fehlt: $source_dir/$name" >&2
        return 1
      fi
      cp -- "$source_dir/$name" "$SOURCE_STAGE/$name"
    elif command -v curl >/dev/null 2>&1; then
      curl -fsSL "$UPSTREAM/$name" -o "$SOURCE_STAGE/$name"
    else
      wget -qO "$SOURCE_STAGE/$name" "$UPSTREAM/$name"
    fi
    [[ -s "$SOURCE_STAGE/$name" ]] || { echo "Leere Programmdatei: $name" >&2; return 1; }
  done
  if (( with_fetch )); then bash -n "$SOURCE_STAGE/$FETCH_FILE"; fi
  python3 -m json.tool "$SOURCE_STAGE/telepraxis-telephony-schema.json" >/dev/null
}

lint_web_files() {
  local name
  for name in "${WEB_FILES[@]}"; do
    if [[ "$name" == *.php ]]; then php -l "$SOURCE_STAGE/$name" >/dev/null; fi
  done
}

ask_default() {
  local prompt="$1"
  local def="$2"
  local value=""
  read -r -p "$prompt [$def]: " value
  if [ -z "$value" ]; then
    value="$def"
  fi
  printf '%s\n' "$value"
}

generate_admin_password() {
  python3 - <<'PY'
import secrets
alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789'
print(''.join(secrets.choice(alphabet) for _ in range(6)))
PY
}

ask_password_or_generate() {
  local result_var="$1"
  local prompt="$2"
  local value=""
  read -r -s -p "$prompt [Enter = automatisch generieren]: " value
  echo
  if [ -z "$value" ]; then
    value="$(generate_admin_password)"
  fi
  printf -v "$result_var" '%s' "$value"
}

ask_user() {
  local u=""
  while :; do
    read -r -p "Lokaler Zielserver-Benutzer: " u || return 1
    if [[ "$u" =~ ^[a-z_][a-z0-9_-]*$ ]]; then
      printf '%s\n' "$u"
      return 0
    fi
    echo "Ungueltiger Benutzername. Erlaubt: a-z, 0-9, _, -, Beginn mit Buchstabe/_" >&2
  done
}

backup_if_exists() {
  local path="$1"
  if [ -f "$path" ]; then
    cp -a "$path" "${path}.bak.$(date +%Y%m%d_%H%M%S)"
  fi
}

ensure_group() {
  local group="$1"
  if getent group "$group" >/dev/null 2>&1; then
    echo "Gruppe $group existiert bereits, verwende sie weiter."
  else
    groupadd "$group"
    echo "Gruppe $group wurde angelegt."
  fi
}

ensure_user() {
  local user="$1"
  local home="$2"

  if [[ "$user" == "$WEB_USER" || "$(id -u "$user" 2>/dev/null)" == 0 ]]; then
    echo "Bitte einen eigenen, unprivilegierten Benutzer angeben." >&2; return 1
  fi
  if id "$user" >/dev/null 2>&1; then
    echo "Benutzer $user existiert bereits, verwende ihn weiter."
    usermod -d "$home" "$user" >/dev/null 2>&1 || true
  else
    useradd -m -d "$home" -s /bin/bash -U "$user"
    passwd -l "$user" >/dev/null 2>&1 || true
    echo "Benutzer $user wurde angelegt."
  fi
}

ensure_dirs() {
  local user="$1"
  local shared_group="$2"
  local inbox_dir="$3"
  local config_dir="$4"

  install -d -o "$user" -g "$shared_group" -m 2770 "$inbox_dir"
  install -d -o "$user" -g "$shared_group" -m 2770 "$config_dir"

  usermod -a -G "$shared_group" "$user"
  if id "$WEB_USER" >/dev/null 2>&1; then
    usermod -a -G "$shared_group" "$WEB_USER"
  fi

  if command -v setfacl >/dev/null 2>&1; then
    setfacl -m "u:${user}:rwx,g:${shared_group}:rwx" "$inbox_dir" || true
    setfacl -m "u:${user}:rwx,g:${shared_group}:rwx" "$config_dir" || true
    if id "$WEB_USER" >/dev/null 2>&1; then
      setfacl -m "u:${WEB_USER}:rwx" "$inbox_dir" || true
      setfacl -m "u:${WEB_USER}:rwx" "$config_dir" || true
      setfacl -d -m "u:${WEB_USER}:rwx,g:${shared_group}:rwx" "$inbox_dir" || true
      setfacl -d -m "u:${WEB_USER}:rwx,g:${shared_group}:rwx" "$config_dir" || true
    fi
    setfacl -d -m "u:${user}:rwx,g:${shared_group}:rwx" "$inbox_dir" || true
    setfacl -d -m "u:${user}:rwx,g:${shared_group}:rwx" "$config_dir" || true
  fi
}

ensure_sms_credentials_file() {
  local user="$1"
  local shared_group="$2"
  local credentials_file="$3"

  if [ ! -f "$credentials_file" ]; then
    printf "{\n}\n" > "$credentials_file"
  fi
  chown "$user:$shared_group" "$credentials_file"
  chmod 0660 "$credentials_file"
}

check_key_paths() {
  local private="$1" public="$2" path
  for path in "$private" "$public"; do
    if [[ -L "$path" || ( -e "$path" && ! -f "$path" ) ]]; then
      echo "Ungueltiger Schluesselpfad: $path" >&2
      return 1
    fi
  done
  if [[ -f "$public" && ! -f "$private" ]]; then
    echo "Privater Schluessel fehlt zu $public. Bestehenden Schluessel aus Sicherung wiederherstellen." >&2
    return 1
  fi
}

generate_ssh_keypair() (
  local private="$1" public="$1.pub" tmp
  tmp="$(mktemp -d "$(dirname "$private")/.fetch-key.XXXXXX")"
  trap 'rm -rf -- "$tmp"' EXIT
  if [[ ! -f "$private" ]]; then
    ssh-keygen -t ed25519 -N '' -f "$tmp/key" >/dev/null
    ln "$tmp/key" "$private"
  fi
  ssh-keygen -y -P '' -f "$private" > "$tmp/derived"
  if [[ -f "$public" ]]; then
    # SSH-Kommentare gehoeren nicht zum Schluessel.
    awk '{print $1, $2}' "$public" > "$tmp/existing"
    awk '{print $1, $2}' "$tmp/derived" | cmp -s - "$tmp/existing" || {
      echo "SSH-Public-Key passt nicht zum privaten Schluessel: $public" >&2; return 1;
    }
  else
    ln "$tmp/derived" "$public"
  fi
  chmod 0600 "$private"
  chmod 0644 "$public"
)

generate_decrypt_keypair() (
  local private="$1" public="$2" tmp
  tmp="$(mktemp -d "$(dirname "$private")/.decrypt-key.XXXXXX")"
  trap 'rm -rf -- "$tmp"' EXIT
  if [[ ! -f "$private" ]]; then
    openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:4096 -out "$tmp/key" >/dev/null 2>&1
    ln "$tmp/key" "$private"
  fi
  openssl rsa -passin pass: -in "$private" -pubout -out "$tmp/derived" 2>/dev/null
  if [[ -f "$public" ]]; then
    openssl pkey -pubin -in "$public" -pubout -out "$tmp/existing"
    cmp -s "$tmp/derived" "$tmp/existing" || {
      echo "RSA-Public-Key passt nicht zum privaten Schluessel: $public" >&2; return 1;
    }
  else
    ln "$tmp/derived" "$public"
  fi
  chmod 0600 "$private"
  chmod 0644 "$public"
)

prepare_account() {
  local user="$1" group="$2" base="$3"
  ensure_group "$group"
  install -d -o root -g root -m 0755 "$BASE_ROOT"
  ensure_user "$user" "$base"
  [[ ! -L "$base" ]] || { echo "Symlink im Benutzerverzeichnis: $base" >&2; return 1; }
  install -d -o "$user" -g "$group" -m 0710 "$base"
}

prepare_keys() {
  local user="$1" base="$2" ssh_key="$3" private="$4" public="$5"
  [[ ! -L "$base/.ssh" ]] || { echo "Symlink im SSH-Verzeichnis." >&2; return 1; }
  install -d -o "$user" -g "$user" -m 0700 "$base/.ssh"

  # Das Home gehoert dem Kanalbenutzer. Dort mit seinen Rechten arbeiten.
  {
    declare -f check_key_paths generate_ssh_keypair generate_decrypt_keypair
    cat <<'KEYS'
umask 077
check_key_paths "$1" "$1.pub"
check_key_paths "$2" "$3"
generate_ssh_keypair "$1"
generate_decrypt_keypair "$2" "$3"
KEYS
  } | runuser -u "$user" -- flock --exclusive "$base" bash -seu -o pipefail -- "$ssh_key" "$private" "$public"
}

patch_app_file() {
  local app_file="$1"
  local inbox_dir="$2"
  local admin_password="$3"

  python3 - "$app_file" "$inbox_dir" "$admin_password" <<'PY'
import pathlib, re, sys
app_file, inbox_dir, admin_password = sys.argv[1:]
path = pathlib.Path(app_file)
text = path.read_text(encoding='utf-8')

def php_single_quoted(value):
    return "'" + value.replace("\\", "\\\\").replace("'", "\\'") + "'"

if '/srv/telepraxis/inbox' in text:
    text = text.replace('/srv/telepraxis/inbox', inbox_dir)
else:
    pattern = r"const\s+TELEPRAXIS_INBOX_DIR\s*=\s*.*?;"
    replacement = f"const TELEPRAXIS_INBOX_DIR = {php_single_quoted(inbox_dir)};"
    text, count = re.subn(pattern, lambda _: replacement, text, count=1, flags=re.S)
    if count != 1:
        raise SystemExit('Patch fehlgeschlagen: TELEPRAXIS_INBOX_DIR nicht gefunden')

pattern = r"const\s+TELEPRAXIS_ADMIN_PASSWORD\s*=\s*.*?;"
replacement = f"const TELEPRAXIS_ADMIN_PASSWORD = {php_single_quoted(admin_password)};"
text, count = re.subn(pattern, lambda _: replacement, text, count=1, flags=re.S)
if count != 1:
    raise SystemExit('Patch fehlgeschlagen: TELEPRAXIS_ADMIN_PASSWORD nicht gefunden')

path.write_text(text, encoding='utf-8')
PY

}

patch_sms_files() {
  local sms_lib_file="$1"
  local sms_config_file="$2"
  local credentials_file="$3"
  local sms_config_password="$4"

  python3 - "$sms_lib_file" "$sms_config_file" "$credentials_file" "$sms_config_password" <<'PY'
import pathlib, re, sys
sms_lib_file, sms_config_file, credentials_file, sms_config_password = sys.argv[1:]

def php_single_quoted(value):
    return "'" + value.replace("\\", "\\\\").replace("'", "\\'") + "'"

def patch_constant(text, name, value, label):
    pattern = rf"const\s+{name}\s*=\s*.*?;"
    replacement = f"const {name} = {php_single_quoted(value)};"
    text, count = re.subn(pattern, lambda _: replacement, text, count=1, flags=re.S)
    if count != 1:
        raise SystemExit(f'Patch fehlgeschlagen: {label} nicht gefunden')
    return text

for filename in (sms_lib_file, sms_config_file):
    path = pathlib.Path(filename)
    text = path.read_text(encoding='utf-8')
    text = patch_constant(text, 'TP_SMS_CREDENTIALS_FILE', credentials_file, 'TP_SMS_CREDENTIALS_FILE')
    if filename == sms_config_file:
        text = patch_constant(text, 'TP_SMS_CONFIG_ADMIN_PASSWORD', sms_config_password, 'TP_SMS_CONFIG_ADMIN_PASSWORD')
    path.write_text(text, encoding='utf-8')
PY

}

patch_fetch_file() {
  local fetch_file="$1"
  local remote_host="$2"
  local remote_user="$3"
  local remote_port="$4"
  local remote_dir="$5"
  local local_pull_dir="$6"
  local local_out_dir="$7"
  local private_key_file="$8"
  local ssh_identity_file="$9"

  python3 - "$fetch_file" "$remote_host" "$remote_user" "$remote_port" "$remote_dir" "$local_pull_dir" "$local_out_dir" "$private_key_file" "$ssh_identity_file" <<'PY'
import pathlib, re, sys
(fetch_file, remote_host, remote_user, remote_port, remote_dir,
 local_pull_dir, local_out_dir, private_key_file, ssh_identity_file) = sys.argv[1:]
text = pathlib.Path(fetch_file).read_text(encoding='utf-8')
replacements = {
    'REMOTE_HOST': remote_host,
    'REMOTE_USER': remote_user,
    'REMOTE_DIR': remote_dir,
    'LOCAL_PULL_DIR': local_pull_dir,
    'LOCAL_OUT_DIR': local_out_dir,
    'PRIVATE_KEY_FILE': private_key_file,
    'SSH_IDENTITY_FILE': ssh_identity_file,
    'SSH_PORT': remote_port,
    'RUN_FOREVER': '1',
    'SLEEP_SECONDS': '5',
    'DELETE_REMOTE_ON_SUCCESS': '1',
    'DELETE_LOCAL_ENC_ON_SUCCESS': '1',
}
for key, value in replacements.items():
    pattern = rf'^{key}=".*"$'
    repl = f'{key}="{value}"'
    text, count = re.subn(pattern, repl, text, count=1, flags=re.M)
    if count != 1:
        raise SystemExit(f'Patch fehlgeschlagen fuer {key}')
if 'umask 0002' not in text:
    text, count = re.subn(r'set -o pipefail\n', 'set -o pipefail\n\numask 0002\n', text, count=1)
    if count != 1:
        raise SystemExit('Patch fehlgeschlagen fuer umask 0002')
text = text.replace('chmod 0640 "$final_out"', 'chmod 0660 "$final_out"')
pathlib.Path(fetch_file).write_text(text, encoding='utf-8')
PY

  chmod 0755 "$fetch_file"
}

create_service_file() {
  local service_file="$1"
  local user="$2"
  local exec_script="$3"

  cat > "$service_file" <<SERVICE
[Unit]
Description=telepraxis fetch and decrypt for $user
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=$user
Group=$user
UMask=0002
ExecStart=$exec_script
Restart=always
RestartSec=2
WorkingDirectory=/srv/telepraxis/$user

[Install]
WantedBy=multi-user.target
SERVICE
}

restart_active_web_services() {
  local services unit failed=0
  if ! services="$(systemctl list-units --type=service --state=active,reloading --plain --no-legend --no-pager \
      apache2.service nginx.service 'php*-fpm.service')"; then
    echo "Die laufenden Webdienste konnten nicht ermittelt werden." >&2
    return 1
  fi
  while read -r unit _; do
    [[ -n "$unit" ]] || continue
    if ! systemctl try-restart "$unit"; then
      echo "Neustart von $unit fehlgeschlagen. Bitte den Dienststatus prüfen." >&2
      failed=1
    fi
  done <<< "$services"
  return "$failed"
}

show_summary() {
  local user="$1"
  local shared_group="$2"
  local source_host="$3"
  local source_user="$4"
  local source_port="$5"
  local base_dir="$6"
  local inbox_dir="$7"
  local config_dir="$8"
  local app_file="$9"
  local sms_lib_file="${10}"
  local sms_config_file="${11}"
  local sms_credentials="${12}"
  local fetch_file="${13}"
  local service_name="${14}"
  local ssh_pub="${15}"
  local decrypt_pub="${16}"
  local app_admin_password="${17}"
  local sms_config_password="${18}"

  echo
  echo "Fertig."
  echo "Lokaler Benutzer:       $user"
  echo "Freigabegruppe:         $shared_group"
  if [[ -n "$fetch_file" ]]; then
    echo "Quellserver:            $source_user@$source_host:$source_port"
    echo "Fetch-Script:           $fetch_file"
    echo "Dienst:                 $service_name"
  else
    echo "Kienzlefon-Direktablage: Als Ausgabeverzeichnis die lokale Inbox wählen."
  fi
  echo "Lokale Basis:           $base_dir"
  echo "Lokale Inbox:           $inbox_dir"
  echo "Lokale Config:          $config_dir"
  echo "App-Datei:              $app_file"
  echo "SMS-Funktionsdatei:     $sms_lib_file"
  echo "SMS-Konfiguration:      $sms_config_file"
  echo "SMS-Credentials:        $sms_credentials"
  echo
  echo "Adminpasswoerter:"
  echo "----------------------------------------------------------------"
  echo "kienzlefon app: $app_admin_password"
  echo "sms-config.php:          $sms_config_password"
  echo "----------------------------------------------------------------"
  echo
  if [[ -n "$fetch_file" ]]; then
    echo "SSH-Public-Key fuer das Quellsystem (authorized_keys):"
    echo "----------------------------------------------------------------"
    cat "$ssh_pub"
    echo "----------------------------------------------------------------"
    echo
    echo "RSA-Public-Key fuer Fon und telepraxis-receive-<ssh-benutzer>.php auf dem Quellsystem:"
    echo "----------------------------------------------------------------"
    cat "$decrypt_pub"
    echo "----------------------------------------------------------------"
    echo
    echo "Hinweise:"
    echo "- Auf dem Quellsystem den obigen SSH-Public-Key in authorized_keys des dortigen SSH-Benutzers eintragen."
    echo "- Den RSA-Public-Key am Kienzlefon-Verschlüsseler hinterlegen. Bei Formularempfang auch dem Quellserver-Setup übergeben."
  fi
  echo "- Die lokale Inbox ist fuer ${WEB_USER} und ${user} vorbereitet."
  echo "- Die SMS-Credentials liegen ausserhalb des Webroots unter: $sms_credentials"
  echo "- sms-config.php ist ueber den normalen Webserver erreichbar und mit dem obigen Adminpasswort geschuetzt."
}

usage() {
  echo "Aufruf: bash zielserver-vorbereiten-v1.8.sh [--source-dir VERZEICHNIS] [--user ZIELBENUTZER] [--with-fetch | --no-fetch | --prepare-keys]"
}

main() {
  local source_dir="" keys_only=0 with_fetch="" choice script_dir
  local target_user=""
  local shared_group=""
  local source_host=""
  local source_user=""
  local source_port=""
  local base_dir=""
  local pull_dir=""
  local inbox_dir=""
  local config_dir=""
  local sms_credentials=""
  local ssh_key=""
  local decrypt_private=""
  local decrypt_public=""
  local fetch_script=""
  local app_target=""
  local sms_lib_target=""
  local sms_config_target=""
  local service_name=""
  local service_file=""
  local remote_dir=""
  local app_admin_password=""
  local sms_config_password=""

  while (( $# )); do
    case "$1" in
      --source-dir)
        [[ -n "${2:-}" && -d "$2" && ! -L "$2" ]] || { echo "Quellverzeichnis fehlt oder ist ungueltig." >&2; return 2; }
        source_dir="$(CDPATH= cd -P -- "$2" && pwd -P)"
        shift 2 ;;
      --user)
        [[ "${2:-}" =~ ^[a-z_][a-z0-9_-]*$ ]] || { echo "Ungueltiger Zielbenutzer." >&2; return 2; }
        target_user="$2"; shift 2 ;;
      --with-fetch|--no-fetch)
        [[ -z "$with_fetch" ]] || { usage >&2; return 2; }
        if [[ "$1" == --with-fetch ]]; then with_fetch=1; else with_fetch=0; fi
        shift ;;
      --prepare-keys) keys_only=1; shift ;;
      --help|-h) usage; return 0 ;;
      *) usage >&2; return 2 ;;
    esac
  done
  require_root
  if (( keys_only )); then
    [[ "$with_fetch" != 0 ]] || { usage >&2; return 2; }
    with_fetch=1
  elif [[ -z "$with_fetch" && -t 0 ]]; then
    echo "Praxisserver – Erstinstallation der Kienzlefon-App"
    echo "  1) Mit Abholer (verschlüsselte Lieferungen per SSH)"
    echo "  2) Ohne Abholer (Kienzlefon schreibt direkt in die lokale Inbox)"
    while :; do
      read -r -p "Auswahl [1]: " choice || return 1
      case "${choice:-1}" in 1) with_fetch=1; break ;; 2) with_fetch=0; break ;; esac
      echo "Bitte 1 oder 2 wählen." >&2
    done
  fi
  with_fetch="${with_fetch:-1}"
  if (( keys_only )); then
    need_cmd ssh-keygen
    need_cmd openssl
    need_cmd runuser
    need_cmd flock
  else
    need_cmd python3
    need_cmd systemctl
    script_dir="$(CDPATH= cd -P -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
    if [[ -z "$source_dir" && ( -f "$script_dir/docs/INSTALLER.md" || -f "$script_dir/scripts/pack.sh" ) ]]; then source_dir="$script_dir"; fi
    if [[ -z "$source_dir" ]] && ! command -v curl >/dev/null 2>&1; then need_cmd wget; fi
    stage_programs "$source_dir" "$with_fetch"
  fi

  if [[ -z "$target_user" ]]; then target_user="$(ask_user)"; fi
  shared_group="tp-${target_user}"
  base_dir="${BASE_ROOT}/${target_user}"
  ssh_key="${base_dir}/.ssh/telepraxis_fetch_key"
  decrypt_private="${base_dir}/telepraxis_decrypt_private.pem"
  decrypt_public="${base_dir}/telepraxis_decrypt_public.pem"
  if (( keys_only )); then
    prepare_account "$target_user" "$shared_group" "$base_dir"
    prepare_keys "$target_user" "$base_dir" "$ssh_key" "$decrypt_private" "$decrypt_public"
    printf 'RSA-Public-Key fuer Fon und Empfangs-PHP: %s\nSSH-Public-Key fuer den Abrufkanal: %s\n' "$decrypt_public" "${ssh_key}.pub"
    return 0
  fi
  if (( with_fetch )); then
    source_host="$(ask_default 'Quellserver Hostname/FQDN' 'kontakt.praxispi.de')"
    source_user="$(ask_default 'SSH-Benutzer auf dem Quellsystem' "$target_user")"
    source_port="$(ask_default 'SSH-Port auf dem Quellsystem' '22')"
    fetch_script="${BIN_DIR}/telepraxis_fetch_and_decrypt_${target_user}.sh"
  fi
  ask_password_or_generate app_admin_password 'Supervisor/Adminpasswort fuer die kienzlefon app'
  ask_password_or_generate sms_config_password 'Adminpasswort fuer sms-config.php'

  pull_dir="${base_dir}/.encrypted"
  inbox_dir="${base_dir}/inbox"
  config_dir="${base_dir}/config"
  sms_credentials="${config_dir}/sms-credentials.json"
  app_target="${WEBROOT_DIR}/telepraxis-app.php"
  sms_lib_target="${WEBROOT_DIR}/telepraxis-sms.php"
  sms_config_target="${WEBROOT_DIR}/sms-config.php"
  service_name="telepraxis-fetch-and-decrypt-${target_user}.service"
  service_file="${UNIT_DIR}/${service_name}"
  remote_dir="/srv/telepraxis/${source_user}/inbox"

  local app_component
  patch_app_file "$SOURCE_STAGE/telepraxis-app.php" "$inbox_dir" "$app_admin_password"
  patch_sms_files "$SOURCE_STAGE/telepraxis-sms.php" "$SOURCE_STAGE/sms-config.php" "$sms_credentials" "$sms_config_password"
  if (( with_fetch )); then
    patch_fetch_file "$SOURCE_STAGE/$FETCH_FILE" "$source_host" "$source_user" "$source_port" "$remote_dir" "$pull_dir" "$inbox_dir" "$decrypt_private" "$ssh_key"
    bash -n "$SOURCE_STAGE/$FETCH_FILE"
  fi

  if command -v php >/dev/null 2>&1; then lint_web_files; fi
  install_php_extension_packages "$with_fetch"
  if (( with_fetch )); then
    need_cmd ssh-keygen
    need_cmd openssl
    need_cmd runuser
    need_cmd flock
    need_cmd jq
    need_cmd scp
    need_cmd ssh
    python3 "$SOURCE_STAGE/$AUDIO_HELPER" --help >/dev/null || { echo "Python 3 mit cryptography fehlt." >&2; return 1; }
  fi
  need_cmd php
  verify_php_extensions
  lint_web_files

  prepare_account "$target_user" "$shared_group" "$base_dir"
  if (( with_fetch )); then
    prepare_keys "$target_user" "$base_dir" "$ssh_key" "$decrypt_private" "$decrypt_public"
    install -d -o "$target_user" -g "$target_user" -m 0750 "$pull_dir"
  fi
  ensure_dirs "$target_user" "$shared_group" "$inbox_dir" "$config_dir"
  ensure_sms_credentials_file "$target_user" "$shared_group" "$sms_credentials"
  mkdir -p "$WEBROOT_DIR"
  for app_component in "${WEB_FILES[@]}"; do
    backup_if_exists "${WEBROOT_DIR}/${app_component}"
    install -m 0644 "$SOURCE_STAGE/$app_component" "$WEBROOT_DIR/$app_component"
  done

  if (( with_fetch )); then
    mkdir -p "$BIN_DIR"
    backup_if_exists "$BIN_DIR/$AUDIO_HELPER"
    install -m 0644 "$SOURCE_STAGE/$AUDIO_HELPER" "$BIN_DIR/$AUDIO_HELPER"
    backup_if_exists "$fetch_script"
    install -m 0755 -o "$target_user" -g "$target_user" "$SOURCE_STAGE/$FETCH_FILE" "$fetch_script"

    create_service_file "$service_file" "$target_user" "$fetch_script"
    chmod 0644 "$service_file"

    systemctl daemon-reload
    systemctl enable --now "$service_name"
  fi

  restart_active_web_services

  show_summary "$target_user" "$shared_group" "$source_host" "$source_user" "$source_port" "$base_dir" "$inbox_dir" "$config_dir" "$app_target" "$sms_lib_target" "$sms_config_target" "$sms_credentials" "$fetch_script" "$service_name" "${ssh_key}.pub" "$decrypt_public" "$app_admin_password" "$sms_config_password"
}

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then main "$@"; fi
