# The fake quick tunnel (tests/Install/Harness/fake-tunnel/fake-tunnel.sh; J6, ADR 0082), built by FakeTunnel as
# fsa-test-fake-tunnel-<id>. BASE is the Caddy image pinned in distribution/release.json.
ARG BASE
FROM ${BASE}
COPY --chmod=0755 fake-tunnel.sh /usr/local/bin/fake-tunnel
# A copy without the file capability (cp drops it): the tunnel service drops every capability and sets
# no-new-privileges, under which executing a binary with file capabilities fails; the fake only uses ports > 1024.
RUN cp /usr/bin/caddy /usr/local/bin/caddy
USER 65532:65532
ENTRYPOINT ["/usr/local/bin/fake-tunnel"]
