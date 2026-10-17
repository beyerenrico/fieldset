# Keycloak (development and E2E only)

Identity provider for the SSO journeys (BUILD_PROMPT §8.7, ADR 0036). `compose.dev.yaml` runs
`quay.io/keycloak/keycloak:26.7.5` under the profile `sso` with `start-dev --import-realm`; the realm in
this folder is imported on the first start of a container (there is no volume, so recreating the container
re-imports it).

```bash
COMPOSE_PROFILES=sso docker compose up -d keycloak          # wait for (healthy)
COMPOSE_PROFILES=sso docker compose up -d --force-recreate keycloak   # after editing the realm
php artisan db:seed --class=SsoDemoSeeder                   # the matching organizations (idempotent)
```

- Issuer: `http://keycloak.localhost:8180/realms/form-schema` (`KC_HOSTNAME`). Browsers resolve
  `*.localhost` to loopback by themselves; the app containers reach it through `extra_hosts:
  keycloak.localhost:host-gateway`. With `KC_HOSTNAME_BACKCHANNEL_DYNAMIC`, token and JWKS URLs follow the
  host that asked (`http://localhost:8180/...` from the host). The issuer never changes.
- Keycloak sets its cookies `Secure` even over http. Browsers accept that on `localhost` and `*.localhost`
  only, so do not replace the hostname with an IP address.
- Admin console: http://keycloak.localhost:8180/admin (admin / admin, development only). The admin API
  refuses plain http from outside the container (`sslRequired: external` on the master realm); use
  `docker compose exec keycloak /opt/keycloak/bin/kcadm.sh` instead.

## Realm `form-schema` (`sslRequired: none`)

| Client | Settings |
|---|---|
| `form-schema-app` (OIDC, confidential) | secret `form-schema-dev-oidc-secret`; standard flow only; PKCE S256 required; redirect URI `http://localhost:8080/auth/sso/0b9f2d6e-5c1a-4e8b-9f3d-7a2c4e6b8d10/callback`; mapper `groups` (group names, no full path) in the ID token, access token and userinfo |
| `http://localhost:8080/auth/sso/3c7e9a1b-6d2f-4b8a-8e5c-1f4a7d9b2e60/saml/metadata` (SAML) | ACS (POST) `…/saml/acs`; response and assertion signed (RSA-SHA256); NameID format email (forced); attributes `email`, `firstName`, `lastName`, `groups`; AuthnRequests need no signature |

The provider uuids, the client id and the secret are the ones `database/seeders/SsoDemoSeeder.php` writes;
`tests/Feature/Sso/SeedSsoDemoTest.php` checks that both files agree. SAML metadata:
`http://keycloak.localhost:8180/realms/form-schema/protocol/saml/descriptor`.

Users (password `password`, email verified, no required actions):

| User | Groups | In the app |
|---|---|---|
| sso.designer@sso-demo.test | form-designers | JIT into SSO Demo GmbH as Field Worker + Designer (mapping) |
| sso.worker@sso-demo.test | – | JIT as Field Worker |
| legacy@sso-demo.test | – | also a local password user; enforcement refuses its password |
| saml.reviewer@saml-demo.test | form-reviewers | JIT into SAML Demo GmbH as Field Worker + Reviewer |

Notes:
- Keycloak leaves the `groups` claim out when a user has no groups (sso.worker); the mapping sync then sees a
  missing claim rather than an empty list.
- The login page (theme keycloak.v2) uses `#username`, `#password` and `#kc-login`; `login_hint` prefills
  the username.
