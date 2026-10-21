#!/bin/sh
# The fake quick tunnel of the release harness (J6, BUILD_PROMPT_V2 §11; ADR 0082). It stands in for cloudflared in
# the instance's `tunnel` service (FIELDSET_TEST_TUNNEL, honoured only by harness images) and takes the same arguments
# (`tunnel --metrics <addr:port> --url http://app:8080`):
# - its metrics server answers /quicktunnel like cloudflared's ({"hostname":"<name>.trycloudflare.com"}), with a new
#   random name on every start, as a real quick tunnel gets;
# - port 8443 forwards plain HTTP to the app with that name as Host, so requests reach the app from this container's
#   fixed address (the tunnel peer), as cloudflared's would. Journeys send their requests there from the tunnel network.
# It contacts nothing outside the instance.
set -eu
metrics=0.0.0.0:20241
url=http://app:8080
while [ $# -gt 0 ]; do
	case $1 in
	--metrics) metrics=$2 && shift 2 ;;
	--url) url=$2 && shift 2 ;;
	*) shift ;;
	esac
done
name=fsa-test-$(od -An -N5 -tx1 /dev/urandom | tr -d ' \n')
export XDG_CONFIG_HOME=/tmp XDG_DATA_HOME=/tmp
cat >/tmp/Caddyfile <<EOF
{
	admin off
	persist_config off
	auto_https off
}
http://:${metrics##*:} {
	respond /quicktunnel \`{"hostname":"${name}.trycloudflare.com"}\` 200
	respond 404
}
http://:8443 {
	reverse_proxy ${url#http://} {
		header_up Host ${name}.trycloudflare.com
		header_up X-Forwarded-Proto https
	}
}
EOF
exec /usr/local/bin/caddy run --config /tmp/Caddyfile --adapter caddyfile
