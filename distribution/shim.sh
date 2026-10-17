#!/bin/sh
# fsa: runs an `fsa` command of this instance in a throwaway ops container (BUILD_PROMPT_V2 §6.2, §13.1;
# ADR 0048, 0049, 0070). install.sh installs it as <instance dir>/fsa and sources it for the first
# `fsa install`. It passes host facts as FSA_* variables, mounts the Docker socket, the instance directory at the
# same absolute path and the parent directory of every file-path flag, writes a nonce file the container must
# see, and opens a URL a command hands back. The ops image comes from state.json (digests.app), never from a
# release manifest, so a failed shim swap cannot lock the owner out.
#
# Contract with install.sh (keep stable): FSA_SHIM_LIBRARY=1 only defines the functions, and
# `fsa_run <instance dir> <image> <fsa command> [args...]` returns the command's exit code.

fsa_fail() { # <exit code> <error code> <message> [hint]: report on stderr (and as the JSON envelope with --json)
	printf 'fsa: %s\n' "$3" >&2
	[ -z "${4:-}" ] || printf '  Fix: %s\n' "$4" >&2
	if [ "${fsa_json:-0}" = 1 ]; then
		printf '{"schema_version":1,"command":%s,"status":"failed","changed":false,"data":{},"secrets":[],"warnings":[],"errors":[{"code":%s,"message":%s,"hint":%s,"retryable":false}]}\n' \
			"$(fsa_json_string "${fsa_command:-}")" "$(fsa_json_string "$2")" "$(fsa_json_string "$3")" "$(fsa_json_string "${4:-}")"
	fi
	exit "$1"
}

fsa_json_string() {
	printf '"%s"' "$(printf '%s' "$1" | sed 's/\\/\\\\/g; s/"/\\"/g' | tr -d '\000-\037')"
}

fsa_file_flag_mode() { # sets fsa_mode: rw for output paths, ro for input paths, empty otherwise (FilePathFlags)
	case $1 in
	--secrets-file | --output | --*-out) fsa_mode=rw ;;
	--*-file | --from-backup) fsa_mode=ro ;;
	*) fsa_mode='' ;;
	esac
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
	case $FSA_HOST_OS in
	darwin) [ -n "${SSH_CONNECTION:-}" ] || ! command -v open >/dev/null 2>&1 || fsa_opener=open ;;
	linux) [ -z "${DISPLAY:-}${WAYLAND_DISPLAY:-}" ] || ! command -v xdg-open >/dev/null 2>&1 || fsa_opener=xdg-open ;;
	esac
}

fsa_host_facts() { # exports the FSA_* host facts; sets fsa_user, fsa_group and fsa_socket (KTD2)
	FSA_HOST_UID=$(id -u)
	FSA_HOST_GID=$(id -g)
	FSA_HOST_OS=$(uname -s | tr '[:upper:]' '[:lower:]')
	FSA_TZ=$(fsa_timezone)
	FSA_LAN_ADDRESSES=$(fsa_lan_addresses)
	fsa_browser_opener
	FSA_DESKTOP=0
	[ -z "$fsa_opener" ] || FSA_DESKTOP=1
	fsa_info=$(docker info --format '{{.OperatingSystem}}|{{.Name}}|{{json .SecurityOptions}}' 2>/dev/null) ||
		fsa_fail 10 docker_unreachable 'The Docker daemon is not reachable.' 'Start Docker (or check DOCKER_HOST and your docker group membership), then run the command again.'
	case "$FSA_HOST_OS|$fsa_info" in
	*name=rootless*) FSA_DOCKER_RUNTIME=rootless ;;
	linux\|*'Docker Desktop'* | linux\|*OrbStack* | linux\|*\|colima*\|* | linux\|*\|lima-*\|* | linux\|*\|rancher-desktop\|*) FSA_DOCKER_RUNTIME=desktop ;;
	linux\|*) FSA_DOCKER_RUNTIME=rootful ;;
	*) FSA_DOCKER_RUNTIME=desktop ;;
	esac
	fsa_socket=/var/run/docker.sock
	case ${DOCKER_HOST:-} in
	'') [ "$FSA_DOCKER_RUNTIME" != rootless ] || fsa_socket=${XDG_RUNTIME_DIR:-/run/user/$FSA_HOST_UID}/docker.sock ;;
	unix://*) [ "$FSA_DOCKER_RUNTIME" = desktop ] || fsa_socket=${DOCKER_HOST#unix://} ;;
	*) fsa_fail 10 docker_host_unsupported "fsa needs the local Docker socket, not DOCKER_HOST=$DOCKER_HOST." 'Unset DOCKER_HOST or point it at a unix:// socket.' ;;
	esac
	fsa_group=''
	if [ "$FSA_DOCKER_RUNTIME" = rootful ]; then
		FSA_CONTAINER_UID=$FSA_HOST_UID
		fsa_user=$FSA_HOST_UID:$FSA_HOST_GID
		fsa_group=$(stat -L -c %g "$fsa_socket" 2>/dev/null) || fsa_group=''  # rootful means Linux: GNU or busybox stat
	else
		FSA_CONTAINER_UID=0
		fsa_user=0:0
	fi
	FSA_DOCKER_SOCKET=$fsa_socket
	export FSA_HOST_UID FSA_HOST_GID FSA_HOST_OS FSA_TZ FSA_LAN_ADDRESSES FSA_DESKTOP FSA_DOCKER_RUNTIME FSA_CONTAINER_UID FSA_DOCKER_SOCKET
}

fsa_check_path() { # <path>: refuse what a bind mount cannot carry
	case $1 in
	*:* | *,* | *"$fsa_nl"*) fsa_fail 2 invalid_path "Paths with ':', ',' or line breaks cannot be mounted: $1" 'Use another path.' ;;
	esac
}

fsa_add_mount() { # <directory> <ro|rw>: mount a file flag's parent directory at the same path (rw wins)
	fsa_check_path "$1"
	[ -d "$1" ] || fsa_fail 2 path_missing "The directory $1 does not exist." 'Create it, or pass a path in an existing directory.'
	[ "$1" != / ] || fsa_fail 2 invalid_path 'fsa does not mount the root directory.' 'Pass a path in a subdirectory.'
	case $1/ in "$fsa_dir"/*) return 0 ;; esac
	case "$fsa_nl$fsa_mounts" in *"$fsa_nl$2:$1$fsa_nl"*) return 0 ;; esac
	fsa_mounts=$fsa_mounts$2:$1$fsa_nl
	if [ "$2" = rw ]; then
		: >"$1/$FSA_NONCE" || fsa_fail 2 path_unwritable "Cannot write to $1." 'Pass a path in a directory you can write to.'
		fsa_cleanup_files=$fsa_cleanup_files$1/$FSA_NONCE$fsa_nl
	fi
}

fsa_run_cleanup() { # removes the nonce and hand-over files of this run (idempotent)
	fsa_old_ifs=$IFS
	IFS=$fsa_nl
	set -f
	for fsa_file in ${fsa_cleanup_files:-}; do
		rm -f "$fsa_file"
	done
	set +f
	IFS=$fsa_old_ifs
	fsa_cleanup_files=''
}

fsa_open_url() { # opens the URL a command handed over in FSA_OPEN_URL_FILE: http(s) only, one argument, no shell
	[ -n "${FSA_OPEN_URL_FILE:-}" ] && [ -f "$fsa_dir/$FSA_OPEN_URL_FILE" ] || return 0
	fsa_url=$(head -n 1 "$fsa_dir/$FSA_OPEN_URL_FILE")
	rm -f "$fsa_dir/$FSA_OPEN_URL_FILE"
	printf '%s\n' "$fsa_url" | grep -Eqx 'https?://[][A-Za-z0-9._~:/?#@!$&()*+,;=%-]+' || return 0
	"$fsa_opener" "$fsa_url" >/dev/null 2>&1 || printf 'fsa: could not open a browser; see the command output for the link.\n' >&2
}

fsa_run() { # <instance dir> <image> <fsa command> [args...]: runs it in an ops container, returns its exit code
	fsa_dir=$1
	fsa_image=$2
	shift 2
	fsa_nl='
'
	fsa_command=${1:-}
	fsa_json=0
	for fsa_arg in "$@"; do [ "$fsa_arg" != --json ] || fsa_json=1; done
	fsa_check_path "$fsa_dir"
	fsa_host_facts
	FSA_TTY=0
	[ "$fsa_json" = 1 ] || [ ! -t 1 ] || ! (exec </dev/tty) 2>/dev/null || FSA_TTY=1
	FSA_LAUNCHER=${FSA_LAUNCHER:-shim}
	FSA_INSTANCE_DIR=$fsa_dir
	FSA_NONCE=.fsa-nonce-$(od -An -N16 -tx1 /dev/urandom | tr -d ' \n')
	FSA_OPEN_URL_FILE=''
	[ -z "$fsa_opener" ] || FSA_OPEN_URL_FILE=.fsa-open-url-$(od -An -N16 -tx1 /dev/urandom | tr -d ' \n')
	fsa_mounts=''
	fsa_cleanup_files=$fsa_dir/$FSA_NONCE$fsa_nl${FSA_OPEN_URL_FILE:+$fsa_dir/$FSA_OPEN_URL_FILE$fsa_nl}
	: >"$fsa_dir/$FSA_NONCE" || fsa_fail 3 instance_unwritable "Cannot write to $fsa_dir." 'Run fsa as the instance owner.'
	[ -z "${FSA_TRUST_KEY:-}" ] || fsa_add_mount "$(dirname "$FSA_TRUST_KEY")" ro

	# File-path flags: absolute paths, parent directory mounted at the same path (KTD2). --dir is this instance.
	fsa_n=$#
	while [ "$fsa_n" -gt 0 ]; do
		fsa_arg=$1
		shift
		fsa_n=$((fsa_n - 1))
		fsa_name=${fsa_arg%%=*}
		case $fsa_arg in --*) fsa_file_flag_mode "$fsa_name" ;; *) fsa_mode='' ;; esac
		if [ "$fsa_name" = --dir ] || { [ -n "$fsa_mode" ] && [ "$fsa_name" = "$fsa_arg" ]; }; then
			[ "$fsa_n" -gt 0 ] || [ "$fsa_name" != "$fsa_arg" ] || fsa_fail 2 invalid_usage "$fsa_name needs a value."
			if [ "$fsa_name" = "$fsa_arg" ]; then
				fsa_arg=$fsa_name=$1
				shift
				fsa_n=$((fsa_n - 1))
			fi
		fi
		fsa_value=${fsa_arg#*=}
		case $fsa_value in /*) ;; *) fsa_value=$PWD/$fsa_value ;; esac
		if [ "$fsa_name" = --dir ]; then
			[ "$(cd "$fsa_value" 2>/dev/null && pwd -P)" = "$(cd "$fsa_dir" && pwd -P)" ] || fsa_fail 2 other_instance "This fsa manages $fsa_dir, not $fsa_value." "Run $fsa_value/fsa instead."
		elif [ -n "$fsa_mode" ]; then
			fsa_check_path "$fsa_value"
			fsa_add_mount "$(dirname "$fsa_value")" "$fsa_mode"
			set -- "$@" "$fsa_name=$fsa_value"
		else
			set -- "$@" "$fsa_arg"
		fi
	done
	FSA_MOUNTS=$fsa_mounts
	export FSA_LAUNCHER FSA_TTY FSA_INSTANCE_DIR FSA_NONCE FSA_MOUNTS FSA_OPEN_URL_FILE

	case $fsa_command in '' | -*) set -- --entrypoint fsa "$fsa_image" "$@" ;; *) set -- --entrypoint fsa "$fsa_image" "$@" --dir "$fsa_dir" ;; esac
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
	for fsa_var in FSA_LAUNCHER FSA_TTY FSA_HOST_UID FSA_HOST_GID FSA_HOST_OS FSA_INSTANCE_DIR FSA_LAN_ADDRESSES FSA_DESKTOP \
		FSA_TZ FSA_DOCKER_RUNTIME FSA_CONTAINER_UID FSA_DOCKER_SOCKET FSA_NONCE FSA_MOUNTS FSA_OPEN_URL_FILE \
		FSA_TRUST_KEY FSA_REGISTRY FSA_RELEASE_URL FSA_RELEASE_MANIFEST; do
		set -- -e "$fsa_var" "$@"
	done
	set -- --rm --init --label app.form-schema.role=ops --user "$fsa_user" -e HOME=/tmp -e DOCKER_CONFIG=/tmp/.docker \
		-e DOCKER_HOST=unix:///var/run/docker.sock -v "$fsa_socket:/var/run/docker.sock" -v "$fsa_dir:$fsa_dir" -w "$fsa_dir" "$@"

	# A terminal only when there is one and no --json (-t merges stderr into stdout); install.sh passes no stdin.
	fsa_status=0
	if [ "$FSA_TTY" = 1 ]; then
		docker run -it "$@" </dev/tty || fsa_status=$?
	elif [ "$FSA_LAUNCHER" = shim ]; then
		docker run -i "$@" || fsa_status=$?
	else
		docker run "$@" </dev/null || fsa_status=$?
	fi
	fsa_open_url
	fsa_run_cleanup
	case $fsa_status in
	125 | 126 | 127) fsa_fail 10 docker_run_failed "Docker could not start the fsa container (exit $fsa_status)." 'See the Docker error above; check that the image and the socket are available.' ;;
	esac
	return "$fsa_status"
}

fsa_shim_main() {
	set -eu
	fsa_nl='
'
	fsa_command=${1:-}
	fsa_json=0
	for fsa_arg in "$@"; do [ "$fsa_arg" != --json ] || fsa_json=1; done
	fsa_self=$0
	while [ -L "$fsa_self" ]; do
		fsa_link=$(readlink "$fsa_self")
		case $fsa_link in /*) fsa_self=$fsa_link ;; *) fsa_self=$(dirname "$fsa_self")/$fsa_link ;; esac
	done
	fsa_home=$(cd "$(dirname "$fsa_self")" && pwd)
	command -v docker >/dev/null 2>&1 || fsa_fail 10 docker_missing 'Docker is not installed.' 'Install Docker, then run the command again.'
	fsa_image=$(fsa_state_image "$fsa_home/state.json") ||
		fsa_fail 3 no_state "$fsa_home has no usable state.json; the installation did not finish." 'Run install.sh again with the same flags to finish it.'
	FSA_LAUNCHER=shim
	trap fsa_run_cleanup EXIT
	trap 'exit 130' INT
	trap 'exit 143' TERM
	fsa_exit=0
	fsa_run "$fsa_home" "$fsa_image" "$@" || fsa_exit=$?
	exit "$fsa_exit"
}

[ "${FSA_SHIM_LIBRARY:-}" = 1 ] || { fsa_shim_main "$@"; }
