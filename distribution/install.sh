#!/bin/sh
# Fieldset installer (BUILD_PROMPT_V2 R7, R8, R58; ADR 0048, 0060, 0070):
#
#   curl --proto '=https' --tlsv1.2 -fsSL https://github.com/beyerenrico/fieldset/releases/download/v<version>/install.sh | sh -s -- --version <version> [flags]
#
# A pre-release (2.0.0-rc.1 …) is published with --latest=false, so take install.sh from its own release as above.
# From 2.0.0 on, the newest stable release is also at .../releases/latest/download/install.sh (then omit --version).
#
# It checks Docker, downloads the release manifest and its Sigstore bundle, verifies them with the official
# cosign image pinned below against the signer identity built into this file, and only then pulls the app image
# by the manifest's digest. It creates the instance directory (0700), stores the verified manifest, installs the
# `fieldset` shim from the image and hands over to `fieldset install` in an ops container. Flags go to `fieldset install`;
# install.sh reads --dir, --version, --json, --yes, --password-stdin and --passphrase-stdin too, and handles --link/--no-link (PATH link)
# and --help itself; with --adopt <checkout> it hands over to `fieldset adopt <checkout>` instead (a 1.0 install, ADR 0087). Its own stdin is never passed on (with curl | sh it is the script), so --password-stdin (the SMTP
# password of --smtp-username) and --passphrase-stdin (the identity's passphrase of --from-backup) work only as fieldset's
# hidden prompt on a terminal; else use --password-file or --passphrase-file. With
# --json, stdout carries only the JSON result and stderr only NDJSON events. FIELDSET_RELEASE_URL and
# FIELDSET_REGISTRY point at a mirror. Docker's DOCKER_DEFAULT_PLATFORM (linux/amd64 or linux/arm64) picks the platform
# of the images install.sh and fieldset pull and run (cosign excepted), e.g. amd64 under emulation on Apple Silicon.
# Everything is in functions and runs from the last line, so a truncated download executes nothing.

fieldset_install_trust() {
	build=production # the release harness generates a copy with build=harness (ADR 0070); never edit by hand
	cosign_image='ghcr.io/sigstore/cosign/cosign:v3.1.3@sha256:9e5c2f2edc34351160407ca3416c61855bdf9403c3c5936e0f0be7fc261611b8'
	identity_regexp='^https://github\.com/beyerenrico/fieldset/\.github/workflows/release\.yml@refs/tags/v(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(-(alpha|beta|rc)(\.(0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*))*)?$'
	oidc_issuer='https://token.actions.githubusercontent.com'
	workflow_trigger='push'
}

fieldset_install_fail() { # <exit code> <error code> <message> [hint] [retryable: true|false]; codes as fieldset's (ADR 0066)
	if [ "${json:-0}" = 1 ]; then
		fieldset_install_say error "$3${4:+ Fix: $4}"
		hint=null
		[ -z "${4:-}" ] || hint="\"$(fieldset_install_escape "$4")\""
		printf '{"schema_version":1,"command":"install","status":"failed","exit_code":%s,"changed":false,"data":{},"secrets":[],"warnings":[],"errors":[{"code":"%s","message":"%s","hint":%s,"retryable":%s}]}\n' \
			"$1" "$2" "$(fieldset_install_escape "$3")" "$hint" "${5:-false}"
	else
		printf 'install.sh: %s\n' "$3" >&2
		[ -z "${4:-}" ] || printf '  Fix: %s\n' "$4" >&2
	fi
	exit "$1"
}

fieldset_install_escape() { printf '%s' "$1" | sed 's/\\/\\\\/g; s/"/\\"/g' | tr -d '\000-\037'; }

fieldset_install_say() { # <info|warn|error> <message>: progress on stderr, one NDJSON event with --json (KTD3)
	if [ "${json:-0}" = 1 ]; then
		printf '{"ts":"%s","phase":"%s","level":"%s","message":"%s"}\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "${phase:-inputs}" "$1" "$(fieldset_install_escape "$2")" >&2
	else
		printf '%s\n' "$2" >&2
	fi
}

fieldset_install_phase() { phase=$1 && fieldset_install_say info "$2"; }

fieldset_install_cleanup() {
	if command -v fieldset_run_cleanup >/dev/null 2>&1; then fieldset_run_cleanup; fi
	[ -z "${tmp:-}" ] || rm -rf "$tmp"
}

fieldset_install_normalize() { # <absolute path>: without ., .., repeated or trailing slashes (lexically)
	norm=''
	norm_ifs=$IFS
	IFS=/
	set -f
	for part in $1; do
		case $part in '' | .) ;; ..) norm=${norm%/*} ;; *) norm=$norm/$part ;; esac
	done
	set +f
	IFS=$norm_ifs
	printf '%s\n' "${norm:-/}"
}

fieldset_install_reserved() { # <normalized path>: the same rule and list as the shim's fieldset_reserved_path (ADR 0070)
	case $1 in / | /home) return 0 ;; esac
	for root in /tmp /var /usr /etc /run /proc /sys /dev /bin /sbin /lib /lib64 /root; do
		case $1/ in "$root"/*) return 0 ;; esac
		case $root/ in "$1"/*) return 0 ;; esac
	done
	return 1
}

fieldset_install_platform_ok() { # <DOCKER_DEFAULT_PLATFORM>: the same rule as the shim's fieldset_platform_ok (ADR 0070)
	case $1 in linux/amd64 | linux/arm64 | linux/amd64/v[1-9] | linux/arm64/v[1-9]) return 0 ;; esac
	return 1
}

fieldset_install_check_inputs() { # everything that needs no network: exit 2 before any download
	if [ -n "${FIELDSET_TRUST_KEY:-}" ]; then
		[ "$build" = harness ] || fieldset_install_fail 2 trust_key_not_allowed 'FIELDSET_TRUST_KEY is accepted only by release-harness builds of install.sh.' 'Unset FIELDSET_TRUST_KEY; releases are verified against the signer identity built into install.sh.'
		[ -f "$FIELDSET_TRUST_KEY" ] || fieldset_install_fail 2 trust_key_invalid 'FIELDSET_TRUST_KEY does not name a file.'
	fi
	url=${FIELDSET_RELEASE_URL:-https://github.com/beyerenrico/fieldset/releases}
	url=${url%/}
	case $url in
	https://*) proto='=https' ;;
	http://*) [ "$build" = harness ] || fieldset_install_fail 2 insecure_release_url 'FIELDSET_RELEASE_URL must be an https URL.'
		proto='=https,http' ;;
	*) fieldset_install_fail 2 invalid_release_url 'FIELDSET_RELEASE_URL must be an https URL.' ;;
	esac
	if [ -n "${FIELDSET_REGISTRY:-}" ] && ! printf '%s\n' "$FIELDSET_REGISTRY" | grep -Eqx '(localhost|[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+|[0-9]{1,3}(\.[0-9]{1,3}){3})(:[0-9]{1,5})?'; then
		fieldset_install_fail 2 invalid_registry 'FIELDSET_REGISTRY must be a registry host, optionally with a port.'
	fi
	# The docker CLI reads DOCKER_DEFAULT_PLATFORM for every pull, create and run (cosign's clear it); the shim
	# hands it to fieldset.
	if [ -n "${DOCKER_DEFAULT_PLATFORM:-}" ] && ! fieldset_install_platform_ok "$DOCKER_DEFAULT_PLATFORM"; then
		fieldset_install_fail 2 invalid_platform "DOCKER_DEFAULT_PLATFORM must be linux/amd64 or linux/arm64 (a variant such as linux/arm64/v8 is fine), not $DOCKER_DEFAULT_PLATFORM." \
			"Unset DOCKER_DEFAULT_PLATFORM to use the engine's own platform."
	fi
	semver='(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(-(alpha|beta|rc)(\.(0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*))*)?'
	if [ -n "$version" ] && { case $version in *"$nl"*) true ;; *) false ;; esac || ! printf '%s\n' "$version" | grep -Eqx "$semver"; }; then
		fieldset_install_fail 2 invalid_version "--version must look like 2.0.0 or 2.0.0-rc.1, not $version."
	fi
	fieldset_install_check_stdin
	fieldset_install_check_dir
}

fieldset_install_stdin_ok() { # <json> <stdout tty> <terminal usable> (0|1): can fieldset prompt for --password-stdin on the terminal?
	# The same conditions as the shim's fieldset_wants_tty for install.sh, which then hands the container /dev/tty as stdin.
	[ "$1" = 0 ] && [ "$2" = 1 ] && [ "$3" = 1 ]
}

fieldset_install_check_stdin() { # --password-stdin, --passphrase-stdin: install.sh never passes its own stdin on (curl | sh: it is the script)
	[ "$password_stdin" = 1 ] || [ "$passphrase_stdin" = 1 ] || return 0
	stdout_tty=0 tty_usable=0
	[ ! -t 1 ] || stdout_tty=1
	! (exec </dev/tty) 2>/dev/null || tty_usable=1
	fieldset_install_stdin_ok "$json" "$stdout_tty" "$tty_usable" && return 0
	[ "$password_stdin" = 0 ] || fieldset_install_fail 2 password_stdin_unavailable \
		'install.sh does not pass its stdin on (with curl | sh it is the script itself), so --password-stdin cannot read the SMTP password here.' \
		'Pass --password-file=<path> (a file that holds only the password, mode 0600), or run install.sh in a terminal (without --json) to be asked for it.'
	fieldset_install_fail 2 passphrase_stdin_unavailable \
		'install.sh does not pass its stdin on (with curl | sh it is the script itself), so --passphrase-stdin cannot read the passphrase here.' \
		'Pass --passphrase-file=<path> (a file that holds only the passphrase, mode 0600), or run install.sh in a terminal (without --json) to be asked for it.'
}

fieldset_install_check_dir() { # --dir: absolute, normalized, mountable, not a system path, empty or an instance
	if [ -z "$dir" ]; then
		if [ "$(id -u)" = 0 ] && [ "$(uname -s)" = Linux ]; then dir=/opt/fieldset; else dir=${HOME:?}/fieldset; fi
	fi
	case $dir in /*) ;; *) dir=$PWD/$dir ;; esac
	case $dir in
	*:* | *,* | *"$nl"*) fieldset_install_fail 2 invalid_dir "Cannot use $dir as the instance directory." "Pass --dir with an absolute path without ':', ',' or line breaks." ;;
	esac
	dir=$(fieldset_install_normalize "$dir")
	[ "$dir" != / ] || fieldset_install_fail 2 invalid_dir 'Cannot use / as the instance directory.' 'Pass --dir with a directory of its own.'
	! fieldset_install_reserved "$dir" || fieldset_install_fail 2 path_reserved \
		"Cannot use $dir as the instance directory: fieldset mounts it into its containers, over their own files." \
		'Pass --dir with a directory under your home directory or /opt.'
	[ ! -L "$dir" ] || fieldset_install_fail 2 invalid_dir "$dir is a symbolic link." 'Pass the real path with --dir.'
	if [ -e "$dir" ] && { [ ! -d "$dir" ] || fieldset_install_foreign "$dir"; }; then
		fieldset_install_fail 2 invalid_dir "$dir exists and is not an instance directory." 'Pass --dir with an empty or new directory.'
	fi
}

fieldset_install_foreign() { # <dir>: true when it is no instance directory and holds more than backups/
	[ ! -f "$1/.fieldset-instance" ] || return 1
	for entry in "$1"/* "$1"/.[!.]* "$1"/..?*; do
		[ -e "$entry" ] || [ -L "$entry" ] || continue
		[ "${entry##*/}" = backups ] || return 0
	done
	return 1
}

fieldset_install_download() { # <url> <file> <what>: 404 is an input error (exit 2), anything else a network one (exit 3, retryable)
	http=$(curl --proto "$proto" --tlsv1.2 -fsSL --retry 2 -w '%{http_code}' -o "$2" "$1" 2>"$tmp/curl.err") && return 0
	case $http:$3 in
	404:manifest | 410:manifest)
		fieldset_install_fail 2 release_not_found "Cannot download $1: there is no such release (HTTP $http)." \
			'Check --version against the releases page. Before 2.0.0 there is no latest release: pass --version and take install.sh from releases/download/v<version>/.' ;;
	404:* | 410:*)
		fieldset_install_fail 3 manifest_missing "Cannot download $1: the release has no $3 (HTTP $http)." 'Install another release; this one is incomplete.' ;;
	esac
	fieldset_install_fail 3 release_unavailable "Cannot download $1: $(tail -n 1 "$tmp/curl.err" 2>/dev/null)" \
		'Check the network and FIELDSET_RELEASE_URL, then run install.sh again.' true
}

fieldset_install_docker() { # <docker args...>: Docker's stderr goes to $tmp/docker.err (stderr stays NDJSON with --json)
	docker "$@" 2>"$tmp/docker.err"
}

fieldset_install_docker_error() { tail -n 1 "$tmp/docker.err" 2>/dev/null || true; }

fieldset_install_cosign_docker() { # <docker args...>: fieldset_install_docker for cosign, as the engine's own platform: verifying
	# does not depend on it, and Docker's classic image store keeps one platform per by-digest reference (ADR 0070)
	env DOCKER_DEFAULT_PLATFORM= docker "$@" 2>"$tmp/docker.err"
}

fieldset_install_verify() { # <dir with the manifest and its bundle>: cosign verify-blob in the pinned image (R58)
	work=$1
	stage=$work/verify
	if ! { mkdir "$stage" && cp "$work/release-manifest.json" "$stage/release-manifest.json" && cp "$work/release-manifest.json.sigstore.json" "$stage/bundle.json"; } 2>/dev/null; then
		fieldset_install_fail 3 verify_failed 'Cannot stage the manifest for verification.'
	fi
	if [ -n "${FIELDSET_TRUST_KEY:-}" ]; then
		cp "$FIELDSET_TRUST_KEY" "$stage/trust.pub" || fieldset_install_fail 2 trust_key_invalid 'Cannot read FIELDSET_TRUST_KEY.'
		set -- --network none "$cosign_image" verify-blob --bundle /verify/bundle.json --key /verify/trust.pub --insecure-ignore-tlog=true
	else
		set -- "$cosign_image" verify-blob --bundle /verify/bundle.json --certificate-identity-regexp "$identity_regexp" \
			--certificate-oidc-issuer "$oidc_issuer" --certificate-github-workflow-trigger "$workflow_trigger"
	fi
	chmod 0755 "$stage" && chmod 0644 "$stage"/*
	docker image inspect "$cosign_image" >/dev/null 2>&1 || fieldset_install_cosign_docker pull -q "$cosign_image" >/dev/null ||
		fieldset_install_fail 3 cosign_unavailable "Cannot pull the cosign image that verifies releases: $(fieldset_install_docker_error)" 'Check that the host can reach ghcr.io.' true
	# docker cp instead of a bind mount: works for temporary directories Docker Desktop or Colima do not share.
	cid=$(fieldset_install_cosign_docker create -e HOME=/tmp "$@" /verify/release-manifest.json) ||
		fieldset_install_fail 3 verify_failed "Cannot create the cosign container: $(fieldset_install_docker_error)"
	status=0
	{ docker cp "$stage" "$cid:/verify" && docker start -a "$cid"; } >"$work/verify.log" 2>&1 || status=$?
	docker rm -f "$cid" >/dev/null 2>&1 || true
	[ "$status" = 0 ] || fieldset_install_fail 3 manifest_signature_invalid "The release manifest signature does not match the signer built into install.sh: $(tail -n 1 "$work/verify.log")" \
		'Install only releases published by the form-schema-app release workflow: never retry with another install.sh or release URL and never bypass this check. Only if the message shows that Sigstore (TUF, Rekor) could not be reached may the same command be run again later.'
}

fieldset_install_read_manifest() { # <manifest>: sets release (version) and ref (app image by digest, FIELDSET_REGISTRY applied)
	flat=$(tr -d ' \t\r\n' <"$1")
	release=$(printf '%s\n' "$flat" | sed -n 's/^{"schema_version":1,"version":"\([0-9A-Za-z.-]*\)".*/\1/p')
	app=$(printf '%s\n' "$flat" | sed -n 's/.*"images":{"app":{"registry":"\([a-z0-9.:-]*\)","repository":"\([a-z0-9._\/-]*\)","tag":"[A-Za-z0-9_.-]*","digest":"\(sha256:[a-f0-9]\{64\}\)".*/\1 \2 \3/p')
	[ -n "$release" ] && [ -n "$app" ] || fieldset_install_fail 3 manifest_invalid 'The release manifest is not in a format this install.sh reads.' 'Use the install.sh of the same release.'
	[ -z "$version" ] || [ "$version" = "$release" ] || fieldset_install_fail 3 release_mismatch "The manifest is for $release, not $version." \
		'Run install.sh with the same --version as the release you download it from, or omit --version.'
	registry=${app%% *}
	app=${app#* }
	ref=${FIELDSET_REGISTRY:-$registry}/${app%% *}@${app#* }
}

fieldset_install_prepare_dir() { # instance directory 0700, marker, verified release, shim from the image
	umask 077
	if ! { mkdir -p "$dir" && chmod 0700 "$dir"; } 2>/dev/null; then
		fieldset_install_fail 2 invalid_dir "Cannot create $dir." 'Pass --dir with a directory you can write to.'
	fi
	[ -f "$dir/.fieldset-instance" ] || printf '{"schema_version":"1.0","kind":"fieldset instance"}\n' >"$dir/.fieldset-instance"
	releases=$dir/releases/$release
	{ mkdir -p "$releases" && chmod 0700 "$dir/releases" "$releases"; } 2>/dev/null || fieldset_install_fail 2 invalid_dir "Cannot write to $dir."
	for file in release-manifest.json release-manifest.json.sigstore.json; do
		{ cp "$tmp/$file" "$releases/.$file.tmp" && mv "$releases/.$file.tmp" "$releases/$file"; } 2>/dev/null || fieldset_install_fail 2 invalid_dir "Cannot write to $dir."
	done
	fieldset_install_docker run --rm --network none --entrypoint cat "$ref" /var/www/html/distribution/shim.sh >"$dir/.fieldset.tmp" && [ -s "$dir/.fieldset.tmp" ] ||
		fieldset_install_fail 3 shim_unavailable "Cannot read the fieldset shim from $ref: $(fieldset_install_docker_error)"
	chmod 0700 "$dir/.fieldset.tmp" && mv "$dir/.fieldset.tmp" "$dir/fieldset"
}

fieldset_install_link() { # optional PATH link (ADR 0049): never replaces an existing fieldset
	if [ "$(id -u)" = 0 ]; then bin=/usr/local/bin; else bin=$HOME/.local/bin; fi
	if [ -e "$bin/fieldset" ] || [ -L "$bin/fieldset" ]; then
		fieldset_install_say warn "install.sh: $bin/fieldset already exists and was left alone; run $dir/fieldset."
	elif { mkdir -p "$bin" && ln -s "$dir/fieldset" "$bin/fieldset"; } 2>/dev/null; then
		fieldset_install_say info "install.sh: linked $bin/fieldset to $dir/fieldset."
	else
		fieldset_install_say warn "install.sh: could not link $bin/fieldset; run $dir/fieldset."
	fi
}

fieldset_install_wants_prompt() { # <link: ''|0|1> <yes> <json> <tty> (0|1): ask about the PATH link? Only a person, never --yes
	[ -z "$1" ] && [ "$2" = 0 ] && [ "$3" = 0 ] && [ "$4" = 1 ]
}

fieldset_install_usage() {
	printf 'Usage: curl --proto =https --tlsv1.2 -fsSL <release>/install.sh | sh -s -- [--version <version>] [fieldset install flags] [--link|--no-link]\n'
	printf '  <release> is https://github.com/beyerenrico/fieldset/releases/download/v<version> (pre-releases: also pass\n'
	printf '  --version <version>); from 2.0.0 on also .../releases/latest/download for the newest stable release.\n'
	printf '  --yes answers every question and does not link fieldset into PATH unless --link is given.\n'
	printf '  --adopt <checkout> converts the 1.0 install in <checkout> into this new instance (fieldset adopt --help).\n'
	printf 'Flags of each command: fieldset install --help, fieldset <command> --help (without the PATH link: <dir>/fieldset <command> --help).\n'
	printf 'Guides: docs/guide/install.md and, for agents and scripts, docs/guide/agent-guide.md (also a release asset),\n'
	printf '  in the repository https://github.com/beyerenrico/fieldset.\n'
	printf 'FIELDSET_RELEASE_URL, FIELDSET_REGISTRY: mirrors.\n'
	printf 'DOCKER_DEFAULT_PLATFORM=linux/amd64 or linux/arm64: pull and run that platform (another one runs emulated).\n'
}

fieldset_install_main() {
	set -eu
	nl='
'
	fieldset_install_trust
	dir='' version='' json=0 yes=0 link='' tmp='' phase=inputs password_stdin=0 passphrase_stdin=0 adopt=''
	n=$#
	while [ "$n" -gt 0 ]; do
		arg=$1
		shift
		n=$((n - 1))
		case $arg in
		--dir=*) dir=${arg#*=} ;;
		--adopt=*) adopt=${arg#*=} ;;
		--version=*) version=${arg#*=} && set -- "$@" "$arg" ;;
		--dir | --version | --adopt)
			[ "$n" -gt 0 ] || fieldset_install_fail 2 invalid_usage "$arg needs a value."
			case $arg in --dir) dir=$1 ;; --adopt) adopt=$1 ;; *) version=$1 && set -- "$@" "$arg" "$1" ;; esac
			shift
			n=$((n - 1)) ;;
		--json) json=1 && set -- "$@" "$arg" ;;
		--yes) yes=1 && set -- "$@" "$arg" ;;
		--password-stdin) password_stdin=1 && set -- "$@" "$arg" ;;
		--passphrase-stdin) passphrase_stdin=1 && set -- "$@" "$arg" ;;
		--link) link=1 ;;
		--no-link) link=0 ;;
		-h | --help) fieldset_install_usage && exit 0 ;;
		*) set -- "$@" "$arg" ;;
		esac
	done
	fieldset_install_check_inputs
	command -v docker >/dev/null 2>&1 || fieldset_install_fail 10 docker_missing 'Docker is not installed.' 'Install Docker Engine or Docker Desktop, then run install.sh again.'
	docker version --format '{{.Server.Version}}' >/dev/null 2>&1 ||
		fieldset_install_fail 10 docker_unavailable 'The Docker daemon is not reachable.' 'Start Docker (or check your docker group membership), then run install.sh again.' true
	command -v curl >/dev/null 2>&1 || fieldset_install_fail 3 curl_missing 'curl is required.'

	trap fieldset_install_cleanup EXIT
	trap 'exit 130' INT
	trap 'exit 143' TERM
	tmp=$(mktemp -d "${TMPDIR:-/tmp}/fieldset-install.XXXXXX" 2>/dev/null) || fieldset_install_fail 3 tmp_unavailable 'Cannot create a temporary directory.'
	base=$url/latest/download
	[ -z "$version" ] || base=$url/download/v$version
	fieldset_install_phase verify "Verifying release ${version:-latest} from $url"
	fieldset_install_download "$base/release-manifest.json" "$tmp/release-manifest.json" manifest
	fieldset_install_download "$base/release-manifest.json.sigstore.json" "$tmp/release-manifest.json.sigstore.json" 'signature bundle'
	fieldset_install_verify "$tmp"
	fieldset_install_read_manifest "$tmp/release-manifest.json"
	fieldset_install_phase pull "Pulling $ref${DOCKER_DEFAULT_PLATFORM:+ for $DOCKER_DEFAULT_PLATFORM}"
	fieldset_install_docker pull -q "$ref" >/dev/null ||
		fieldset_install_fail 3 image_unavailable "Cannot pull $ref: $(fieldset_install_docker_error)" 'Check the network and FIELDSET_REGISTRY, then run install.sh again.' true
	fieldset_install_prepare_dir

	command=install
	[ -z "$adopt" ] || command=adopt
	fieldset_install_phase handover "Starting fieldset $command $release in $dir"
	# The shim from the verified image runs the handover; FIELDSET_SHIM_LIBRARY=1 makes it define its functions only.
	# shellcheck disable=SC2034 # read by the sourced shim
	FIELDSET_SHIM_LIBRARY=1 FIELDSET_LAUNCHER=install.sh
	# shellcheck source=/dev/null # distribution/shim.sh, linted on its own
	. "$dir/fieldset"
	FIELDSET_RELEASE_MANIFEST=$releases/release-manifest.json
	export FIELDSET_RELEASE_MANIFEST
	status=0
	if [ -n "$adopt" ]; then # --adopt <checkout>: `fieldset adopt` converts a 1.0 install (the shim mounts it read-only, ADR 0087)
		fieldset_run "$dir" "$ref" adopt --dir "$dir" "$adopt" "$@" || status=$?
	else
		fieldset_run "$dir" "$ref" install --dir "$dir" "$@" || status=$?
	fi
	if [ "$status" = 0 ] && fieldset_install_wants_prompt "$link" "$yes" "$json" "${FIELDSET_TTY:-0}"; then
		printf 'Link fieldset into your PATH? [y/N] ' >/dev/tty
		read -r answer </dev/tty || answer=''
		case $answer in [Yy]*) link=1 ;; esac
	fi
	[ "$status" != 0 ] || [ "$link" != 1 ] || fieldset_install_link
	exit "$status"
}

[ "${FIELDSET_INSTALL_LIBRARY:-}" = 1 ] || { fieldset_install_main "$@"; }
