#!/bin/sh
# Read-only WSL fact-finding for the 2.1.1 release checks (plan U1, ADR 0095). Run it inside the WSL distro that will
# host Fieldset, with Docker Desktop's WSL integration or Docker Engine running:
#
#   sh facts.sh > wsl-facts.txt
#
# It changes no Windows setting and no Docker setting. It creates one temporary directory under $HOME and removes it,
# runs a few throwaway containers of the Fieldset image (pulled if missing) and removes them. Before you share the
# output, replace host names and anything else you consider private; IP addresses of your home network are fine.
# shellcheck disable=SC2016 # PowerShell commands are single-quoted on purpose: $_ and $env: belong to PowerShell
set -u

image=${FIELDSET_FACTS_IMAGE:-ghcr.io/beyerenrico/fieldset:2.1.0}
probe_port=${FIELDSET_FACTS_PORT:-18443}

section() { printf '\n== %s\n' "$1"; }
show() { printf '%s: %s\n' "$1" "$2"; }
run() { # <label> <command...>: prints the command's output (first lines) or its failure
	facts_label=$1
	shift
	if facts_out=$("$@" 2>&1); then
		show "$facts_label" "$(printf '%s' "$facts_out" | head -n 20)"
	else
		show "$facts_label" "FAILED: $(printf '%s' "$facts_out" | tail -n 3)"
	fi
}

windows_exe() { # <name>: absolute path of a Windows executable, also when PATH lacks the Windows directories
	if command -v "$1" >/dev/null 2>&1; then
		command -v "$1"
		return 0
	fi
	for facts_dir in /mnt/c/Windows/System32 /mnt/c/Windows/System32/WindowsPowerShell/v1.0; do
		[ -x "$facts_dir/$1" ] && { printf '%s\n' "$facts_dir/$1"; return 0; }
	done
	return 1
}

ps_run() { # <label> <powershell command>: runs one PowerShell command, strips CR, reports the wall time
	facts_label=$1
	if [ -z "$powershell" ]; then
		show "$facts_label" 'skipped (powershell.exe not reachable: interop off or not WSL)'
		return 0
	fi
	facts_start=$(date +%s)
	facts_out=$("$powershell" -NoProfile -NonInteractive -Command "$2" 2>&1 | tr -d '\r')
	facts_end=$(date +%s)
	show "$facts_label" "$(printf '%s' "$facts_out" | head -n 20) [$((facts_end - facts_start)) s]"
}

section 'Host'
run 'uname' uname -srm
show 'kernel release' "$(cat /proc/sys/kernel/osrelease 2>/dev/null || printf 'unreadable')"
show 'WSL_DISTRO_NAME set' "$([ -n "${WSL_DISTRO_NAME:-}" ] && printf yes || printf no)"
show 'WSL_INTEROP set' "$([ -n "${WSL_INTEROP:-}" ] && printf yes || printf no)"
facts_markers=''
for facts_marker in /proc/sys/fs/binfmt_misc/WSLInterop*; do
	[ -e "$facts_marker" ] && facts_markers="$facts_markers ${facts_marker##*/}"
done
show 'interop marker' "${facts_markers:- none}"
show 'user' "uid=$(id -u) gid=$(id -g) groups=$(id -G)"
show 'PID 1' "$(ps -p 1 -o comm= 2>/dev/null || printf unknown)"
show '/etc/wsl.conf' "$(grep -v '^[[:space:]]*#' /etc/wsl.conf 2>/dev/null | grep -v '^[[:space:]]*$' | tr '\n' ';')"
show 'home file system' "$(stat -f -c %T "$HOME" 2>/dev/null || printf unknown)"
show '/mnt/c file system' "$(stat -f -c %T /mnt/c 2>/dev/null || printf 'not mounted')"
show 'Windows drive mounts' "$(awk '$2 ~ /^\/mnt\// || $3 == "drvfs" || $3 == "9p" || $3 == "virtiofs" { print $2, $3 }' /proc/mounts 2>/dev/null | tr '\n' ';')"

section 'Docker'
if ! command -v docker >/dev/null 2>&1; then
	show 'docker' 'not found in this distro (Docker Desktop: Settings > Resources > WSL integration)'
	exit 0
fi
run 'versions' docker version --format 'client {{.Client.Version}} / server {{.Server.Version}}'
run 'info' docker info --format '{{.OperatingSystem}}|{{.Name}}|{{.KernelVersion}}|{{json .SecurityOptions}}'
run 'labels' docker info --format '{{json .Labels}}'
show 'socket (distro side)' "$(stat -L -c 'uid=%u gid=%g mode=%a' /var/run/docker.sock 2>/dev/null || printf 'missing')"
show 'DOCKER_HOST' "${DOCKER_HOST:-unset}"

if ! docker image inspect "$image" >/dev/null 2>&1; then
	run 'pull' docker pull --quiet "$image"
fi

work=$(mktemp -d "$HOME/.fieldset-facts.XXXXXX") || { show 'temp dir' 'could not create one under $HOME'; exit 0; }
chmod 0700 "$work"
: >"$work/host-file"

section 'Ownership seen from a container'
# Read as root and as an unrelated UID: on macOS Docker Desktop the owner follows the reader, under WSL it does not.
run 'host files seen by container root (uid:gid)' docker run --rm --user 0:0 --entrypoint stat -v "$work:$work:ro" "$image" -c '%n %u:%g' "$work" "$work/host-file"
run 'host files seen by container uid 4242' docker run --rm --user 4242:4242 --entrypoint stat -v "$work:$work:ro" "$image" -c '%n %u:%g' "$work"
run 'socket, container view' docker run --rm --user 0:0 --entrypoint stat -v /var/run/docker.sock:/var/run/docker.sock "$image" -L -c 'uid=%u gid=%g mode=%a' /var/run/docker.sock
socket_gid=$(docker run --rm --user 0:0 --entrypoint stat -v /var/run/docker.sock:/var/run/docker.sock "$image" -L -c '%g' /var/run/docker.sock 2>/dev/null)
if [ -n "$socket_gid" ]; then
	run 'docker as host user with the container-side socket group' docker run --rm --user "$(id -u):$(id -g)" --group-add "$socket_gid" \
		-e HOME=/tmp -v /var/run/docker.sock:/var/run/docker.sock --entrypoint docker "$image" version --format 'server {{.Server.Version}}'
fi
run 'file written by container root' docker run --rm --user 0:0 --entrypoint touch -v "$work:$work" "$image" "$work/root-file"
show 'its owner on the distro (uid:gid)' "$(stat -c '%u:%g' "$work/root-file" 2>/dev/null || ls -ln "$work/root-file" 2>/dev/null || printf 'not created')"
docker run --rm --user 0:0 --entrypoint rm -v "$work:$work" "$image" -f "$work/root-file" >/dev/null 2>&1

section 'Windows side'
powershell=$(windows_exe powershell.exe || true)
show 'powershell.exe' "${powershell:-not reachable}"
ps_run 'LAN IPv4 (connected, with gateway)' "Get-NetIPConfiguration | Where-Object { \$_.IPv4DefaultGateway } | ForEach-Object { \$_.InterfaceAlias + ' ' + \$_.IPv4Address.IPAddress }"
ps_run 'network profile' 'Get-NetConnectionProfile | ForEach-Object { $_.InterfaceAlias + " " + $_.NetworkCategory }'
ps_run 'WSL networking mode and idle timeout (.wslconfig)' 'if (Test-Path "$env:USERPROFILE\.wslconfig") { Get-Content "$env:USERPROFILE\.wslconfig" | Select-String -Pattern "networkingMode|instanceIdleTimeout|vmIdleTimeout|firewall" } else { "no .wslconfig" }'
ps_run 'portproxy entries' 'netsh interface portproxy show v4tov4'
ps_run 'Docker Desktop port binding setting' 'Get-ChildItem "$env:APPDATA\Docker\settings*.json" -ErrorAction SilentlyContinue | Select-String -Pattern "portBinding" | ForEach-Object { $_.Line.Trim() }'

section 'Publishing on a Windows LAN address'
lan_ip=''
if [ -n "$powershell" ]; then
	lan_ip=$("$powershell" -NoProfile -NonInteractive -Command 'Get-NetIPConfiguration | Where-Object { $_.IPv4DefaultGateway } | Select-Object -First 1 | ForEach-Object { $_.IPv4Address.IPAddress }' 2>/dev/null | tr -d '\r' | head -n 1)
fi
if printf '%s\n' "$lan_ip" | grep -Eqx '([0-9]{1,3}\.){3}[0-9]{1,3}'; then
	run "bind $lan_ip:$probe_port" docker run --rm -p "$lan_ip:$probe_port:$probe_port" --entrypoint true "$image"
	run "bind 0.0.0.0:$probe_port" docker run --rm -p "0.0.0.0:$probe_port:$probe_port" --entrypoint true "$image"
else
	show 'bind test' 'skipped (no Windows LAN address)'
fi

rm -rf "$work"

section 'Manual step (Docker Desktop idle distro)'
printf '%s\n' 'With a Fieldset instance running: close every WSL terminal, wait two minutes, then open the app URL in a' \
	'Windows browser. Note whether it still loads.'
