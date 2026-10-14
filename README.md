# Alias

Alias looks up player names that were recorded from the same network address. Recording is disabled by default because these records are sensitive, linkable player data.

## Compatibility and verification

- Minimum Axolotl-PM API: `5.0.0`.
- CI runs PHPStan at maximum level and builds against pinned API 5.0.0 and 5.49.1 source revisions.
- The generated PHAR is smoke-tested on the official Axolotl-PM 5.49.1 server release. See the [workflow runs](https://github.com/NhanAZ-Plugins/Alias/actions).
- These checks cover the listed source revisions. They do not prove compatibility with every API 5.x revision or test a connected player's network session.

## Privacy and stored data

IP-based alias recording remains opt-in. Review your privacy obligations before setting `record-ip-aliases: true` in `config.yml`. New indexes use a local HMAC key rather than storing raw IP addresses in their paths or YAML values. The keyed associations remain sensitive data. Protect the plugin data directory and keep `ip-index.key` with its indexes.

Validated legacy records can be read as a fallback and imported during a player's next join. The plugin does not remove or rewrite existing player files. Server owners remain responsible for data retention and deletion.

## Attribution and license

The historical manifest credits Ghast Noob. This repository does not contain a license file; no license was added or changed by this update.

## Build

The GitHub Actions workflow uses [DevTools](https://github.com/NhanAZ/DevTools) to run PHPStan, build the plugin PHAR and validate the artifact.
