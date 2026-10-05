# Changelog

## 0.1.1

- Clearing or refreshing the Stache now also brings Statamic's list of asset files, and what it remembers about each one, in line with the disk. With a shared cache that list outlives a deploy, so an image added, renamed or removed in Git stayed missing or lingered until the whole cache was cleared.

## 0.1.0

- First release.
- Control panel saves are mailed to object storage as immutable batches of content-addressed blobs.
- A GitHub Action lands the batches in the repository as commits authored by the editor, with a three-way merge against the branch.
- Edits that conflict with the branch go to their own branch and pull request, so nothing is lost.
