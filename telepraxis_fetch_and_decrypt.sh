#!/usr/bin/env bash
# v1.1 – SSH-Abruf für verschlüsselte JSON und Kienzlefon-Audiopakete.
# Pakete und Ersatzlieferungen bleiben bis zur Quittungsverarbeitung beim Erzeuger.
# Lokal: Bash, OpenSSH, OpenSSL, jq und Python 3 mit cryptography.
set -u
set -o pipefail

###############################################################################
# KONFIGURATION
###############################################################################

# Remote
REMOTE_HOST="trallala.de"
REMOTE_USER="root"
REMOTE_DIR="/srv/telepraxis/inbox"

# Lokal
# Hier landen die geholten .enc-Dateien zunchst.
LOCAL_PULL_DIR="/Volumes/webroot/inbox/.encrypted"

# Hier landen die entschlsselten .json-Dateien.
LOCAL_OUT_DIR="/Volumes/webroot/inbox"

# Private Key fr die Inhalts-Entschlsselung
PRIVATE_KEY_FILE="telepraxis_decrypt_private.pem"

# SSH-Key fr SCP/SSH zum Server
SSH_IDENTITY_FILE=".ssh/id_rsa_trallala"

# SSH-Port
SSH_PORT="22"

# Zustzliche SSH-Optionen
SSH_EXTRA_OPTS="-o BatchMode=yes -o StrictHostKeyChecking=yes"

# Dateimuster auf dem Server
REMOTE_FILE_PATTERN="*.enc"

AUDIO_HELPER="${TELEPRAXIS_AUDIO_HELPER:-$(dirname "$0")/telepraxis-audio-delivery.py}"
AUDIO_STATE="${TELEPRAXIS_AUDIO_STATE:-${LOCAL_PULL_DIR%/}/.vorgang-v1}"

# 1 = alle 5 Sekunden pollen
# 0 = genau einmal laufen und beenden
RUN_FOREVER="1"
SLEEP_SECONDS="5"

# 1 = Remote-Datei nach erfolgreicher Entschlsselung lschen
DELETE_REMOTE_ON_SUCCESS="1"

# 1 = lokale .enc-Datei nach erfolgreicher Entschlsselung lschen
DELETE_LOCAL_ENC_ON_SUCCESS="1"

###############################################################################
# HILFSFUNKTIONEN
###############################################################################

log() {
    printf '%s %s\n' "[$(date '+%Y-%m-%d %H:%M:%S')]" "$*"
}

fail() {
    log "FEHLER: $*"
    exit 1
}

require_cmd() {
    command -v "$1" >/dev/null 2>&1 || fail "Bentigtes Kommando fehlt: $1"
}

shell_quote() {
    printf "'%s'" "$(printf "%s" "$1" | sed "s/'/'\\\\''/g")"
}

ssh_base_cmd() {
    ssh -i "$SSH_IDENTITY_FILE" -p "$SSH_PORT" $SSH_EXTRA_OPTS "${REMOTE_USER}@${REMOTE_HOST}" "$@"
}

scp_get_cmd() {
    scp -q -i "$SSH_IDENTITY_FILE" -P "$SSH_PORT" $SSH_EXTRA_OPTS "$@"
}

hex_of_file() {
    od -An -vtx1 "$1" | tr -d ' \n'
}

sha256_of_file() {
    openssl dgst -sha256 "$1" | sed 's/^.*= //'
}

decrypt_one_file() {
    # $1 = lokale .enc-Datei
    # $2 = lokales Ausgabeverzeichnis
    local enc_file="$1"
    local out_dir="$2"
    local base_name out_name final_out tmp_out
    local tmpdir expected_sha actual_sha cipher cipher_arg
    local key_hex iv_hex

    base_name="$(basename "$enc_file")"
    out_name="${base_name%.enc}"
    final_out="${out_dir%/}/${out_name}"
    tmp_out="${out_dir%/}/.${out_name}.tmp"

    tmpdir="$(mktemp -d "$out_dir/.decrypt.XXXXXX")" || return 1
    [ -d "$tmpdir" ] || return 1

    # Wrapper-Felder extrahieren und base64-dekodieren
    if ! jq -e '.v == 1 and .cipher == "AES-256-CBC" and (.sha256 | test("^[0-9a-f]{64}$"))' "$enc_file" >/dev/null; then
        rm -rf -- "$tmpdir"
        return 1
    fi
    if ! jq -e -r '.ek' "$enc_file" | openssl base64 -d -A -out "$tmpdir/ek.bin" >/dev/null 2>&1; then
        log "Entschlsselung fehlgeschlagen: ek konnte nicht gelesen werden: $enc_file"
        rm -rf "$tmpdir"
        return 1
    fi

    if ! jq -e -r '.iv' "$enc_file" | openssl base64 -d -A -out "$tmpdir/iv.bin" >/dev/null 2>&1; then
        log "Entschlsselung fehlgeschlagen: iv konnte nicht gelesen werden: $enc_file"
        rm -rf "$tmpdir"
        return 1
    fi

    if ! jq -e -r '.ct' "$enc_file" | openssl base64 -d -A -out "$tmpdir/ct.bin" >/dev/null 2>&1; then
        log "Entschlsselung fehlgeschlagen: ct konnte nicht gelesen werden: $enc_file"
        rm -rf "$tmpdir"
        return 1
    fi

    if ! expected_sha="$(jq -e -r '.sha256' "$enc_file" 2>/dev/null)"; then
        log "Entschlsselung fehlgeschlagen: sha256 fehlt: $enc_file"
        rm -rf "$tmpdir"
        return 1
    fi

    if ! cipher="$(jq -e -r '.cipher' "$enc_file" 2>/dev/null)"; then
        log "Entschlsselung fehlgeschlagen: cipher fehlt: $enc_file"
        rm -rf "$tmpdir"
        return 1
    fi

    cipher_arg="$(printf '%s' "$cipher" | tr '[:upper:]' '[:lower:]')"

    # AES-Schlssel mit RSA-Private-Key entschlsseln
    if ! openssl pkeyutl \
        -decrypt \
        -inkey "$PRIVATE_KEY_FILE" \
        -pkeyopt rsa_padding_mode:pkcs1 \
        -in "$tmpdir/ek.bin" \
        -out "$tmpdir/key.bin" >/dev/null 2>&1
    then
        log "RSA-Entschlsselung fehlgeschlagen: $enc_file"
        rm -rf "$tmpdir"
        return 1
    fi

    key_hex="$(hex_of_file "$tmpdir/key.bin")"
    iv_hex="$(hex_of_file "$tmpdir/iv.bin")"

    if [ "${#key_hex}" != 64 ] || [ "${#iv_hex}" != 32 ]; then
        log "Leerer Schlssel oder IV nach Entschlsselung: $enc_file"
        rm -rf "$tmpdir"
        return 1
    fi

    # Nutzdaten entschlsseln
    if ! openssl enc -d "-${cipher_arg}" \
        -K "$key_hex" \
        -iv "$iv_hex" \
        -in "$tmpdir/ct.bin" \
        -out "$tmpdir/plain.json" >/dev/null 2>&1
    then
        log "AES-Entschlsselung fehlgeschlagen: $enc_file"
        rm -rf "$tmpdir"
        return 1
    fi

    # SHA256 prfen
    actual_sha="$(sha256_of_file "$tmpdir/plain.json")"
    if [ "$actual_sha" != "$expected_sha" ]; then
        log "SHA256-Prfung fehlgeschlagen: $enc_file"
        rm -rf "$tmpdir"
        return 1
    fi

    # JSON validieren
    if ! jq -e . "$tmpdir/plain.json" >/dev/null 2>&1; then
        log "Entschlsseltes Ergebnis ist kein gltiges JSON: $enc_file"
        rm -rf "$tmpdir"
        return 1
    fi

    # Atomisch schreiben
    if ! cp "$tmpdir/plain.json" "$tmp_out"; then
        log "Konnte temporre Zieldatei nicht schreiben: $tmp_out"
        rm -rf "$tmpdir"
        return 1
    fi

    if ! mv -f "$tmp_out" "$final_out"; then
        rm -f "$tmp_out"
        log "Konnte finale Zieldatei nicht schreiben: $final_out"
        rm -rf "$tmpdir"
        return 1
    fi

    chmod 0640 "$final_out" >/dev/null 2>&1 || true

    rm -rf "$tmpdir"
    log "Entschlsselt: $final_out"
    return 0
}

list_remote_files() {
    local remote_dir_q
    remote_dir_q="$(shell_quote "$REMOTE_DIR")"

    ssh_base_cmd "cd ${remote_dir_q} && find . -maxdepth 1 -type f \( -name $(shell_quote "$REMOTE_FILE_PATTERN") -o -name '*.kienzlepaket' \) -print"
}

send_receipts() {
    local ack name remote_dir remote_target lines digest
    lines="$(jq -ce '.[]' <<<"$1")" || return 1
    while IFS= read -r ack; do
        name="$(jq -er '[.vorgang_id, .sha256, .status] | join(".") + ".ack.json"' <<<"$ack")" || return 1
        [[ "$name" =~ ^[A-Za-z0-9_-]{1,64}\.[a-f0-9]{64}\.(abgelegt|ueberholt|ersatz_json_noetig)\.ack\.json$ ]] || return 1
        remote_dir="$(shell_quote "${REMOTE_DIR%/}/.kienzlefon-ack")"
        remote_target="$(shell_quote "${REMOTE_DIR%/}/.kienzlefon-ack/$name")"
        digest="$(printf '%s\n' "$ack" | openssl dgst -sha256 | sed 's/^.*= //')" || return 1
        [[ "$digest" =~ ^[a-f0-9]{64}$ ]] || return 1
        ssh_base_cmd "umask 007; mkdir -p -- $remote_dir &&
            tmp=\$(mktemp $remote_dir/.ack.XXXXXX) &&
            trap 'rm -f -- \"\$tmp\"' 0 &&
            cat >\"\$tmp\" && actual=\$(sha256sum -- \"\$tmp\") &&
            [ \"\${actual%% *}\" = '$digest' ] && chmod 660 \"\$tmp\" &&
            mv -f -- \"\$tmp\" $remote_target" <<<"$ack" || return 1
    done <<< "$lines"
}

remote_sha256() {
    ssh_base_cmd "sha256sum -- $(shell_quote "${REMOTE_DIR%/}/$1")" </dev/null | cut -d ' ' -f 1
}

delivery_helper() {
    python3 "$AUDIO_HELPER" --inbox "$LOCAL_OUT_DIR" --state "$AUDIO_STATE" "$@"
}

record_audio_failure() {
    local name="$1" digest receipts
    digest="$(remote_sha256 "$name")" || return 1
    [[ "$digest" =~ ^[a-f0-9]{64}$ ]] || return 1
    receipts="$(delivery_helper --failure-hash "$digest" "$name")" || return 1
    send_receipts "$receipts"
}

fetch_remote_file() {
    # $1 = Dateiname relativ zu REMOTE_DIR
    # $2 = lokaler Zielpfad
    local remote_name="$1"
    local local_path="$2"
    local remote_spec

    remote_spec="${REMOTE_USER}@${REMOTE_HOST}:${REMOTE_DIR%/}/${remote_name}"
    scp_get_cmd "$remote_spec" "$local_path" </dev/null
}

process_one_remote_file() (
    umask 007
    local name="$1" scratch enc plain receipts remote_path digest code=0
    local package=0
    if [[ "$name" =~ ^[A-Za-z0-9_-]{1,64}\.kienzlepaket$ ]]; then
        package=1
    elif ! [[ "$name" =~ ^[A-Za-z0-9_-][A-Za-z0-9._-]*\.json\.enc$ ]]; then
        return 0
    fi
    scratch="$(mktemp -d "$download_root/.vorgang.XXXXXX")" || return 1
    trap 'rm -rf -- "$scratch"' EXIT
    enc="$scratch/$name"
    if ! fetch_remote_file "$name" "$enc"; then
        (( package )) && record_audio_failure "$name"
        return
    fi
    if (( package )); then
        receipts="$(delivery_helper --key "$PRIVATE_KEY_FILE" "$enc")" || code=$?
        if (( code == 2 )); then
            record_audio_failure "$name"
            return
        fi
        (( code == 0 )) || return 1
    else
        plain="$scratch/${name%.enc}"
        decrypt_one_file "$enc" "$scratch" || return 1
        receipts="$(delivery_helper --plain-json "$plain" "$enc")" || return 1
    fi
    if [[ "$receipts" != '[]' ]]; then
        send_receipts "$receipts" || return 1
    elif [[ "$DELETE_REMOTE_ON_SUCCESS" == 1 ]]; then
        # Nur herkömmliche JSON-only-Lieferungen verwenden die bisherige Abrufbereinigung.
        digest="$(sha256_of_file "$enc")" || return 1
        remote_path="$(shell_quote "${REMOTE_DIR%/}/$name")"
        ssh_base_cmd "actual=\$(sha256sum -- $remote_path) && [ \"\${actual%% *}\" = '$digest' ] && rm -f -- $remote_path" </dev/null || return 1
    fi
    if [[ "$DELETE_LOCAL_ENC_ON_SUCCESS" == 0 && "$receipts" == '[]' ]]; then
        mv -f -- "$enc" "$LOCAL_PULL_DIR/$name" || return 1
    fi
    return 0
)

run_once() (
    local listing name result=0
    local download_root="$AUDIO_STATE/.fetch-work"
    # An inherited descriptor keeps flock held until this polling round exits.
    exec 9>>"$AUDIO_STATE/.fetch.lock" || return 1
    python3 -c 'import fcntl; fcntl.flock(9, fcntl.LOCK_EX)' || return 1
    if [[ -e "$download_root" || -L "$download_root" ]]; then
        rm -rf -- "$download_root" || return 1
    fi
    mkdir -m 0700 -- "$download_root" || return 1
    trap 'rm -rf -- "$download_root"' EXIT
    listing="$(list_remote_files)" || { log "SSH-Abruf nicht erreichbar. Lieferung bleibt offen."; return 1; }
    while IFS= read -r name; do
        [[ -n "$name" ]] || continue
        process_one_remote_file "${name#./}" || { log "Lieferung noch offen: ${name#./}"; result=1; }
    done <<< "$listing"
    return "$result"
)

main() {
    require_cmd bash
    require_cmd ssh
    require_cmd scp
    require_cmd openssl
    require_cmd jq
    require_cmd mktemp
    require_cmd od

    [ -f "$PRIVATE_KEY_FILE" ] || fail "Private Key nicht gefunden: $PRIVATE_KEY_FILE"
    [ -f "$SSH_IDENTITY_FILE" ] || fail "SSH-Key nicht gefunden: $SSH_IDENTITY_FILE"

    [ -d "$LOCAL_OUT_DIR" ] || fail "Konfigurierte Inbox ist nicht verfügbar. Lieferung bleibt offen."
    mkdir -p "$LOCAL_PULL_DIR" || fail "Lokales Abrufverzeichnis ist nicht verfügbar."
    require_cmd python3
    [ -r "$AUDIO_HELPER" ] || fail "Audio-Helfer fehlt."
    python3 "$AUDIO_HELPER" --help >/dev/null || fail "Python mit cryptography wird benötigt."
    (umask 077; mkdir -p -- "$AUDIO_STATE") || fail "Importnachweis konnte nicht eingerichtet werden."

    if [ "$RUN_FOREVER" = "1" ]; then
        log "Starte Polling-Schleife alle ${SLEEP_SECONDS}s"
        while true; do
            run_once
            sleep "$SLEEP_SECONDS"
        done
    else
        run_once
    fi
}

main "$@"
