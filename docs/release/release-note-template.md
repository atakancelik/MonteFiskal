# Release note template

Copy into `CHANGELOG.md` under a new `## [x.y.z] - YYYY-MM-DD` heading. The release workflow refuses a tag whose entry has
no "Fiscal behaviour changed" line.

```markdown
## [x.y.z] - YYYY-MM-DD

Fiscal behaviour changed: yes | no
Schema revision: fs-v1 <revision>

<!-- "yes" when any request, IKOF, number, validation or sending rule changed. Then every installation must
register this version on the SEP and enter the new software code (BR-R7). -->

### Added
### Changed
### Fixed
### Security
```

## Release checklist

1. `<Version>` in `Directory.Build.props`, `Version:` and `MONTEFISKAL_WC_VERSION` in the plugin main file, and
   `Stable tag` in `readme.txt` all name the new version.
2. `python3 -I tools/ai-kit/generate.py` updates the version stamp of the prompts and the briefing; commit the result.
3. Every CI workflow is green on the release commit; the efitest workflow passed on that commit when fiscal behaviour
   changed.
4. The CHANGELOG entry has the "Fiscal behaviour changed" line and, for "yes", the SEP reminder is in the release text.
5. Tag `vX.Y.Z`; the release workflow builds NuGet packages, the plugin zip and `SHA256SUMS` into a draft release.
6. Review the draft, publish it, push the NuGet packages, and let Packagist pick up the tag.
