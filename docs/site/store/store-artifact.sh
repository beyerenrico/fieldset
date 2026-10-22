#!/usr/bin/env bash
# Verifies an untrusted docs build artifact and appends it to the docs-site branch as one commit (docs site plan U7;
# KTDs "Jobs split by privilege; privileged jobs verify the untrusted build" and "An append-only storage branch").
#
# The privileged store job of .github/workflows/docs.yml runs this file and version-order.jq beside it from a sparse
# checkout of main at the run's commit, never from the artifact. It runs only bash, git, jq, grep, cmp, find, cp and rm:
# no npm, node, php, composer or docker. Every fact it trusts comes from its arguments (resolve's verified tag and
# commit, the run's tooling commit) and from git; build's decision.json is never read for a decision.
#
#   store-artifact.sh --artifact <dir> --site <dir> --tag-repo <dir> --tag <vX.Y.Z[-rc.N]> --commit <sha>
#                     --minor <X.Y> --tooling <sha>
#
# --artifact  the downloaded build artifact; its site/ holds the files by ownership class (scripts/build.mjs)
# --site      a worktree of docs-site on the branch to append to; an unborn branch before the first publish
# --tag-repo  a git directory holding refs/tags/<tag> (the spec files and staged Markdown are read from there)
# --commit    the tag's commit, as resolve verified it against the signed release
# --tooling   the commit of main this run uses, which build passed as --built-with
#
# It rejects the artifact unless: every entry is a regular file or directory with a plain name; the release may publish
# by the decision table (a copy tag of 2.1 or later, never older than the stored copy, never an rc over a final); in
# versions.json only this minor's entry (exactly as this release states it) and `latest` (recomputed with
# version-order.jq) change; root-owned entries and spec files appear exactly when the published minor is latest, and
# every spec JSON file then equals the tag's file; every install.sh URL comes from the tag's staged Markdown or is this
# release's own download URL; every api-reference.html carries the exact CSP before anything else and no inline
# executable script; no page loads a script or stylesheet from another origin. It then replaces the copy (and, on a
# root refresh, the root-owned entries; spec files are only added or updated), checks the staged change against the
# same ownership classes and commits as github-actions[bot]. Pushing is the workflow's next step, which alone holds
# the token. Exit status: 0 committed (or nothing changed), 1 rejected, 2 usage.
set -euo pipefail
export LC_ALL=C

readonly REPOSITORY='beyerenrico/fieldset' # scripts/build.mjs REPOSITORY
readonly ROOT_OWNED=(index.html 404.html hashmap.json assets/ latest/ screenshots/) # scripts/build.mjs ROOT_OWNED
readonly SPEC_DIR='spec/form-definition/1.0'
readonly STAGED_MARKDOWN=(docs/guide docs/api/field-app-v1.md CHANGELOG.md) # lib/stage.mjs allowlist
readonly FIRST_COPY_TAG='v2.1.0-rc.0' # R10: the lowest copy tag of 2.1; anything below gets no copy
readonly REFERENCE_CSP="default-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'none'; form-action 'none'" # lib/openapi.mjs
readonly CSP_META="<meta http-equiv=\"Content-Security-Policy\" content=\"${REFERENCE_CSP}\">"
readonly NAME='[A-Za-z0-9_@~+-][A-Za-z0-9._@~+-]*'
readonly URL_CHAR='[A-Za-z0-9._~:/?#@!&*+,;=%-]'
readonly INSTALL_URL="${URL_CHAR}*//${URL_CHAR}*install\\.sh"
readonly LOCAL_REF='^(/fieldset/|[A-Za-z0-9_~@+-]|\.\.?/)[A-Za-z0-9._~@+/-]*$'
ORDER_JQ="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/version-order.jq"
readonly ORDER_JQ

fail() {
	echo "::error::store: $1" >&2
	exit 1
}

usage() {
	echo "store-artifact.sh: $1" >&2
	echo 'Usage: store-artifact.sh --artifact <dir> --site <dir> --tag-repo <dir> --tag <tag> --commit <sha> --minor <X.Y> --tooling <sha>' >&2
	exit 2
}

parse_args() {
	while [ "$#" -gt 0 ]; do
		[ "$#" -ge 2 ] || usage "$1 needs a value"
		case "$1" in
		--artifact) ARTIFACT=$2 ;;
		--site) SITE=$2 ;;
		--tag-repo) TAG_REPO=$2 ;;
		--tag) TAG=$2 ;;
		--commit) COMMIT=$2 ;;
		--minor) MINOR=$2 ;;
		--tooling) TOOLING=$2 ;;
		*) usage "unknown argument $(printf '%q' "$1")" ;;
		esac
		shift 2
	done
	for name in ARTIFACT SITE TAG_REPO TAG COMMIT MINOR TOOLING; do
		[ -n "${!name:-}" ] || usage '--artifact, --site, --tag-repo, --tag, --commit, --minor and --tooling are all required'
	done
	ART="$ARTIFACT/site"
}

# -1, 0 or 1 for two copy tags; exit status 5 (from jq) when either is not a copy tag.
order() {
	jq -n --arg a "$1" --arg b "$2" -f "$ORDER_JQ"
}

check_inputs() {
	order "$TAG" "$TAG" >/dev/null 2>&1 || fail "$(printf '%q' "$TAG") is not a docs copy tag (vX.Y.Z or vX.Y.Z-rc.N)"
	[[ "$TAG" =~ ^v([0-9]+\.[0-9]+)\. ]] || fail "cannot read the minor of $TAG"
	[ "$MINOR" = "${BASH_REMATCH[1]}" ] || fail "--minor $(printf '%q' "$MINOR") is not the minor of $TAG"
	[ "$(order "$TAG" "$FIRST_COPY_TAG")" != -1 ] || fail "$TAG is below 2.1, which gets no copy (R10)"
	[[ "$COMMIT" =~ ^[0-9a-f]{40}$ ]] || fail '--commit is not a full commit SHA'
	[[ "$TOOLING" =~ ^[0-9a-f]{40}$ ]] || fail '--tooling is not a full commit SHA'
	local tagged
	tagged="$(git -C "$TAG_REPO" rev-parse --verify --quiet "refs/tags/${TAG}^{commit}")" || fail "$TAG is not in the tag repository"
	[ "$tagged" = "$COMMIT" ] || fail "$TAG points at $tagged, not at the verified commit $COMMIT"
	[ -d "$ART" ] || fail 'the artifact has no site/ directory'
	[ -d "$SITE" ] || fail "$SITE is not a directory"
}

# Only regular files and directories with plain names (no dot segment, no special character) leave the artifact.
check_entries() {
	local odd path
	odd="$(find "$ART" -mindepth 1 ! -type f ! -type d -print -quit)"
	[ -z "$odd" ] || fail "the artifact holds a symlink or other non-regular file: ${odd#"$ART"/}"
	while IFS= read -r -d '' path; do
		path="${path#"$ART"/}"
		[[ "$path" =~ ^${NAME}(/${NAME})*$ ]] || fail "the artifact holds a path outside the allowed names: $(printf '%q' "$path")"
	done < <(find "$ART" -mindepth 1 -print0)
}

stored_versions() {
	if git -C "$SITE" rev-parse --verify --quiet HEAD >/dev/null; then
		[ -f "$SITE/versions.json" ] || fail 'docs-site has commits but no versions.json'
		STORED="$SITE/versions.json"
	else
		[ ! -e "$SITE/versions.json" ] || fail 'the unborn docs-site worktree already holds versions.json'
		STORED=''
	fi
}

stored_field() { # <jq arguments and filter>, run on the stored file; empty before the first publish
	[ -z "$STORED" ] || jq -r "$@" "$STORED"
}

# The decision table (HTD "Version decision") against this minor's stored copy.
check_decision() {
	local stored_tag
	# shellcheck disable=SC2016 # $m is a jq variable
	stored_tag="$(stored_field --arg m "$MINOR" '[.copies[] | select(.minor == $m) | .tag] | if length > 1 then error("minor listed twice") else .[0] // "" end')" ||
		fail 'the stored versions.json on docs-site is malformed'
	[ -n "$stored_tag" ] || return 0
	if [[ "$TAG" == *-rc.* && "$stored_tag" != *-rc.* ]]; then
		fail "$MINOR has the final copy $stored_tag; a pre-release never replaces it (R21)"
	fi
	[ "$(order "$TAG" "$stored_tag")" != -1 ] || fail "$TAG is older than the stored copy $stored_tag"
}

# Every copy entry is self-consistent and names a copy tag; LATEST is the newest final, else the newest rc (R8).
compute_latest() {
	local versions="$ART/versions.json" tags tag best='' pool=() finals=()
	[ -f "$versions" ] || fail 'the artifact has no versions.json'
	jq -e '.schema == 1 and (.copies | type == "array" and length > 0) and ([.copies[].minor] | length == (unique | length))
		and all(.copies[]; (.tag | type == "string") and .tag == "v\(.version)"
			and .minor == (.version | capture("^(?<m>[0-9]+\\.[0-9]+)\\.").m) and .prerelease == (.version | test("-")))' \
		"$versions" >/dev/null 2>&1 || fail 'versions.json in the artifact is not a consistent schema 1 file'
	tags="$(jq -r '.copies[].tag' "$versions")"
	while IFS= read -r tag; do
		order "$tag" "$tag" >/dev/null 2>&1 || fail "versions.json lists $(printf '%q' "$tag"), which is not a copy tag"
		if [[ "$tag" == *-rc.* ]]; then pool+=("$tag"); else finals+=("$tag"); fi
	done <<<"$tags"
	[ "${#finals[@]}" -eq 0 ] || pool=("${finals[@]}")
	for tag in "${pool[@]}"; do
		if [ -z "$best" ] || [ "$(order "$tag" "$best")" = 1 ]; then best=$tag; fi
	done
	[[ "$best" =~ ^v([0-9]+\.[0-9]+)\. ]] || fail "cannot read the minor of $best"
	LATEST="${BASH_REMATCH[1]}"
	REFRESH=false
	[ "$LATEST" != "$MINOR" ] || REFRESH=true
}

# Only this minor's entry, exactly as this release states it, and latest may differ from the stored file.
check_versions_diff() {
	local prerelease=false
	[[ "$TAG" != *-rc.* ]] || prerelease=true
	jq -e -n --slurpfile art "$ART/versions.json" --slurpfile stored "${STORED:-/dev/null}" --arg minor "$MINOR" \
		--arg tag "$TAG" --argjson prerelease "$prerelease" --arg tooling "$TOOLING" --arg latest "$LATEST" '
		def others: [.copies[] | select(.minor != $minor)] | sort_by(.minor);
		$art[0] as $a | ($stored[0] // {schema: 1, copies: []}) as $s
		| $a.latest == $latest
		and ([$a.copies[] | select(.minor == $minor)]
			== [{minor: $minor, version: $tag[1:], tag: $tag, prerelease: $prerelease, builtWith: $tooling}])
		and ($a | others) == ($s | others)
		and ($a | del(.latest, .copies)) == ($s | del(.latest, .copies))' >/dev/null 2>&1 ||
		fail "versions.json changes more than the $MINOR entry for $TAG (built with $TOOLING) and latest $LATEST"
}

is_root_owned() { # <top-level name> → the ROOT_OWNED entry, or status 1
	local entry
	for entry in "${ROOT_OWNED[@]}"; do
		if [ "${entry%/}" = "$1" ]; then
			echo "$entry"
			return 0
		fi
	done
	return 1
}

# The top-level entries the verified tag allows: the copy and versions.json always, the root and spec on a refresh.
check_top_level() {
	local path name entry
	[ -d "$ART/$MINOR" ] || fail "the artifact has no $MINOR/ copy"
	for path in "$ART"/*; do
		name="${path#"$ART"/}"
		if [ "$name" = "$MINOR" ] || [ "$name" = versions.json ]; then continue; fi
		if entry="$(is_root_owned "$name")" || [ "$name" = spec ]; then
			[ "$REFRESH" = true ] || fail "$name is root-owned, but $TAG does not refresh the root (latest stays $LATEST)"
			if [ "$name" = spec ]; then entry=spec/; fi
			if [[ "$entry" == */ ]]; then [ -d "$path" ] || fail "$name must be a directory"; else [ -f "$path" ] || fail "$name must be a file"; fi
			continue
		fi
		fail "the artifact holds $name, which no ownership class of $TAG allows"
	done
	if [ "$REFRESH" = true ]; then
		for name in index.html 404.html "$SPEC_DIR/meta-schema.json"; do
			[ -f "$ART/$name" ] || fail "$TAG refreshes the root, but the artifact lacks $name"
		done
	fi
}

# On a refresh the spec directory holds pages and exactly the tag's JSON files, byte for byte (R5, the $id).
check_spec() {
	[ "$REFRESH" = true ] || return 0
	local file rel expected actual
	while IFS= read -r -d '' file; do
		rel="${file#"$ART"/}"
		if [[ "$rel" == spec/form-definition || "$rel" == "$SPEC_DIR" ]] && [ -d "$file" ]; then continue; fi
		[[ "$rel" == "$SPEC_DIR/"* && "${rel#"$SPEC_DIR/"}" != */* && -f "$file" ]] || fail "spec/ holds $rel, which is not a file of $SPEC_DIR/"
		[[ "$rel" == *.json || "$rel" == *.html ]] || fail "$rel is neither a spec JSON file nor a page"
	done < <(find "$ART/spec" -mindepth 1 -print0)
	expected="$(git -C "$TAG_REPO" ls-tree --name-only "$COMMIT" "$SPEC_DIR/" | grep -E '\.json$' | sort || true)"
	actual="$(find "$ART/$SPEC_DIR" -type f -name '*.json' -print | sed "s|^$ART/||" | sort)"
	[ -n "$expected" ] || fail "$TAG has no JSON files in $SPEC_DIR"
	[ "$expected" = "$actual" ] || fail "the spec JSON files differ from those of $TAG: ${actual//$'\n'/ }"
	while IFS= read -r file; do
		cmp -s "$ART/$file" <(git -C "$TAG_REPO" show "$COMMIT:$file") || fail "$file differs from $file at $TAG"
	done <<<"$expected"
}

# Install commands come from the tag's staged Markdown or are this release's own download URL (the rc notice, home).
check_install_urls() {
	local allowed found url status=0
	allowed="$(git -C "$TAG_REPO" grep -hoE "$INSTALL_URL" "$COMMIT" -- "${STAGED_MARKDOWN[@]}")" || status=$?
	[ "$status" -le 1 ] || fail "git grep at $TAG failed with status $status"
	allowed+=$'\n'"https://github.com/${REPOSITORY}/releases/download/${TAG}/install.sh"
	found="$(grep -rhoaE "$INSTALL_URL" "$ART" | sort -u || true)"
	while IFS= read -r url; do
		[ -n "$url" ] || continue
		grep -qxF -- "$url" <<<"$allowed" || fail "the artifact names an install.sh URL that $TAG does not: $url"
	done <<<"$found"
}

# api-reference.html: exactly one CSP meta, the exact literal, nothing but doctype/html/head/charset before it, and every
# script either a JSON data block or loaded by src (R22: the CSP blocks requests and inline script from there on).
check_reference_page() {
	local file=$1 html before tag rel=${1#"$ART"/}
	html="$(<"$file")"
	[ "$(grep -oiF 'Content-Security-Policy' <<<"$html" | wc -l)" -eq 1 ] || fail "$rel must have exactly one Content-Security-Policy"
	[[ "$html" == *"$CSP_META"* ]] || fail "$rel does not carry the exact CSP literal $CSP_META"
	before="${html%%"$CSP_META"*}"
	while IFS= read -r tag; do
		[ -n "$tag" ] || continue
		[[ "$tag" =~ ^\<(\!doctype\ html|html(\ lang=\"[A-Za-z-]+\")?|head|meta\ charset=\"utf-8\")\>$ ]] ||
			fail "$rel has $tag before its CSP"
	done < <(grep -oiE '<[^>]*>' <<<"$before" || true)
	while IFS= read -r tag; do
		[ -n "$tag" ] || continue
		[[ "$tag" =~ [[:space:]]src= || "$tag" =~ [[:space:]]type=\"application/json\" ]] ||
			fail "$rel has an inline executable script: $tag"
	done < <(grep -oiE '<script([[:space:]][^>]*)?>' <<<"$html" || true)
}

check_reference_pages() {
	local file count=0
	while IFS= read -r -d '' file; do
		check_reference_page "$file"
		count=$((count + 1))
	done < <(find "$ART" -type f -name api-reference.html -print0)
	[ "$count" -ge 1 ] || fail "the $MINOR copy has no api-reference.html"
}

check_ref() { # <file> <tag> <attribute>: the attribute, written once as attr="…", points at this site
	local where=${1#"$ART"/} before value after
	[[ "$2" =~ ^(.*)[[:space:]]$3=\"([^\"]*)\"(.*)$ ]] || fail "$where has a $3 that is not written as $3=\"…\": $2"
	before="${BASH_REMATCH[1]}" value="${BASH_REMATCH[2]}" after="${BASH_REMATCH[3]}"
	[[ ! "$before$after" =~ [[:space:]]$3[[:space:]]*= ]] || fail "$where has a tag with two $3 attributes: $2"
	[[ "$value" =~ $LOCAL_REF ]] || fail "$where loads $value from another origin: $2"
}

# No page loads a script or stylesheet from another origin; no <base> re-points relative URLs. Tags and attribute
# names match case-insensitively, as HTML does.
check_origins() {
	local file tag quotes
	shopt -s nocasematch
	while IFS= read -r -d '' file; do
		while IFS= read -r tag; do
			[ -n "$tag" ] || continue
			quotes="${tag//[^\"]/}"
			[[ "$tag" != \<base* ]] || fail "${file#"$ART"/} has a <base> element"
			[[ "$tag" != *"'"* && $((${#quotes} % 2)) -eq 0 ]] || fail "${file#"$ART"/} has an unparseable tag: $tag"
			if [[ "$tag" == \<script* && "$tag" =~ [[:space:]]src[[:space:]]*= ]]; then check_ref "$file" "$tag" src; fi
			if [[ "$tag" == \<link* && "$tag" =~ stylesheet|preload|prefetch ]]; then check_ref "$file" "$tag" href; fi
		done < <(grep -aoiE '<(script|link|base)([[:space:]][^>]*)?>' "$file" || true)
	done < <(find "$ART" -type f -name '*.html' -print0)
	shopt -u nocasematch
	if grep -rlaiE --include='*.css' '@import[^;]*(//|:)' "$ART" >/dev/null; then
		fail 'a stylesheet @imports another origin'
	fi
}

replace_entry() { # <top-level entry>: removed from the site, then copied from the artifact when it has one
	rm -rf "${SITE:?}/${1:?}"
	if [ -e "$ART/$1" ]; then cp -R "$ART/$1" "$SITE/$1"; fi
}

apply() {
	local entry
	replace_entry "$MINOR"
	cp "$ART/versions.json" "$SITE/versions.json"
	[ "$REFRESH" = true ] || return 0
	for entry in "${ROOT_OWNED[@]}"; do replace_entry "${entry%/}"; done
	mkdir -p "$SITE/$SPEC_DIR"
	cp "$ART/$SPEC_DIR"/* "$SITE/$SPEC_DIR/"
}

allowed_change() { # <status> <path>
	local entry
	if [[ "$2" == "$MINOR/"* || "$2" == versions.json ]]; then return 0; fi
	[ "$REFRESH" = true ] || return 1
	if [[ "$2" == "$SPEC_DIR/"* && "${2#"$SPEC_DIR/"}" != */* ]]; then [ "$1" != D ]; return; fi
	for entry in "${ROOT_OWNED[@]}"; do
		if [[ "$entry" == */ && "$2" == "$entry"* ]] || [ "$2" = "$entry" ]; then return 0; fi
	done
	return 1
}

# The staged change, checked again against the ownership classes: nothing else changes, spec files are never deleted.
check_staged() {
	local status path mode
	git -C "$SITE" add -A -f
	while IFS=$'\t' read -r status path; do
		allowed_change "$status" "$path" || fail "the publish would change $path ($status), which $TAG may not change"
	done < <(git -C "$SITE" diff --cached --name-status --no-renames)
	while read -r mode _; do
		[[ "$mode" == 100644 || "$mode" == 100755 ]] || fail "the publish would store an entry with mode $mode"
	done < <(git -C "$SITE" ls-files --stage)
}

commit() {
	if git -C "$SITE" rev-parse --verify --quiet HEAD >/dev/null && git -C "$SITE" diff --cached --quiet; then
		echo "store: $TAG is stored unchanged; nothing to commit"
		return 0
	fi
	local bot='github-actions[bot]' email='41898282+github-actions[bot]@users.noreply.github.com'
	GIT_AUTHOR_NAME=$bot GIT_AUTHOR_EMAIL=$email GIT_COMMITTER_NAME=$bot GIT_COMMITTER_EMAIL=$email \
		git -C "$SITE" commit --quiet --no-verify -m "docs: publish ${TAG} as /fieldset/${MINOR}/" \
		-m "Release commit ${COMMIT}, tooling ${TOOLING}; latest ${LATEST}; root refreshed: ${REFRESH}."
	echo "store: committed $(git -C "$SITE" rev-parse HEAD) for $TAG (latest $LATEST, root refreshed: $REFRESH)"
}

main() {
	parse_args "$@"
	check_inputs
	check_entries
	stored_versions
	check_decision
	compute_latest
	check_versions_diff
	check_top_level
	check_spec
	check_install_urls
	check_reference_pages
	check_origins
	apply
	check_staged
	commit
}

main "$@"
