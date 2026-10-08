# Webba Deploy — architecture gap fixes

## Scope (approved)
- [ ] Settings UI: global settings (logging, cache flush) + delete-on-uninstall opt-in
- [ ] Uninstall: honor opt-in and clear transient rows
- [ ] Deploy lock: prevent concurrent deploys
- [ ] SecretEncryption: authenticated encryption (encrypt-then-MAC), backward compatible decrypt
- [ ] JwtGenerator: drop deprecated openssl_free_key()
- [ ] NativeUpdateIntegration: do not fabricate an update object from `false`
- [ ] Tests: PHPUnit + Brain Monkey, phpunit.xml.dist, composer script, CI job
- [ ] Ignore build/test artifacts

## Deferred (needs product decision / live site)
- GitHub App webhooks (Phase 5) — needs `webhooks: write` App permission; readme markets read-only
- WP.org screenshots — needs a running site

## Review
(pending)
