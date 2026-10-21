#!/bin/sh
# fsa: runs an `fsa` command of this instance in a throwaway ops container (BUILD_PROMPT_V2 §6.2, §13.1;
# ADR 0048, 0049, 0070). install.sh installs it as <instance dir>/fsa and sources it for the first
# `fsa install`. It passes host facts as FIELDSET_* variables, mounts the Docker socket, the instance directory at the
# same absolute path and the parent directory of every file-path flag, writes a nonce file the container must
# see, and opens a URL a command hands back. The ops image comes from state.json (digests.app), never from a
# release manifest, so a failed shim swap cannot lock the owner out.
#
# The first argument that is not a flag (or a flag's value) is the command; `--dir <instance>` goes right after
# it. HOME and DOCKER_CONFIG live on a container-private tmpfs, and no mount may cover a system path of the
# image or a directory other users can write to (fsa_reserved_path, LaunchGuard). With --json, stdout carries only
# the command's JSON document and stderr only NDJSON events (KTD3). Docker's DOCKER_DEFAULT_PLATFORM, when set
# (linux/amd64 or linux/arm64), picks the platform of the ops container and, passed on, of every image fsa runs.
#
# Contract with install.sh (keep stable): FIELDSET_SHIM_LIBRARY=1 only defines the functions, and
# `fsa_run <instance dir> <image> <fsa command> [args...]` returns the command's exit code.

fsa_json_string() {
	printf '"%s"' "$(printf '%s' "$1" | sed 's/\\/\\\\/g; s/"/\\"/g' | tr -d '\000-\037')"
}

fsa_say() { # <info|warn|error> <message> [hint]: on stderr, one NDJSON event with --json, else human lines (KTD3)
	if [ "${fsa_json:-0}" = 1 ]; then
		printf '{"ts":"%s","phase":"launcher","level":"%s","message":%s}\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$1" \
			"$(fsa_json_string "$2${3:+ Fix: $3}")" >&2
	else
		printf 'fsa: %s\n' "$2" >&2
		[ -z "${3:-}" ] || printf '  Fix: %s\n' "$3" >&2
	fi
}

fsa_fail() { # <exit code> <error code> <message> [hint] [retryable: true|false]: report, plus the envelope with --json
	fsa_say error "$3" "${4:-}"
	if [ "${fsa_json:-0}" = 1 ]; then
		fsa_hint=null
		[ -z "${4:-}" ] || fsa_hint=$(fsa_json_string "$4")
		printf '{"schema_version":1,"command":%s,"status":"failed","exit_code":%s,"changed":false,"data":{},"secrets":[],"warnings":[],"errors":[{"code":%s,"message":%s,"hint":%s,"retryable":%s}]}\n' \
			"$(fsa_json_string "${fsa_command:-}")" "$1" "$(fsa_json_string "$2")" "$(fsa_json_string "$3")" "$fsa_hint" "${5:-false}"
	fi
	exit "$1"
}

fsa_file_flag_mode() { # sets fsa_mode: rw for output paths, ro for input paths, empty otherwise (FilePathFlags)
	case $1 in
	--secrets-file | --output | --*-out) fsa_mode=rw ;;
	--*-file | --from-backup) fsa_mode=ro ;;
	*) fsa_mode='' ;;
	esac
}

# Every value-taking option of the fsa:* commands plus the --version alias of install and adopt, so that a flag
# before the command keeps its value (`fsa --behind-proxy traefik install`); ShimScriptTest keeps it in step with the
# commands.
fsa_value_flags='--acme-ca --acme-ca-root-file --acme-email --app-key-file --behind-proxy --dir --domain --from-address
--from-backup --from-name --identity-file --identity-out --kind --lan-address --locale --lock-id --org --orphans
--owner-email --owner-name --passphrase-file --password-file --phone-https --port --proxy-network --release
--scratch --seal --secrets-file --since --smtp-encryption --smtp-host --smtp-port --smtp-username --source
--tail --test-to --to --trusted-proxy --url --version'

fsa_takes_value() { # <flag>: true for a value-taking flag written without =value (dir, file-path or listed flags)
	fsa_file_flag_mode "$1"
	[ -z "$fsa_mode" ] || return 0
	for fsa_flag in $fsa_value_flags; do
		[ "$fsa_flag" != "$1" ] || return 0
	done
	return 1
}

fsa_find_command() { # sets fsa_command/fsa_found (first argument before -- that is no flag or flag value) and fsa_json
	fsa_command='' fsa_found=0 fsa_json=0 fsa_skip=0
	for fsa_arg in "$@"; do
		if [ "$fsa_skip" = 1 ]; then
			fsa_skip=0
			continue
		fi
		case $fsa_arg in
		--) break ;;
		--json) fsa_json=1 ;;
		--*=*) ;;
		-*) if [ "$fsa_found" = 0 ] && fsa_takes_value "$fsa_arg"; then fsa_skip=1; fi ;;
		*) [ "$fsa_found" = 1 ] || { fsa_command=$fsa_arg && fsa_found=1; } ;;
		esac
	done
}

fsa_state_image() { # <state.json>: the app image the instance runs (digests.app, InstanceState's 4-space layout)
	[ -f "$1" ] || return 1
	fsa_ref=$(awk '/^    "digests": \{$/ { inside = 1; next }
		inside && (/^    \}/ || /^    \]/) { exit }
		inside && /^        "app": "/ { sub(/^        "app": "/, ""); sub(/",?$/, ""); print; exit }' "$1")
	printf '%s\n' "$fsa_ref" | grep -Eqx '[A-Za-z0-9._:/-]+@sha256:[a-f0-9]{64}' || return 1
	printf '%s\n' "$fsa_ref"
}

fsa_timezone() {
	fsa_tz=${TZ:-}
	[ -n "$fsa_tz" ] || fsa_tz=$(cat /etc/timezone 2>/dev/null) || fsa_tz=''
	[ -n "$fsa_tz" ] || fsa_tz=$(readlink /etc/localtime 2>/dev/null) || fsa_tz=''
	fsa_tz=${fsa_tz#:}
	fsa_tz=${fsa_tz##*zoneinfo/}
	case $fsa_tz in '' | /* | *[!A-Za-z0-9_+/-]*) fsa_tz='' ;; esac
	printf '%s' "$fsa_tz"
}

fsa_lan_addresses() { # candidate LAN addresses, without loopback and container or VM bridges
	if command -v ip >/dev/null 2>&1; then
		ip -o -4 addr show scope global 2>/dev/null |
			awk '$2 !~ /^(docker|br-|veth|virbr|cni|flannel|cali|vxlan|tun|wg)/ { split($4, a, "/"); printf "%s ", a[1] }'
	elif command -v ifconfig >/dev/null 2>&1; then
		ifconfig 2>/dev/null | awk '/^[a-z]/ { iface = $1 }
			$1 == "inet" && iface !~ /^(lo|docker|br-|veth|bridge|utun|vmnet)/ { sub(/^addr:/, "", $2); if ($2 !~ /^127\./) printf "%s ", $2 }'
	fi
}

fsa_browser_opener() { # sets fsa_opener when a desktop session can open a browser
	fsa_opener=''
	case $FIELDSET_HOST_OS in
	darwin) [ -n "${SSH_CONNECTION:-}" ] || ! command -v open >/dev/null 2>&1 || fsa_opener=open ;;
	linux) [ -z "${DISPLAY:-}${WAYLAND_DISPLAY:-}" ] || ! command -v xdg-open >/dev/null 2>&1 || fsa_opener=xdg-open ;;
	esac
}

fsa_host_facts() { # exports the FIELDSET_* host facts; sets fsa_user, fsa_group and fsa_socket (KTD2)
	FIELDSET_HOST_UID=$(id -u)
	FIELDSET_HOST_GID=$(id -g)
	FIELDSET_HOST_OS=$(uname -s | tr '[:upper:]' '[:lower:]')
	FIELDSET_TZ=$(fsa_timezone)
	FIELDSET_LAN_ADDRESSES=$(fsa_lan_addresses)
	fsa_browser_opener
	FIELDSET_DESKTOP=0
	[ -z "$fsa_opener" ] || FIELDSET_DESKTOP=1
	fsa_info=$(docker info --format '{{.OperatingSystem}}|{{.Name}}|{{json .SecurityOptions}}' 2>/dev/null) ||
		fsa_fail 10 docker_unavailable 'The Docker daemon is not reachable.' 'Start Docker (or check DOCKER_HOST and your docker group membership), then run the command again.' true
	case "$FIELDSET_HOST_OS|$fsa_info" in
	*name=rootless*) FIELDSET_DOCKER_RUNTIME=rootless ;;
	linux\|*'Docker Desktop'* | linux\|*OrbStack* | linux\|*\|colima*\|* | linux\|*\|lima-*\|* | linux\|*\|rancher-desktop\|*) FIELDSET_DOCKER_RUNTIME=desktop ;;
	linux\|*) FIELDSET_DOCKER_RUNTIME=rootful ;;
	*) FIELDSET_DOCKER_RUNTIME=desktop ;;
	esac
	fsa_socket=/var/run/docker.sock
	case ${DOCKER_HOST:-} in
	'') [ "$FIELDSET_DOCKER_RUNTIME" != rootless ] || fsa_socket=${XDG_RUNTIME_DIR:-/run/user/$FIELDSET_HOST_UID}/docker.sock ;;
	unix://*) [ "$FIELDSET_DOCKER_RUNTIME" = desktop ] || fsa_socket=${DOCKER_HOST#unix://} ;;
	*) fsa_fail 10 docker_host_unsupported "fsa needs the local Docker socket, not DOCKER_HOST=$DOCKER_HOST." 'Unset DOCKER_HOST or point it at a unix:// socket.' ;;
	esac
	fsa_group=''
	if [ "$FIELDSET_DOCKER_RUNTIME" = rootful ]; then
		FIELDSET_CONTAINER_UID=$FIELDSET_HOST_UID
		fsa_user=$FIELDSET_HOST_UID:$FIELDSET_HOST_GID
		fsa_group=$(stat -L -c %g "$fsa_socket" 2>/dev/null) || fsa_group=''  # rootful means Linux: GNU or busybox stat
	else
		FIELDSET_CONTAINER_UID=0
		fsa_user=0:0
	fi
	FIELDSET_DOCKER_SOCKET=$fsa_socket
	export FIELDSET_HOST_UID FIELDSET_HOST_GID FIELDSET_HOST_OS FIELDSET_TZ FIELDSET_LAN_ADDRESSES FIELDSET_DESKTOP FIELDSET_DOCKER_RUNTIME FIELDSET_CONTAINER_UID FIELDSET_DOCKER_SOCKET
}

fsa_platform_ok() { # <DOCKER_DEFAULT_PLATFORM>: a platform releases ship, optionally with a variant (ADR 0070)
	case $1 in linux/amd64 | linux/arm64 | linux/amd64/v[1-9] | linux/arm64/v[1-9]) return 0 ;; esac
	return 1
}

fsa_check_path() { # <path>: refuse what a bind mount cannot carry
	case $1 in
	*:* | *,* | *"$fsa_nl"*) fsa_fail 2 invalid_path "Paths with ':', ',' or line breaks cannot be mounted: $1" 'Use another path.' ;;
	esac
}

fsa_reserved_path() { # <absolute path without . or ..>: true when a mount there could cover a path of the ops image or
	# a directory other users can write to (/tmp, /var/tmp, /dev/shm): the path equals, contains or lies inside one of
	# these, or is / or /home. Kept identical to install.sh and LaunchGuard::RESERVED (tests/Unit/Distribution).
	case $1 in / | /home) return 0 ;; esac
	for fsa_root in /tmp /var /usr /etc /run /proc /sys /dev /bin /sbin /lib /lib64 /root; do
		case $1/ in "$fsa_root"/*) return 0 ;; esac
		case $fsa_root/ in "$1"/*) return 0 ;; esac
	done
	return 1
}

fsa_normalize_path() { # <absolute path>: without ., .., repeated or trailing slashes (lexically)
	fsa_norm=''
	fsa_old_ifs=$IFS
	IFS=/
	set -f
	for fsa_part in $1; do
		case $fsa_part in '' | .) ;; ..) fsa_norm=${fsa_norm%/*} ;; *) fsa_norm=$fsa_norm/$fsa_part ;; esac
	done
	set +f
	IFS=$fsa_old_ifs
	printf '%s\n' "${fsa_norm:-/}"
}

fsa_refuse_reserved() { # <path>: exit 2 path_reserved for a reserved mount path
	! fsa_reserved_path "$1" || fsa_fail 2 path_reserved \
		"fsa does not mount $1: it would cover files of the fsa container or a directory other users can write to." \
		'Pass a path under your home directory or in the instance directory.'
}

fsa_add_mount() { # <directory> <ro|rw>: mounts its physical path at the same path (rw wins); sets fsa_mount_dir
	# Both the path as written and the directory it resolves to must be allowed: the second is what gets mounted.
	fsa_check_path "$1"
	fsa_mount_dir=$1
	case $fsa_mount_dir in /*) ;; *) fsa_mount_dir=$PWD/$fsa_mount_dir ;; esac
	fsa_mount_dir=$(fsa_normalize_path "$fsa_mount_dir")
	[ "$fsa_mount_dir" != / ] || fsa_fail 2 invalid_path 'fsa does not mount the root directory.' 'Pass a path in a subdirectory.'
	fsa_refuse_reserved "$fsa_mount_dir"
	fsa_mount_dir=$(cd -P "$1" 2>/dev/null && pwd -P) ||
		fsa_fail 2 path_missing "The directory $1 does not exist." 'Create it, or pass a path in an existing directory.'
	fsa_check_path "$fsa_mount_dir"
	[ "$fsa_mount_dir" != / ] || fsa_fail 2 invalid_path 'fsa does not mount the root directory.' 'Pass a path in a subdirectory.'
	fsa_refuse_reserved "$fsa_mount_dir"
	case $fsa_mount_dir/ in "$fsa_dir"/*) return 0 ;; esac
	case "$fsa_nl$fsa_mounts" in *"$fsa_nl$2:$fsa_mount_dir$fsa_nl"*) return 0 ;; esac
	fsa_mounts=$fsa_mounts$2:$fsa_mount_dir$fsa_nl
	if [ "$2" = rw ]; then
		{ : >"$fsa_mount_dir/$FIELDSET_NONCE"; } 2>/dev/null || fsa_fail 2 path_unwritable "Cannot write to $fsa_mount_dir." 'Pass a path in a directory you can write to.'
		fsa_cleanup_files=$fsa_cleanup_files$fsa_mount_dir/$FIELDSET_NONCE$fsa_nl
	fi
}

fsa_adopt_checkout() { # <path>: `fsa adopt <checkout>` reads a 1.0 checkout, mounted read-only at its physical path (ADR 0087)
	fsa_checkout=$1
	case $fsa_checkout in /*) ;; *) fsa_checkout=$PWD/$fsa_checkout ;; esac
	fsa_add_mount "$fsa_checkout" ro
	fsa_checkout=$fsa_mount_dir
}

fsa_run_cleanup() { # removes the nonce, hand-over and temporary files of this run (idempotent)
	if [ -n "${fsa_cleanup_files:-}" ]; then
		fsa_old_ifs=$IFS
		IFS=$fsa_nl
		set -f
		for fsa_file in $fsa_cleanup_files; do
			rm -f "$fsa_file"
		done
		set +f
		IFS=$fsa_old_ifs
	fi
	fsa_cleanup_files=''
	[ -z "${fsa_tmp:-}" ] || rm -rf "$fsa_tmp"
	fsa_tmp=''
}

fsa_keep_file() { # <path>: leave this file in place at cleanup
	fsa_kept=''
	fsa_old_ifs=$IFS
	IFS=$fsa_nl
	set -f
	for fsa_file in ${fsa_cleanup_files:-}; do
		[ "$fsa_file" = "$1" ] || fsa_kept=$fsa_kept$fsa_file$fsa_nl
	done
	set +f
	IFS=$fsa_old_ifs
	fsa_cleanup_files=$fsa_kept
}

fsa_open_url() { # the URL a command handed over in FIELDSET_OPEN_URL_FILE: http(s) only, one argument, no shell
	[ -n "${FIELDSET_OPEN_URL_FILE:-}" ] && [ -f "$fsa_dir/$FIELDSET_OPEN_URL_FILE" ] || return 0
	fsa_url_file=$fsa_dir/$FIELDSET_OPEN_URL_FILE
	chmod 0600 "$fsa_url_file" 2>/dev/null || true
	fsa_url=$(head -n 1 "$fsa_url_file")
	if ! printf '%s\n' "$fsa_url" | grep -Eqx 'https?://[][A-Za-z0-9._~:/?#@!$&()*+,;=%-]+'; then
		rm -f "$fsa_url_file"
		fsa_say warn 'Ignored a hand-over URL that is not a plain http(s) link.'
		return 0
	fi
	# The file goes only once the link reached a person: the browser, or the terminal (never stdout or stderr,
	# which an agent's transcript keeps). Otherwise it stays (0600) for the owner to read.
	if [ -n "${fsa_opener:-}" ] && "$fsa_opener" "$fsa_url" >/dev/null 2>&1; then
		rm -f "$fsa_url_file"
	elif (printf 'fsa: could not open a browser. Open this link to sign in:\n  %s\n' "$fsa_url" >"$fsa_tty_device") 2>/dev/null; then
		rm -f "$fsa_url_file"
	else
		fsa_keep_file "$fsa_url_file"
		fsa_say warn "Could not open a browser; the sign-in link is in $fsa_url_file (readable only by you)." \
			"Open it from there and delete the file, or issue a new link: fsa login-link <email> --secrets-file <file>"
	fi
}

fsa_wants_tty() { # <launcher> <json> <stdin tty> <stdout tty> <terminal usable> (0|1 each): run the container with -it?
	# Only for a person: no --json (-t merges stderr into stdout), stdout a terminal and the terminal usable. The shim
	# also needs stdin to be the terminal, so piped input (--…-stdin flags) still reaches the command; install.sh's
	# stdin is the script itself (curl | sh), so it hands the container the terminal instead.
	[ "$2" = 0 ] && [ "$4" = 1 ] && [ "$5" = 1 ] && { [ "$1" != shim ] || [ "$3" = 1 ]; }
}

fsa_run_setup() { # host facts, the terminal decision, the nonce and the hand-over file of this run
	fsa_check_path "$fsa_dir"
	! fsa_reserved_path "$fsa_dir" || fsa_fail 2 path_reserved \
		"fsa does not run an instance in $fsa_dir: Docker would mount it over files of the fsa container." \
		'Install the instance under your home directory or /opt (install.sh --dir).'
	if [ -n "${DOCKER_DEFAULT_PLATFORM:-}" ] && ! fsa_platform_ok "$DOCKER_DEFAULT_PLATFORM"; then
		fsa_fail 2 invalid_platform "DOCKER_DEFAULT_PLATFORM must be linux/amd64 or linux/arm64 (a variant such as linux/arm64/v8 is fine), not $DOCKER_DEFAULT_PLATFORM." \
			"Unset DOCKER_DEFAULT_PLATFORM to use the engine's own platform."
	fi
	fsa_host_facts
	FIELDSET_LAUNCHER=${FIELDSET_LAUNCHER:-shim}
	fsa_stdin_tty=0 fsa_stdout_tty=0 fsa_tty_usable=0
	[ ! -t 0 ] || fsa_stdin_tty=1
	[ ! -t 1 ] || fsa_stdout_tty=1
	! (exec <"$fsa_tty_device") 2>/dev/null || fsa_tty_usable=1
	FIELDSET_TTY=0
	! fsa_wants_tty "$FIELDSET_LAUNCHER" "$fsa_json" "$fsa_stdin_tty" "$fsa_stdout_tty" "$fsa_tty_usable" || FIELDSET_TTY=1
	FIELDSET_INSTANCE_DIR=$fsa_dir
	FIELDSET_NONCE=.fsa-nonce-$(od -An -N16 -tx1 /dev/urandom | tr -d ' \n')
	FIELDSET_OPEN_URL_FILE=''
	[ -z "$fsa_opener" ] || FIELDSET_OPEN_URL_FILE=.fsa-open-url-$(od -An -N16 -tx1 /dev/urandom | tr -d ' \n')
	fsa_mounts=''
	fsa_cleanup_files=$fsa_dir/$FIELDSET_NONCE$fsa_nl${FIELDSET_OPEN_URL_FILE:+$fsa_dir/$FIELDSET_OPEN_URL_FILE$fsa_nl}
	{ : >"$fsa_dir/$FIELDSET_NONCE"; } 2>/dev/null || fsa_fail 3 instance_unwritable "Cannot write to $fsa_dir." 'Run fsa as the instance owner.'
	fsa_tmp=$(mktemp -d "${TMPDIR:-/tmp}/fsa-run.XXXXXX" 2>/dev/null) || fsa_fail 3 tmp_unavailable 'Cannot create a temporary directory.'
	if [ -n "${FIELDSET_TRUST_KEY:-}" ]; then
		fsa_add_mount "$(dirname "$FIELDSET_TRUST_KEY")" ro
		FIELDSET_TRUST_KEY=$fsa_mount_dir/$(basename "$FIELDSET_TRUST_KEY")
		export FIELDSET_TRUST_KEY
	fi
}

fsa_stderr_filter() { # the container's NDJSON passes; other lines (Docker's own) become events with --json; all kept
	while IFS= read -r fsa_line || [ -n "$fsa_line" ]; do
		case $fsa_line in
		'{'*) printf '%s\n' "$fsa_line" ;;
		*)
			printf '%s\n' "$fsa_line" >>"$fsa_tmp/stderr"
			if [ "$fsa_json" = 1 ]; then fsa_say warn "$fsa_line"; else printf '%s\n' "$fsa_line"; fi ;;
		esac
	done
}

fsa_docker_run() { # <docker run args…>: stdout streams through, stderr goes through fsa_stderr_filter
	: >"$fsa_tmp/stderr"
	{
		{
			fsa_rc=0
			if [ "$FIELDSET_TTY" = 1 ] && [ "$FIELDSET_LAUNCHER" != shim ]; then
				docker run -it "$@" <"$fsa_tty_device" 2>&1 1>&3 3>&- || fsa_rc=$?
			elif [ "$FIELDSET_TTY" = 1 ]; then
				docker run -it "$@" 2>&1 1>&3 3>&- || fsa_rc=$?
			elif [ "$FIELDSET_LAUNCHER" = shim ]; then
				docker run -i "$@" 2>&1 1>&3 3>&- || fsa_rc=$?
			else
				docker run "$@" </dev/null 2>&1 1>&3 3>&- || fsa_rc=$?
			fi
			printf '%s\n' "$fsa_rc" >"$fsa_tmp/status"
		} | fsa_stderr_filter 3>&- >&2
	} 3>&1
	read -r fsa_rc <"$fsa_tmp/status" || fsa_rc=1
	return "$fsa_rc"
}

fsa_run_container() { # <fsa args…>: the ops container (ADR 0070); returns its exit code
	set -- --entrypoint fsa "$fsa_image" "$@"
	fsa_old_ifs=$IFS
	IFS=$fsa_nl
	set -f
	for fsa_mount in $fsa_mounts; do
		fsa_path=${fsa_mount#*:}
		case "${fsa_mount%%:*}|$fsa_nl$fsa_mounts" in
		rw\|*) set -- -v "$fsa_path:$fsa_path" "$@" ;;
		*"${fsa_nl}rw:$fsa_path$fsa_nl"*) ;;
		*) set -- -v "$fsa_path:$fsa_path:ro" "$@" ;;
		esac
	done
	set +f
	IFS=$fsa_old_ifs
	[ -z "$fsa_group" ] || set -- --group-add "$fsa_group" "$@"
	# FIELDSET_TEST_DNS: fixed DNS answers for the doctor; FIELDSET_TEST_TUNNEL: the fake tunnel image and lifetime. Both are
	# honoured only by release-harness images (ADR 0075, ADR 0082).
	for fsa_var in FIELDSET_LAUNCHER FIELDSET_TTY FIELDSET_HOST_UID FIELDSET_HOST_GID FIELDSET_HOST_OS FIELDSET_INSTANCE_DIR FIELDSET_LAN_ADDRESSES FIELDSET_DESKTOP \
		FIELDSET_TZ FIELDSET_DOCKER_RUNTIME FIELDSET_CONTAINER_UID FIELDSET_DOCKER_SOCKET FIELDSET_NONCE FIELDSET_MOUNTS FIELDSET_OPEN_URL_FILE \
		FIELDSET_TRUST_KEY FIELDSET_REGISTRY FIELDSET_RELEASE_URL FIELDSET_RELEASE_MANIFEST DOCKER_DEFAULT_PLATFORM FIELDSET_TEST_DNS FIELDSET_TEST_TUNNEL; do
		set -- -e "$fsa_var" "$@"
	done
	# HOME and DOCKER_CONFIG on a tmpfs only this container sees: nothing on the host can plant a Docker CLI plugin
	# or config into the process that holds the socket.
	set -- --rm --init --label app.fieldset.role=ops --user "$fsa_user" \
		--tmpfs "/run/fsa-home:mode=0700,uid=${fsa_user%%:*},gid=${fsa_user#*:}" -e HOME=/run/fsa-home -e DOCKER_CONFIG=/run/fsa-home/.docker \
		-e DOCKER_HOST=unix:///var/run/docker.sock -v "$fsa_socket:/var/run/docker.sock" -v "$fsa_dir:$fsa_dir" -w "$fsa_dir" "$@"
	fsa_docker_run "$@"
}

fsa_run_status() { # maps a failed `docker run` (125-127) to an fsa error; Docker Desktop's unshared paths are exit 3
	case $fsa_status in 125 | 126 | 127) ;; *) return 0 ;; esac
	if grep -Eqi 'mounts denied|not shared from the host|is not shared' "$fsa_tmp/stderr" 2>/dev/null; then
		fsa_fail 3 path_not_shared 'Docker cannot mount a directory fsa needs: it is not shared with the Docker engine.' \
			'Share the directory with Docker (Docker Desktop: Settings > Resources > File sharing; Colima: mounts in colima.yaml) or use a path under your home directory, then run the command again.'
	fi
	fsa_docker_error=$(tail -n 1 "$fsa_tmp/stderr" 2>/dev/null) || fsa_docker_error=''
	fsa_fail 10 docker_run_failed "Docker could not start the fsa container (exit $fsa_status)${fsa_docker_error:+: $fsa_docker_error}" \
		'Check that the image and the Docker socket are available, then run the command again.'
}

fsa_run() { # <instance dir> <image> <fsa command> [args...]: runs it in an ops container, returns its exit code
	fsa_dir=$1
	fsa_image=$2
	shift 2
	fsa_nl='
'
	fsa_tty_device=/dev/tty
	fsa_find_command "$@"
	fsa_run_setup

	# The command moves to the front with --dir <instance> after it. File-path flags become absolute, physical
	# paths, and their parent directory is mounted at the same path (KTD2). --dir must name this instance. The first
	# argument of `adopt` is the 1.0 checkout: mounted read-only at its own physical path.
	fsa_found=0 fsa_ended=0 fsa_checkout=''
	fsa_n=$#
	while [ "$fsa_n" -gt 0 ]; do
		fsa_arg=$1
		shift
		fsa_n=$((fsa_n - 1))
		if [ "$fsa_ended" = 1 ] || [ "$fsa_arg" = -- ]; then
			fsa_ended=1
			set -- "$@" "$fsa_arg"
			continue
		fi
		case $fsa_arg in
		-*) ;;
		*)
			if [ "$fsa_found" = 0 ]; then
				fsa_found=1
			elif [ "$fsa_command" = adopt ] && [ -z "$fsa_checkout" ]; then
				fsa_adopt_checkout "$fsa_arg"
				set -- "$@" "$fsa_checkout"
			else
				set -- "$@" "$fsa_arg"
			fi
			continue ;;
		esac
		fsa_name=${fsa_arg%%=*}
		fsa_file_flag_mode "$fsa_name"
		if [ "$fsa_name" != --dir ] && [ -z "$fsa_mode" ]; then
			set -- "$@" "$fsa_arg"
			# The value of a value-taking flag is neither the command nor adopt's checkout: keep it with its flag.
			if [ "$fsa_name" = "$fsa_arg" ] && [ "$fsa_n" -gt 0 ] && fsa_takes_value "$fsa_name"; then
				fsa_arg=$1
				shift
				fsa_n=$((fsa_n - 1))
				set -- "$@" "$fsa_arg"
			fi
			continue
		fi
		if [ "$fsa_name" = "$fsa_arg" ]; then
			[ "$fsa_n" -gt 0 ] || fsa_fail 2 invalid_usage "$fsa_name needs a value."
			fsa_arg=$fsa_name=$1
			shift
			fsa_n=$((fsa_n - 1))
		fi
		fsa_value=${fsa_arg#*=}
		[ -n "$fsa_value" ] || fsa_fail 2 invalid_usage "$fsa_name needs a value."
		case $fsa_value in /*) ;; *) fsa_value=$PWD/$fsa_value ;; esac
		if [ "$fsa_name" = --dir ]; then
			[ "$(cd "$fsa_value" 2>/dev/null && pwd -P)" = "$(cd "$fsa_dir" && pwd -P)" ] || fsa_fail 2 other_instance "This fsa manages $fsa_dir, not $fsa_value." "Run $fsa_value/fsa instead."
		else
			fsa_check_path "$fsa_value"
			fsa_add_mount "$(dirname "$fsa_value")" "$fsa_mode"
			set -- "$@" "$fsa_name=$fsa_mount_dir/$(basename "$fsa_value")"
		fi
	done
	[ "$fsa_found" = 0 ] || set -- "$fsa_command" --dir "$fsa_dir" "$@"
	FIELDSET_MOUNTS=$fsa_mounts
	export FIELDSET_LAUNCHER FIELDSET_TTY FIELDSET_INSTANCE_DIR FIELDSET_NONCE FIELDSET_MOUNTS FIELDSET_OPEN_URL_FILE

	fsa_status=0
	fsa_run_container "$@" || fsa_status=$?
	fsa_open_url
	fsa_run_status
	fsa_run_cleanup
	return "$fsa_status"
}

fsa_shim_main() {
	set -eu
	fsa_nl='
'
	fsa_find_command "$@"
	fsa_self=$0
	while [ -L "$fsa_self" ]; do
		fsa_link=$(readlink "$fsa_self")
		case $fsa_link in /*) fsa_self=$fsa_link ;; *) fsa_self=$(dirname "$fsa_self")/$fsa_link ;; esac
	done
	fsa_home=$(cd "$(dirname "$fsa_self")" && pwd)
	command -v docker >/dev/null 2>&1 || fsa_fail 10 docker_missing 'Docker is not installed.' 'Install Docker, then run the command again.'
	fsa_image=$(fsa_state_image "$fsa_home/state.json") ||
		fsa_fail 3 no_state "$fsa_home has no usable state.json; the installation did not finish." 'Run install.sh again with the same flags to finish it.'
	FIELDSET_LAUNCHER=shim
	trap fsa_run_cleanup EXIT
	trap 'exit 130' INT
	trap 'exit 143' TERM
	fsa_exit=0
	fsa_run "$fsa_home" "$fsa_image" "$@" || fsa_exit=$?
	exit "$fsa_exit"
}

[ "${FIELDSET_SHIM_LIBRARY:-}" = 1 ] || { fsa_shim_main "$@"; }
