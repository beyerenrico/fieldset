#!/bin/sh
# form-schema-app installer (BUILD_PROMPT_V2 R7, R8, R58; ADR 0048, 0060, 0070):
#
#   curl --proto '=https' --tlsv1.2 -fsSL https://github.com/beyerenrico/form-schema-app/releases/latest/download/install.sh | sh -s -- [flags]
#
# It checks Docker, downloads the release manifest and its Sigstore bundle, verifies them with the official
# cosign image pinned below against the signer identity built into this file, and only then pulls the app image
# by the manifest's digest. It creates the instance directory (0700), stores the verified manifest, installs the
# `fsa` shim from the image and hands over to `fsa install` in an ops container. Flags go to `fsa install`;
# install.sh reads --dir, --version and --json too, and handles --link/--no-link (PATH link) and --help itself.
# FSA_RELEASE_URL and FSA_REGISTRY point at a mirror. Everything is in functions and runs from the last line,
# so a truncated download executes nothing.

fsa_install_trust() {
	build=production # the release harness generates a copy with build=harness (ADR 0070); never edit by hand
	cosign_image='ghcr.io/sigstore/cosign/cosign:v3.1.3@sha256:9e5c2f2edc34351160407ca3416c61855bdf9403c3c5936e0f0be7fc261611b8'
	identity_regexp='^https://github\.com/beyerenrico/form-schema-app/\.github/workflows/release\.yml@refs/tags/v(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(-(alpha|beta|rc)(\.(0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*))*)?$'
	oidc_issuer='https://token.actions.githubusercontent.com'
	workflow_trigger='push'
}

fsa_install_fail() { # <exit code> <error code> <message> [hint]
	printf 'install.sh: %s\n' "$3" >&2
	[ -z "${4:-}" ] || printf '  Fix: %s\n' "$4" >&2
	if [ "${json:-0}" = 1 ]; then
		printf '{"schema_version":1,"command":"install","status":"failed","changed":false,"data":{},"secrets":[],"warnings":[],"errors":[{"code":"%s","message":"%s","hint":"%s","retryable":false}]}\n' \
			"$2" "$(fsa_install_escape "$3")" "$(fsa_install_escape "${4:-}")"
	fi
	exit "$1"
}

fsa_install_escape() { printf '%s' "$1" | sed 's/\\/\\\\/g; s/"/\\"/g' | tr -d '\000-\037'; }

fsa_install_say() { # <phase> <message>: progress on stderr, NDJSON with --json (KTD3)
	if [ "${json:-0}" = 1 ]; then
		printf '{"ts":"%s","phase":"%s","level":"info","message":"%s"}\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$1" "$(fsa_install_escape "$2")" >&2
	else
		printf '%s\n' "$2" >&2
	fi
}

fsa_install_cleanup() {
	if command -v fsa_run_cleanup >/dev/null 2>&1; then fsa_run_cleanup; fi
	[ -z "${tmp:-}" ] || rm -rf "$tmp"
}

fsa_install_check_inputs() { # everything that needs no network: exit 2 before any download
	if [ -n "${FSA_TRUST_KEY:-}" ]; then
		[ "$build" = harness ] || fsa_install_fail 2 trust_key_not_allowed 'FSA_TRUST_KEY is accepted only by release-harness builds of install.sh.' 'Unset FSA_TRUST_KEY; releases are verified against the signer identity built into install.sh.'
		[ -f "$FSA_TRUST_KEY" ] || fsa_install_fail 2 trust_key_invalid 'FSA_TRUST_KEY does not name a file.'
	fi
	url=${FSA_RELEASE_URL:-https://github.com/beyerenrico/form-schema-app/releases}
	url=${url%/}
	case $url in
	https://*) proto='=https' ;;
	http://*) [ "$build" = harness ] || fsa_install_fail 2 insecure_release_url 'FSA_RELEASE_URL must be an https URL.'
		proto='=https,http' ;;
	*) fsa_install_fail 2 invalid_release_url 'FSA_RELEASE_URL must be an https URL.' ;;
	esac
	if [ -n "${FSA_REGISTRY:-}" ] && ! printf '%s\n' "$FSA_REGISTRY" | grep -Eqx '(localhost|[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+|[0-9]{1,3}(\.[0-9]{1,3}){3})(:[0-9]{1,5})?'; then
		fsa_install_fail 2 invalid_registry 'FSA_REGISTRY must be a registry host, optionally with a port.'
	fi
	semver='(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(-(alpha|beta|rc)(\.(0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*))*)?'
	if [ -n "$version" ] && { case $version in *"$nl"*) true ;; *) false ;; esac || ! printf '%s\n' "$version" | grep -Eqx "$semver"; }; then
		fsa_install_fail 2 invalid_version "--version must look like 2.0.0 or 2.0.0-rc.1, not $version."
	fi
	if [ -z "$dir" ]; then
		if [ "$(id -u)" = 0 ] && [ "$(uname -s)" = Linux ]; then dir=/opt/form-schema-app; else dir=${HOME:?}/form-schema-app; fi
	fi
	case $dir in /*) ;; *) dir=$PWD/$dir ;; esac
	while [ "$dir" != / ] && [ "${dir%/}" != "$dir" ]; do dir=${dir%/}; done
	case $dir in
	/ | *:* | *,* | *"$nl"*) fsa_install_fail 2 invalid_dir "Cannot use $dir as the instance directory." "Pass --dir with an absolute path without ':', ',' or line breaks." ;;
	esac
	[ ! -L "$dir" ] || fsa_install_fail 2 invalid_dir "$dir is a symbolic link." 'Pass the real path with --dir.'
	if [ -e "$dir" ] && { [ ! -d "$dir" ] || fsa_install_foreign "$dir"; }; then
		fsa_install_fail 2 invalid_dir "$dir exists and is not an instance directory." 'Pass --dir with an empty or new directory.'
	fi
}

fsa_install_foreign() { # <dir>: true when it is no instance directory and holds more than backups/
	[ ! -f "$1/.fsa-instance" ] || return 1
	for entry in "$1"/* "$1"/.[!.]* "$1"/..?*; do
		[ -e "$entry" ] || [ -L "$entry" ] || continue
		[ "${entry##*/}" = backups ] || return 0
	done
	return 1
}

fsa_install_download() { # <url> <file>
	curl --proto "$proto" --tlsv1.2 -fsSL --retry 2 -o "$2" "$1" ||
		fsa_install_fail 3 release_unavailable "Cannot download $1." 'Check the version, the network and FSA_RELEASE_URL, then run install.sh again.'
}

fsa_install_verify() { # <dir with the manifest and its bundle>: cosign verify-blob in the pinned image (R58)
	work=$1
	stage=$work/verify
	if ! { mkdir "$stage" && cp "$work/release-manifest.json" "$stage/release-manifest.json" && cp "$work/release-manifest.json.sigstore.json" "$stage/bundle.json"; }; then
		fsa_install_fail 3 verify_failed 'Cannot stage the manifest for verification.'
	fi
	if [ -n "${FSA_TRUST_KEY:-}" ]; then
		cp "$FSA_TRUST_KEY" "$stage/trust.pub" || fsa_install_fail 2 trust_key_invalid 'Cannot read FSA_TRUST_KEY.'
		set -- --network none "$cosign_image" verify-blob --bundle /verify/bundle.json --key /verify/trust.pub --insecure-ignore-tlog=true
	else
		set -- "$cosign_image" verify-blob --bundle /verify/bundle.json --certificate-identity-regexp "$identity_regexp" \
			--certificate-oidc-issuer "$oidc_issuer" --certificate-github-workflow-trigger "$workflow_trigger"
	fi
	chmod 0755 "$stage" && chmod 0644 "$stage"/*
	docker image inspect "$cosign_image" >/dev/null 2>&1 || docker pull -q "$cosign_image" >/dev/null ||
		fsa_install_fail 3 cosign_unavailable 'Cannot pull the cosign image that verifies releases.' 'Check that the host can reach ghcr.io.'
	# docker cp instead of a bind mount: works for temporary directories Docker Desktop or Colima do not share.
	cid=$(docker create -e HOME=/tmp "$@" /verify/release-manifest.json) || fsa_install_fail 3 verify_failed 'Cannot create the cosign container.'
	status=0
	{ docker cp "$stage" "$cid:/verify" && docker start -a "$cid"; } >"$work/verify.log" 2>&1 || status=$?
	docker rm -f "$cid" >/dev/null 2>&1 || true
	[ "$status" = 0 ] || fsa_install_fail 3 manifest_signature_invalid "The release manifest signature does not match the signer built into install.sh: $(tail -n 1 "$work/verify.log")" \
		'Install only releases published by the form-schema-app release workflow. If Sigstore was unreachable, retry later.'
}

fsa_install_read_manifest() { # <manifest>: sets release (version) and ref (app image by digest, FSA_REGISTRY applied)
	flat=$(tr -d ' \t\r\n' <"$1")
	release=$(printf '%s\n' "$flat" | sed -n 's/^{"schema_version":1,"version":"\([0-9A-Za-z.-]*\)".*/\1/p')
	app=$(printf '%s\n' "$flat" | sed -n 's/.*"images":{"app":{"registry":"\([a-z0-9.:-]*\)","repository":"\([a-z0-9._\/-]*\)","tag":"[A-Za-z0-9_.-]*","digest":"\(sha256:[a-f0-9]\{64\}\)".*/\1 \2 \3/p')
	[ -n "$release" ] && [ -n "$app" ] || fsa_install_fail 3 manifest_invalid 'The release manifest is not in a format this install.sh reads.' 'Use the install.sh of the same release.'
	[ -z "$version" ] || [ "$version" = "$release" ] || fsa_install_fail 3 manifest_invalid "The manifest is for $release, not $version."
	registry=${app%% *}
	app=${app#* }
	ref=${FSA_REGISTRY:-$registry}/${app%% *}@${app#* }
}

fsa_install_prepare_dir() { # instance directory 0700, marker, verified release, shim from the image
	umask 077
	if ! { mkdir -p "$dir" && chmod 0700 "$dir"; }; then
		fsa_install_fail 2 invalid_dir "Cannot create $dir." 'Pass --dir with a directory you can write to.'
	fi
	[ -f "$dir/.fsa-instance" ] || printf '{"schema_version":"1.0","kind":"form-schema-app instance"}\n' >"$dir/.fsa-instance"
	releases=$dir/releases/$release
	{ mkdir -p "$releases" && chmod 0700 "$dir/releases" "$releases"; } || fsa_install_fail 2 invalid_dir "Cannot write to $dir."
	for file in release-manifest.json release-manifest.json.sigstore.json; do
		{ cp "$tmp/$file" "$releases/.$file.tmp" && mv "$releases/.$file.tmp" "$releases/$file"; } || fsa_install_fail 2 invalid_dir "Cannot write to $dir."
	done
	docker run --rm --network none --entrypoint cat "$ref" /var/www/html/distribution/shim.sh >"$dir/.fsa.tmp" && [ -s "$dir/.fsa.tmp" ] ||
		fsa_install_fail 3 shim_unavailable "Cannot read the fsa shim from $ref."
	chmod 0700 "$dir/.fsa.tmp" && mv "$dir/.fsa.tmp" "$dir/fsa"
}

fsa_install_link() { # optional PATH link (ADR 0049): never replaces an existing fsa
	if [ "$(id -u)" = 0 ]; then bin=/usr/local/bin; else bin=$HOME/.local/bin; fi
	if [ -e "$bin/fsa" ] || [ -L "$bin/fsa" ]; then
		printf 'install.sh: %s/fsa already exists and was left alone; run %s/fsa.\n' "$bin" "$dir" >&2
	elif mkdir -p "$bin" && ln -s "$dir/fsa" "$bin/fsa"; then
		printf 'install.sh: linked %s/fsa to %s/fsa.\n' "$bin" "$dir" >&2
	fi
}

fsa_install_main() {
	set -eu
	nl='
'
	fsa_install_trust
	dir='' version='' json=0 link='' tmp=''
	n=$#
	while [ "$n" -gt 0 ]; do
		arg=$1
		shift
		n=$((n - 1))
		case $arg in
		--dir=*) dir=${arg#*=} ;;
		--version=*) version=${arg#*=} && set -- "$@" "$arg" ;;
		--dir | --version)
			[ "$n" -gt 0 ] || fsa_install_fail 2 invalid_usage "$arg needs a value."
			if [ "$arg" = --dir ]; then dir=$1; else version=$1 && set -- "$@" "$arg" "$1"; fi
			shift
			n=$((n - 1)) ;;
		--json) json=1 && set -- "$@" "$arg" ;;
		--link) link=1 ;;
		--no-link) link=0 ;;
		-h | --help)
			printf 'Usage: curl --proto =https --tlsv1.2 -fsSL <release>/install.sh | sh -s -- [fsa install flags] [--link]\n'
			printf 'Flags and prompts: docs/guide/agent-guide.md. FSA_RELEASE_URL, FSA_REGISTRY: mirrors.\n'
			exit 0 ;;
		*) set -- "$@" "$arg" ;;
		esac
	done
	fsa_install_check_inputs
	command -v docker >/dev/null 2>&1 || fsa_install_fail 10 docker_missing 'Docker is not installed.' 'Install Docker Engine or Docker Desktop, then run install.sh again.'
	docker version --format '{{.Server.Version}}' >/dev/null 2>&1 ||
		fsa_install_fail 10 docker_unreachable 'The Docker daemon is not reachable.' 'Start Docker (or check your docker group membership), then run install.sh again.'
	command -v curl >/dev/null 2>&1 || fsa_install_fail 3 curl_missing 'curl is required.'

	trap fsa_install_cleanup EXIT
	trap 'exit 130' INT
	trap 'exit 143' TERM
	tmp=$(mktemp -d "${TMPDIR:-/tmp}/fsa-install.XXXXXX") || fsa_install_fail 3 tmp_unavailable 'Cannot create a temporary directory.'
	base=$url/latest/download
	[ -z "$version" ] || base=$url/download/v$version
	fsa_install_say verify "Verifying release ${version:-latest} from $url"
	fsa_install_download "$base/release-manifest.json" "$tmp/release-manifest.json"
	fsa_install_download "$base/release-manifest.json.sigstore.json" "$tmp/release-manifest.json.sigstore.json"
	fsa_install_verify "$tmp"
	fsa_install_read_manifest "$tmp/release-manifest.json"
	fsa_install_say pull "Pulling $ref"
	docker pull -q "$ref" >/dev/null || fsa_install_fail 3 image_unavailable "Cannot pull $ref." 'Check the network and FSA_REGISTRY, then run install.sh again.'
	fsa_install_prepare_dir

	fsa_install_say handover "Starting fsa install $release in $dir"
	# The shim from the verified image runs the handover; FSA_SHIM_LIBRARY=1 makes it define its functions only.
	# shellcheck disable=SC2034 # read by the sourced shim
	FSA_SHIM_LIBRARY=1 FSA_LAUNCHER=install.sh
	# shellcheck source=/dev/null # distribution/shim.sh, linted on its own
	. "$dir/fsa"
	FSA_RELEASE_MANIFEST=$releases/release-manifest.json
	export FSA_RELEASE_MANIFEST
	status=0
	fsa_run "$dir" "$ref" install --dir "$dir" "$@" || status=$?
	if [ "$status" = 0 ] && [ -z "$link" ] && [ "${FSA_TTY:-0}" = 1 ]; then
		printf 'Link fsa into your PATH? [y/N] ' >/dev/tty
		read -r answer </dev/tty || answer=''
		case $answer in [Yy]*) link=1 ;; esac
	fi
	[ "$status" != 0 ] || [ "$link" != 1 ] || fsa_install_link
	exit "$status"
}

[ "${FSA_INSTALL_LIBRARY:-}" = 1 ] || { fsa_install_main "$@"; }
