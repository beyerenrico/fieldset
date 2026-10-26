#!/bin/sh
# fieldset: runs a `fieldset` command of this instance in a throwaway ops container (BUILD_PROMPT_V2 §6.2, §13.1;
# ADR 0048, 0049, 0070). install.sh installs it as <instance dir>/fieldset and sources it for the first
# `fieldset install`. It passes host facts as FIELDSET_* variables, mounts the Docker socket, the instance directory at the
# same absolute path and the parent directory of every file-path flag, writes a nonce file the container must
# see, and opens a URL a command hands back. The ops image comes from state.json (digests.app), never from a
# release manifest, so a failed shim swap cannot lock the owner out.
#
# The first argument that is not a flag (or a flag's value) is the command; `--dir <instance>` goes right after
# it. HOME and DOCKER_CONFIG live on a container-private tmpfs, and no mount may cover a system path of the
# image or a directory other users can write to (fieldset_reserved_path, LaunchGuard). The ops container runs as the
# owner a throwaway container measured (or the one saved in state.json): root where container root is the host user, the
# host user where IDs pass through (fieldset_owner). On WSL it refuses WSL 1 and an
# instance directory on a Windows drive, and names the Docker Desktop step when Docker is missing or down. With --json,
# stdout carries only the command's JSON document and stderr only NDJSON events (KTD3). Docker's DOCKER_DEFAULT_PLATFORM, when set
# (linux/amd64 or linux/arm64), picks the platform of the ops container and, passed on, of every image fieldset runs.
#
# Contract with install.sh (keep stable): FIELDSET_SHIM_LIBRARY=1 only defines the functions, and
# `fieldset_run <instance dir> <image> <fieldset command> [args...]` returns the command's exit code. install.sh sets
# fieldset_leftover=1 when the instance directory held the marker before it ran (an install that did not finish).

fieldset_json_string() {
	printf '"%s"' "$(printf '%s' "$1" | sed 's/\\/\\\\/g; s/"/\\"/g' | tr -d '\000-\037')"
}

fieldset_say() { # <info|warn|error> <message> [hint]: on stderr, one NDJSON event with --json, else human lines (KTD3)
	if [ "${fieldset_json:-0}" = 1 ]; then
		printf '{"ts":"%s","phase":"launcher","level":"%s","message":%s}\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$1" \
			"$(fieldset_json_string "$2${3:+ Fix: $3}")" >&2
	else
		printf 'fieldset: %s\n' "$2" >&2
		[ -z "${3:-}" ] || printf '  Fix: %s\n' "$3" >&2
	fi
}

fieldset_fail() { # <exit code> <error code> <message> [hint] [retryable: true|false]: report, plus the envelope with --json
	fieldset_say error "$3" "${4:-}"
	if [ "${fieldset_json:-0}" = 1 ]; then
		fieldset_hint=null
		[ -z "${4:-}" ] || fieldset_hint=$(fieldset_json_string "$4")
		printf '{"schema_version":1,"command":%s,"status":"failed","exit_code":%s,"changed":false,"data":{},"secrets":[],"warnings":[],"errors":[{"code":%s,"message":%s,"hint":%s,"retryable":%s}]}\n' \
			"$(fieldset_json_string "${fieldset_command:-}")" "$1" "$(fieldset_json_string "$2")" "$(fieldset_json_string "$3")" "$fieldset_hint" "${5:-false}"
	fi
	exit "$1"
}

fieldset_file_flag_mode() { # sets fieldset_mode: rw for output paths, ro for input paths, empty otherwise (FilePathFlags)
	case $1 in
	--secrets-file | --output | --*-out) fieldset_mode=rw ;;
	--*-file | --from-backup) fieldset_mode=ro ;;
	*) fieldset_mode='' ;;
	esac
}

# Every value-taking option of the fieldset:* commands plus the --version alias of install and adopt, so that a flag
# before the command keeps its value (`fieldset --behind-proxy traefik install`); ShimScriptTest keeps it in step with the
# commands.
fieldset_value_flags='--acme-ca --acme-ca-root-file --acme-email --app-key-file --behind-proxy --dir --domain --from-address
--from-backup --from-name --identity-file --identity-out --kind --lan-address --locale --lock-id --org --orphans
--owner-email --owner-name --passphrase-file --password-file --phone-https --port --proxy-network --release
--scratch --seal --secrets-file --since --smtp-encryption --smtp-host --smtp-port --smtp-username --source
--tail --test-to --to --trusted-proxy --url --version'

fieldset_takes_value() { # <flag>: true for a value-taking flag written without =value (dir, file-path or listed flags)
	fieldset_file_flag_mode "$1"
	[ -z "$fieldset_mode" ] || return 0
	for fieldset_flag in $fieldset_value_flags; do
		[ "$fieldset_flag" != "$1" ] || return 0
	done
	return 1
}

fieldset_find_command() { # sets fieldset_command/fieldset_found (first argument before -- that is no flag or flag value) and fieldset_json
	fieldset_command='' fieldset_found=0 fieldset_json=0 fieldset_skip=0
	for fieldset_arg in "$@"; do
		if [ "$fieldset_skip" = 1 ]; then
			fieldset_skip=0
			continue
		fi
		case $fieldset_arg in
		--) break ;;
		--json) fieldset_json=1 ;;
		--*=*) ;;
		-*) if [ "$fieldset_found" = 0 ] && fieldset_takes_value "$fieldset_arg"; then fieldset_skip=1; fi ;;
		*) [ "$fieldset_found" = 1 ] || { fieldset_command=$fieldset_arg && fieldset_found=1; } ;;
		esac
	done
}

fieldset_state_image() { # <state.json>: the app image the instance runs (digests.app, InstanceState's 4-space layout)
	[ -f "$1" ] || return 1
	fieldset_ref=$(awk '/^    "digests": \{$/ { inside = 1; next }
		inside && (/^    \}/ || /^    \]/) { exit }
		inside && /^        "app": "/ { sub(/^        "app": "/, ""); sub(/",?$/, ""); print; exit }' "$1")
	printf '%s\n' "$fieldset_ref" | grep -Eqx '[A-Za-z0-9._:/-]+@sha256:[a-f0-9]{64}' || return 1
	printf '%s\n' "$fieldset_ref"
}

fieldset_timezone() {
	fieldset_tz=${TZ:-}
	[ -n "$fieldset_tz" ] || fieldset_tz=$(cat /etc/timezone 2>/dev/null) || fieldset_tz=''
	[ -n "$fieldset_tz" ] || fieldset_tz=$(readlink /etc/localtime 2>/dev/null) || fieldset_tz=''
	fieldset_tz=${fieldset_tz#:}
	fieldset_tz=${fieldset_tz##*zoneinfo/}
	case $fieldset_tz in '' | /* | *[!A-Za-z0-9_+/-]*) fieldset_tz='' ;; esac
	printf '%s' "$fieldset_tz"
}

fieldset_lan_addresses() { # candidate LAN addresses, without loopback and container or VM bridges
	if command -v ip >/dev/null 2>&1; then
		ip -o -4 addr show scope global 2>/dev/null |
			awk '$2 !~ /^(docker|br-|veth|virbr|cni|flannel|cali|vxlan|tun|wg)/ { split($4, a, "/"); printf "%s ", a[1] }'
	elif command -v ifconfig >/dev/null 2>&1; then
		ifconfig 2>/dev/null | awk '/^[a-z]/ { iface = $1 }
			$1 == "inet" && iface !~ /^(lo|docker|br-|veth|bridge|utun|vmnet)/ { sub(/^addr:/, "", $2); if ($2 !~ /^127\./) printf "%s ", $2 }'
	fi
}

fieldset_browser_opener() { # sets fieldset_opener when a desktop session can open a browser
	fieldset_opener=''
	case $FIELDSET_HOST_OS in
	darwin) [ -n "${SSH_CONNECTION:-}" ] || ! command -v open >/dev/null 2>&1 || fieldset_opener=open ;;
	linux) [ -z "${DISPLAY:-}${WAYLAND_DISPLAY:-}" ] || ! command -v xdg-open >/dev/null 2>&1 || fieldset_opener=xdg-open ;;
	esac
}

fieldset_wsl_detect() { # sets fieldset_wsl (2: WSL 2, 1: WSL 1, else 0) and fieldset_wsl_distro (a plain WSL_DISTRO_NAME)
	# A "microsoft" kernel release counts only with an interop marker or WSL_DISTRO_NAME, as some cloud kernels carry the
	# word too; WSL 1 is the capital-M kernel without "WSL2". FIELDSET_TEST_PROC replaces /proc in the tests only.
	fieldset_wsl=0 fieldset_wsl_distro=''
	case ${WSL_DISTRO_NAME:-} in '' | *[!A-Za-z0-9._-]*) ;; *) fieldset_wsl_distro=$WSL_DISTRO_NAME ;; esac
	fieldset_proc=${FIELDSET_TEST_PROC:-/proc}
	[ -e "$fieldset_proc/sys/fs/binfmt_misc/WSLInterop" ] || [ -e "$fieldset_proc/sys/fs/binfmt_misc/WSLInterop-late" ] ||
		[ -n "${WSL_DISTRO_NAME:-}" ] || return 0
	fieldset_kernel=$(cat "$fieldset_proc/sys/kernel/osrelease" 2>/dev/null) || fieldset_kernel=''
	case $fieldset_kernel in *WSL2*) fieldset_wsl=2 ;; *Microsoft*) fieldset_wsl=1 ;; *microsoft*) fieldset_wsl=2 ;; esac
}

fieldset_wsl_check() { # fieldset_wsl_detect, then exit 3 wsl1_unsupported on WSL 1 (R25)
	fieldset_wsl_detect
	[ "$fieldset_wsl" != 1 ] || fieldset_fail 3 wsl1_unsupported 'fieldset needs WSL 2, and this distro runs on WSL 1.' \
		"Convert it in PowerShell on Windows: wsl --set-version ${fieldset_wsl_distro:-<distro>} 2 (wsl -l -v lists the distros), then run the command again."
}

fieldset_windows_drive() { # <absolute path>: true when its physical path lies on a Windows drive (KTD11): the mount that holds
	# it is drvfs, 9p with drvfs options, or any 9p under WSL 2. A part that does not exist yet is kept as written. Kept
	# identical to install.sh's fieldset_install_windows_drive (WslScriptsTest).
	case $1 in /*) ;; *) return 1 ;; esac
	fieldset_base=$1 fieldset_rest=''
	while [ ! -d "$fieldset_base" ]; do
		fieldset_rest=/${fieldset_base##*/}$fieldset_rest
		fieldset_base=${fieldset_base%/*}
		[ -n "$fieldset_base" ] || fieldset_base=/
	done
	fieldset_real=$(cd -P "$fieldset_base" 2>/dev/null && pwd -P) || fieldset_real=$fieldset_base
	fieldset_real=${fieldset_real%/}$fieldset_rest
	awk -v path="${fieldset_real:-/}" -v wsl="${fieldset_wsl:-0}" '{ mp = $2; gsub(/\\040/, " ", mp); gsub(/\\011/, "\t", mp)
		if ((mp == "/" || path == mp || index(path, mp "/") == 1) && length(mp) >= best) { best = length(mp); type = $3; opts = $4 } }
		END { exit !(type == "drvfs" || (type == "9p" && (wsl == 2 || opts ~ /drvfs/))) }' "${FIELDSET_TEST_PROC:-/proc}/mounts" 2>/dev/null
}

fieldset_docker_missing() { # exit 10 docker_missing; on WSL 2 naming Docker Desktop's WSL integration and Docker Engine (R26)
	[ "${fieldset_wsl:-0}" = 2 ] || fieldset_fail 10 docker_missing 'Docker is not installed.' 'Install Docker, then run the command again.'
	fieldset_fail 10 docker_missing 'Docker is not available in this WSL distro.' \
		"Start Docker Desktop on Windows and turn on its WSL integration for ${fieldset_wsl_distro:-this distro} (Settings > Resources > WSL integration), or install Docker Engine in this distro, then run the command again."
}

fieldset_docker_unreachable() { # `docker info` failed: exit 10 docker_unavailable; on WSL 2 the Docker Desktop step (R26)
	[ "${fieldset_wsl:-0}" = 2 ] || fieldset_fail 10 docker_unavailable 'The Docker daemon is not reachable.' \
		'Start Docker (or check DOCKER_HOST and your docker group membership), then run the command again.' true
	# Docker Desktop's docker in a distro without its WSL integration only says that the integration is off.
	! docker info 2>&1 | grep -qi 'WSL integration' || fieldset_docker_missing
	fieldset_fail 10 docker_unavailable 'The Docker daemon is not reachable.' \
		'Start Docker Desktop on Windows (Docker Engine in WSL: sudo service docker start), then run the command again.' true
}

fieldset_host_facts() { # exports the FIELDSET_* host facts but the owner (fieldset_owner); sets fieldset_socket (KTD2)
	FIELDSET_HOST_UID=$(id -u)
	FIELDSET_HOST_GID=$(id -g)
	FIELDSET_HOST_OS=$(uname -s | tr '[:upper:]' '[:lower:]')
	FIELDSET_TZ=$(fieldset_timezone)
	FIELDSET_LAN_ADDRESSES=$(fieldset_lan_addresses)
	fieldset_browser_opener
	FIELDSET_DESKTOP=0
	[ -z "$fieldset_opener" ] || FIELDSET_DESKTOP=1
	fieldset_info=$(docker info --format '{{.OperatingSystem}}|{{.Name}}|{{json .SecurityOptions}}' 2>/dev/null) || fieldset_docker_unreachable
	FIELDSET_WSL=${fieldset_wsl:-0}
	case "$FIELDSET_WSL|$fieldset_info" in # the backend by the prefix of OperatingSystem, as DockerInfo::wslBackend()
	2\|'Docker Desktop'*) FIELDSET_WSL_BACKEND=desktop ;;
	2\|*) FIELDSET_WSL_BACKEND=engine ;;
	*) FIELDSET_WSL_BACKEND='' ;;
	esac
	case "$FIELDSET_HOST_OS|$fieldset_info" in
	*name=rootless*) FIELDSET_DOCKER_RUNTIME=rootless ;;
	linux\|*'Docker Desktop'* | linux\|*OrbStack* | linux\|*\|colima*\|* | linux\|*\|lima-*\|* | linux\|*\|rancher-desktop\|*) FIELDSET_DOCKER_RUNTIME=desktop ;;
	linux\|*) FIELDSET_DOCKER_RUNTIME=rootful ;;
	*) FIELDSET_DOCKER_RUNTIME=desktop ;;
	esac
	fieldset_socket=/var/run/docker.sock
	case ${DOCKER_HOST:-} in
	'') [ "$FIELDSET_DOCKER_RUNTIME" != rootless ] || fieldset_socket=${XDG_RUNTIME_DIR:-/run/user/$FIELDSET_HOST_UID}/docker.sock ;;
	unix://*) [ "$FIELDSET_DOCKER_RUNTIME" = desktop ] || fieldset_socket=${DOCKER_HOST#unix://} ;;
	*) fieldset_fail 10 docker_host_unsupported "fieldset needs the local Docker socket, not DOCKER_HOST=$DOCKER_HOST." 'Unset DOCKER_HOST or point it at a unix:// socket.' ;;
	esac
	FIELDSET_DOCKER_SOCKET=$fieldset_socket
	export FIELDSET_HOST_UID FIELDSET_HOST_GID FIELDSET_HOST_OS FIELDSET_TZ FIELDSET_LAN_ADDRESSES FIELDSET_DESKTOP FIELDSET_DOCKER_RUNTIME FIELDSET_DOCKER_SOCKET \
		FIELDSET_WSL FIELDSET_WSL_BACKEND
}

fieldset_state_owner() { # <state.json>: sets fieldset_saved_uid, _runtime and _group from a measured owner (state 1.1, KTD5)
	# or fails: a state without one (written before 2.1.1) or with a value out of grammar. InstanceState's 4-space layout.
	[ -f "$1" ] || return 1
	fieldset_saved=$(awk '/^    "owner": \{$/ { inside = 1; next }
		inside && /^    \}/ { exit }
		inside && /^        "[a-z_]+": / { key = $1; gsub(/[":]/, "", key); value = $0; sub(/^        "[a-z_]+": /, "", value)
			sub(/,$/, "", value); sub(/^"/, "", value); sub(/"$/, "", value); owner[key] = value }
		END { uid = owner["container_uid"]; runtime = owner["runtime"]; group = owner["socket_group"]
			if (uid ~ /^[0-9]+$/ && runtime ~ /^(rootful|rootless|desktop)$/ && group ~ /^[0-9]*$/) print uid, runtime, group }' "$1" 2>/dev/null)
	[ -n "$fieldset_saved" ] || return 1
	fieldset_saved_uid=${fieldset_saved%% *}
	fieldset_saved=${fieldset_saved#* }
	fieldset_saved_runtime=${fieldset_saved%% *}
	fieldset_saved_group=${fieldset_saved#* }
}

fieldset_measure() { # the measuring container (KTD2, KTD4); sets fieldset_measured_uid, _group and _mode
	# Container root of the verified image reads the owner of the nonce file the host user just wrote (0: root maps to the
	# host user; the host UID: IDs pass through) and the mounted socket as containers see it. Nothing is written.
	fieldset_measured=$(docker run --rm --network none --label app.fieldset.role=owner --user 0:0 --entrypoint stat \
		-v "$fieldset_socket:/var/run/docker.sock:ro" -v "$fieldset_dir:$fieldset_dir:ro" "$fieldset_image" \
		-L -c '%u %g %a' "$fieldset_dir/$FIELDSET_NONCE" /var/run/docker.sock 2>"$fieldset_tmp/measure") || return 1
	fieldset_measured=$(printf '%s\n' "$fieldset_measured" | awk 'NR == 1 { owner = $1 } NR == 2 { group = $2; mode = $3 }
		END { if (NR == 2 && owner ~ /^[0-9]+$/ && group ~ /^[0-9]+$/ && mode ~ /^[0-7]+$/) print owner, group, mode; else exit 1 }') || return 1
	fieldset_measured_uid=${fieldset_measured%% *}
	fieldset_measured=${fieldset_measured#* }
	fieldset_measured_group=${fieldset_measured%% *}
	fieldset_measured_mode=${fieldset_measured#* }
}

fieldset_classify() { # <owner> <socket group> <socket mode> as container root read them: root-mapped, pass-through or a stop (R27)
	if [ "$1" = 0 ]; then
		fieldset_container_uid=0
	elif [ "$1" = "$FIELDSET_HOST_UID" ]; then
		fieldset_container_uid=$1 fieldset_inner_group=$2
		case $3 in *[2367]? | *[2367]) ;; *) # the ops user reaches the socket through its group (or as one of the others)
			fieldset_fail 3 socket_no_group_access "The Docker socket grants its group no access inside containers (group $2, mode $3), so fieldset cannot reach Docker as UID $1." \
				"Let the socket's group read and write it (Docker Engine: the docker group and mode 660; Docker Desktop: restart it), then run the command again." ;;
		esac
	else
		fieldset_fail 3 owner_mapping_unknown "Containers see files of UID $FIELDSET_HOST_UID as owned by UID $1: Docker maps user IDs in a way fieldset does not support (user-namespace remapping?)." \
			'Use a Docker engine where container root is you (Docker Desktop, rootless Docker) or where user IDs pass through (Docker Engine without userns-remap, WSL 2), then run the command again.'
	fi
}

fieldset_owner_rules() { # the measurement could not run (KTD3): WSL 2 passes IDs through unless rootless, else the product-name rules
	if [ "$FIELDSET_WSL" = 2 ] && [ "$FIELDSET_DOCKER_RUNTIME" != rootless ]; then
		fieldset_container_uid=$FIELDSET_HOST_UID
		fieldset_measure_error=$(tail -n 1 "$fieldset_tmp/measure" 2>/dev/null) || fieldset_measure_error=''
		fieldset_say warn "Could not measure who owns the instance files inside containers${fieldset_measure_error:+ ($fieldset_measure_error)}; WSL 2 passes user IDs through, so fieldset runs as UID $FIELDSET_HOST_UID."
	elif [ "$FIELDSET_DOCKER_RUNTIME" = rootful ]; then
		fieldset_container_uid=$FIELDSET_HOST_UID
	else
		fieldset_container_uid=0
	fi
}

fieldset_owner() { # exports FIELDSET_CONTAINER_UID, FIELDSET_OWNER_SOURCE and FIELDSET_SOCKET_GROUP; sets fieldset_user, fieldset_group
	# KTD3: install, doctor and repair-ownership measure afresh, and so does any command without a saved owner (adopt, an
	# instance from before 2.1.1) or whose detected runtime differs from the saved one; otherwise the saved owner applies.
	fieldset_saved_runtime='' fieldset_inner_group='' FIELDSET_OWNER_SOURCE=saved FIELDSET_SOCKET_GROUP=''
	case $fieldset_command in
	install | doctor | repair-ownership) ;;
	*) fieldset_state_owner "$fieldset_dir/state.json" || fieldset_saved_runtime='' ;;
	esac
	if [ -n "$fieldset_saved_runtime" ] && [ "$fieldset_saved_runtime" = "$FIELDSET_DOCKER_RUNTIME" ]; then
		fieldset_container_uid=$fieldset_saved_uid fieldset_inner_group=$fieldset_saved_group
	elif fieldset_measure; then
		FIELDSET_OWNER_SOURCE=measured
		fieldset_classify "$fieldset_measured_uid" "$fieldset_measured_group" "$fieldset_measured_mode"
	else
		FIELDSET_OWNER_SOURCE=rules
		fieldset_owner_rules
	fi
	fieldset_group=''
	if [ "$fieldset_container_uid" = 0 ]; then
		fieldset_user=0:0
	else
		fieldset_user=$FIELDSET_HOST_UID:$FIELDSET_HOST_GID
		if [ "$FIELDSET_WSL" = 2 ] && [ -n "$fieldset_inner_group" ]; then # Docker Desktop's socket belongs to root inside containers
			fieldset_group=$fieldset_inner_group FIELDSET_SOCKET_GROUP=$fieldset_inner_group
		else
			fieldset_group=$(stat -L -c %g "$fieldset_socket" 2>/dev/null) || fieldset_group='' # native Linux: GNU or busybox stat
		fi
	fi
	FIELDSET_CONTAINER_UID=$fieldset_container_uid
	export FIELDSET_CONTAINER_UID FIELDSET_OWNER_SOURCE FIELDSET_SOCKET_GROUP
}

fieldset_reclaim() { # R24, KTD12: entries a failed 2.1.0 install left to root go back to the measured owner before install runs
	case $fieldset_command in install | adopt) ;; *) return 0 ;; esac
	[ "${fieldset_leftover:-0}" = 1 ] && [ "$FIELDSET_OWNER_SOURCE" = measured ] && [ "$fieldset_container_uid" != 0 ] && [ ! -f "$fieldset_dir/state.json" ] || return 0
	# Owners only, on the instance's file system, never of or through a symlink, never of a file with a second hard link.
	docker run --rm --network none --label app.fieldset.role=owner --user 0:0 --entrypoint find -v "$fieldset_dir:$fieldset_dir" "$fieldset_image" \
		"$fieldset_dir" -xdev -uid 0 ! -type l '(' -type d -o -links 1 ')' -exec chown -h "$fieldset_container_uid:$FIELDSET_HOST_GID" '{}' + >/dev/null 2>"$fieldset_tmp/reclaim" ||
		fieldset_fail 3 instance_unwritable "Cannot give the files an earlier install left to root in $fieldset_dir back to UID $fieldset_container_uid: $(tail -n 1 "$fieldset_tmp/reclaim" 2>/dev/null)" \
			"Give them back yourself (sudo chown -R -h $fieldset_container_uid:$FIELDSET_HOST_GID $fieldset_dir), then run install.sh again."
}

fieldset_platform_ok() { # <DOCKER_DEFAULT_PLATFORM>: a platform releases ship, optionally with a variant (ADR 0070)
	case $1 in linux/amd64 | linux/arm64 | linux/amd64/v[1-9] | linux/arm64/v[1-9]) return 0 ;; esac
	return 1
}

fieldset_check_path() { # <path>: refuse what a bind mount cannot carry
	case $1 in
	*:* | *,* | *"$fieldset_nl"*) fieldset_fail 2 invalid_path "Paths with ':', ',' or line breaks cannot be mounted: $1" 'Use another path.' ;;
	esac
}

fieldset_reserved_path() { # <absolute path without . or ..>: true when a mount there could cover a path of the ops image or
	# a directory other users can write to (/tmp, /var/tmp, /dev/shm): the path equals, contains or lies inside one of
	# these, or is / or /home. Kept identical to install.sh and LaunchGuard::RESERVED (tests/Unit/Distribution).
	case $1 in / | /home) return 0 ;; esac
	for fieldset_root in /tmp /var /usr /etc /run /proc /sys /dev /bin /sbin /lib /lib64 /root; do
		case $1/ in "$fieldset_root"/*) return 0 ;; esac
		case $fieldset_root/ in "$1"/*) return 0 ;; esac
	done
	return 1
}

fieldset_normalize_path() { # <absolute path>: without ., .., repeated or trailing slashes (lexically)
	fieldset_norm=''
	fieldset_old_ifs=$IFS
	IFS=/
	set -f
	for fieldset_part in $1; do
		case $fieldset_part in '' | .) ;; ..) fieldset_norm=${fieldset_norm%/*} ;; *) fieldset_norm=$fieldset_norm/$fieldset_part ;; esac
	done
	set +f
	IFS=$fieldset_old_ifs
	printf '%s\n' "${fieldset_norm:-/}"
}

fieldset_refuse_reserved() { # <path>: exit 2 path_reserved for a reserved mount path
	! fieldset_reserved_path "$1" || fieldset_fail 2 path_reserved \
		"fieldset does not mount $1: it would cover files of the fieldset container or a directory other users can write to." \
		'Pass a path under your home directory or in the instance directory.'
}

fieldset_add_mount() { # <directory> <ro|rw>: mounts its physical path at the same path (rw wins); sets fieldset_mount_dir
	# Both the path as written and the directory it resolves to must be allowed: the second is what gets mounted.
	fieldset_check_path "$1"
	fieldset_mount_dir=$1
	case $fieldset_mount_dir in /*) ;; *) fieldset_mount_dir=$PWD/$fieldset_mount_dir ;; esac
	fieldset_mount_dir=$(fieldset_normalize_path "$fieldset_mount_dir")
	[ "$fieldset_mount_dir" != / ] || fieldset_fail 2 invalid_path 'fieldset does not mount the root directory.' 'Pass a path in a subdirectory.'
	fieldset_refuse_reserved "$fieldset_mount_dir"
	fieldset_mount_dir=$(cd -P "$1" 2>/dev/null && pwd -P) ||
		fieldset_fail 2 path_missing "The directory $1 does not exist." 'Create it, or pass a path in an existing directory.'
	fieldset_check_path "$fieldset_mount_dir"
	[ "$fieldset_mount_dir" != / ] || fieldset_fail 2 invalid_path 'fieldset does not mount the root directory.' 'Pass a path in a subdirectory.'
	fieldset_refuse_reserved "$fieldset_mount_dir"
	case $fieldset_mount_dir/ in "$fieldset_dir"/*) return 0 ;; esac
	case "$fieldset_nl$fieldset_mounts" in *"$fieldset_nl$2:$fieldset_mount_dir$fieldset_nl"*) return 0 ;; esac
	fieldset_mounts=$fieldset_mounts$2:$fieldset_mount_dir$fieldset_nl
	if [ "$2" = rw ]; then
		{ : >"$fieldset_mount_dir/$FIELDSET_NONCE"; } 2>/dev/null || fieldset_fail 2 path_unwritable "Cannot write to $fieldset_mount_dir." 'Pass a path in a directory you can write to.'
		fieldset_cleanup_files=$fieldset_cleanup_files$fieldset_mount_dir/$FIELDSET_NONCE$fieldset_nl
	fi
}

fieldset_adopt_checkout() { # <path>: `fieldset adopt <checkout>` reads a 1.0 checkout, mounted read-only at its physical path (ADR 0087)
	fieldset_checkout=$1
	case $fieldset_checkout in /*) ;; *) fieldset_checkout=$PWD/$fieldset_checkout ;; esac
	fieldset_add_mount "$fieldset_checkout" ro
	fieldset_checkout=$fieldset_mount_dir
}

fieldset_run_cleanup() { # removes the nonce, hand-over and temporary files of this run (idempotent)
	if [ -n "${fieldset_cleanup_files:-}" ]; then
		fieldset_old_ifs=$IFS
		IFS=$fieldset_nl
		set -f
		for fieldset_file in $fieldset_cleanup_files; do
			rm -f "$fieldset_file"
		done
		set +f
		IFS=$fieldset_old_ifs
	fi
	fieldset_cleanup_files=''
	[ -z "${fieldset_tmp:-}" ] || rm -rf "$fieldset_tmp"
	fieldset_tmp=''
}

fieldset_keep_file() { # <path>: leave this file in place at cleanup
	fieldset_kept=''
	fieldset_old_ifs=$IFS
	IFS=$fieldset_nl
	set -f
	for fieldset_file in ${fieldset_cleanup_files:-}; do
		[ "$fieldset_file" = "$1" ] || fieldset_kept=$fieldset_kept$fieldset_file$fieldset_nl
	done
	set +f
	IFS=$fieldset_old_ifs
	fieldset_cleanup_files=$fieldset_kept
}

fieldset_open_url() { # the URL a command handed over in FIELDSET_OPEN_URL_FILE: http(s) only, one argument, no shell
	[ -n "${FIELDSET_OPEN_URL_FILE:-}" ] && [ -f "$fieldset_dir/$FIELDSET_OPEN_URL_FILE" ] || return 0
	fieldset_url_file=$fieldset_dir/$FIELDSET_OPEN_URL_FILE
	chmod 0600 "$fieldset_url_file" 2>/dev/null || true
	fieldset_url=$(head -n 1 "$fieldset_url_file")
	if ! printf '%s\n' "$fieldset_url" | grep -Eqx 'https?://[][A-Za-z0-9._~:/?#@!$&()*+,;=%-]+'; then
		rm -f "$fieldset_url_file"
		fieldset_say warn 'Ignored a hand-over URL that is not a plain http(s) link.'
		return 0
	fi
	# The file goes only once the link reached a person: the browser, or the terminal (never stdout or stderr,
	# which an agent's transcript keeps). Otherwise it stays (0600) for the owner to read.
	if [ -n "${fieldset_opener:-}" ] && "$fieldset_opener" "$fieldset_url" >/dev/null 2>&1; then
		rm -f "$fieldset_url_file"
	elif (printf 'fieldset: could not open a browser. Open this link to sign in:\n  %s\n' "$fieldset_url" >"$fieldset_tty_device") 2>/dev/null; then
		rm -f "$fieldset_url_file"
	else
		fieldset_keep_file "$fieldset_url_file"
		fieldset_say warn "Could not open a browser; the sign-in link is in $fieldset_url_file (readable only by you)." \
			"Open it from there and delete the file, or issue a new link: fieldset login-link <email> --secrets-file <file>"
	fi
}

fieldset_wants_tty() { # <launcher> <json> <stdin tty> <stdout tty> <terminal usable> (0|1 each): run the container with -it?
	# Only for a person: no --json (-t merges stderr into stdout), stdout a terminal and the terminal usable. The shim
	# also needs stdin to be the terminal, so piped input (--…-stdin flags) still reaches the command; install.sh's
	# stdin is the script itself (curl | sh), so it hands the container the terminal instead.
	[ "$2" = 0 ] && [ "$4" = 1 ] && [ "$5" = 1 ] && { [ "$1" != shim ] || [ "$3" = 1 ]; }
}

fieldset_run_setup() { # host facts, the terminal decision, the nonce and the hand-over file of this run
	fieldset_check_path "$fieldset_dir"
	! fieldset_reserved_path "$fieldset_dir" || fieldset_fail 2 path_reserved \
		"fieldset does not run an instance in $fieldset_dir: Docker would mount it over files of the fieldset container." \
		'Install the instance under your home directory or /opt (install.sh --dir).'
	fieldset_wsl_check
	! fieldset_windows_drive "$fieldset_dir" || fieldset_fail 2 path_on_windows_drive \
		"fieldset does not run an instance on a Windows drive: $fieldset_dir is on one, where Linux file owners and permissions do not work." \
		'Install the instance in the Linux file system of the distro: install.sh --dir ~/fieldset (a directory, not a link to a Windows drive).'
	if [ -n "${DOCKER_DEFAULT_PLATFORM:-}" ] && ! fieldset_platform_ok "$DOCKER_DEFAULT_PLATFORM"; then
		fieldset_fail 2 invalid_platform "DOCKER_DEFAULT_PLATFORM must be linux/amd64 or linux/arm64 (a variant such as linux/arm64/v8 is fine), not $DOCKER_DEFAULT_PLATFORM." \
			"Unset DOCKER_DEFAULT_PLATFORM to use the engine's own platform."
	fi
	fieldset_host_facts
	FIELDSET_LAUNCHER=${FIELDSET_LAUNCHER:-shim}
	fieldset_stdin_tty=0 fieldset_stdout_tty=0 fieldset_tty_usable=0
	[ ! -t 0 ] || fieldset_stdin_tty=1
	[ ! -t 1 ] || fieldset_stdout_tty=1
	! (exec <"$fieldset_tty_device") 2>/dev/null || fieldset_tty_usable=1
	FIELDSET_TTY=0
	! fieldset_wants_tty "$FIELDSET_LAUNCHER" "$fieldset_json" "$fieldset_stdin_tty" "$fieldset_stdout_tty" "$fieldset_tty_usable" || FIELDSET_TTY=1
	FIELDSET_INSTANCE_DIR=$fieldset_dir
	FIELDSET_NONCE=.fieldset-nonce-$(od -An -N16 -tx1 /dev/urandom | tr -d ' \n')
	FIELDSET_OPEN_URL_FILE=''
	[ -z "$fieldset_opener" ] || FIELDSET_OPEN_URL_FILE=.fieldset-open-url-$(od -An -N16 -tx1 /dev/urandom | tr -d ' \n')
	fieldset_mounts=''
	fieldset_cleanup_files=$fieldset_dir/$FIELDSET_NONCE$fieldset_nl${FIELDSET_OPEN_URL_FILE:+$fieldset_dir/$FIELDSET_OPEN_URL_FILE$fieldset_nl}
	{ : >"$fieldset_dir/$FIELDSET_NONCE"; } 2>/dev/null || fieldset_fail 3 instance_unwritable "Cannot write to $fieldset_dir." 'Run fieldset as the instance owner.'
	fieldset_tmp=$(mktemp -d "${TMPDIR:-/tmp}/fieldset-run.XXXXXX" 2>/dev/null) || fieldset_fail 3 tmp_unavailable 'Cannot create a temporary directory.'
	fieldset_owner
	fieldset_reclaim
	if [ -n "${FIELDSET_TRUST_KEY:-}" ]; then
		fieldset_add_mount "$(dirname "$FIELDSET_TRUST_KEY")" ro
		FIELDSET_TRUST_KEY=$fieldset_mount_dir/$(basename "$FIELDSET_TRUST_KEY")
		export FIELDSET_TRUST_KEY
	fi
}

fieldset_stderr_filter() { # the container's NDJSON passes; other lines (Docker's own) become events with --json; all kept
	while IFS= read -r fieldset_line || [ -n "$fieldset_line" ]; do
		case $fieldset_line in
		'{'*) printf '%s\n' "$fieldset_line" ;;
		*)
			printf '%s\n' "$fieldset_line" >>"$fieldset_tmp/stderr"
			if [ "$fieldset_json" = 1 ]; then fieldset_say warn "$fieldset_line"; else printf '%s\n' "$fieldset_line"; fi ;;
		esac
	done
}

fieldset_docker_run() { # <docker run args…>: stdout streams through, stderr goes through fieldset_stderr_filter
	: >"$fieldset_tmp/stderr"
	{
		{
			fieldset_rc=0
			if [ "$FIELDSET_TTY" = 1 ] && [ "$FIELDSET_LAUNCHER" != shim ]; then
				docker run -it "$@" <"$fieldset_tty_device" 2>&1 1>&3 3>&- || fieldset_rc=$?
			elif [ "$FIELDSET_TTY" = 1 ]; then
				docker run -it "$@" 2>&1 1>&3 3>&- || fieldset_rc=$?
			elif [ "$FIELDSET_LAUNCHER" = shim ]; then
				docker run -i "$@" 2>&1 1>&3 3>&- || fieldset_rc=$?
			else
				docker run "$@" </dev/null 2>&1 1>&3 3>&- || fieldset_rc=$?
			fi
			printf '%s\n' "$fieldset_rc" >"$fieldset_tmp/status"
		} | fieldset_stderr_filter 3>&- >&2
	} 3>&1
	read -r fieldset_rc <"$fieldset_tmp/status" || fieldset_rc=1
	return "$fieldset_rc"
}

fieldset_run_container() { # <fieldset args…>: the ops container (ADR 0070); returns its exit code
	set -- --entrypoint fieldset "$fieldset_image" "$@"
	fieldset_old_ifs=$IFS
	IFS=$fieldset_nl
	set -f
	for fieldset_mount in $fieldset_mounts; do
		fieldset_path=${fieldset_mount#*:}
		case "${fieldset_mount%%:*}|$fieldset_nl$fieldset_mounts" in
		rw\|*) set -- -v "$fieldset_path:$fieldset_path" "$@" ;;
		*"${fieldset_nl}rw:$fieldset_path$fieldset_nl"*) ;;
		*) set -- -v "$fieldset_path:$fieldset_path:ro" "$@" ;;
		esac
	done
	set +f
	IFS=$fieldset_old_ifs
	[ -z "$fieldset_group" ] || set -- --group-add "$fieldset_group" "$@"
	# FIELDSET_TEST_DNS: fixed DNS answers for the doctor; FIELDSET_TEST_TUNNEL: the fake tunnel image and lifetime. Both are
	# honoured only by release-harness images (ADR 0075, ADR 0082).
	for fieldset_var in FIELDSET_LAUNCHER FIELDSET_TTY FIELDSET_HOST_UID FIELDSET_HOST_GID FIELDSET_HOST_OS FIELDSET_INSTANCE_DIR FIELDSET_LAN_ADDRESSES FIELDSET_DESKTOP \
		FIELDSET_TZ FIELDSET_DOCKER_RUNTIME FIELDSET_CONTAINER_UID FIELDSET_OWNER_SOURCE FIELDSET_SOCKET_GROUP FIELDSET_DOCKER_SOCKET FIELDSET_WSL FIELDSET_WSL_BACKEND FIELDSET_NONCE FIELDSET_MOUNTS FIELDSET_OPEN_URL_FILE \
		FIELDSET_TRUST_KEY FIELDSET_REGISTRY FIELDSET_RELEASE_URL FIELDSET_RELEASE_MANIFEST DOCKER_DEFAULT_PLATFORM FIELDSET_TEST_DNS FIELDSET_TEST_TUNNEL; do
		set -- -e "$fieldset_var" "$@"
	done
	# HOME and DOCKER_CONFIG on a tmpfs only this container sees: nothing on the host can plant a Docker CLI plugin
	# or config into the process that holds the socket.
	set -- --rm --init --label app.fieldset.role=ops --user "$fieldset_user" \
		--tmpfs "/run/fieldset-home:mode=0700,uid=${fieldset_user%%:*},gid=${fieldset_user#*:}" -e HOME=/run/fieldset-home -e DOCKER_CONFIG=/run/fieldset-home/.docker \
		-e DOCKER_HOST=unix:///var/run/docker.sock -v "$fieldset_socket:/var/run/docker.sock" -v "$fieldset_dir:$fieldset_dir" -w "$fieldset_dir" "$@"
	fieldset_docker_run "$@"
}

fieldset_run_status() { # maps a failed `docker run` (125-127) to a fieldset error; Docker Desktop's unshared paths are exit 3
	case $fieldset_status in 125 | 126 | 127) ;; *) return 0 ;; esac
	if grep -Eqi 'mounts denied|not shared from the host|is not shared' "$fieldset_tmp/stderr" 2>/dev/null; then
		fieldset_fail 3 path_not_shared 'Docker cannot mount a directory fieldset needs: it is not shared with the Docker engine.' \
			'Share the directory with Docker (Docker Desktop: Settings > Resources > File sharing; Colima: mounts in colima.yaml) or use a path under your home directory, then run the command again.'
	fi
	fieldset_docker_error=$(tail -n 1 "$fieldset_tmp/stderr" 2>/dev/null) || fieldset_docker_error=''
	fieldset_fail 10 docker_run_failed "Docker could not start the fieldset container (exit $fieldset_status)${fieldset_docker_error:+: $fieldset_docker_error}" \
		'Check that the image and the Docker socket are available, then run the command again.'
}

fieldset_run() { # <instance dir> <image> <fieldset command> [args...]: runs it in an ops container, returns its exit code
	fieldset_dir=$1
	fieldset_image=$2
	shift 2
	fieldset_nl='
'
	fieldset_tty_device=/dev/tty
	fieldset_find_command "$@"
	fieldset_run_setup

	# The command moves to the front with --dir <instance> after it. File-path flags become absolute, physical
	# paths, and their parent directory is mounted at the same path (KTD2). --dir must name this instance. The first
	# argument of `adopt` is the 1.0 checkout: mounted read-only at its own physical path.
	fieldset_found=0 fieldset_ended=0 fieldset_checkout=''
	fieldset_n=$#
	while [ "$fieldset_n" -gt 0 ]; do
		fieldset_arg=$1
		shift
		fieldset_n=$((fieldset_n - 1))
		if [ "$fieldset_ended" = 1 ] || [ "$fieldset_arg" = -- ]; then
			fieldset_ended=1
			set -- "$@" "$fieldset_arg"
			continue
		fi
		case $fieldset_arg in
		-*) ;;
		*)
			if [ "$fieldset_found" = 0 ]; then
				fieldset_found=1
			elif [ "$fieldset_command" = adopt ] && [ -z "$fieldset_checkout" ]; then
				fieldset_adopt_checkout "$fieldset_arg"
				set -- "$@" "$fieldset_checkout"
			else
				set -- "$@" "$fieldset_arg"
			fi
			continue ;;
		esac
		fieldset_name=${fieldset_arg%%=*}
		fieldset_file_flag_mode "$fieldset_name"
		if [ "$fieldset_name" != --dir ] && [ -z "$fieldset_mode" ]; then
			set -- "$@" "$fieldset_arg"
			# The value of a value-taking flag is neither the command nor adopt's checkout: keep it with its flag.
			if [ "$fieldset_name" = "$fieldset_arg" ] && [ "$fieldset_n" -gt 0 ] && fieldset_takes_value "$fieldset_name"; then
				fieldset_arg=$1
				shift
				fieldset_n=$((fieldset_n - 1))
				set -- "$@" "$fieldset_arg"
			fi
			continue
		fi
		if [ "$fieldset_name" = "$fieldset_arg" ]; then
			[ "$fieldset_n" -gt 0 ] || fieldset_fail 2 invalid_usage "$fieldset_name needs a value."
			fieldset_arg=$fieldset_name=$1
			shift
			fieldset_n=$((fieldset_n - 1))
		fi
		fieldset_value=${fieldset_arg#*=}
		[ -n "$fieldset_value" ] || fieldset_fail 2 invalid_usage "$fieldset_name needs a value."
		case $fieldset_value in /*) ;; *) fieldset_value=$PWD/$fieldset_value ;; esac
		if [ "$fieldset_name" = --dir ]; then
			[ "$(cd "$fieldset_value" 2>/dev/null && pwd -P)" = "$(cd "$fieldset_dir" && pwd -P)" ] || fieldset_fail 2 other_instance "This fieldset manages $fieldset_dir, not $fieldset_value." "Run $fieldset_value/fieldset instead."
		else
			fieldset_check_path "$fieldset_value"
			fieldset_add_mount "$(dirname "$fieldset_value")" "$fieldset_mode"
			set -- "$@" "$fieldset_name=$fieldset_mount_dir/$(basename "$fieldset_value")"
		fi
	done
	[ "$fieldset_found" = 0 ] || set -- "$fieldset_command" --dir "$fieldset_dir" "$@"
	FIELDSET_MOUNTS=$fieldset_mounts
	export FIELDSET_LAUNCHER FIELDSET_TTY FIELDSET_INSTANCE_DIR FIELDSET_NONCE FIELDSET_MOUNTS FIELDSET_OPEN_URL_FILE

	fieldset_status=0
	fieldset_run_container "$@" || fieldset_status=$?
	fieldset_open_url
	fieldset_run_status
	fieldset_run_cleanup
	return "$fieldset_status"
}

fieldset_shim_main() {
	set -eu
	fieldset_nl='
'
	fieldset_find_command "$@"
	fieldset_self=$0
	while [ -L "$fieldset_self" ]; do
		fieldset_link=$(readlink "$fieldset_self")
		case $fieldset_link in /*) fieldset_self=$fieldset_link ;; *) fieldset_self=$(dirname "$fieldset_self")/$fieldset_link ;; esac
	done
	fieldset_home=$(cd "$(dirname "$fieldset_self")" && pwd)
	command -v docker >/dev/null 2>&1 || { fieldset_wsl_check && fieldset_docker_missing; }
	fieldset_image=$(fieldset_state_image "$fieldset_home/state.json") ||
		fieldset_fail 3 no_state "$fieldset_home has no usable state.json; the installation did not finish." 'Run install.sh again with the same flags to finish it.'
	FIELDSET_LAUNCHER=shim
	trap fieldset_run_cleanup EXIT
	trap 'exit 130' INT
	trap 'exit 143' TERM
	fieldset_exit=0
	fieldset_run "$fieldset_home" "$fieldset_image" "$@" || fieldset_exit=$?
	exit "$fieldset_exit"
}

[ "${FIELDSET_SHIM_LIBRARY:-}" = 1 ] || { fieldset_shim_main "$@"; }
