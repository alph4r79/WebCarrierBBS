# Security Policy

Thanks for helping keep WebCarrier BBS and the people who run it safe.

## Supported versions

Security fixes are provided for the latest released version. Please make sure you are on
the newest release before reporting, and before deploying a board in public.

| Version | Supported |
|---------|-----------|
| 1.8.x   | yes       |
| older   | no        |

## Reporting a vulnerability

Please report security issues privately, not in public issues, discussions, or pull
requests.

Use GitHub's private vulnerability reporting:

1. Go to the repository's **Security** tab.
2. Click **Report a vulnerability**.
3. Fill in the advisory form.

This keeps the report confidential between you and the maintainer until a fix is ready.

If you cannot use that form, open a normal issue that contains only the sentence
"I would like to report a security issue privately" and nothing else, and wait to be
contacted. Do not include any details in a public issue.

### What to include

- The version and how the board is set up (SQLite or MySQL/MariaDB, web server).
- A clear description of the problem and its impact.
- Steps to reproduce, ideally against a local test install.
- A proof of concept if you have one. Keep it minimal and non-destructive.

### What to expect

- An acknowledgement as soon as the report is seen.
- An honest assessment of severity and a plan for a fix.
- Credit in the release notes once the fix is out, if you want it.

This is a solo open source project, so please allow reasonable time for a response and a
fix before any public disclosure. Coordinated disclosure is appreciated.

## Scope

In scope:

- The WebCarrier BBS application code in this repository (terminal, sysop backend, doors
  API, update and backup, installer).
- The default configuration and the shipped `.htaccess` files.

Out of scope:

- Issues that only exist because a server is misconfigured, for example a web server that
  does not honour the shipped `.htaccess` and serves `data/` or `core/` directly. The
  documentation explains the rule needed on non-Apache servers. Reports that point out a
  concrete, exploitable default are of course welcome.
- Denial of service through sheer traffic volume.
- Third party components and the hosting environment.

## A note on the update channel

Release archives are signed with an Ed25519 key. The automatic updater verifies the
SHA256 hash and the signature before installing anything, and refuses downgrades. The
private signing key is never stored in this repository or on the website. If you believe
you can bypass the signature check, the hash check, the downgrade protection, or the
zip extraction guards, that is exactly the kind of report we want to hear about.

## Testing responsibly

If you test against the public demo, keep it gentle: no load testing, no destructive
actions, no attempts to disrupt the service for others. Prefer a local install for
anything intrusive. Never access, modify, or destroy data that is not yours, and never
pivot to other systems.

## Safe harbour

Good faith security research that follows this policy is welcome. We will not pursue or
support legal action against researchers who act in good faith, stay within scope, avoid
privacy violations and service disruption, and give us a reasonable chance to fix the
issue before disclosing it.
