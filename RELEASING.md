# Releasing

Coldstash is published at https://wordpress.org/plugins/coldstash from the SVN
repository https://plugins.svn.wordpress.org/coldstash. Releases are automated:
pushing a `v*` tag publishes to SVN. Nobody commits to SVN by hand.

## One-time setup
Repository secrets on GitHub (Settings → Secrets and variables → Actions):
- `SVN_USERNAME` — `nareknovembit` (case sensitive, not the email address).
- `SVN_PASSWORD` — the SVN password from the "Account & Security" section of the
  WordPress.org profile, not the login password.

## Cutting a release
1. Bump the version in three places: the `Version:` header and `COLDSTASH_VERSION`
   in `coldstash.php`, and `Stable tag:` in `readme.txt`. Add a changelog entry.
2. `composer test` green.
3. Commit, then `git tag vX.Y.Z && git push origin main vX.Y.Z`.

`.github/workflows/build-and-distribute.yml` then:
- fails if the three version numbers or the tag disagree;
- builds `dist/coldstash.zip` (`bin/build-zip.sh`; what is left out is defined
  once, in `.distignore`);
- runs Plugin Check on the built package;
- attaches the zip to the GitHub release;
- deploys the built package to SVN `trunk/` and `tags/X.Y.Z/`, and syncs
  `.wordpress-org/` to SVN `assets/`.

## Plugin page assets
`.wordpress-org/` holds exactly what goes into SVN `assets/`: icons (PNG + SVG),
both banners and `screenshot-1..4.png` (captions are `== Screenshots ==` in
readme.txt). The sources to re-render them are in `docs/wporg-assets-source/`.

Changes to `readme.txt` or assets alone ship with the next tagged release.
