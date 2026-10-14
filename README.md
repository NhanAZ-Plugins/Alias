# Alias

Alias is a legacy PocketMine-MP plugin that lists recorded player names associated with the same IP address. The original author is Ghast Noob.

## Compatibility

The manifest declares API `1.12.0`. This source has not been migrated to Axolotl-PM 5 and has no verified build or runtime test for that server. Do not install it on an Axolotl-PM 5 server.

## IP and alias data

IP-based recording is disabled by default. A server owner must review the privacy implications and set `record-ip-aliases: true` in the generated `config.yml` before the plugin records or looks up aliases. The plugin uses a locally stored HMAC key to index new records without writing raw IP addresses into the new index filenames or YAML values. These keyed associations are still sensitive, linkable player data; protect the plugin data directory and keep `ip-index.key` with its indexes. Losing that key prevents the new records from being correlated.

Existing legacy files may contain raw IP addresses. The plugin can read validated legacy records as a fallback and import their names on a player's next join, but it does not delete or rewrite those files. Server owners remain responsible for retention and deletion of legacy player data under their own policies.

## Project status

This repository is retained as a historical source reference. No new PHAR or release was produced during the Axolotl-PM 5 migration campaign. The repository has no license file, and redistribution rights have not been verified. The original author credit remains in `plugin.yml`.
