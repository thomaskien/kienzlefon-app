#!/usr/bin/env bash
# quellserver-benutzer-erzeugen-v1.8.sh
#
# v1.8
# von Dr. Thomas Kienzle / ChatGPT
#
# Changelog
# 2026-11-18: Empfangs- und Kontaktvorlage gemeinsam aus dem Komponentenpaket vorbereiten.
# v1.8
# - setzt das SSH-Home auf owner=<ssh-benutzer>, group=tp-<ssh-benutzer>, mode=0710
# - erlaubt www-data damit nur das Durchqueren zum Inbox-Verzeichnis, ohne das SSH-Home schreibbar oder listbar zu machen
# v1.7
# - installiert zusaetzlich ein kanalbezogenes Kontaktformular als /var/www/html/kontakt-<ssh-benutzer>.php
# - patcht im Kontaktformular OTP_DB und Endpoint auf denselben Abrufkanal
# v1.6
# - benennt die Empfangsdatei pro Abrufkanal nun als /var/www/html/telepraxis-receive-<ssh-benutzer>.php
# - dokumentiert den kanalbezogenen HTTPS-Endpoint am Ende der Einrichtung
# v1.5
# - startet beim Webstack-Reload nur aktive Dienste neu
# - meldet fehlgeschlagene Restarts als Warnung, ohne erfolgreiche Kanaleinrichtung zu verdecken
# v1.4
# - behebt Cleanup-Fehler am Skriptende, wenn der temporaere PEM-Pfad wegen set -u nicht mehr sichtbar ist
# - der temporaere Public-Key-Zwischenspeicher wird nun robust ueber eine globale Variable entfernt
# v1.3
# - korrigiert unsichtbare Prompts bei interaktiven Eingaben innerhalb von Command-Substitutionen
# - Bedienhinweise gehen nun auf stderr, Rueckgabewerte bleiben auf stdout
# v1.2
# - trennt SSH-Home strikt von schreibbaren App-Verzeichnissen
# - legt zusaetzlich /srv/telepraxis/state/<ssh-benutzer> fuer OTP/SQLite an
# - verwendet gemeinsame Gruppe tp-<ssh-benutzer> statt www-data direkt in die Benutzer-Primärgruppe zu legen
# - patched nun INBOX_DIR, OTP_DB, IONOS_PSK und PUBLIC_KEY_PEM
# - setzt Rechte fuer SSH (.ssh/authorized_keys) strikt und fuer inbox/state separat
# - Zieldatei bleibt /var/www/html/telepraxis-receive<ssh-benutzer>.php
# v1.1
# - Zieldatei fuer PHP nun /var/www/html/telepraxis-receive<ssh-benutzer>.php
# - IONOS-Secret wird nun interaktiv manuell abgefragt oder automatisch erzeugt
# - IONOS-Secret wird in die PHP-Datei gepatcht und am Ende ausgegeben
# v1.0
# - legt interaktiv separaten SSH-Benutzer an oder konfiguriert ihn nach
# - legt /srv/telepraxis/<ssh-benutzer>/inbox an
# - richtet ~/.ssh/authorized_keys ein
# - bootstrapped telepraxis-receive.php von GitHub
# - setzt INBOX_DIR auf /srv/telepraxis/<ssh-benutzer>/inbox
# - setzt den eingebetteten PUBLIC_KEY_PEM interaktiv
# - nimmt www-data in die Benutzergruppe auf
# - setzt Rechte so, dass PHP schreiben und der SSH-Benutzer lesen/loeschen kann
# - erstellt Backup einer bestehenden telepraxis-receive<ssh-benutzer>.php

set -euo pipefail

UPSTREAM="https://raw.githubusercontent.com/thomaskien/IONOS_AI_Receptionist_Interface_Arztpraxis/refs/heads/main"
WEB_ROOT="/var/www/html"
WEB_USER="www-data"
BASE_ROOT="/srv/telepraxis"
STATE_ROOT="${BASE_ROOT}/state"
SOURCE_STAGE=""

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

ask_ssh_user() {
  local u=""
  while :; do
    read -r -p "SSH-Benutzername: " u || return 1
    if [[ "$u" =~ ^[a-z_][a-z0-9_-]*$ ]]; then
      printf '%s\n' "$u"
      return 0
    fi
    echo "Ungueltiger Benutzername. Erlaubt: a-z, 0-9, _, -, Beginn mit Buchstabe/_" >&2
  done
}

ask_ssh_pubkey() {
  local k=""
  echo "Bitte SSH-Public-Key in EINER Zeile einfügen (z. B. ssh-ed25519 ...):" >&2
  while :; do
    read -r k || return 1
    if printf '%s' "$k" | grep -Eq '^(ssh-ed25519|ssh-rsa|ecdsa-sha2-nistp(256|384|521)|sk-ssh-ed25519@openssh.com|sk-ecdsa-sha2-nistp256@openssh.com) '; then
      printf '%s\n' "$k"
      return 0
    fi
    echo "Ungueltiger SSH-Public-Key. Bitte erneut komplett einfügen:" >&2
  done
}

ask_public_key_pem() {
  local pem_file="$1"
  local line=""
  : > "$pem_file"

  echo
  echo "Bitte den Public Key fuer die JSON-Verschluesselung als kompletten PEM-Block einfuegen."
  echo "Mit -----END PUBLIC KEY----- abschliessen."
  echo

  local started=0
  while IFS= read -r line; do
    if [ $started -eq 0 ]; then
      if [ "$line" != "-----BEGIN PUBLIC KEY-----" ]; then
        echo "Die erste Zeile muss genau -----BEGIN PUBLIC KEY----- sein."
        continue
      fi
      started=1
    fi

    printf '%s\n' "$line" >> "$pem_file"

    if [ "$line" = "-----END PUBLIC KEY-----" ]; then
      break
    fi
  done

  if ! grep -qx -- '-----BEGIN PUBLIC KEY-----' "$pem_file"; then
    echo "BEGIN PUBLIC KEY fehlt."
    exit 1
  fi
  if ! grep -qx -- '-----END PUBLIC KEY-----' "$pem_file"; then
    echo "END PUBLIC KEY fehlt."
    exit 1
  fi
}

ask_ionos_secret() {
  local mode=""
  local secret=""

  echo >&2
  echo "IONOS-Secret festlegen:" >&2
  echo "  [A] automatisch erzeugen" >&2
  echo "  [M] manuell eingeben" >&2

  while :; do
    read -r -p "Auswahl [A/M] (Default A): " mode
    mode="${mode:-A}"
    case "$(printf '%s' "$mode" | tr '[:lower:]' '[:upper:]')" in
      A)
        secret="$(openssl rand -base64 32 | tr -d '\n' | tr '/+' '_-' | tr -d '=')"
        printf '%s\n' "$secret"
        return 0
        ;;
      M)
        while :; do
          read -r -p "IONOS-Secret manuell eingeben: " secret
          if [ -n "$secret" ]; then
            printf '%s\n' "$secret"
            return 0
          fi
          echo "Eingabe darf nicht leer sein." >&2
        done
        ;;
      *)
        echo "Bitte A oder M eingeben." >&2
        ;;
    esac
  done
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

  if id "$user" >/dev/null 2>&1; then
    echo "Benutzer $user existiert bereits, verwende ihn weiter."
    usermod -d "$home" "$user" >/dev/null 2>&1 || true
  else
    useradd -m -d "$home" -s /bin/bash -U "$user"
    passwd -l "$user" >/dev/null 2>&1 || true
    echo "Benutzer $user wurde angelegt."
  fi
}

setup_dirs_and_ssh() {
  local user="$1"
  local shared_group="$2"
  local ssh_pubkey="$3"
  local home="${BASE_ROOT}/${user}"
  local ssh_dir="${home}/.ssh"
  local auth_keys="${ssh_dir}/authorized_keys"
  local inbox="${home}/inbox"
  local state_dir="${STATE_ROOT}/${user}"

  install -d -o root -g root -m 0755 "$BASE_ROOT"
  install -d -o root -g root -m 0755 "$STATE_ROOT"
  install -d -o "$user" -g "$shared_group" -m 0710 "$home"
  install -d -o "$user" -g "$user" -m 0700 "$ssh_dir"
  install -d -o "$user" -g "$shared_group" -m 2770 "$inbox"
  install -d -o root -g "$shared_group" -m 2770 "$state_dir"

  touch "$auth_keys"
  if ! grep -Fqx "$ssh_pubkey" "$auth_keys"; then
    printf '%s\n' "$ssh_pubkey" >> "$auth_keys"
  fi
  chown "$user:$user" "$auth_keys"
  chmod 0600 "$auth_keys"

  usermod -a -G "$shared_group" "$user" || true
  if id "$WEB_USER" >/dev/null 2>&1; then
    usermod -a -G "$shared_group" "$WEB_USER" || true
  fi

  if command -v setfacl >/dev/null 2>&1; then
    setfacl -m "u:${user}:rwx,g:${shared_group}:rwx" "$inbox" "$state_dir" || true
    if id "$WEB_USER" >/dev/null 2>&1; then
      setfacl -m "u:${WEB_USER}:rwx" "$inbox" "$state_dir" || true
      setfacl -d -m "u:${WEB_USER}:rwx,g:${shared_group}:rwx" "$inbox" "$state_dir" || true
    fi
    setfacl -d -m "u:${user}:rwx,g:${shared_group}:rwx" "$inbox" "$state_dir" || true
  fi

  echo "SSH und Verzeichnisstruktur eingerichtet:"
  echo "  Home : $home"
  echo "  Inbox: $inbox"
  echo "  State: $state_dir"
}

stage_templates() {
  local source_dir="$1" name
  SOURCE_STAGE="$(mktemp -d)"
  trap 'rm -rf -- "$SOURCE_STAGE"' EXIT
  for name in telepraxis-receive.php kontakt.php; do
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
    [[ -s "$SOURCE_STAGE/$name" ]] || { echo "Leere PHP-Vorlage: $name" >&2; return 1; }
    php -l "$SOURCE_STAGE/$name" >/dev/null
  done
}

install_template() {
  local source="$1" target="$2" backup
  if [[ -f "$target" ]]; then
    backup="${target}.bak.$(date +%Y%m%d_%H%M%S)"
    cp -a "$target" "$backup"
    echo "Backup erstellt: $backup"
  fi
  install -m 0644 "$source" "$target"
}

patch_php_file() {
  local php_file="$1"
  local inbox_dir="$2"
  local otp_db="$3"
  local pem_file="$4"
  local ionos_secret="$5"

  python3 - "$php_file" "$inbox_dir" "$otp_db" "$ionos_secret" "$pem_file" <<'PY'
import pathlib, re, sys
php_file, inbox_dir, otp_db, ionos_secret, pem_file = sys.argv[1:]
text = pathlib.Path(php_file).read_text(encoding='utf-8')
pem = pathlib.Path(pem_file).read_text(encoding='utf-8').rstrip('\n')
def php_string(value):
    return "'" + value.replace("\\", "\\\\").replace("'", "\\'") + "'"

patterns = [
    (r"\$INBOX_DIR\s*=\s*'[^']*';", f"$INBOX_DIR = {php_string(inbox_dir)};"),
    (r"\$OTP_DB\s*=\s*'[^']*';", f"$OTP_DB = {php_string(otp_db)};"),
    (r"\$IONOS_PSK\s*=\s*'[^']*';", f"$IONOS_PSK = {php_string(ionos_secret)};"),
]
for pattern, replacement in patterns:
    new_text, count = re.subn(pattern, lambda _: replacement, text, count=1)
    if count != 1:
        raise SystemExit(f"Patch fehlgeschlagen fuer Pattern: {pattern}")
    text = new_text
new_text, count = re.subn(
    r"\$PUBLIC_KEY_PEM\s*=\s*<<<'PEM'\n.*?\nPEM;",
    "$PUBLIC_KEY_PEM = <<<'PEM'\n" + pem + "\nPEM;",
    text,
    count=1,
    flags=re.S,
)
if count != 1:
    raise SystemExit("Patch fehlgeschlagen fuer PUBLIC_KEY_PEM")
pathlib.Path(php_file).write_text(new_text, encoding='utf-8')
PY

  php -l "$php_file" >/dev/null
}

patch_contact_file() {
  local contact_file="$1"
  local otp_db="$2"
  local endpoint="$3"

  python3 - "$contact_file" "$otp_db" "$endpoint" <<'PY'
import json, pathlib, re, sys

contact_file, otp_db, endpoint = sys.argv[1:]
path = pathlib.Path(contact_file)
text = path.read_text(encoding='utf-8')

def php_single_quoted(value):
    return "'" + value.replace("\\", "\\\\").replace("'", "\\'") + "'"

text, count = re.subn(
    r"\$OTP_DB\s*=\s*'[^']*';",
    f"$OTP_DB = {php_single_quoted(otp_db)};",
    text,
    count=1,
)
if count != 1:
    raise SystemExit("Patch fehlgeschlagen fuer OTP_DB im Kontaktformular")

text, count = re.subn(
    r'const\s+endpoint\s*=\s*"[^"]*";',
    f"const endpoint = {json.dumps(endpoint)};",
    text,
    count=1,
)
if count != 1:
    raise SystemExit("Patch fehlgeschlagen fuer endpoint im Kontaktformular")

path.write_text(text, encoding='utf-8')
PY

  php -l "$contact_file" >/dev/null
}

maybe_restart_webstack() {
  local unit=""

  if systemctl is-active --quiet apache2.service 2>/dev/null; then
    systemctl restart apache2.service || echo "Warnung: apache2.service konnte nicht neu gestartet werden." >&2
  fi
  if systemctl is-active --quiet nginx.service 2>/dev/null; then
    systemctl restart nginx.service || echo "Warnung: nginx.service konnte nicht neu gestartet werden." >&2
  fi
  while IFS= read -r unit; do
    [ -n "$unit" ] || continue
    if systemctl is-active --quiet "$unit" 2>/dev/null; then
      systemctl restart "$unit" || echo "Warnung: $unit konnte nicht neu gestartet werden." >&2
    fi
  done < <(systemctl list-unit-files --type=service --no-legend 2>/dev/null | awk '/^php[0-9.]+-fpm\.service/ {print $1}')
}

show_summary() {
  local user="$1"
  local shared_group="$2"
  local inbox="$3"
  local state_dir="$4"
  local php_file="$5"
  local ionos_secret="$6"
  local contact_file="$7"

  echo
  echo "Fertig."
  echo "SSH-Benutzer:   $user"
  echo "Freigabegruppe: $shared_group"
  echo "Inbox:          $inbox"
  echo "OTP-State:      ${state_dir}/otp.sqlite"
  echo "PHP-Datei:      $php_file"
  echo "HTTPS-Endpoint: https://<dein-vhost-fqdn>/telepraxis-receive-${user}.php"
  echo "Kontaktformular: $contact_file"
  echo "Kontakt-URL:     https://<dein-vhost-fqdn>/kontakt-${user}.php"
  echo "IONOS-Secret:   $ionos_secret"
  echo
  echo "Fetch-Quelle kuenftig:"
  echo "  $user@$(hostname -f):$inbox/"
  echo
  echo "Hinweis:"
  echo "  SSH-Home bleibt strikt unter ${BASE_ROOT}/${user}."
  echo "  Schreibbare App-Bereiche sind getrennt: inbox und ${STATE_ROOT}/${user}."
}

main() {
  local source_dir=""
  if [[ $# == 1 && ( "$1" == --help || "$1" == -h ) ]]; then
    echo "Aufruf: bash quellserver-benutzer-erzeugen-v1.8.sh [--source-dir VERZEICHNIS]"
    return 0
  fi
  if (( $# )); then
    if [[ $# != 2 || "$1" != --source-dir || ! -d "$2" || -L "$2" ]]; then
      echo "Erwartet: --source-dir VERZEICHNIS (regulaeres Verzeichnis)" >&2
      return 2
    fi
    source_dir="$(CDPATH= cd -P -- "$2" && pwd -P)"
  fi
  require_root
  need_cmd php
  need_cmd python3
  need_cmd openssl
  if [[ -z "$source_dir" ]] && ! command -v curl >/dev/null 2>&1; then need_cmd wget; fi
  stage_templates "$source_dir"

  local ssh_user=""
  local shared_group=""
  local ssh_pubkey=""
  local ionos_secret=""
  local home=""
  local inbox=""
  local state_dir=""
  local otp_db=""
  local target_php=""
  local contact_php=""

  ssh_user="$(ask_ssh_user)"
  shared_group="tp-${ssh_user}"
  ssh_pubkey="$(ask_ssh_pubkey)"

  local pem="$SOURCE_STAGE/public.pem"
  ask_public_key_pem "$SOURCE_STAGE/input.pem"
  openssl rsa -pubin -in "$SOURCE_STAGE/input.pem" -pubout -out "$pem" 2>/dev/null || {
    echo "Ungueltiger oeffentlicher RSA-Schluessel." >&2; return 1;
  }
  ionos_secret="$(ask_ionos_secret)"

  home="${BASE_ROOT}/${ssh_user}"
  inbox="${home}/inbox"
  state_dir="${STATE_ROOT}/${ssh_user}"
  otp_db="${state_dir}/otp.sqlite"
  target_php="${WEB_ROOT}/telepraxis-receive-${ssh_user}.php"
  contact_php="${WEB_ROOT}/kontakt-${ssh_user}.php"

  patch_php_file "$SOURCE_STAGE/telepraxis-receive.php" "$inbox" "$otp_db" "$pem" "$ionos_secret"
  patch_contact_file "$SOURCE_STAGE/kontakt.php" "$otp_db" "/telepraxis-receive-${ssh_user}.php"

  ensure_group "$shared_group"
  ensure_user "$ssh_user" "$home"
  setup_dirs_and_ssh "$ssh_user" "$shared_group" "$ssh_pubkey"
  mkdir -p "$WEB_ROOT"
  install_template "$SOURCE_STAGE/telepraxis-receive.php" "$target_php"
  install_template "$SOURCE_STAGE/kontakt.php" "$contact_php"
  maybe_restart_webstack
  show_summary "$ssh_user" "$shared_group" "$inbox" "$state_dir" "$target_php" "$ionos_secret" "$contact_php"
}

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then main "$@"; fi
